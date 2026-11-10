<?php

namespace App\Domain\Sis;

use App\Domain\Audit\AuditRecorder;
use App\Domain\Tenancy\TenantContext;
use App\Models\Institution;
use App\Models\SisRegistrySetting;
use App\Models\SisStudent;
use App\Models\SisStudentLifecycleRequest;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class SisStudentLifecycleApprovalWorkflow
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly SisStudentLifecycle $lifecycle,
        private readonly AuditRecorder $audit,
    ) {}

    /** @return bool True when the transition is pending independent review. */
    public function request(SisStudent $student, string $to, Carbon $effectiveAt, string $reasonCode, ?string $reason, User $actor): bool
    {
        $institution = Institution::query()->firstOrFail();
        $settings = SisRegistrySetting::query()->where('institution_id', $institution->getKey())->first();
        if (! $settings?->lifecycle_requires_approval) {
            $this->lifecycle->transition($student, $to, $effectiveAt, $reasonCode, $reason, $actor);

            return false;
        }

        DB::transaction(function () use ($student, $to, $effectiveAt, $reasonCode, $reason, $actor, $institution): void {
            $locked = SisStudent::query()->lockForUpdate()->findOrFail($student->getKey());
            if ($locked->status === $to) {
                throw ValidationException::withMessages(['status' => 'The student already has this lifecycle status.']);
            }
            $this->lifecycle->assertCanTransition($locked, $to, $effectiveAt);
            if (SisStudentLifecycleRequest::query()->where('student_id', $locked->getKey())->where('status', 'pending')->exists()) {
                throw ValidationException::withMessages(['status' => 'A lifecycle transition is already awaiting independent review.']);
            }

            $request = SisStudentLifecycleRequest::query()->create([
                'institution_id' => $institution->getKey(), 'student_id' => $locked->getKey(),
                'from_status' => $locked->status, 'to_status' => $to, 'effective_at' => $effectiveAt,
                'reason_code' => $reasonCode, 'reason' => $reason, 'requested_by' => $actor->getKey(),
                'status' => 'pending', 'pending_student_id' => $locked->getKey(),
            ]);
            $this->audit->record('sis.student.lifecycle_transition_requested', $actor, (int) $this->context->tenant()->getKey(), $request, [
                'student_id' => $locked->getKey(), 'from' => $locked->status, 'to' => $to, 'reason_code' => $reasonCode,
            ]);
        }, 3);

        return true;
    }

    public function review(SisStudentLifecycleRequest $request, User $reviewer, string $decision, ?string $decisionReason): string
    {
        return DB::transaction(function () use ($request, $reviewer, $decision, $decisionReason): string {
            $lockedRequest = SisStudentLifecycleRequest::query()->lockForUpdate()->findOrFail($request->getKey());
            if ($lockedRequest->status !== 'pending') {
                throw ValidationException::withMessages(['decision' => 'This lifecycle request has already been reviewed.']);
            }
            if ((int) $lockedRequest->requested_by === (int) $reviewer->getKey()) {
                throw ValidationException::withMessages(['decision' => 'A requester cannot review their own lifecycle transition.']);
            }
            $student = SisStudent::query()->lockForUpdate()->findOrFail($lockedRequest->student_id);
            if ($student->status !== $lockedRequest->from_status) {
                $lockedRequest->forceFill([
                    'status' => 'stale', 'pending_student_id' => null,
                    'reviewed_by' => $reviewer->getKey(), 'reviewed_at' => now(),
                    'decision_reason' => 'The student lifecycle changed before this request was reviewed.',
                ])->save();
                $this->audit->record('sis.student.lifecycle_transition_stale', $reviewer, (int) $this->context->tenant()->getKey(), $lockedRequest, [
                    'student_id' => $student->getKey(), 'to' => $lockedRequest->to_status,
                ]);

                return 'stale';
            }

            if ($decision === 'approve') {
                $this->lifecycle->transition($student, $lockedRequest->to_status, $lockedRequest->effective_at, $lockedRequest->reason_code, $lockedRequest->reason, $reviewer);
            }
            $status = $decision === 'approve' ? 'approved' : 'rejected';
            $lockedRequest->forceFill([
                'status' => $status, 'pending_student_id' => null, 'reviewed_by' => $reviewer->getKey(),
                'reviewed_at' => now(), 'decision_reason' => $decisionReason,
            ])->save();
            $this->audit->record('sis.student.lifecycle_transition_'.$status, $reviewer, (int) $this->context->tenant()->getKey(), $lockedRequest, [
                'student_id' => $student->getKey(), 'from' => $lockedRequest->from_status,
                'to' => $lockedRequest->to_status, 'reason_code' => $lockedRequest->reason_code,
            ]);

            return $status;
        }, 3);
    }
}
