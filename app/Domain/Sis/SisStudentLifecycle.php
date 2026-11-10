<?php

namespace App\Domain\Sis;

use App\Domain\Audit\AuditRecorder;
use App\Domain\Tenancy\TenantContext;
use App\Models\SisStudent;
use App\Models\SisStudentLifecycleEvent;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class SisStudentLifecycle
{
    /** @var array<string, list<string>> */
    private const TRANSITIONS = [
        'pre_admitted' => ['admitted', 'withdrawn'],
        'admitted' => ['active', 'withdrawn', 'transferred'],
        'active' => ['on_leave', 'suspended', 'transferred', 'withdrawn', 'graduated'],
        'on_leave' => ['active', 'withdrawn', 'transferred'],
        'suspended' => ['active', 'withdrawn'],
        'transferred' => ['active', 'alumni', 'archived'],
        'withdrawn' => ['active', 'archived'],
        'graduated' => ['alumni', 'archived'],
        'alumni' => ['archived'],
        'archived' => [],
    ];

    public function __construct(
        private readonly TenantContext $context,
        private readonly AuditRecorder $audit,
        private readonly SisStudentAccountAccessPolicy $accountAccessPolicy,
    ) {}

    public function transition(SisStudent $student, string $to, Carbon $effectiveAt, string $reasonCode, ?string $reason, User $actor): SisStudent
    {
        return DB::transaction(function () use ($student, $to, $effectiveAt, $reasonCode, $reason, $actor): SisStudent {
            $locked = SisStudent::query()->lockForUpdate()->findOrFail($student->getKey());
            if ($locked->status === $to) {
                return $locked;
            }
            $this->assertCanTransition($locked, $to, $effectiveAt);

            $from = $locked->status;
            $locked->forceFill(['status' => $to, 'archived_at' => $to === 'archived' ? $effectiveAt : null, 'updated_by' => $actor->getKey()])->save();
            SisStudentLifecycleEvent::query()->create([
                'student_id' => $locked->getKey(), 'event_type' => 'status_changed', 'from_status' => $from,
                'to_status' => $to, 'effective_at' => $effectiveAt, 'reason_code' => $reasonCode,
                'reason' => $reason, 'actor_user_id' => $actor->getKey(), 'safe_metadata' => [],
            ]);
            $this->audit->record('sis.student.status_changed', $actor, (int) $this->context->tenant()->getKey(), $locked, ['from' => $from, 'to' => $to, 'reason_code' => $reasonCode]);
            $this->accountAccessPolicy->applyLifecycleTransition($locked, $from, $to, $reasonCode, $actor);

            return $locked;
        }, 3);
    }

    public function assertCanTransition(SisStudent $student, string $to, Carbon $effectiveAt): void
    {
        if (! in_array($to, self::TRANSITIONS[$student->status] ?? [], true)) {
            throw ValidationException::withMessages(['status' => 'This student status transition is not permitted.']);
        }
        if ($effectiveAt->isFuture()) {
            throw ValidationException::withMessages(['effective_at' => 'Future status changes are not supported; record the transition on its effective date.']);
        }
        $latestEvent = SisStudentLifecycleEvent::query()->where('student_id', $student->getKey())
            ->orderByDesc('effective_at')->orderByDesc('id')->first();
        if ($latestEvent && $effectiveAt->lt($latestEvent->effective_at)) {
            throw ValidationException::withMessages(['effective_at' => 'Status history must remain chronological. Use an audited correction workflow for historical errors.']);
        }
    }
}
