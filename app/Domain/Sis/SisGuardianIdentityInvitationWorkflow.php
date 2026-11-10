<?php

namespace App\Domain\Sis;

use App\Domain\Audit\AuditRecorder;
use App\Domain\Tenancy\TenantContext;
use App\Models\Institution;
use App\Models\Role;
use App\Models\SisStudent;
use App\Models\SisStudentGuardian;
use App\Models\TenantInvitation;
use App\Models\TenantMembership;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class SisGuardianIdentityInvitationWorkflow
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly AuditRecorder $audit,
    ) {}

    public function issue(SisStudentGuardian $guardian, User $actor): SisGuardianIdentityInvitationResult
    {
        $tenantId = (int) $this->context->tenant()->getKey();
        $rawToken = Str::random(64);

        $invitation = DB::transaction(function () use ($guardian, $rawToken, $actor, $tenantId): TenantInvitation {
            $snapshot = SisStudent::query()->where('tenant_id', $tenantId)->findOrFail($guardian->student_id);
            Institution::query()->where('tenant_id', $tenantId)->lockForUpdate()->findOrFail($snapshot->institution_id);
            $student = SisStudent::query()->where('tenant_id', $tenantId)->lockForUpdate()->findOrFail($guardian->student_id);
            $lockedGuardian = SisStudentGuardian::query()->where('tenant_id', $tenantId)->lockForUpdate()->findOrFail($guardian->getKey());
            $email = Str::lower(trim((string) $lockedGuardian->guardian_email));

            if ($lockedGuardian->authority_status !== 'verified' || $lockedGuardian->revoked_at !== null) {
                throw ValidationException::withMessages(['guardian_identity' => 'A guardian account can only be invited after the relationship is verified and not revoked.']);
            }
            if ($email === '' || $lockedGuardian->guardian_user_id !== null) {
                throw ValidationException::withMessages(['guardian_identity' => 'A verified guardian email without an existing account link is required.']);
            }
            if ((int) $student->institution_id !== (int) $snapshot->institution_id) {
                throw ValidationException::withMessages(['guardian_identity' => 'The student institution changed during verification. Reload the profile and retry.']);
            }

            $existingUser = User::query()->whereRaw('LOWER(email) = ?', [$email])->first();
            if ($existingUser) {
                $membership = TenantMembership::query()->withoutGlobalScope('tenant')->where('tenant_id', $tenantId)
                    ->where('user_id', $existingUser->getKey())->lockForUpdate()->first();
                if ($membership && (int) $membership->institution_id !== (int) $student->institution_id) {
                    throw ValidationException::withMessages(['guardian_identity' => 'This identity has a membership in another institution in this tenant and needs manual review.']);
                }
                if ($membership?->status === 'active') {
                    throw ValidationException::withMessages(['guardian_identity' => 'This person already has an active institution account. Link the verified account from the guardian verification workflow.']);
                }
            }

            $pendingInvitations = TenantInvitation::query()->where('tenant_id', $tenantId)
                ->whereRaw('LOWER(email) = ?', [$email])->where('status', 'pending')->lockForUpdate()->get();
            foreach ($pendingInvitations as $pendingInvitation) {
                if ((int) $pendingInvitation->sis_student_guardian_id !== (int) $lockedGuardian->getKey()) {
                    throw ValidationException::withMessages(['guardian_identity' => 'A pending institution invitation already uses this email. Resolve it before inviting this guardian.']);
                }
                $pendingInvitation->forceFill(['status' => 'cancelled'])->save();
                $this->audit->record('sis.guardian.identity_invitation_cancelled', $actor, $tenantId, $pendingInvitation, [
                    'guardian_id' => $lockedGuardian->getKey(), 'reason' => 'reissued',
                ]);
            }

            $parentRole = Role::query()->where('tenant_id', $tenantId)->where('key', 'parent.guardian')->where('scope', 'tenant')->firstOrFail();
            $invitation = TenantInvitation::query()->create([
                'tenant_id' => $tenantId,
                'institution_id' => $student->institution_id,
                'sis_student_guardian_id' => $lockedGuardian->getKey(),
                'campus_id' => $student->home_campus_id,
                'invited_by' => $actor->getKey(),
                'email' => $email,
                'role_key' => $parentRole->key,
                'token_hash' => hash('sha256', $rawToken),
                'status' => 'pending',
                'expires_at' => now()->addDays(7),
            ]);

            $this->audit->record('sis.guardian.identity_invited', $actor, $tenantId, $invitation, [
                'guardian_id' => $lockedGuardian->getKey(), 'student_id' => $student->getKey(),
                'role_key' => $parentRole->key, 'profile_access_granted' => false,
            ]);

            return $invitation;
        }, 3);

        return new SisGuardianIdentityInvitationResult($invitation, $rawToken);
    }

    public function linkOnAcceptance(TenantInvitation $invitation, User $user, TenantMembership $membership): void
    {
        if ($invitation->sis_student_guardian_id === null) {
            return;
        }

        abort_unless($invitation->sis_student_id === null && $invitation->role_key === 'parent.guardian', 409);
        $tenantId = (int) $invitation->tenant_id;
        $guardianSnapshot = SisStudentGuardian::query()->where('tenant_id', $tenantId)->findOrFail($invitation->sis_student_guardian_id);
        $studentSnapshot = SisStudent::query()->where('tenant_id', $tenantId)->findOrFail($guardianSnapshot->student_id);

        DB::transaction(function () use ($invitation, $user, $membership, $tenantId, $studentSnapshot): void {
            Institution::query()->where('tenant_id', $tenantId)->lockForUpdate()->findOrFail($studentSnapshot->institution_id);
            $student = SisStudent::query()->where('tenant_id', $tenantId)->lockForUpdate()->findOrFail($studentSnapshot->getKey());
            $guardian = SisStudentGuardian::query()->where('tenant_id', $tenantId)->lockForUpdate()->findOrFail($invitation->sis_student_guardian_id);
            $email = Str::lower(trim((string) $guardian->guardian_email));

            abort_unless($guardian->authority_status === 'verified' && $guardian->revoked_at === null && $email !== '', 409);
            abort_unless($user->email_verified_at !== null && hash_equals($email, Str::lower(trim($user->email))), 409);
            abort_unless((int) $student->institution_id === (int) $invitation->institution_id
                && (int) $membership->tenant_id === $tenantId
                && (int) $membership->institution_id === (int) $student->institution_id
                && (int) $membership->user_id === (int) $user->getKey()
                && $membership->status === 'active', 409);
            abort_if($guardian->guardian_user_id !== null && (int) $guardian->guardian_user_id !== (int) $user->getKey(), 409);

            $conflictingIdentity = SisStudentGuardian::query()->where('tenant_id', $tenantId)
                ->where('guardian_user_id', $user->getKey())->whereKeyNot($guardian->getKey())
                ->whereRaw('LOWER(TRIM(guardian_email)) <> ?', [$email])->exists();
            abort_if($conflictingIdentity, 409);

            $guardian->forceFill(['guardian_user_id' => $user->getKey(), 'can_view_profile' => false])->save();
            $this->audit->record('sis.guardian.identity_provisioned', $user, $tenantId, $guardian, [
                'student_id' => $student->getKey(), 'membership_id' => $membership->getKey(),
                'invitation_id' => $invitation->getKey(), 'profile_access_granted' => false,
            ]);
        }, 3);
    }
}
