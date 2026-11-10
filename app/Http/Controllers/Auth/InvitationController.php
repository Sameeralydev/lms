<?php

namespace App\Http\Controllers\Auth;

use App\Domain\Audit\AuditRecorder;
use App\Domain\Sis\SisGuardianIdentityInvitationWorkflow;
use App\Domain\Sis\SisStudentIdentityInvitationWorkflow;
use App\Domain\Tenancy\TenantContext;
use App\Http\Controllers\Controller;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\TenantInvitation;
use App\Models\TenantMembership;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password as PasswordRule;
use Inertia\Inertia;
use Inertia\Response;

class InvitationController extends Controller
{
    public function show(string $token): Response|RedirectResponse
    {
        $invitation = $this->findValid($token);
        abort_unless($invitation instanceof TenantInvitation, 404);

        return Inertia::render('auth/accept-invitation', [
            'token' => $token,
            'email' => $invitation->email,
            'tenantName' => $invitation->tenant->name,
            'signed' => request()->hasValidSignature(),
            'signedUrl' => request()->fullUrl(),
        ]);
    }

    public function accept(
        string $token,
        Request $request,
        AuditRecorder $audit,
        TenantContext $context,
        SisStudentIdentityInvitationWorkflow $sisIdentity,
        SisGuardianIdentityInvitationWorkflow $guardianIdentity,
    ): RedirectResponse {
        abort_unless($request->hasValidSignature(), 403);
        $invitation = $this->findValid($token);
        abort_unless($invitation instanceof TenantInvitation, 404);
        abort_if($invitation->sis_student_id !== null && $invitation->sis_student_guardian_id !== null, 409);

        if ($request->user() && strtolower($request->user()->email) !== strtolower($invitation->email)) {
            return redirect()->route('login')->with('status', 'Sign in with the invited email address to continue.');
        }

        $existing = User::query()->where('email', $invitation->email)->first();
        if ($existing && (! $request->user() || $request->user()->getKey() !== $existing->getKey())) {
            return redirect()->route('login')->with('status', 'Sign in with the invited email address to continue.');
        }

        $rules = $existing
            ? []
            : ['name' => ['required', 'string', 'max:160'], 'password' => ['required', 'confirmed', PasswordRule::min(12)->letters()->mixedCase()->numbers()]];
        $data = $request->validate($rules);

        $membership = DB::transaction(function () use ($invitation, $existing, $data, $audit, $context, $sisIdentity, $guardianIdentity): TenantMembership {
            $locked = TenantInvitation::query()->lockForUpdate()->findOrFail($invitation->getKey());
            abort_unless($locked->status === 'pending' && $locked->expires_at->isFuture(), 410);
            $context->enterProvisioning(Tenant::query()->findOrFail($locked->tenant_id));

            try {
                $user = $existing ?? User::query()->create([
                    'name' => $data['name'],
                    'email' => $locked->email,
                    'password' => Hash::make($data['password']),
                ]);
                if (! $user->email_verified_at) {
                    $user->forceFill(['email_verified_at' => now()])->save();
                }

                $membership = TenantMembership::query()->withoutGlobalScope('tenant')->firstOrNew([
                    'tenant_id' => $locked->tenant_id,
                    'user_id' => $user->getKey(),
                ]);
                abort_if($membership->sis_student_suspension_student_id !== null, 409, 'Reactivate the linked student record before accepting another membership invitation.');
                if (($locked->sis_student_id !== null || $locked->sis_student_guardian_id !== null) && $membership->exists
                    && (int) $membership->institution_id !== (int) $locked->institution_id) {
                    abort(409, 'This account has a membership in a different institution and requires identity review.');
                }
                abort_if($membership->exists && $membership->status === 'active', 409);
                if ($membership->exists) {
                    $previousRoleKeys = $membership->roles()->pluck('key')->all();
                    $membership->roles()->detach();
                    if ($previousRoleKeys !== []) {
                        $audit->record('membership.invitation_roles_replaced', $user, (int) $locked->tenant_id, $membership, [
                            'previous_role_keys' => $previousRoleKeys,
                            'assigned_role_key' => $locked->role_key,
                        ]);
                    }
                }
                $membership->forceFill([
                    'tenant_id' => $locked->tenant_id,
                    'institution_id' => $locked->institution_id,
                    'user_id' => $user->getKey(),
                    'campus_id' => $locked->campus_id,
                    'status' => 'active',
                    'activated_at' => now(),
                    'revoked_at' => null,
                ])->save();
                $role = Role::query()->where('tenant_id', $locked->tenant_id)->where('key', $locked->role_key)->firstOrFail();
                $membership->roles()->syncWithoutDetaching([$role->getKey() => ['assigned_by' => $locked->invited_by, 'tenant_id' => $locked->tenant_id]]);
                $sisIdentity->linkOnAcceptance($locked, $user, $membership);
                $guardianIdentity->linkOnAcceptance($locked, $user, $membership);
                $locked->forceFill(['status' => 'accepted', 'accepted_at' => now()])->save();
                $audit->record('membership.invitation_accepted', $user, (int) $locked->tenant_id, $membership, ['role_key' => $locked->role_key]);

                return $membership;
            } finally {
                $context->clear();
            }
        });

        if (! $request->user()) {
            Auth::login($membership->user()->firstOrFail());
        }
        $request->session()->regenerate();

        $destination = $membership->hasPermission('tenant.view')
            ? redirect()->route('tenant.dashboard', ['tenant' => $membership->tenant()->firstOrFail()->slug])
            : redirect()->route('workspaces.index');

        return $destination->with('status', 'Your institution membership is active.');
    }

    private function findValid(string $token): ?TenantInvitation
    {
        return TenantInvitation::query()->with('tenant')
            ->where('token_hash', hash('sha256', $token))
            ->where('status', 'pending')
            ->where('expires_at', '>', now())
            ->whereHas('tenant', fn ($query) => $query->where('status', 'active'))
            ->first();
    }
}
