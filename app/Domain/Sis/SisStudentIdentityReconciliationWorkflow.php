<?php

namespace App\Domain\Sis;

use App\Domain\Academic\AcademicAccess;
use App\Domain\Audit\AuditRecorder;
use App\Domain\Tenancy\TenantContext;
use App\Models\Role;
use App\Models\SisIdentityReconciliationCase;
use App\Models\SisStudent;
use App\Models\TenantMembership;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

final class SisStudentIdentityReconciliationWorkflow
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly AcademicAccess $academicAccess,
        private readonly AuditRecorder $audit,
    ) {}

    public function request(SisStudent $student, int $proposedUserId, string $reasonCode, string $reason, User $actor): SisIdentityReconciliationCase
    {
        $tenantId = (int) $this->context->tenant()->getKey();

        return DB::transaction(function () use ($student, $proposedUserId, $reasonCode, $reason, $actor, $tenantId): SisIdentityReconciliationCase {
            $lockedStudent = SisStudent::query()->where('tenant_id', $tenantId)->lockForUpdate()->findOrFail($student->getKey());
            if ($lockedStudent->user_id === null) {
                throw ValidationException::withMessages(['identity' => 'Use the normal identity-link flow while the student has no linked account.']);
            }
            if ((int) $lockedStudent->user_id === $proposedUserId) {
                throw ValidationException::withMessages(['proposed_user_id' => 'The proposed account is already linked to this student.']);
            }
            if (! in_array($lockedStudent->status, ['pre_admitted', 'admitted', 'active', 'on_leave'], true)) {
                throw ValidationException::withMessages(['identity' => 'Identity reconciliation is unavailable for the current student lifecycle status.']);
            }
            if (SisIdentityReconciliationCase::query()->where('tenant_id', $tenantId)
                ->where('student_id', $lockedStudent->getKey())->where('status', 'pending')->exists()) {
                throw new ConflictHttpException('An identity reconciliation review is already pending for this student.');
            }

            $targetMembership = TenantMembership::query()->where('tenant_id', $tenantId)
                ->where('institution_id', $lockedStudent->institution_id)->where('user_id', $proposedUserId)
                ->where('status', 'active')->lockForUpdate()->firstOrFail();
            $this->academicAccess->assertCampus($targetMembership->campus_id === null ? null : (int) $targetMembership->campus_id);

            if (SisStudent::query()->where('tenant_id', $tenantId)->where('user_id', $proposedUserId)->exists()) {
                throw ValidationException::withMessages(['proposed_user_id' => 'The proposed identity is already linked to another student record.']);
            }

            $case = SisIdentityReconciliationCase::query()->create([
                'institution_id' => $lockedStudent->institution_id,
                'student_id' => $lockedStudent->getKey(),
                'current_user_id' => $lockedStudent->user_id,
                'proposed_user_id' => $proposedUserId,
                'requested_by' => $actor->getKey(),
                'status' => 'pending',
                'reason_code' => $reasonCode,
                'request_reason' => $reason,
                'requested_at' => now(),
                'pending_student_id' => $lockedStudent->getKey(),
            ]);
            $this->audit->record('sis.student.identity_reconciliation_requested', $actor, $tenantId, $case, [
                'student_id' => (int) $lockedStudent->getKey(),
                'current_user_id' => (int) $lockedStudent->user_id,
                'proposed_user_id' => $proposedUserId,
                'reason_code' => $reasonCode,
            ]);

            return $case;
        }, 3);
    }

    public function decide(SisIdentityReconciliationCase $case, string $decision, string $decisionReason, User $actor): SisIdentityReconciliationCase
    {
        if (! in_array($decision, ['approve', 'reject'], true)) {
            throw ValidationException::withMessages(['decision' => 'Select an approval or rejection decision.']);
        }
        $tenantId = (int) $this->context->tenant()->getKey();

        return DB::transaction(function () use ($case, $decision, $decisionReason, $actor, $tenantId): SisIdentityReconciliationCase {
            $lockedCase = SisIdentityReconciliationCase::query()->where('tenant_id', $tenantId)
                ->lockForUpdate()->findOrFail($case->getKey());
            if ($lockedCase->status !== 'pending') {
                throw new ConflictHttpException('This identity reconciliation case is no longer pending.');
            }
            if ((int) $lockedCase->requested_by === (int) $actor->getKey()
                || (int) $lockedCase->current_user_id === (int) $actor->getKey()
                || (int) $lockedCase->proposed_user_id === (int) $actor->getKey()) {
                throw ValidationException::withMessages(['decision' => 'A different authorized reviewer who is not either linked identity must decide this case.']);
            }

            $student = SisStudent::query()->where('tenant_id', $tenantId)->lockForUpdate()->findOrFail($lockedCase->student_id);
            if ((int) $student->institution_id !== (int) $lockedCase->institution_id
                || (int) $student->user_id !== (int) $lockedCase->current_user_id
                || ! in_array($student->status, ['pre_admitted', 'admitted', 'active', 'on_leave'], true)) {
                $lockedCase->forceFill([
                    'status' => 'stale', 'reviewed_by' => $actor->getKey(), 'reviewed_at' => now(),
                    'decision_reason' => 'The student identity or lifecycle state changed after the review was requested.',
                    'pending_student_id' => null,
                ])->save();
                $this->audit->record('sis.student.identity_reconciliation_stale', $actor, $tenantId, $lockedCase, [
                    'student_id' => (int) $student->getKey(), 'reason_code' => 'preconditions_changed',
                ]);

                return $lockedCase;
            }

            $this->academicAccess->assertCampus($student->home_campus_id === null ? null : (int) $student->home_campus_id);
            if ($decision === 'reject') {
                $lockedCase->forceFill([
                    'status' => 'rejected', 'reviewed_by' => $actor->getKey(), 'reviewed_at' => now(),
                    'decision_reason' => $decisionReason, 'pending_student_id' => null,
                ])->save();
                $this->audit->record('sis.student.identity_reconciliation_rejected', $actor, $tenantId, $lockedCase, [
                    'student_id' => (int) $student->getKey(), 'reason_code' => $lockedCase->reason_code,
                ]);

                return $lockedCase;
            }

            $targetMembership = TenantMembership::query()->where('tenant_id', $tenantId)
                ->where('institution_id', $lockedCase->institution_id)->where('user_id', $lockedCase->proposed_user_id)
                ->where('status', 'active')->lockForUpdate()->first();
            $alreadyLinked = SisStudent::query()->where('tenant_id', $tenantId)
                ->where('user_id', $lockedCase->proposed_user_id)->whereKeyNot($student->getKey())->exists();
            if ($targetMembership === null || $alreadyLinked) {
                $lockedCase->forceFill([
                    'status' => 'stale', 'reviewed_by' => $actor->getKey(), 'reviewed_at' => now(),
                    'decision_reason' => 'The proposed account is no longer eligible for this institution.',
                    'pending_student_id' => null,
                ])->save();
                $this->audit->record('sis.student.identity_reconciliation_stale', $actor, $tenantId, $lockedCase, [
                    'student_id' => (int) $student->getKey(), 'reason_code' => 'proposed_identity_unavailable',
                ]);

                return $lockedCase;
            }
            $this->academicAccess->assertCampus($targetMembership->campus_id === null ? null : (int) $targetMembership->campus_id);

            $studentRole = Role::query()->where('tenant_id', $tenantId)->where('key', 'student')->where('scope', 'tenant')->firstOrFail();
            $currentMembership = TenantMembership::query()->where('tenant_id', $tenantId)
                ->where('institution_id', $lockedCase->institution_id)->where('user_id', $lockedCase->current_user_id)
                ->lockForUpdate()->first();
            if ($currentMembership !== null) {
                $this->academicAccess->assertCampus($currentMembership->campus_id === null ? null : (int) $currentMembership->campus_id);
                $currentMembership->roles()->detach($studentRole->getKey());
                if ($currentMembership->roles()->count() === 0) {
                    $currentMembership->forceFill([
                        'status' => 'revoked', 'revoked_at' => now(),
                        'sis_student_suspension_student_id' => null,
                        'sis_student_suspended_at' => null,
                        'sis_student_suspension_reason_code' => null,
                    ])->save();
                }
            }
            $targetMembership->roles()->syncWithoutDetaching([$studentRole->getKey() => [
                'assigned_by' => $actor->getKey(), 'tenant_id' => $tenantId,
            ]]);
            $student->forceFill(['user_id' => $lockedCase->proposed_user_id, 'updated_by' => $actor->getKey()])->save();
            $lockedCase->forceFill([
                'status' => 'approved', 'reviewed_by' => $actor->getKey(), 'reviewed_at' => now(),
                'applied_at' => now(), 'decision_reason' => $decisionReason, 'pending_student_id' => null,
            ])->save();
            $this->audit->record('sis.student.identity_reconciliation_applied', $actor, $tenantId, $lockedCase, [
                'student_id' => (int) $student->getKey(),
                'previous_user_id' => (int) $lockedCase->current_user_id,
                'new_user_id' => (int) $lockedCase->proposed_user_id,
                'reason_code' => $lockedCase->reason_code,
            ]);

            return $lockedCase;
        }, 3);
    }
}
