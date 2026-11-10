<?php

namespace App\Http\Controllers\Workspace;

use App\Domain\Audit\AuditRecorder;
use App\Domain\Tenancy\TenantContext;
use App\Http\Controllers\Controller;
use App\Mail\TenantInvitationMail;
use App\Models\Campus;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\TenantInvitation;
use App\Models\TenantMembership;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Illuminate\Support\Testing\Fakes\MailFake;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class MembershipController extends Controller
{
    public function index(TenantContext $context): Response
    {
        return Inertia::render('workspace/members/index', [
            'memberships' => TenantMembership::query()->with(['user:id,name,email', 'campus:id,name', 'roles:id,key,name'])->orderByDesc('created_at')->paginate(20),
            'invitations' => TenantInvitation::query()->where('tenant_id', $context->tenant()->getKey())->where('status', 'pending')->latest()->limit(20)->get(['id', 'email', 'role_key', 'expires_at', 'created_at']),
            'roles' => Role::query()->where('scope', 'tenant')->where(fn ($query) => $query->whereNull('tenant_id')->orWhere('tenant_id', $context->tenant()->getKey()))->orderBy('name')->get(['id', 'key', 'name', 'tenant_id']),
            'campuses' => Campus::query()->where('status', 'active')->orderBy('name')->get(['id', 'name']),
            'tenant' => ['slug' => $context->tenant()->slug],
        ]);
    }

    public function invite(Request $request, TenantContext $context, AuditRecorder $audit): RedirectResponse
    {
        $mailIsTestFake = app()->runningUnitTests() && Mail::getFacadeRoot() instanceof MailFake;
        $mailIsLocalE2e = app()->environment('testing') && config('app.e2e_test_mode') && in_array($request->ip(), ['127.0.0.1', '::1'], true);
        abort_if(in_array(config('mail.default'), ['log', 'array'], true) && ! $mailIsTestFake && ! $mailIsLocalE2e, 503, 'Invitation delivery is disabled until a real mail transport is configured.');
        $data = $request->validate([
            'email' => ['required', 'email', 'max:255'],
            'role_key' => ['required', 'string', 'max:80'],
            'campus_id' => ['required_if:role_key,campus.admin', 'nullable', 'integer', Rule::exists('campuses', 'id')->where('tenant_id', $context->tenant()->getKey())->where('status', 'active')],
        ]);
        $email = Str::lower(trim($data['email']));
        $role = Role::query()->where('key', $data['role_key'])->where('scope', 'tenant')->where(fn ($query) => $query->whereNull('tenant_id')->orWhere('tenant_id', $context->tenant()->getKey()))->firstOrFail();
        $actorMembership = $context->membership();
        $actorPermissionKeys = [];
        foreach ($actorMembership->roles()->with('permissions')->get() as $actorRole) {
            foreach ($actorRole->permissions as $permission) {
                $actorPermissionKeys[] = $permission->key;
            }
        }
        abort_unless($actorMembership->roles()->where('key', 'tenant.owner')->exists() || $role->permissions()->whereNotIn('key', $actorPermissionKeys)->doesntExist(), 403);
        abort_if($role->key === 'tenant.owner' && ! $actorMembership->roles()->where('key', 'tenant.owner')->exists(), 403);
        abort_if($role->key === 'tenant.owner' && $email === strtolower($request->user()->email), 403);
        $studentSuspended = TenantMembership::query()->where('tenant_id', $context->tenant()->getKey())
            ->whereNotNull('sis_student_suspension_student_id')
            ->whereHas('user', fn ($users) => $users->where('email', $email))->exists();
        abort_if($studentSuspended, 409, 'Reactivate the linked student record before issuing another membership invitation.');
        $alreadyMember = TenantMembership::query()->whereHas('user', fn ($users) => $users->where('email', $email))->where('status', 'active')->exists();
        abort_if($alreadyMember, 409, 'This account already has an active membership in this institution.');

        $rawToken = Str::random(64);
        $invitation = DB::transaction(function () use ($context, $request, $audit, $role, $data, $email, $rawToken): TenantInvitation {
            TenantInvitation::query()->where('tenant_id', $context->tenant()->getKey())->where('email', $email)->where('status', 'pending')->update(['status' => 'cancelled']);
            $invitation = TenantInvitation::query()->create([
                'tenant_id' => $context->tenant()->getKey(),
                'institution_id' => $context->membership()->institution_id,
                'campus_id' => $data['campus_id'] ?? null,
                'invited_by' => $request->user()->getKey(),
                'email' => $email,
                'role_key' => $role->key,
                'token_hash' => hash('sha256', $rawToken),
                'status' => 'pending',
                'expires_at' => now()->addDays(7),
            ]);
            $audit->record('membership.invited', $request->user(), (int) $context->tenant()->getKey(), $invitation, ['role_key' => $role->key]);

            return $invitation;
        });

        $url = URL::temporarySignedRoute('invitations.show', $invitation->expires_at, ['token' => $rawToken]);
        Mail::to($email)->send(new TenantInvitationMail($context->tenant()->name, $request->user()->name, $url));

        $response = back()->with('status', 'Invitation sent. The secure acceptance link expires in seven days.');
        if ($mailIsLocalE2e) {
            $response->headers->set('X-E2E-Invitation-Url', $url);
            $response->headers->set('Cache-Control', 'no-store, private');
        }

        return $response;
    }

    public function revoke(Request $request, Tenant $tenant, int $membershipId, TenantContext $context, AuditRecorder $audit): RedirectResponse
    {
        abort_unless($tenant->is($context->tenant()), 404);
        DB::transaction(function () use ($membershipId, $request, $context, $audit): void {
            Tenant::query()->whereKey($context->tenant()->getKey())->lockForUpdate()->firstOrFail();
            $membership = TenantMembership::query()->lockForUpdate()->findOrFail($membershipId);
            abort_unless(in_array($membership->status, ['active', 'suspended'], true), 409);
            $isOwner = $membership->roles()->where('key', 'tenant.owner')->exists();
            if ($isOwner && $membership->status === 'active') {
                abort_unless($context->membership()->roles()->where('key', 'tenant.owner')->exists(), 403);
                $owners = TenantMembership::query()->where('status', 'active')->whereHas('roles', fn ($roles) => $roles->where('key', 'tenant.owner'))->count();
                abort_if($owners <= 1, 409, 'The last active institution owner cannot be revoked.');
            }
            $membership->forceFill([
                'status' => 'revoked', 'revoked_at' => now(),
                'sis_student_suspension_student_id' => null,
                'sis_student_suspended_at' => null,
                'sis_student_suspension_reason_code' => null,
            ])->save();
            $audit->record('membership.revoked', $request->user(), (int) $context->tenant()->getKey(), $membership, []);
        });

        return back()->with('status', 'Membership revoked.');
    }

    public function assignRole(Request $request, Tenant $tenant, int $membershipId, TenantContext $context, AuditRecorder $audit): RedirectResponse
    {
        abort_unless($tenant->is($context->tenant()), 404);
        $membership = TenantMembership::query()->findOrFail($membershipId);
        abort_if((int) $membership->user_id === (int) $request->user()->getKey(), 403, 'Members cannot change their own roles.');
        $data = $request->validate([
            'role_key' => ['required', 'string', 'max:80'],
            'campus_id' => ['required_if:role_key,campus.admin', 'nullable', 'integer', Rule::exists('campuses', 'id')->where('tenant_id', $context->tenant()->getKey())->where('status', 'active')],
        ]);
        abort_if($membership->status !== 'active', 409);
        $role = Role::query()->where('scope', 'tenant')->where('key', $data['role_key'])->where(fn ($query) => $query->whereNull('tenant_id')->orWhere('tenant_id', $context->tenant()->getKey()))->firstOrFail();
        $actor = $context->membership();
        $actorPermissionKeys = [];
        foreach ($actor->roles()->with('permissions')->get() as $actorRole) {
            foreach ($actorRole->permissions as $permission) {
                $actorPermissionKeys[] = $permission->key;
            }
        }
        abort_unless($actor->roles()->where('key', 'tenant.owner')->exists() || $role->permissions()->whereNotIn('key', $actorPermissionKeys)->doesntExist(), 403);
        abort_if($role->key === 'tenant.owner' && ! $actor->roles()->where('key', 'tenant.owner')->exists(), 403);
        if ($membership->roles()->where('key', 'tenant.owner')->exists() && $role->key !== 'tenant.owner') {
            abort_unless($actor->roles()->where('key', 'tenant.owner')->exists(), 403);
        }

        DB::transaction(function () use ($membership, $role, $request, $context, $audit, $data): void {
            Tenant::query()->whereKey($context->tenant()->getKey())->lockForUpdate()->firstOrFail();
            $isCurrentOwner = $membership->roles()->where('key', 'tenant.owner')->exists();
            $isRemovingOwner = $isCurrentOwner && $role->key !== 'tenant.owner';
            if ($isRemovingOwner) {
                $owners = TenantMembership::query()->where('status', 'active')->whereHas('roles', fn ($roles) => $roles->where('key', 'tenant.owner'))->count();
                abort_if($owners <= 1, 409, 'The last active institution owner cannot be demoted.');
            }
            $membership->roles()->sync([$role->getKey() => ['assigned_by' => $request->user()->getKey(), 'tenant_id' => $context->tenant()->getKey()]]);
            $membership->forceFill(['campus_id' => $data['campus_id'] ?? null])->save();
            $audit->record('membership.role_changed', $request->user(), (int) $context->tenant()->getKey(), $membership, ['role_key' => $role->key]);
        });

        return back()->with('status', 'Membership role updated.');
    }

    public function cancelInvitation(Request $request, Tenant $tenant, int $invitationId, TenantContext $context, AuditRecorder $audit): RedirectResponse
    {
        abort_unless($tenant->is($context->tenant()), 404);
        $invitation = TenantInvitation::query()->where('tenant_id', $context->tenant()->getKey())->whereKey($invitationId)->where('status', 'pending')->firstOrFail();
        $invitation->forceFill(['status' => 'cancelled'])->save();
        $audit->record('membership.invitation_cancelled', $request->user(), (int) $context->tenant()->getKey(), $invitation, []);

        return back()->with('status', 'Invitation cancelled.');
    }
}
