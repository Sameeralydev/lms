<?php

namespace App\Domain\Examinations;

use App\Domain\Academic\AcademicAccess;
use App\Domain\Audit\AuditRecorder;
use App\Domain\Tenancy\TenantContext;
use App\Models\ExamInvigilatorAssignment;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/** Owns the response and audit lifecycle for an invigilator's assigned duty. */
final class ExamInvigilatorWorkflow
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly AcademicAccess $access,
        private readonly AuditRecorder $audit,
    ) {}

    public function respond(int $assignmentId, string $decision, User $actor): ExamInvigilatorAssignment
    {
        abort_unless(in_array($decision, ['accept', 'decline'], true), 422);
        $membership = $this->context->membership();
        $tenantId = (int) $membership->tenant_id;

        return DB::transaction(function () use ($assignmentId, $decision, $actor, $tenantId): ExamInvigilatorAssignment {
            $assignment = ExamInvigilatorAssignment::query()->where('tenant_id', $tenantId)->lockForUpdate()->findOrFail($assignmentId);
            abort_unless((int) $assignment->user_id === (int) $actor->getKey(), 403, 'Only the assigned invigilator can respond to this duty.');
            $assignment->loadMissing('session');
            $this->access->assertCampus((int) $assignment->session->campus_id);
            if ($assignment->status !== 'assigned') {
                throw new ConflictHttpException('This examination duty is no longer awaiting a response.');
            }

            if ($decision === 'accept') {
                if ($assignment->accepted_at !== null) {
                    return $assignment;
                }
                $assignment->forceFill(['accepted_at' => now()])->save();
            } else {
                if ($assignment->accepted_at !== null) {
                    throw new ConflictHttpException('An accepted duty must be released by an examination manager.');
                }
                $assignment->forceFill(['status' => 'declined'])->save();
            }

            $this->audit->record('exams.invigilator.duty_'.$decision, $actor, $tenantId, $assignment, [
                'session_id' => (int) $assignment->session_id,
                'duty_role' => (string) $assignment->duty_role,
            ]);
            $eventType = 'exam.invigilator_duty_'.$decision;
            DB::table('quiz_outbox_events')->insertOrIgnore([
                'tenant_id' => $tenantId,
                'event_key' => $eventType.':assignment:'.$assignment->getKey(),
                'event_type' => $eventType,
                'safe_payload' => json_encode(['assignment_id' => (int) $assignment->getKey()], JSON_THROW_ON_ERROR),
                'status' => 'queued', 'attempts' => 0, 'available_at' => now(), 'created_at' => now(), 'updated_at' => now(),
            ]);

            return $assignment->fresh();
        }, 3);
    }
}
