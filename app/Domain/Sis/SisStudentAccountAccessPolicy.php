<?php

namespace App\Domain\Sis;

use App\Domain\Audit\AuditRecorder;
use App\Domain\Tenancy\TenantContext;
use App\Models\SisRegistrySetting;
use App\Models\SisStudent;
use App\Models\TenantMembership;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

final class SisStudentAccountAccessPolicy
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly AuditRecorder $audit,
    ) {}

    public function applyLifecycleTransition(
        SisStudent $student,
        string $from,
        string $to,
        string $reasonCode,
        User $actor,
    ): void {
        if ($to === 'suspended' && $from !== 'suspended' && $this->suspensionPolicyEnabled($student)) {
            $this->suspendStudentOnlyMembership($student, $reasonCode, $actor);
        }

        if ($from === 'suspended' && $to === 'active') {
            $this->reactivateSuspendedMembership($student, $actor);
        }
    }

    private function suspensionPolicyEnabled(SisStudent $student): bool
    {
        $settings = SisRegistrySetting::query()->where('institution_id', $student->institution_id)->first();

        return $settings === null || $settings->suspend_membership_on_student_suspension;
    }

    private function suspendStudentOnlyMembership(SisStudent $student, string $reasonCode, User $actor): void
    {
        if ($student->user_id === null) {
            return;
        }

        $membership = $this->membershipForStudent($student)->lockForUpdate()->first();
        if (! $membership || $membership->status !== 'active') {
            return;
        }

        $roleKeys = $membership->roles()->orderBy('key')->pluck('key')->all();
        if ($roleKeys !== ['student']) {
            $this->audit->record('sis.student.account_access_suspension_skipped', $actor, (int) $student->tenant_id, $membership, [
                'student_id' => $student->getKey(),
                'reason_code' => $reasonCode,
                'reason' => in_array('student', $roleKeys, true) ? 'shared_membership_roles' : 'student_role_not_assigned',
            ]);

            return;
        }

        $membership->forceFill([
            'status' => 'suspended',
            'sis_student_suspension_student_id' => $student->getKey(),
            'sis_student_suspended_at' => now(),
            'sis_student_suspension_reason_code' => $reasonCode,
        ])->save();

        $this->audit->record('sis.student.account_access_suspended', $actor, (int) $student->tenant_id, $membership, [
            'student_id' => $student->getKey(),
            'reason_code' => $reasonCode,
        ]);
    }

    private function reactivateSuspendedMembership(SisStudent $student, User $actor): void
    {
        $membership = TenantMembership::query()
            ->where('tenant_id', $this->context->tenant()->getKey())
            ->where('institution_id', $student->institution_id)
            ->where('sis_student_suspension_student_id', $student->getKey())
            ->lockForUpdate()
            ->first();

        if (! $membership) {
            return;
        }

        if ($membership->status !== 'suspended' || $membership->revoked_at !== null
            || $student->user_id === null || (int) $membership->user_id !== (int) $student->user_id) {
            $this->audit->record('sis.student.account_access_reactivation_skipped', $actor, (int) $student->tenant_id, $membership, [
                'student_id' => $student->getKey(),
                'reason' => 'membership_state_or_identity_changed',
            ]);

            return;
        }

        $roleKeys = $membership->roles()->orderBy('key')->pluck('key')->all();
        if ($roleKeys !== ['student']) {
            $this->audit->record('sis.student.account_access_reactivation_skipped', $actor, (int) $student->tenant_id, $membership, [
                'student_id' => $student->getKey(),
                'reason' => 'membership_roles_changed_review_required',
            ]);

            return;
        }

        $membership->forceFill([
            'status' => 'active',
            'activated_at' => now(),
            'sis_student_suspension_student_id' => null,
            'sis_student_suspended_at' => null,
            'sis_student_suspension_reason_code' => null,
        ])->save();

        $this->audit->record('sis.student.account_access_reactivated', $actor, (int) $student->tenant_id, $membership, [
            'student_id' => $student->getKey(),
        ]);
    }

    /** @return Builder<TenantMembership> */
    private function membershipForStudent(SisStudent $student): Builder
    {
        return TenantMembership::query()
            ->where('tenant_id', $this->context->tenant()->getKey())
            ->where('institution_id', $student->institution_id)
            ->where('user_id', $student->user_id);
    }
}
