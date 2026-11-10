<?php

namespace Tests\Feature;

use App\Domain\Audit\AuditRecorder;
use App\Domain\Identity\PermissionCatalog;
use App\Domain\Tenancy\TenantContext;
use App\Domain\Tenancy\TenantProvisioner;
use App\Mail\TenantInvitationMail;
use App\Models\Campus;
use App\Models\Institution;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\TenantDomain;
use App\Models\TenantInvitation;
use App\Models\TenantMembership;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use LogicException;
use Tests\Support\GuardedRefreshDatabase;
use Tests\TestCase;

class TenantManagementTest extends TestCase
{
    use GuardedRefreshDatabase;

    public function test_platform_operator_provisions_a_tenant_and_invites_a_separate_initial_owner(): void
    {
        $operator = $this->platformSuperAdmin();
        $initialOwner = User::factory()->create(['email' => 'institution-owner@example.test']);
        Mail::fake();

        $this->actingAs($operator)->post('/platform/tenants', [
            ...$this->institutionDetails('Blocked Academy', 'blocked-academy'),
            'owner_email' => $operator->email,
        ])->assertSessionHasErrors('owner_email');
        $this->assertDatabaseMissing('tenants', ['slug' => 'blocked-academy']);

        $this->actingAs($operator)->post('/platform/tenants', [
            ...$this->institutionDetails('Northstar Academy', 'northstar-academy'),
            'owner_email' => $initialOwner->email,
        ])
            ->assertRedirect();

        $tenant = Tenant::query()->where('slug', 'northstar-academy')->firstOrFail();
        $this->assertSame('active', $tenant->status);
        $this->assertDatabaseHas('institutions', ['tenant_id' => $tenant->id, 'official_name' => 'Northstar Academy']);
        $ownerInvitationMail = Mail::sent(TenantInvitationMail::class)->first();
        $this->assertInstanceOf(TenantInvitationMail::class, $ownerInvitationMail);
        $this->actingAs($initialOwner)->post($ownerInvitationMail->acceptanceUrl)
            ->assertRedirect("/t/{$tenant->slug}/dashboard");
        $membership = TenantMembership::query()->withoutGlobalScope('tenant')->where('tenant_id', $tenant->id)->where('user_id', $initialOwner->id)->firstOrFail();
        $this->assertSame('active', $membership->status);
        $this->assertTrue($membership->roles()->where('key', 'tenant.owner')->exists());
        $this->assertDatabaseMissing('tenant_memberships', ['tenant_id' => $tenant->id, 'user_id' => $operator->id]);
        $this->assertDatabaseHas('tenant_invitations', ['tenant_id' => $tenant->id, 'email' => $initialOwner->email, 'role_key' => 'tenant.owner', 'status' => 'accepted']);
        Mail::assertSent(TenantInvitationMail::class, 1);
        $this->assertDatabaseHas('audit_events', ['event_type' => 'tenant.provisioned', 'tenant_id' => $tenant->id]);
        $this->assertDatabaseHas('roles', ['tenant_id' => $tenant->id, 'key' => 'tenant.owner']);
        $this->assertDatabaseHas('roles', ['tenant_id' => $tenant->id, 'key' => 'student']);
    }

    public function test_tenant_context_fails_closed_and_rejects_cross_tenant_writes(): void
    {
        $operator = User::factory()->create();
        $tenant = $this->provision($operator, 'Northstar Academy');

        $this->assertSame(0, Campus::query()->count());
        $this->expectException(LogicException::class);
        Campus::query()->create(['institution_id' => Institution::query()->withoutGlobalScope('tenant')->firstOrFail()->id, 'code' => 'NORTH', 'name' => 'North Campus']);
    }

