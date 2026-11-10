<?php

namespace App\Domain\Sis;

use App\Domain\Audit\AuditRecorder;
use App\Domain\Tenancy\TenantContext;
use App\Models\Institution;
use App\Models\Role;
use App\Models\SisStudent;
use App\Models\TenantInvitation;
use App\Models\TenantMembership;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class SisStudentIdentityInvitationWorkflow
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly AuditRecorder $audit,
    ) {}

    public function issue(SisStudent $student, string $email, User $actor): SisStudentIdentityInvitationResult
    {
        $tenantId = (int) $this->context->tenant()->getKey();
        $normalizedEmail = Str::lower(trim($email));
        $rawToken = Str::random(64);

        $invitation = DB::transaction(function () use ($student, $normalizedEmail, $rawToken, $actor, $tenantId): TenantInvitation {
            Institution::query()->where('tenant_id', $tenantId)->lockForUpdate()->findOrFail($student->institution_id);
            $lockedStudent = SisStudent::query()->where('tenant_id', $tenantId)->lockForUpdate()->findOrFail($student->getKey());
            $this->assertEligible($lockedStudent);

            if ($lockedStudent->user_id !== null) {
                throw ValidationException::withMessages(['identity_email' => 'This student already has a linked login identity.']);
            }

            $existingUser = User::query()->whereRaw('LOWER(email) = ?', [$normalizedEmail])->first();
            if ($existingUser) {
                $existingMembership = TenantMembership::query()->where('tenant_id', $tenantId)
                    ->where('user_id', $existingUser->getKey())->lockForUpdate()->first();
                if ($existingMembership && (int) $existingMembership->institution_id !== (int) $lockedStudent->institution_id) {
                    throw ValidationException::withMessages(['identity_email' => 'This identity has a membership in a different institution in this tenant and needs manual review.']);
                }
                if ($existingMembership?->status === 'active') {
                    $message = 'This person already has an active institution account. Link that account from the existing-account list.';
                    throw ValidationException::withMessages(['identity_email' => $message]);
                }

                if (SisStudent::query()->where('tenant_id', $tenantId)->where('user_id', $existingUser->getKey())->exists()) {
                    throw ValidationException::withMessages(['identity_email' => 'This account is already linked to another student record in this tenant.']);
                }
            }

            $conflictingInvitation = TenantInvitation::query()->where('tenant_id', $tenantId)
                ->whereRaw('LOWER(email) = ?', [$normalizedEmail])->where('status', 'pending')
                ->whereNotNull('sis_student_id')->where('sis_student_id', '<>', $lockedStudent->getKey())
                ->lockForUpdate()->exists();
            if ($conflictingInvitation) {
                throw ValidationException::withMessages(['identity_email' => 'A pending student identity invitation already uses this email. Resolve it before inviting another student.']);
            }

            $priorInvitations = TenantInvitation::query()->where('tenant_id', $tenantId)->where('sis_student_id', $lockedStudent->getKey())
                ->where('status', 'pending')->lockForUpdate()->get();
            foreach ($priorInvitations as $priorInvitation) {
                $priorInvitation->forceFill(['status' => 'cancelled'])->save();
                $this->audit->record('sis.student.identity_invitation_cancelled', $actor, $tenantId, $priorInvitation, [
                    'student_id' => $lockedStudent->getKey(), 'reason' => 'reissued',
                ]);
            }

            $studentRole = Role::query()->where('tenant_id', $tenantId)->where('key', 'student')->where('scope', 'tenant')->firstOrFail();
            $invitation = TenantInvitation::query()->create([
                'tenant_id' => $tenantId,
                'institution_id' => $lockedStudent->institution_id,
                'sis_student_id' => $lockedStudent->getKey(),
                'campus_id' => $lockedStudent->home_campus_id,
                'invited_by' => $actor->getKey(),
                'email' => $normalizedEmail,
                'role_key' => $studentRole->key,
                'token_hash' => hash('sha256', $rawToken),
                'status' => 'pending',
                'expires_at' => now()->addDays(7),
            ]);

            $this->audit->record('sis.student.identity_invited', $actor, $tenantId, $invitation, [
                'student_id' => $lockedStudent->getKey(), 'role_key' => $studentRole->key,
                'campus_scoped' => $lockedStudent->home_campus_id !== null,
            ]);

            return $invitation;
        }, 3);

        return new SisStudentIdentityInvitationResult($invitation, $rawToken);
    }

    public function linkOnAcceptance(TenantInvitation $invitation, User $user, TenantMembership $membership): void
    {
        if ($invitation->sis_student_id === null) {
            return;
        }

        $tenantId = (int) $invitation->tenant_id;
        abort_unless($invitation->role_key === 'student', 409);

        DB::transaction(function () use ($invitation, $user, $membership, $tenantId): void {
            Institution::query()->where('tenant_id', $tenantId)->lockForUpdate()->findOrFail($invitation->institution_id);
            $student = SisStudent::query()->where('tenant_id', $tenantId)->lockForUpdate()->findOrFail($invitation->sis_student_id);
            $this->assertEligible($student);

            abort_unless((int) $student->institution_id === (int) $invitation->institution_id
                && (int) $membership->tenant_id === $tenantId
                && (int) $membership->institution_id === (int) $student->institution_id
                && (int) $membership->user_id === (int) $user->getKey(), 409);

            $alreadyLinked = SisStudent::query()->where('tenant_id', $tenantId)->where('user_id', $user->getKey())
                ->whereKeyNot($student->getKey())->exists();
            abort_if($alreadyLinked || ($student->user_id !== null && (int) $student->user_id !== (int) $user->getKey()), 409);

            $student->forceFill(['user_id' => $user->getKey(), 'updated_by' => $invitation->invited_by])->save();
            $this->audit->record('sis.student.identity_provisioned', $user, $tenantId, $student, [
                'user_id' => $user->getKey(), 'membership_id' => $membership->getKey(),
                'invitation_id' => $invitation->getKey(),
            ]);
        }, 3);
    }

    private function assertEligible(SisStudent $student): void
    {
        if (! in_array($student->status, ['pre_admitted', 'admitted', 'active', 'on_leave'], true)) {
            throw ValidationException::withMessages(['identity_email' => 'A student login can only be provisioned for a pre-admitted, admitted, active, or on-leave student.']);
        }
    }
}