    public function test_tenant_route_requires_membership_and_does_not_allow_slug_substitution(): void
    {
        $owner = User::factory()->create();
        $outsider = User::factory()->create();
        $tenant = $this->provision($owner, 'Northstar Academy');

        $this->actingAs($outsider)->get("/t/{$tenant->slug}/dashboard")->assertNotFound();
        $this->actingAs($owner)->get("/t/{$tenant->slug}/dashboard")
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('workspace/dashboard')
                ->where('auth.tenant.slug', $tenant->slug)
                ->where('auth.tenant.roles', ['Institution Owner'])
                ->where('auth.tenant.permissions', fn (Collection $permissions): bool => $permissions->contains('tenant.view') && $permissions->contains('members.invite')));
    }

    public function test_verified_custom_domain_resolves_and_is_bound_to_its_tenant(): void
    {
        $password = 'Phase02-Verified-Domain-Password-2026!';
        $owner = User::factory()->create(['password' => Hash::make($password)]);
        $platformRole = PermissionCatalog::createPlatformRole(
            'platform.super_admin',
            'Platform Super Administrator',
            'Test-only platform operator.',
            array_keys(PermissionCatalog::platformPermissions()),
        );
        $owner->platformRoles()->attach($platformRole->getKey(), ['assigned_by' => $owner->getKey()]);
        $tenantA = $this->provision($owner, 'Northstar Academy');
        $tenantB = $this->provision($owner, 'Southridge College');
        TenantDomain::query()->create([
            'tenant_id' => $tenantA->getKey(),
            'domain' => 'northstar.example.test',
            'verification_token' => Str::random(40),
            'verified_at' => now(),
            'status' => 'verified',
            'is_primary' => true,
        ]);

        $this->actingAs($owner)->get('http://northstar.example.test/')
            ->assertRedirectContains("/t/{$tenantA->slug}/dashboard");
        $this->actingAs($owner)->get("http://northstar.example.test/t/{$tenantA->slug}/dashboard")
            ->assertOk();
        $this->actingAs($owner)->get("http://northstar.example.test/t/{$tenantB->slug}/dashboard")
            ->assertNotFound();
        $this->actingAs($owner)->get('http://northstar.example.test/platform/dashboard')
            ->assertNotFound();
        Auth::logout();
        $this->get('http://northstar.example.test/')
            ->assertRedirectContains('/login');
        $this->post('http://northstar.example.test/login', ['email' => $owner->email, 'password' => $password])
            ->assertRedirectContains("/t/{$tenantA->slug}/dashboard");
    }

    public function test_campus_identifier_from_another_tenant_is_not_resolved(): void
    {
        $ownerA = User::factory()->create();
        $ownerB = User::factory()->create();
        $tenantA = $this->provision($ownerA, 'Northstar Academy');
        $tenantB = $this->provision($ownerB, 'Southridge College');
        $campusB = $this->createCampus($tenantB, $ownerB, 'SOUTH-1');

        $this->actingAs($ownerA)->put("/t/{$tenantA->slug}/campuses/{$campusB->id}", ['code' => 'NORTH-1', 'name' => 'Attempted Access'])
            ->assertNotFound();
        $this->assertDatabaseHas('campuses', ['id' => $campusB->id, 'tenant_id' => $tenantB->id, 'name' => 'SOUTH-1 Campus']);
    }

    public function test_database_composite_foreign_keys_reject_mismatched_tenant_institution_pairs(): void
    {
        $ownerA = User::factory()->create();
        $ownerB = User::factory()->create();
        $tenantA = $this->provision($ownerA, 'Northstar Academy');
        $tenantB = $this->provision($ownerB, 'Southridge College');
        $institutionB = Institution::query()->withoutGlobalScope('tenant')->where('tenant_id', $tenantB->id)->firstOrFail();

        try {
            DB::table('campuses')->insert([
                'tenant_id' => $tenantA->id,
                'institution_id' => $institutionB->id,
                'code' => 'CROSS-1',
                'name' => 'Cross Tenant',
                'status' => 'active',
                'is_primary' => false,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $this->fail('The composite ownership constraint should reject a campus attached to another tenant institution.');
        } catch (QueryException) {
            $this->assertDatabaseMissing('campuses', ['code' => 'CROSS-1']);
        }
    }

    public function test_membership_role_database_constraint_rejects_cross_tenant_role_assignment(): void
    {
        $ownerA = User::factory()->create();
        $ownerB = User::factory()->create();
        $tenantA = $this->provision($ownerA, 'Northstar Academy');
        $tenantB = $this->provision($ownerB, 'Southridge College');
        $membershipA = TenantMembership::query()->withoutGlobalScope('tenant')->where('tenant_id', $tenantA->id)->where('user_id', $ownerA->id)->firstOrFail();
        $foreignRole = Role::query()->where('tenant_id', $tenantB->id)->where('key', 'auditor')->firstOrFail();

        try {
            DB::table('membership_role')->insert([
                'membership_id' => $membershipA->id,
                'tenant_id' => $tenantA->id,
                'role_id' => $foreignRole->id,
                'assigned_by' => $ownerA->id,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $this->fail('The composite role ownership constraint should reject a role from another tenant.');
        } catch (QueryException) {
            $this->assertDatabaseMissing('membership_role', ['membership_id' => $membershipA->id, 'role_id' => $foreignRole->id]);
        }
    }

    public function test_audit_events_reject_model_updates(): void
    {
        $event = app(AuditRecorder::class)->record('test.immutable', null, null, null, ['safe' => true]);
        $this->expectException(LogicException::class);
        $event->forceFill(['event_type' => 'test.changed'])->save();
    }

    public function test_audit_events_reject_model_deletes(): void
    {
        $event = app(AuditRecorder::class)->record('test.immutable', null, null, null, ['safe' => true]);
        $this->expectException(LogicException::class);
        $event->delete();
    }

    public function test_owner_can_create_campus_and_set_primary_with_audit_history(): void
    {
        $owner = User::factory()->create();
        $tenant = $this->provision($owner, 'Northstar Academy');

        $this->actingAs($owner)->post("/t/{$tenant->slug}/campuses", ['code' => 'MAIN', 'name' => 'Main Campus', 'city' => 'Lahore', 'is_primary' => true])
            ->assertRedirect();

        $this->assertDatabaseHas('campuses', ['tenant_id' => $tenant->id, 'code' => 'MAIN', 'is_primary' => true]);
        $this->assertDatabaseHas('audit_events', ['tenant_id' => $tenant->id, 'event_type' => 'campus.created']);
    }

    public function test_invitation_is_signed_single_use_and_activates_membership(): void
    {
        Mail::fake();
        $owner = User::factory()->create();
        $tenant = $this->provision($owner, 'Northstar Academy');
        $email = 'invited.member@example.test';
        $invitedUrl = null;

        $inviteResponse = $this->actingAs($owner)->post("/t/{$tenant->slug}/members/invitations", ['email' => $email, 'role_key' => 'auditor'])
            ->assertSessionHasNoErrors()
            ->assertRedirect();
        $this->assertFalse($inviteResponse->headers->has('X-E2E-Invitation-Url'));

        Mail::assertSent(TenantInvitationMail::class, function (TenantInvitationMail $mail) use (&$invitedUrl, $tenant): bool {
            $this->assertSame($tenant->name, $mail->tenantName);
            $invitedUrl = $mail->acceptanceUrl;

            return true;
        });
        $this->assertIsString($invitedUrl);
        $this->get($invitedUrl)->assertOk()->assertInertia(fn ($page) => $page->component('auth/accept-invitation')->where('email', $email));
        $this->post($invitedUrl, [
            'name' => 'Invited Member',
            'password' => 'Strong-Invite-Password-2026',
            'password_confirmation' => 'Strong-Invite-Password-2026',
        ])->assertRedirect(route('login'));
        $this->assertDatabaseHas('tenant_invitations', ['tenant_id' => $tenant->id, 'email' => $email, 'status' => 'pending']);
        Auth::logout();
        $response = $this->post($invitedUrl, [
            'name' => 'Invited Member',
            'password' => 'Strong-Invite-Password-2026',
            'password_confirmation' => 'Strong-Invite-Password-2026',
        ]);
        $response->assertRedirect("/t/{$tenant->slug}/dashboard");
        $member = User::query()->where('email', $email)->firstOrFail();
        $this->assertAuthenticatedAs($member);
        $membership = TenantMembership::query()->withoutGlobalScope('tenant')->where('tenant_id', $tenant->id)->where('user_id', $member->id)->firstOrFail();
        $this->assertSame('active', $membership->status);
        $this->assertTrue($membership->roles()->where('key', 'auditor')->exists());
        $this->assertDatabaseHas('tenant_invitations', ['tenant_id' => $tenant->id, 'email' => $email, 'status' => 'accepted']);
        $this->assertDatabaseHas('audit_events', ['tenant_id' => $tenant->id, 'event_type' => 'membership.invitation_accepted']);
        $this->post($invitedUrl, ['name' => 'Again'])->assertNotFound();
    }

    public function test_reactivating_a_revoked_membership_replaces_old_roles_with_only_the_invited_role(): void
    {
        $owner = User::factory()->create();
        $tenant = $this->provision($owner, 'Role Reset Academy');
        $member = User::factory()->create(['email_verified_at' => now()]);
        $membership = $this->createMember($tenant, $member, 'tenant.admin');
        $membership->forceFill(['status' => 'revoked', 'revoked_at' => now()])->save();

        $invitedRole = Role::query()->where('tenant_id', $tenant->getKey())->where('key', 'student')->firstOrFail();
        $token = Str::random(64);
        $expiresAt = now()->addDays(7);
        $invitation = TenantInvitation::query()->create([
            'tenant_id' => $tenant->getKey(),
            'institution_id' => $membership->institution_id,
            'invited_by' => $owner->getKey(),
            'email' => $member->email,
            'role_key' => $invitedRole->key,
            'token_hash' => hash('sha256', $token),
            'status' => 'pending',
            'expires_at' => $expiresAt,
        ]);
        $acceptUrl = URL::temporarySignedRoute('invitations.accept', $expiresAt, ['token' => $token]);

        $this->actingAs($member)->post($acceptUrl)->assertRedirect(route('workspaces.index'));

        $this->assertSame('active', $membership->fresh()->status);
        $this->assertSame(['student'], $membership->fresh()->roles()->pluck('key')->all());
        $this->assertDatabaseHas('tenant_invitations', ['id' => $invitation->getKey(), 'status' => 'accepted']);
        $this->assertDatabaseHas('audit_events', ['tenant_id' => $tenant->getKey(), 'event_type' => 'membership.invitation_roles_replaced']);
    }

    public function test_non_owner_cannot_assign_owner_role_and_member_cannot_change_own_role(): void
    {
        $owner = User::factory()->create();
        $tenant = $this->provision($owner, 'Northstar Academy');
        $admin = User::factory()->create();
        $target = User::factory()->create();
        $adminMembership = $this->createMember($tenant, $admin, 'tenant.admin');
        $targetMembership = $this->createMember($tenant, $target, 'auditor');

        $this->actingAs($admin)->put("/t/{$tenant->slug}/members/{$targetMembership->id}/role", ['role_key' => 'tenant.owner'])->assertForbidden();
        $ownerMembership = TenantMembership::query()->withoutGlobalScope('tenant')->where('tenant_id', $tenant->id)->where('user_id', $owner->id)->firstOrFail();
        $this->put("/t/{$tenant->slug}/members/{$ownerMembership->id}/role", ['role_key' => 'auditor'])->assertForbidden();
        $this->assertTrue($ownerMembership->fresh()->roles()->where('key', 'tenant.owner')->exists());
        $this->assertTrue($adminMembership->fresh()->roles()->where('key', 'tenant.admin')->exists());
    }

    public function test_campus_administrator_role_is_scoped_to_its_assigned_campus(): void
    {
        $owner = User::factory()->create();
        $tenant = $this->provision($owner, 'Northstar Academy');
        $assignedCampus = $this->createCampus($tenant, $owner, 'NORTH-MAIN');
        $otherCampus = $this->createCampus($tenant, $owner, 'NORTH-EAST');
        $otherOwner = User::factory()->create();
        $otherTenant = $this->provision($otherOwner, 'Southridge College');
        $foreignCampus = $this->createCampus($otherTenant, $otherOwner, 'SOUTH-MAIN');
        $campusAdmin = User::factory()->create();
        $membership = $this->createMember($tenant, $campusAdmin, 'auditor');

        $invalidAssignment = $this->actingAs($owner)
            ->put("/t/{$tenant->slug}/members/{$membership->id}/role", ['role_key' => 'campus.admin', 'campus_id' => $foreignCampus->id])
            ->assertRedirect();
        $invalidAssignment->assertSessionHasErrors('campus_id');

        $this->put("/t/{$tenant->slug}/members/{$membership->id}/role", ['role_key' => 'campus.admin', 'campus_id' => $assignedCampus->id])
            ->assertRedirect();
        $membership->refresh();
        $this->assertSame($assignedCampus->id, $membership->campus_id);
        $this->assertTrue($membership->roles()->where('key', 'campus.admin')->exists());

        $this->actingAs($campusAdmin)
            ->get("/t/{$tenant->slug}/campuses")
            ->assertOk()
            ->assertInertia(fn ($page) => $page->has('campuses.data', 1)->where('campuses.data.0.id', $assignedCampus->id));
        $this->put("/t/{$tenant->slug}/campuses/{$assignedCampus->id}", ['code' => 'NORTH-MAIN', 'name' => 'Assigned Campus Updated'])
            ->assertRedirect();
        $this->put("/t/{$tenant->slug}/campuses/{$otherCampus->id}", ['code' => 'NORTH-EAST', 'name' => 'Out of Scope Campus'])
            ->assertNotFound();
        $this->put("/t/{$tenant->slug}/campuses/{$foreignCampus->id}", ['code' => 'SOUTH-MAIN', 'name' => 'Foreign Tenant Campus'])
            ->assertNotFound();
        $this->post("/t/{$tenant->slug}/campuses", ['code' => 'NO-CREATE', 'name' => 'Campus Admin Must Not Create'])
            ->assertForbidden();
    }

    public function test_last_active_owner_cannot_be_revoked_or_demoted(): void
    {
        $owner = User::factory()->create();
        $tenant = $this->provision($owner, 'Northstar Academy');
        $ownerMembership = TenantMembership::query()->withoutGlobalScope('tenant')->where('tenant_id', $tenant->id)->where('user_id', $owner->id)->firstOrFail();
        $admin = User::factory()->create();
        $adminMembership = $this->createMember($tenant, $admin, 'tenant.admin');

        $response = $this->actingAs($admin)->post("/t/{$tenant->slug}/members/{$ownerMembership->id}/revoke");
        $this->assertSame(403, $response->getStatusCode(), $response->headers->get('Location', $response->getContent()));
        $this->actingAs($owner)->post("/t/{$tenant->slug}/members/{$ownerMembership->id}/revoke")->assertConflict();
        $this->assertSame('active', $ownerMembership->fresh()->status);
        $this->assertTrue($adminMembership->fresh()->roles()->where('key', 'tenant.admin')->exists());
    }

    public function test_tenant_lifecycle_suspend_and_reactivate_are_audited_and_enforced(): void
    {
        $operator = $this->platformSuperAdmin();
        $tenant = $this->provision($operator, 'Northstar Academy');

        $this->actingAs($operator)->patch("/platform/tenants/{$tenant->slug}/status", ['status' => 'suspended', 'reason' => 'Administrative review'])->assertRedirect();
        $this->get("/t/{$tenant->slug}/dashboard")->assertNotFound();
        $this->patch("/platform/tenants/{$tenant->slug}/status", ['status' => 'active'])->assertRedirect();
        $this->get("/t/{$tenant->slug}/dashboard")->assertOk();
        $this->assertDatabaseHas('audit_events', ['event_type' => 'tenant.status_changed', 'subject_id' => (string) $tenant->id]);
    }

    public function test_theme_settings_and_branding_are_persisted_and_audited(): void
    {
        $owner = User::factory()->create();
        $tenant = $this->provision($owner, 'Northstar Academy');

        $this->actingAs($owner)->put("/t/{$tenant->slug}/settings", ['timezone' => 'Asia/Karachi', 'locale' => 'en-PK', 'date_format' => 'd M Y'])->assertRedirect();
        $this->put("/t/{$tenant->slug}/branding", ['display_name' => 'Northstar', 'primary_color' => '#1357A6', 'accent_color' => '#17A7B8', 'theme' => 'academic-emerald'])->assertRedirect();
        $themePage = "/t/{$tenant->slug}/academics/curriculum?status=draft";
        $this->post('/workspace/theme', ['theme' => 'midnight-violet', 'return_to' => $themePage])
            ->assertRedirect(url($themePage));

        $this->post('/workspace/theme', ['theme' => 'executive-navy', 'return_to' => '//attacker.example/path'])
            ->assertSessionHasErrors('return_to');

        $this->assertDatabaseHas('institutions', ['tenant_id' => $tenant->id, 'timezone' => 'Asia/Karachi', 'locale' => 'en-PK']);
        $this->assertDatabaseHas('tenant_settings', ['tenant_id' => $tenant->id, 'key' => 'date_format']);
        $this->assertDatabaseHas('tenant_brandings', ['tenant_id' => $tenant->id, 'theme' => 'academic-emerald', 'primary_color' => '#1357A6']);
        $this->assertDatabaseHas('user_preferences', ['user_id' => $owner->id, 'key' => 'theme']);
        $this->assertDatabaseHas('audit_events', ['tenant_id' => $tenant->id, 'event_type' => 'tenant.settings_updated']);
        $this->assertDatabaseHas('audit_events', ['tenant_id' => $tenant->id, 'event_type' => 'tenant.branding_updated']);
    }

    public function test_tenant_branding_rejects_primary_colors_without_white_text_contrast(): void
    {
        $owner = User::factory()->create();
        $tenant = $this->provision($owner, 'Northstar Academy');

        $this->actingAs($owner)->put("/t/{$tenant->slug}/branding", [
            'display_name' => 'Northstar',
            'primary_color' => '#B8D5F2',
            'accent_color' => '#17A7B8',
            'theme' => 'executive-navy',
        ])->assertSessionHasErrors('primary_color');

        $this->assertDatabaseHas('tenant_brandings', ['tenant_id' => $tenant->id, 'primary_color' => '#155EEF']);
    }

    public function test_domain_verification_material_is_visible_only_to_domain_managers(): void
    {
        $owner = User::factory()->create();
        $tenant = $this->provision($owner, 'Northstar Academy');

        $this->actingAs($owner)->post("/t/{$tenant->slug}/domains", ['domain' => 'northstar.example.test'])->assertRedirect();
        $this->get("/t/{$tenant->slug}/domains")->assertInertia(fn ($page) => $page
            ->where('canManage', true)
            ->where('domains.0.verification_token', fn (mixed $token): bool => is_string($token) && strlen($token) >= 40));

        $auditor = User::factory()->create();
        $this->createMember($tenant, $auditor, 'auditor');
        $this->actingAs($auditor)->get("/t/{$tenant->slug}/domains")->assertInertia(fn ($page) => $page
            ->where('canManage', false)
            ->where('domains.0.verification_token', null));
        $this->post("/t/{$tenant->slug}/domains", ['domain' => 'unauthorized.example.test'])->assertForbidden();
    }

    public function test_institution_membership_switching_only_lists_the_current_users_memberships(): void
    {
        $user = User::factory()->create();
        $otherOwner = User::factory()->create();
        $tenant = $this->provision($user, 'Northstar Academy');
        $otherTenant = $this->provision($otherOwner, 'Southridge College');

        $this->actingAs($user)->get('/workspaces')->assertOk()->assertInertia(fn ($page) => $page->component('workspace/tenants')->has('workspaces', 1));
        $this->post('/workspaces/switch', ['tenant' => $otherTenant->slug])->assertNotFound();
        $this->post('/workspaces/switch', ['tenant' => $tenant->slug])->assertRedirect("/t/{$tenant->slug}/dashboard");
    }

    private function platformSuperAdmin(): User
    {
        $user = User::factory()->create();
        $role = PermissionCatalog::createPlatformRole('platform.super_admin', 'Platform Super Administrator', 'Test-only platform administrator.', array_keys(PermissionCatalog::platformPermissions()));
        $user->platformRoles()->attach($role->id, ['assigned_by' => $user->id]);

        return $user;
    }

    private function provision(User $owner, string $name): Tenant
    {
        $actor = User::factory()->create();
        $result = app(TenantProvisioner::class)->provision($this->institutionDetails($name, Str::slug($name)), $actor, $owner->email);
        $acceptanceUrl = URL::temporarySignedRoute('invitations.accept', $result->ownerInvitation->expires_at, ['token' => $result->ownerInvitationToken]);
        $this->actingAs($owner)->post($acceptanceUrl)->assertRedirect("/t/{$result->tenant->slug}/dashboard");

        return $result->tenant;
    }

    /** @return array<string, string> */
    private function institutionDetails(string $name, string $slug): array
    {
        return [
            'official_name' => $name,
            'display_name' => $name,
            'slug' => $slug,
            'code' => '',
            'type' => 'academy',
            'contact_email' => '',
            'contact_phone' => '',
            'website' => '',
            'country' => '',
            'region' => '',
            'city' => '',
            'address' => '',
            'timezone' => 'UTC',
            'locale' => 'en',
        ];
    }

    private function createMember(Tenant $tenant, User $user, string $roleKey): TenantMembership
    {
        $context = app(TenantContext::class);
        $context->enterProvisioning($tenant);
        try {
            $institution = Institution::query()->firstOrFail();
            $membership = TenantMembership::query()->create(['institution_id' => $institution->id, 'user_id' => $user->id, 'status' => 'active', 'activated_at' => now()]);
            $role = Role::query()->where('tenant_id', $tenant->id)->where('key', $roleKey)->firstOrFail();
            $membership->roles()->attach($role->id, ['assigned_by' => $tenant->created_by, 'tenant_id' => $tenant->id]);

            return $membership;
        } finally {
            $context->clear();
        }
    }

    private function createCampus(Tenant $tenant, User $owner, string $code): Campus
    {
        $context = app(TenantContext::class);
        $membership = TenantMembership::query()->withoutGlobalScope('tenant')->where('tenant_id', $tenant->id)->where('user_id', $owner->id)->firstOrFail();
        $context->set($tenant, $membership);
        try {
            return Campus::query()->create(['institution_id' => $membership->institution_id, 'code' => $code, 'name' => $code.' Campus', 'status' => 'active']);
        } finally {
            $context->clear();
        }
    }
}
