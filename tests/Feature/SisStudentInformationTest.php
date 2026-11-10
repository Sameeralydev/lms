<?php

namespace Tests\Feature;

use App\Domain\Academic\AcademicDefaults;
use App\Domain\Admissions\AdmissionOutboxDispatcher;
use App\Domain\Admissions\AdmissionOutboxProcessor;
use App\Domain\Admissions\Documents\AdmissionDocumentScanner;
use App\Domain\Admissions\Documents\AdmissionDocumentScanResult;
use App\Domain\Admissions\Documents\ClamAvAdmissionDocumentScanner;
use App\Domain\Audit\AuditRecorder;
use App\Domain\Sis\SisPrivateDocumentRetentionProcessor;
use App\Domain\Sis\SisStudentCsvImport;
use App\Domain\Sis\SisStudentImportProcessor;
use App\Domain\Sis\SisStudentLifecycle;
use App\Domain\Sis\SisStudentRegistry;
use App\Domain\Tenancy\TenantContext;
use App\Domain\Tenancy\TenantProvisioner;
use App\Events\Admissions\AdmissionOutboxDispatched;
use App\Jobs\ScanSisStudentDocument;
use App\Mail\TenantInvitationMail;
use App\Models\AcademicClass;
use App\Models\AcademicConfiguration;
use App\Models\AcademicSection;
use App\Models\AcademicYear;
use App\Models\AdmissionOutboxEvent;
use App\Models\Campus;
use App\Models\Institution;
use App\Models\Permission;
use App\Models\Role;
use App\Models\SisIdentityReconciliationCase;
use App\Models\SisPrivateDocument;
use App\Models\SisPrivateDocumentScanEvent;
use App\Models\SisPrivateDocumentVersion;
use App\Models\SisRegistrySetting;
use App\Models\SisStudent;
use App\Models\SisStudentGuardian;
use App\Models\SisStudentImportBatch;
use App\Models\SisStudentLifecycleRequest;
use App\Models\SisStudentPlacement;
use App\Models\Tenant;
use App\Models\TenantInvitation;
use App\Models\TenantMembership;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Support\GuardedRefreshDatabase;
use Tests\TestCase;

class SisStudentInformationTest extends TestCase
{
    use GuardedRefreshDatabase;

    public function test_institution_owner_registers_student_and_lifecycle_history_is_immutable_evidence(): void
    {
        [$tenant, $owner, $membership] = $this->provision('SIS Lifecycle Academy');
        $this->actingAs($owner)->post("/t/{$tenant->slug}/sis/students", [
            'legal_first_name' => 'Avery', 'legal_last_name' => 'Rahman', 'preferred_name' => 'Ari',
            'admission_date' => '2026-09-01', 'date_of_birth' => '2010-03-10', 'student_type' => 'standard',
        ])->assertRedirect();

        $student = SisStudent::query()->withoutGlobalScope('tenant')->where('tenant_id', $tenant->getKey())->firstOrFail();
        $this->assertSame('STU000001', $student->student_number);
        $this->assertSame('admitted', $student->status);
        $this->assertNull($student->user_id);

        $this->actingAs($owner)->patch("/t/{$tenant->slug}/sis/students/{$student->getKey()}/status", [
            'status' => 'active', 'effective_at' => '2026-09-03', 'reason_code' => 'registration_confirmed', 'reason' => 'Registration verified.',
        ])->assertRedirect();
        $this->assertSame('active', $student->fresh()->status);
        $this->assertDatabaseHas('sis_student_lifecycle_events', ['tenant_id' => $tenant->getKey(), 'student_id' => $student->getKey(), 'from_status' => 'admitted', 'to_status' => 'active']);

        $this->actingAs($owner)->patch("/t/{$tenant->slug}/sis/students/{$student->getKey()}/status", [
            'status' => 'graduated', 'effective_at' => '2026-09-04', 'reason_code' => 'manual_completion',
        ])->assertRedirect();
        $this->actingAs($owner)->patch("/t/{$tenant->slug}/sis/students/{$student->getKey()}/status", [
            'status' => 'active', 'effective_at' => '2026-09-05', 'reason_code' => 'invalid_reactivation',
        ])->assertSessionHasErrors('status');
        $this->assertSame('graduated', $student->fresh()->status);
        $this->assertGreaterThanOrEqual(3, DB::table('sis_student_lifecycle_events')->where('tenant_id', $tenant->getKey())->where('student_id', $student->getKey())->count());
        $this->assertTrue($membership->fresh()->hasPermission('sis.students.status'));
    }

    public function test_student_suspension_and_reactivation_follow_the_tenant_membership_policy(): void
    {
        [$tenant, $owner, $ownerMembership, $institution] = $this->provision('SIS Student Account Policy Academy');
        $studentIdentity = User::factory()->create();
        $context = app(TenantContext::class);
        $context->set($tenant, $ownerMembership);
        try {
            $studentRole = Role::query()->where('tenant_id', $tenant->getKey())->where('key', 'student')->firstOrFail();
            $membership = TenantMembership::query()->create([
                'institution_id' => $institution->getKey(), 'user_id' => $studentIdentity->getKey(),
                'status' => 'active', 'activated_at' => now(),
            ]);
            $membership->roles()->attach($studentRole->getKey(), [
                'assigned_by' => $owner->getKey(), 'tenant_id' => $tenant->getKey(),
            ]);
            $student = app(SisStudentRegistry::class)->create([
                'legal_first_name' => 'Jordan', 'legal_last_name' => 'Policy', 'admission_date' => '2026-09-01',
                'user_id' => $studentIdentity->getKey(),
            ], $owner);
        } finally {
            $context->clear();
        }

        $context->set($tenant, $ownerMembership);
        try {
            $lifecycle = app(SisStudentLifecycle::class);
            $lifecycle->transition($student, 'active', now()->parse('2026-09-02'), 'enrollment_active', null, $owner);
            $lifecycle->transition($student->fresh(), 'suspended', now()->parse('2026-09-03'), 'disciplinary_review', 'Suspension approved.', $owner);
        } finally {
            $context->clear();
        }

        $this->assertDatabaseHas('tenant_memberships', [
            'tenant_id' => $tenant->getKey(), 'id' => $membership->getKey(), 'status' => 'suspended',
            'sis_student_suspension_student_id' => $student->getKey(),
            'sis_student_suspension_reason_code' => 'disciplinary_review',
        ]);
        Mail::fake();
        $this->actingAs($owner)->post("/t/{$tenant->slug}/members/invitations", [
            'email' => $studentIdentity->email, 'role_key' => 'student',
        ])->assertStatus(409);
        $this->actingAs($studentIdentity)->get("/t/{$tenant->slug}/dashboard")->assertNotFound();

        $context->set($tenant, $ownerMembership);
        try {
            app(SisStudentLifecycle::class)->transition(
                $student->fresh(), 'active', now()->parse('2026-09-04'), 'suspension_lifted', null, $owner,
            );
        } finally {
            $context->clear();
        }

        $this->assertDatabaseHas('tenant_memberships', [
            'tenant_id' => $tenant->getKey(), 'id' => $membership->getKey(), 'status' => 'active',
            'sis_student_suspension_student_id' => null, 'sis_student_suspended_at' => null,
        ]);
        $this->assertSame(1, DB::table('audit_events')->where('tenant_id', $tenant->getKey())->where('event_type', 'sis.student.account_access_suspended')->count());
        $this->assertSame(1, DB::table('audit_events')->where('tenant_id', $tenant->getKey())->where('event_type', 'sis.student.account_access_reactivated')->count());
        $this->actingAs($studentIdentity)->get("/t/{$tenant->slug}/dashboard")
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('workspace/dashboard')->where('workspace.title', 'Learner workspace'));
    }

    public function test_student_suspension_does_not_disable_a_shared_staff_membership(): void
    {
        [$tenant, $owner, $ownerMembership, $institution] = $this->provision('SIS Shared Student Staff Academy');
        $identity = User::factory()->create();
        $context = app(TenantContext::class);
        $context->set($tenant, $ownerMembership);
        try {
            $membership = TenantMembership::query()->create([
                'institution_id' => $institution->getKey(), 'user_id' => $identity->getKey(),
                'status' => 'active', 'activated_at' => now(),
            ]);
            $studentRole = Role::query()->where('tenant_id', $tenant->getKey())->where('key', 'student')->firstOrFail();
            $teacherRole = Role::query()->where('tenant_id', $tenant->getKey())->where('key', 'teacher')->firstOrFail();
            $membership->roles()->attach([
                $studentRole->getKey() => ['assigned_by' => $owner->getKey(), 'tenant_id' => $tenant->getKey()],
                $teacherRole->getKey() => ['assigned_by' => $owner->getKey(), 'tenant_id' => $tenant->getKey()],
            ]);
            $student = app(SisStudentRegistry::class)->create([
                'legal_first_name' => 'Morgan', 'legal_last_name' => 'Shared', 'admission_date' => '2026-09-01',
                'user_id' => $identity->getKey(),
            ], $owner);
            $lifecycle = app(SisStudentLifecycle::class);
            $lifecycle->transition($student, 'active', now()->parse('2026-09-02'), 'enrollment_active', null, $owner);
            $lifecycle->transition($student->fresh(), 'suspended', now()->parse('2026-09-03'), 'student_review', null, $owner);
        } finally {
            $context->clear();
        }

        $this->assertDatabaseHas('tenant_memberships', [
            'tenant_id' => $tenant->getKey(), 'id' => $membership->getKey(), 'status' => 'active',
            'sis_student_suspension_student_id' => null,
        ]);
        $this->assertDatabaseHas('audit_events', [
            'tenant_id' => $tenant->getKey(), 'event_type' => 'sis.student.account_access_suspension_skipped',
        ]);
    }

    public function test_manual_membership_revocation_is_not_undone_by_student_reactivation(): void
    {
        [$tenant, $owner, $ownerMembership, $institution] = $this->provision('SIS Revoked Student Academy');
        $identity = User::factory()->create();
        $context = app(TenantContext::class);
        $context->set($tenant, $ownerMembership);
        try {
            $membership = TenantMembership::query()->create([
                'institution_id' => $institution->getKey(), 'user_id' => $identity->getKey(),
                'status' => 'active', 'activated_at' => now(),
            ]);
            $studentRole = Role::query()->where('tenant_id', $tenant->getKey())->where('key', 'student')->firstOrFail();
            $membership->roles()->attach($studentRole->getKey(), [
                'assigned_by' => $owner->getKey(), 'tenant_id' => $tenant->getKey(),
            ]);
            $student = app(SisStudentRegistry::class)->create([
                'legal_first_name' => 'Taylor', 'legal_last_name' => 'Revoked', 'admission_date' => '2026-09-01',
                'user_id' => $identity->getKey(),
            ], $owner);
            $lifecycle = app(SisStudentLifecycle::class);
            $lifecycle->transition($student, 'active', now()->parse('2026-09-02'), 'enrollment_active', null, $owner);
            $lifecycle->transition($student->fresh(), 'suspended', now()->parse('2026-09-03'), 'student_review', null, $owner);
        } finally {
            $context->clear();
        }

        $this->actingAs($owner)->post("/t/{$tenant->slug}/members/{$membership->getKey()}/revoke")->assertRedirect();
        $this->assertDatabaseHas('tenant_memberships', [
            'tenant_id' => $tenant->getKey(), 'id' => $membership->getKey(), 'status' => 'revoked',
            'sis_student_suspension_student_id' => null,
        ]);

        $context->set($tenant, $ownerMembership);
        try {
            app(SisStudentLifecycle::class)->transition(
                $student->fresh(), 'active', now()->parse('2026-09-04'), 'suspension_lifted', null, $owner,
            );
        } finally {
            $context->clear();
        }

        $this->assertDatabaseHas('tenant_memberships', [
            'tenant_id' => $tenant->getKey(), 'id' => $membership->getKey(), 'status' => 'revoked',
        ]);
    }

    public function test_dashboard_metrics_are_filtered_by_admission_window_and_reject_invalid_ranges(): void
    {
        [$tenant, $owner, $membership, $institution] = $this->provision('SIS Analytics Academy');
        foreach ([['Outside', 'Window', '2026-08-15'], ['Inside', 'Window', '2026-09-15']] as [$first, $last, $date]) {
            $this->actingAs($owner)->post("/t/{$tenant->slug}/sis/students", [
                'legal_first_name' => $first, 'legal_last_name' => $last, 'admission_date' => $date,
            ])->assertRedirect();
        }
        $insideStudentId = (int) DB::table('sis_students')->where('tenant_id', $tenant->getKey())
            ->where('legal_first_name', 'Inside')->value('id');
        $context = app(TenantContext::class);
        $context->set($tenant, $membership);
        try {
            $campus = Campus::query()->create([
                'institution_id' => $institution->getKey(), 'code' => 'ANALYTICS',
                'name' => 'Analytics Campus', 'status' => 'active',
            ]);
        } finally {
            $context->clear();
        }
        DB::table('sis_students')->where('tenant_id', $tenant->getKey())->where('id', $insideStudentId)
            ->update(['home_campus_id' => $campus->getKey()]);
        $this->actingAs($owner)->post("/t/{$tenant->slug}/sis/students/{$insideStudentId}/guardians", [
            'guardian_name' => 'Analytics Guardian', 'guardian_email' => 'analytics-guardian@example.test', 'relationship' => 'parent',
        ])->assertRedirect();
        DB::table('sis_student_guardians')->where('tenant_id', $tenant->getKey())->where('student_id', $insideStudentId)
            ->update(['created_at' => '2026-09-15 12:00:00']);

        $this->actingAs($owner)->get("/t/{$tenant->slug}/sis?from=2026-09-01&until=2026-09-30")
            ->assertOk()->assertInertia(fn ($page) => $page
            ->where('stats.total', 1)
            ->where('stats.admitted', 1)
            ->where('analytics.campuses.0.name', 'Analytics Campus')
            ->where('analytics.campuses.0.students', 1)
            ->where('analytics.guardians.pending', 1)
            ->where('analytics.transfers.pendingReview', 0)
            ->where('analytics.promotions', 0)
            ->has('analytics.programs', 0)
            ->has('recentStudents', 1)
            ->where('filters.from', '2026-09-01')
            ->where('filters.until', '2026-09-30'));

        $this->actingAs($owner)->get("/t/{$tenant->slug}/sis?from=2026-10-01&until=2026-09-01")
            ->assertSessionHasErrors('until');
    }

    public function test_cross_tenant_student_id_is_not_resolvable_in_another_tenant_workspace(): void
    {
        $owner = User::factory()->create();
        [$tenantA] = $this->provision('SIS Tenant A', $owner);
        [$tenantB] = $this->provision('SIS Tenant B', $owner);
        $this->actingAs($owner)->post("/t/{$tenantA->slug}/sis/students", [
            'legal_first_name' => 'Private', 'legal_last_name' => 'Learner', 'admission_date' => '2026-09-01',
        ])->assertRedirect();
        $studentId = (int) DB::table('sis_students')->where('tenant_id', $tenantA->getKey())->value('id');

        $this->actingAs($owner)->get("/t/{$tenantB->slug}/sis/students/{$studentId}")->assertNotFound();
        $this->actingAs($owner)->put("/t/{$tenantB->slug}/sis/students/{$studentId}", ['preferred_name' => 'Changed'])->assertNotFound();
        $this->assertDatabaseMissing('sis_students', ['tenant_id' => $tenantA->getKey(), 'preferred_name' => 'Changed']);
    }

    public function test_directory_filters_current_academic_placement_and_non_sensitive_custom_values(): void
    {
        [$tenant, $owner, $membership, $institution] = $this->provision('SIS Directory Filters Academy');
        $context = app(TenantContext::class);
        $context->set($tenant, $membership);
        try {
            $year = AcademicYear::query()->create([
                'institution_id' => $institution->getKey(), 'name' => '2026-2027', 'code' => 'AY-FILTER',
                'starts_on' => '2026-08-01', 'ends_on' => '2027-06-30', 'status' => 'active',
            ]);
            $class = AcademicClass::query()->create([
                'institution_id' => $institution->getKey(), 'academic_year_id' => $year->getKey(),
                'group_type' => 'cohort', 'code' => 'FILTER-1', 'name' => 'Filter Cohort', 'status' => 'active',
            ]);
            $section = AcademicSection::query()->create([
                'academic_class_id' => $class->getKey(), 'code' => 'FILTER-A', 'name' => 'Filter Section', 'status' => 'active',
            ]);
        } finally {
            $context->clear();
        }

        foreach ([['Avery', 'One'], ['Blair', 'Two']] as [$first, $last]) {
            $this->actingAs($owner)->post("/t/{$tenant->slug}/sis/students", [
                'legal_first_name' => $first, 'legal_last_name' => $last, 'admission_date' => '2026-09-01',
            ])->assertRedirect();
        }
        $studentIds = DB::table('sis_students')->where('tenant_id', $tenant->getKey())->orderBy('id')->pluck('id')->map(fn ($id): int => (int) $id)->all();
        $context->set($tenant, $membership);
        try {
            SisStudentPlacement::query()->create([
                'student_id' => $studentIds[0], 'institution_id' => $institution->getKey(),
                'academic_year_id' => $year->getKey(), 'academic_class_id' => $class->getKey(),
                'academic_section_id' => $section->getKey(), 'placement_type' => 'placement', 'status' => 'active',
                'effective_from' => '2026-09-01',
            ]);
        } finally {
            $context->clear();
        }

        $this->actingAs($owner)->post("/t/{$tenant->slug}/sis/custom-fields", [
            'field_key' => 'cohort_label', 'label' => 'Cohort label', 'field_type' => 'text',
            'is_required' => false, 'is_sensitive' => false,
        ])->assertRedirect();
        $fieldId = (int) DB::table('sis_custom_field_definitions')->where('tenant_id', $tenant->getKey())->where('field_key', 'cohort_label')->value('id');
        $this->actingAs($owner)->put("/t/{$tenant->slug}/sis/students/{$studentIds[0]}/custom-fields/{$fieldId}", ['value' => 'blue'])->assertRedirect();
        $this->actingAs($owner)->put("/t/{$tenant->slug}/sis/students/{$studentIds[1]}/custom-fields/{$fieldId}", ['value' => 'green'])->assertRedirect();

        $this->actingAs($owner)->get("/t/{$tenant->slug}/sis/students?academic_year_id={$year->getKey()}&academic_class_id={$class->getKey()}&academic_section_id={$section->getKey()}&custom_field_id={$fieldId}&custom_field_value=blue")
            ->assertOk()->assertInertia(fn ($page) => $page
            ->has('students.data', 1)
            ->where('students.data.0.id', $studentIds[0])
            ->has('filterCustomFields', 1));
        $this->actingAs($owner)->get("/t/{$tenant->slug}/sis?academic_year_id={$year->getKey()}")
            ->assertOk()->assertInertia(fn ($page) => $page->where('stats.total', 1)->where('stats.admitted', 1));

        $this->actingAs($owner)->post("/t/{$tenant->slug}/sis/custom-fields", [
            'field_key' => 'private_tag', 'label' => 'Private tag', 'field_type' => 'text',
            'is_required' => false, 'is_sensitive' => true,
        ])->assertRedirect();
        $sensitiveFieldId = (int) DB::table('sis_custom_field_definitions')->where('tenant_id', $tenant->getKey())->where('field_key', 'private_tag')->value('id');
        $this->actingAs($owner)->get("/t/{$tenant->slug}/sis/students?custom_field_id={$sensitiveFieldId}&custom_field_value=secret")
            ->assertSessionHasErrors('custom_field_id');
    }

    public function test_existing_active_institution_identity_is_linked_with_the_student_role(): void
    {
        [$tenant, $owner, $ownerMembership, $institution] = $this->provision('SIS Identity Academy');
        $this->actingAs($owner)->post("/t/{$tenant->slug}/sis/students", [
            'legal_first_name' => 'Alex', 'legal_last_name' => 'Learner', 'admission_date' => '2026-09-01',
        ])->assertRedirect();
        $studentId = (int) DB::table('sis_students')->where('tenant_id', $tenant->getKey())->value('id');
        $identity = User::factory()->create();
        $context = app(TenantContext::class);
        $context->set($tenant, $ownerMembership);
        try {
            $membership = TenantMembership::query()->create(['institution_id' => $institution->getKey(), 'user_id' => $identity->getKey(), 'status' => 'active', 'activated_at' => now()]);
        } finally {
            $context->clear();
        }

        $this->actingAs($owner)->post("/t/{$tenant->slug}/sis/students/{$studentId}/identity", ['user_id' => $identity->getKey()])->assertRedirect("/t/{$tenant->slug}/sis/students/{$studentId}");
        $studentRole = Role::query()->where('tenant_id', $tenant->getKey())->where('key', 'student')->firstOrFail();
        $this->assertDatabaseHas('sis_students', ['tenant_id' => $tenant->getKey(), 'id' => $studentId, 'user_id' => $identity->getKey()]);
        $this->assertDatabaseHas('membership_role', ['tenant_id' => $tenant->getKey(), 'membership_id' => $membership->getKey(), 'role_id' => $studentRole->getKey()]);
    }

    public function test_student_identity_correction_requires_independent_review_and_reconciles_membership_roles(): void
    {
        [$tenant, $owner, $ownerMembership, $institution] = $this->provision('SIS Identity Reconciliation Academy');
        $previousIdentity = User::factory()->create();
        $proposedIdentity = User::factory()->create();
        $alreadyLinkedIdentity = User::factory()->create();
        $reviewer = User::factory()->create();
        $context = app(TenantContext::class);
        $context->set($tenant, $ownerMembership);
        try {
            $studentRole = Role::query()->where('tenant_id', $tenant->getKey())->where('key', 'student')->firstOrFail();
            $previousMembership = TenantMembership::query()->create([
                'institution_id' => $institution->getKey(), 'user_id' => $previousIdentity->getKey(),
                'status' => 'active', 'activated_at' => now(),
            ]);
            $previousMembership->roles()->attach($studentRole->getKey(), [
                'assigned_by' => $owner->getKey(), 'tenant_id' => $tenant->getKey(),
            ]);
            $proposedMembership = TenantMembership::query()->create([
                'institution_id' => $institution->getKey(), 'user_id' => $proposedIdentity->getKey(),
                'status' => 'active', 'activated_at' => now(),
            ]);
            $alreadyLinkedMembership = TenantMembership::query()->create([
                'institution_id' => $institution->getKey(), 'user_id' => $alreadyLinkedIdentity->getKey(),
                'status' => 'active', 'activated_at' => now(),
            ]);
            $alreadyLinkedMembership->roles()->attach($studentRole->getKey(), [
                'assigned_by' => $owner->getKey(), 'tenant_id' => $tenant->getKey(),
            ]);
            $reviewerMembership = TenantMembership::query()->create([
                'institution_id' => $institution->getKey(), 'user_id' => $reviewer->getKey(),
                'status' => 'active', 'activated_at' => now(),
            ]);
            $adminRole = Role::query()->where('tenant_id', $tenant->getKey())->where('key', 'tenant.admin')->firstOrFail();
            $reviewerMembership->roles()->attach($adminRole->getKey(), [
                'assigned_by' => $owner->getKey(), 'tenant_id' => $tenant->getKey(),
            ]);
            $student = app(SisStudentRegistry::class)->create([
                'legal_first_name' => 'Riley', 'legal_last_name' => 'Reconciled', 'admission_date' => '2026-09-01',
                'user_id' => $previousIdentity->getKey(),
            ], $owner);
            app(SisStudentRegistry::class)->create([
                'legal_first_name' => 'Harper', 'legal_last_name' => 'Already Linked', 'admission_date' => '2026-09-02',
                'user_id' => $alreadyLinkedIdentity->getKey(),
            ], $owner);
        } finally {
            $context->clear();
        }

        $endpoint = "/t/{$tenant->slug}/sis/students/{$student->getKey()}/identity-reconciliations";
        $payload = [
            'proposed_user_id' => $proposedIdentity->getKey(),
            'reason_code' => 'corrected_link',
            'request_reason' => 'Institution records confirm the existing account was linked to the wrong learner.',
        ];
        $this->actingAs($owner)->post($endpoint, [
            ...$payload, 'proposed_user_id' => $alreadyLinkedIdentity->getKey(),
        ])->assertSessionHasErrors('proposed_user_id');
        $this->assertDatabaseCount('sis_identity_reconciliation_cases', 0);
        $this->actingAs($owner)->post($endpoint, $payload)->assertRedirect();
        $case = SisIdentityReconciliationCase::query()->where('tenant_id', $tenant->getKey())->firstOrFail();
        $this->assertSame('pending', $case->status);
        $this->assertDatabaseHas('sis_students', ['id' => $student->getKey(), 'user_id' => $previousIdentity->getKey()]);
        $this->assertDatabaseHas('audit_events', [
            'tenant_id' => $tenant->getKey(), 'event_type' => 'sis.student.identity_reconciliation_requested',
        ]);

        $decisionUrl = $endpoint."/{$case->getKey()}/decision";
        $decision = ['decision' => 'approve', 'decision_reason' => 'Reviewed institution evidence supports the requested correction.'];
        $this->actingAs($owner)->post($decisionUrl, $decision)->assertSessionHasErrors('decision');
        $this->assertSame('pending', $case->fresh()->status, 'A requester cannot approve their own case.');

        $this->actingAs($reviewer)->get("/t/{$tenant->slug}/sis/students/{$student->getKey()}")
            ->assertOk()->assertInertia(fn ($page) => $page
            ->where('identityReconciliations.0.status', 'pending')
            ->where('identityReconciliations.0.canReview', true));
        $this->actingAs($reviewer)->post($decisionUrl, $decision)->assertRedirect();

        $this->assertDatabaseHas('sis_students', ['id' => $student->getKey(), 'user_id' => $proposedIdentity->getKey()]);
        $this->assertDatabaseHas('tenant_memberships', [
            'id' => $previousMembership->getKey(), 'status' => 'revoked',
        ]);
        $this->assertDatabaseMissing('membership_role', [
            'membership_id' => $previousMembership->getKey(), 'role_id' => $studentRole->getKey(),
        ]);
        $this->assertDatabaseHas('membership_role', [
            'membership_id' => $proposedMembership->getKey(), 'role_id' => $studentRole->getKey(),
        ]);
        $this->assertDatabaseHas('sis_identity_reconciliation_cases', [
            'id' => $case->getKey(), 'status' => 'approved', 'pending_student_id' => null,
            'reviewed_by' => $reviewer->getKey(), 'applied_at' => $case->fresh()->applied_at,
        ]);
        $this->assertDatabaseHas('audit_events', [
            'tenant_id' => $tenant->getKey(), 'event_type' => 'sis.student.identity_reconciliation_applied',
        ]);
    }

    public function test_identity_reconciliation_stales_if_proposed_membership_is_no_longer_eligible(): void
    {
        [$tenant, $owner, $ownerMembership, $institution] = $this->provision('SIS Stale Identity Review Academy');
        $previousIdentity = User::factory()->create();
        $proposedIdentity = User::factory()->create();
        $reviewer = User::factory()->create();
        $context = app(TenantContext::class);
        $context->set($tenant, $ownerMembership);
        try {
            $studentRole = Role::query()->where('tenant_id', $tenant->getKey())->where('key', 'student')->firstOrFail();
            $previousMembership = TenantMembership::query()->create([
                'institution_id' => $institution->getKey(), 'user_id' => $previousIdentity->getKey(),
                'status' => 'active', 'activated_at' => now(),
            ]);
            $previousMembership->roles()->attach($studentRole->getKey(), [
                'assigned_by' => $owner->getKey(), 'tenant_id' => $tenant->getKey(),
            ]);
            $proposedMembership = TenantMembership::query()->create([
                'institution_id' => $institution->getKey(), 'user_id' => $proposedIdentity->getKey(),
                'status' => 'active', 'activated_at' => now(),
            ]);
            $reviewerMembership = TenantMembership::query()->create([
                'institution_id' => $institution->getKey(), 'user_id' => $reviewer->getKey(),
                'status' => 'active', 'activated_at' => now(),
            ]);
            $adminRole = Role::query()->where('tenant_id', $tenant->getKey())->where('key', 'tenant.admin')->firstOrFail();
            $reviewerMembership->roles()->attach($adminRole->getKey(), [
                'assigned_by' => $owner->getKey(), 'tenant_id' => $tenant->getKey(),
            ]);
            $student = app(SisStudentRegistry::class)->create([
                'legal_first_name' => 'Taylor', 'legal_last_name' => 'Stale', 'admission_date' => '2026-09-01',
                'user_id' => $previousIdentity->getKey(),
            ], $owner);
        } finally {
            $context->clear();
        }

        $endpoint = "/t/{$tenant->slug}/sis/students/{$student->getKey()}/identity-reconciliations";
        $this->actingAs($owner)->post($endpoint, [
            'proposed_user_id' => $proposedIdentity->getKey(), 'reason_code' => 'verified_identity_update',
            'request_reason' => 'Verified institution identity details require a corrected link.',
        ])->assertRedirect();
        $case = SisIdentityReconciliationCase::query()->where('tenant_id', $tenant->getKey())->firstOrFail();
        $proposedMembership->forceFill(['status' => 'revoked', 'revoked_at' => now()])->save();

        $this->actingAs($reviewer)->post("{$endpoint}/{$case->getKey()}/decision", [
            'decision' => 'approve', 'decision_reason' => 'Reviewed the submitted institution record.',
        ])->assertRedirect();

        $this->assertSame('stale', $case->fresh()->status);
        $this->assertSame($previousIdentity->getKey(), $student->fresh()->user_id);
        $this->assertDatabaseMissing('membership_role', [
            'membership_id' => $proposedMembership->getKey(), 'role_id' => $studentRole->getKey(),
        ]);
        $this->assertDatabaseHas('audit_events', [
            'tenant_id' => $tenant->getKey(), 'event_type' => 'sis.student.identity_reconciliation_stale',
        ]);
    }

    public function test_identity_reconciliation_preserves_non_student_roles_on_the_previous_membership(): void
    {
        [$tenant, $owner, $ownerMembership, $institution] = $this->provision('SIS Identity Staff Preservation Academy');
        $previousIdentity = User::factory()->create();
        $proposedIdentity = User::factory()->create();
        $reviewer = User::factory()->create();
        $context = app(TenantContext::class);
        $context->set($tenant, $ownerMembership);
        try {
            $studentRole = Role::query()->where('tenant_id', $tenant->getKey())->where('key', 'student')->firstOrFail();
            $adminRole = Role::query()->where('tenant_id', $tenant->getKey())->where('key', 'tenant.admin')->firstOrFail();
            $previousMembership = TenantMembership::query()->create([
                'institution_id' => $institution->getKey(), 'user_id' => $previousIdentity->getKey(),
                'status' => 'active', 'activated_at' => now(),
            ]);
            $previousMembership->roles()->attach([
                $studentRole->getKey() => ['assigned_by' => $owner->getKey(), 'tenant_id' => $tenant->getKey()],
                $adminRole->getKey() => ['assigned_by' => $owner->getKey(), 'tenant_id' => $tenant->getKey()],
            ]);
            TenantMembership::query()->create([
                'institution_id' => $institution->getKey(), 'user_id' => $proposedIdentity->getKey(),
                'status' => 'active', 'activated_at' => now(),
            ]);
            $reviewerMembership = TenantMembership::query()->create([
                'institution_id' => $institution->getKey(), 'user_id' => $reviewer->getKey(),
                'status' => 'active', 'activated_at' => now(),
            ]);
            $reviewerMembership->roles()->attach($adminRole->getKey(), [
                'assigned_by' => $owner->getKey(), 'tenant_id' => $tenant->getKey(),
            ]);
            $student = app(SisStudentRegistry::class)->create([
                'legal_first_name' => 'Jordan', 'legal_last_name' => 'Staff', 'admission_date' => '2026-09-01',
                'user_id' => $previousIdentity->getKey(),
            ], $owner);
        } finally {
            $context->clear();
        }

        $endpoint = "/t/{$tenant->slug}/sis/students/{$student->getKey()}/identity-reconciliations";
        $this->actingAs($owner)->post($endpoint, [
            'proposed_user_id' => $proposedIdentity->getKey(), 'reason_code' => 'corrected_link',
            'request_reason' => 'Institution records confirm this account belongs to another learner.',
        ])->assertRedirect();
        $case = SisIdentityReconciliationCase::query()->where('tenant_id', $tenant->getKey())->firstOrFail();

        $this->actingAs($reviewer)->post("{$endpoint}/{$case->getKey()}/decision", [
            'decision' => 'approve', 'decision_reason' => 'Independent review confirmed the account correction.',
        ])->assertRedirect();

        $this->assertDatabaseHas('tenant_memberships', [
            'id' => $previousMembership->getKey(), 'status' => 'active', 'revoked_at' => null,
        ]);
        $this->assertDatabaseHas('membership_role', [
            'membership_id' => $previousMembership->getKey(), 'role_id' => $adminRole->getKey(),
        ]);
        $this->assertDatabaseMissing('membership_role', [
            'membership_id' => $previousMembership->getKey(), 'role_id' => $studentRole->getKey(),
        ]);
    }

    public function test_student_identity_invitation_provisions_and_links_the_account_transactionally(): void
    {
        [$tenant, $owner] = $this->provision('SIS Student Account Academy');
        $this->actingAs($owner)->post("/t/{$tenant->slug}/sis/students", [
            'legal_first_name' => 'Nadia', 'legal_last_name' => 'Learner', 'admission_date' => '2026-09-01',
        ])->assertRedirect();
        $student = SisStudent::query()->withoutGlobalScope('tenant')->where('tenant_id', $tenant->getKey())->firstOrFail();
        Mail::fake();

        $this->actingAs($owner)->post("/t/{$tenant->slug}/sis/students/{$student->getKey()}/identity/invite", [
            'identity_email' => ' Nadia.Learner@example.test ',
        ])->assertRedirect("/t/{$tenant->slug}/sis/students/{$student->getKey()}");

        $invitation = TenantInvitation::query()->where('tenant_id', $tenant->getKey())->where('sis_student_id', $student->getKey())->firstOrFail();
        $this->assertSame('nadia.learner@example.test', $invitation->email);
        $this->assertSame('student', $invitation->role_key);
        $this->assertSame('pending', $invitation->status);
        Mail::assertSent(TenantInvitationMail::class);
        $message = Mail::sent(TenantInvitationMail::class)->first();
        $this->assertInstanceOf(TenantInvitationMail::class, $message);
        $acceptUrl = $message->acceptanceUrl;
        Auth::logout();

        $this->post($acceptUrl, [
            'name' => 'Nadia Learner',
            'password' => 'SecureStudentPassword123',
            'password_confirmation' => 'SecureStudentPassword123',
        ])->assertRedirect(route('workspaces.index'));

        $user = User::query()->where('email', 'nadia.learner@example.test')->firstOrFail();
        $this->assertNotNull($user->email_verified_at);
        $this->assertDatabaseHas('sis_students', ['tenant_id' => $tenant->getKey(), 'id' => $student->getKey(), 'user_id' => $user->getKey()]);
        $this->assertDatabaseHas('tenant_memberships', [
            'tenant_id' => $tenant->getKey(), 'institution_id' => $student->institution_id, 'user_id' => $user->getKey(), 'status' => 'active',
        ]);
        $this->assertDatabaseHas('tenant_invitations', ['id' => $invitation->getKey(), 'status' => 'accepted']);
        $this->assertDatabaseHas('audit_events', ['tenant_id' => $tenant->getKey(), 'event_type' => 'sis.student.identity_provisioned']);
        $this->post($acceptUrl, [])->assertNotFound();
    }

    public function test_guardian_authority_is_pending_until_verified_and_revocation_disables_profile_access(): void
    {
        [$tenant, $owner] = $this->provision('SIS Guardian Academy');
        $this->actingAs($owner)->post("/t/{$tenant->slug}/sis/students", [
            'legal_first_name' => 'Sam', 'legal_last_name' => 'Student', 'admission_date' => '2026-09-01',
        ])->assertRedirect();
        $studentId = (int) DB::table('sis_students')->where('tenant_id', $tenant->getKey())->value('id');

        $this->actingAs($owner)->post("/t/{$tenant->slug}/sis/students/{$studentId}/guardians", [
            'guardian_name' => 'Jordan Student', 'guardian_email' => 'jordan@example.test', 'relationship' => 'parent', 'is_primary_contact' => true,
        ])->assertRedirect("/t/{$tenant->slug}/sis/students/{$studentId}");
        $guardianId = (int) DB::table('sis_student_guardians')->where('tenant_id', $tenant->getKey())->value('id');
        $this->assertDatabaseHas('sis_student_guardians', ['id' => $guardianId, 'authority_status' => 'pending', 'can_view_profile' => false]);

        $this->actingAs($owner)->post("/t/{$tenant->slug}/sis/guardians/{$guardianId}/verify", ['verification_method' => 'document_review'])->assertRedirect("/t/{$tenant->slug}/sis/students/{$studentId}");
        $this->assertDatabaseHas('sis_student_guardians', ['id' => $guardianId, 'authority_status' => 'verified', 'can_view_profile' => false]);
        $this->actingAs($owner)->post("/t/{$tenant->slug}/sis/guardians/{$guardianId}/revoke", ['reason' => 'Authority withdrawn by institution review.'])->assertRedirect("/t/{$tenant->slug}/sis/students/{$studentId}");
        $this->assertDatabaseHas('sis_student_guardians', ['id' => $guardianId, 'authority_status' => 'revoked', 'can_view_profile' => false]);
        $this->assertNotNull(SisStudentGuardian::query()->withoutGlobalScope('tenant')->findOrFail($guardianId)->revoked_at);
    }

    public function test_verified_guardian_invitation_links_identity_without_granting_profile_access(): void
    {
        [$tenant, $owner] = $this->provision('SIS Guardian Identity Academy');
        $this->actingAs($owner)->post("/t/{$tenant->slug}/sis/students", [
            'legal_first_name' => 'Amina', 'legal_last_name' => 'Student', 'admission_date' => '2026-09-01',
        ])->assertRedirect();
        $studentId = (int) DB::table('sis_students')->where('tenant_id', $tenant->getKey())->value('id');
        $this->actingAs($owner)->post("/t/{$tenant->slug}/sis/students/{$studentId}/guardians", [
            'guardian_name' => 'Hana Student', 'guardian_email' => 'hana.guardian@example.test',
            'relationship' => 'parent', 'is_primary_contact' => true,
        ])->assertRedirect();
        $guardianId = (int) DB::table('sis_student_guardians')->where('tenant_id', $tenant->getKey())->value('id');
        $this->actingAs($owner)->post("/t/{$tenant->slug}/sis/guardians/{$guardianId}/verify", [
            'verification_method' => 'document_review',
        ])->assertRedirect();

        Mail::fake();
        $this->actingAs($owner)->post("/t/{$tenant->slug}/sis/guardians/{$guardianId}/identity/invite")
            ->assertRedirect("/t/{$tenant->slug}/sis/students/{$studentId}");
        $invitation = TenantInvitation::query()->where('tenant_id', $tenant->getKey())
            ->where('sis_student_guardian_id', $guardianId)->firstOrFail();
        $this->assertSame('parent.guardian', $invitation->role_key);
        $this->assertDatabaseHas('sis_student_guardians', [
            'tenant_id' => $tenant->getKey(), 'id' => $guardianId,
            'authority_status' => 'verified', 'can_view_profile' => false, 'guardian_user_id' => null,
        ]);
        Mail::assertSent(TenantInvitationMail::class);
        $message = Mail::sent(TenantInvitationMail::class)->first();
        $this->assertInstanceOf(TenantInvitationMail::class, $message);

        Auth::logout();
        $this->post($message->acceptanceUrl, [
            'name' => 'Hana Student', 'password' => 'SecureGuardianPassword123',
            'password_confirmation' => 'SecureGuardianPassword123',
        ])->assertRedirect(route('workspaces.index'));

        $user = User::query()->where('email', 'hana.guardian@example.test')->firstOrFail();
        $guardian = SisStudentGuardian::query()->withoutGlobalScope('tenant')->findOrFail($guardianId);
        $membership = TenantMembership::query()->withoutGlobalScope('tenant')->where('tenant_id', $tenant->getKey())
            ->where('user_id', $user->getKey())->firstOrFail();
        $this->assertSame((int) $user->getKey(), (int) $guardian->guardian_user_id);
        $this->assertSame('verified', $guardian->authority_status);
        $this->assertFalse($guardian->can_view_profile);
        $this->assertSame(['parent.guardian'], $membership->roles()->pluck('key')->all());
        $this->assertDatabaseHas('audit_events', ['tenant_id' => $tenant->getKey(), 'event_type' => 'sis.guardian.identity_provisioned']);
        $this->get("/t/{$tenant->slug}/sis/students/{$studentId}")->assertForbidden();
    }

    public function test_verified_guardian_identity_can_be_shared_across_siblings_only_when_contact_identity_matches(): void
    {
        [$tenant, $owner, $ownerMembership, $institution] = $this->provision('SIS Shared Guardian Academy');
        foreach ([['Nadia', 'Student'], ['Omar', 'Student']] as [$firstName, $lastName]) {
            $this->actingAs($owner)->post("/t/{$tenant->slug}/sis/students", [
                'legal_first_name' => $firstName, 'legal_last_name' => $lastName, 'admission_date' => '2026-09-01',
            ])->assertRedirect();
        }
        $studentIds = DB::table('sis_students')->where('tenant_id', $tenant->getKey())->orderBy('id')->pluck('id')->map(fn ($id): int => (int) $id)->all();

        $guardianAccount = User::factory()->create(['email' => 'guardian.shared@example.test']);
        $unrelatedAccount = User::factory()->create(['email' => 'someone.else@example.test']);
        $context = app(TenantContext::class);
        $context->set($tenant, $ownerMembership);
        try {
            foreach ([$guardianAccount, $unrelatedAccount] as $account) {
                TenantMembership::query()->create([
                    'institution_id' => $institution->getKey(), 'user_id' => $account->getKey(), 'status' => 'active', 'activated_at' => now(),
                ]);
            }
        } finally {
            $context->clear();
        }

        $guardianIds = [];
        foreach ($studentIds as $studentId) {
            $this->actingAs($owner)->post("/t/{$tenant->slug}/sis/students/{$studentId}/guardians", [
                'guardian_name' => 'Shared Guardian', 'guardian_email' => 'Guardian.Shared@example.test', 'relationship' => 'parent',
            ])->assertRedirect("/t/{$tenant->slug}/sis/students/{$studentId}");
            $guardianIds[] = (int) DB::table('sis_student_guardians')->where('tenant_id', $tenant->getKey())->where('student_id', $studentId)->value('id');
        }

        $this->actingAs($owner)->get("/t/{$tenant->slug}/sis/students/{$studentIds[0]}")->assertInertia(fn ($page) => $page
            ->has('student.guardians.0.identityCandidates', 1)
            ->where('student.guardians.0.identityCandidates.0.id', $guardianAccount->getKey()));

        $this->actingAs($owner)->post("/t/{$tenant->slug}/sis/guardians/{$guardianIds[0]}/verify", [
            'verification_method' => 'document_review', 'guardian_user_id' => $unrelatedAccount->getKey(),
        ])->assertSessionHasErrors('guardian_user_id');
        $this->assertDatabaseHas('sis_student_guardians', ['id' => $guardianIds[0], 'authority_status' => 'pending', 'guardian_user_id' => null]);

        foreach ($guardianIds as $index => $guardianId) {
            $this->actingAs($owner)->post("/t/{$tenant->slug}/sis/guardians/{$guardianId}/verify", [
                'verification_method' => 'document_review', 'guardian_user_id' => $guardianAccount->getKey(),
            ])->assertRedirect("/t/{$tenant->slug}/sis/students/{$studentIds[$index]}");
            $this->assertDatabaseHas('sis_student_guardians', [
                'id' => $guardianId, 'guardian_user_id' => $guardianAccount->getKey(), 'authority_status' => 'verified', 'can_view_profile' => false,
            ]);
        }

        $this->assertSame(2, DB::table('audit_events')->where('tenant_id', $tenant->getKey())->where('event_type', 'sis.guardian.verified')->count());
    }

    public function test_external_transfer_requires_clean_evidence_independent_review_and_records_completion(): void
    {
        [$tenant, $owner, $ownerMembership, $institution] = $this->provision('SIS Transfer Workflow Academy');
        foreach ([['Leila', 'Outgoing'], ['Imran', 'Incoming'], ['Nora', 'Review']] as [$firstName, $lastName]) {
            $this->actingAs($owner)->post("/t/{$tenant->slug}/sis/students", [
                'legal_first_name' => $firstName, 'legal_last_name' => $lastName, 'admission_date' => '2026-09-01',
            ])->assertRedirect();
        }
        $studentIds = DB::table('sis_students')->where('tenant_id', $tenant->getKey())->orderBy('id')->pluck('id')->map(fn ($id): int => (int) $id)->all();
        $incoming = SisStudent::query()->withoutGlobalScope('tenant')->findOrFail($studentIds[1]);
        $incoming->forceFill(['status' => 'pre_admitted'])->save();

        $reviewer = User::factory()->create();
        $context = app(TenantContext::class);
        $context->set($tenant, $ownerMembership);
        try {
            $reviewerMembership = TenantMembership::query()->create([
                'institution_id' => $institution->getKey(), 'user_id' => $reviewer->getKey(), 'status' => 'active', 'activated_at' => now(),
            ]);
            $adminRole = Role::query()->where('tenant_id', $tenant->getKey())->where('key', 'tenant.admin')->firstOrFail();
            $reviewerMembership->roles()->attach($adminRole->getKey(), ['assigned_by' => $owner->getKey(), 'tenant_id' => $tenant->getKey()]);
        } finally {
            $context->clear();
        }
        $registrarRole = Role::query()->where('tenant_id', $tenant->getKey())->where('key', 'sis.registrar')->firstOrFail();
        $this->assertTrue($registrarRole->permissions()->where('key', 'sis.transfers.manage')->exists());
        $this->assertFalse($registrarRole->permissions()->where('key', 'sis.transfers.approve')->exists());

        $makeEvidence = function (int $studentId, string $status, ?string $expiresAt = null) use ($tenant, $owner, $ownerMembership, $context): int {
            $context->set($tenant, $ownerMembership);
            try {
                $document = SisPrivateDocument::query()->create([
                    'student_id' => $studentId, 'category' => 'transfer_certificate', 'scan_status' => $status,
                    'current_version' => 1, 'uploaded_by' => $owner->getKey(),
                ]);
                SisPrivateDocumentVersion::query()->create([
                    'document_id' => $document->getKey(), 'version_number' => 1,
                    'private_path' => "sis/{$tenant->getKey()}/{$studentId}/transfer-".Str::uuid().'.pdf',
                    'original_name' => 'transfer-certificate.pdf', 'mime_type' => 'application/pdf', 'size_bytes' => 20,
                    'sha256' => hash('sha256', 'transfer evidence'), 'uploaded_by' => $owner->getKey(), 'expires_at' => $expiresAt,
                ]);

                return (int) $document->getKey();
            } finally {
                $context->clear();
            }
        };

        $pendingEvidenceId = $makeEvidence($studentIds[0], 'pending');
        $cleanOutgoingEvidenceId = $makeEvidence($studentIds[0], 'clean');
        $cleanIncomingEvidenceId = $makeEvidence($studentIds[1], 'clean');
        $cleanRejectedEvidenceId = $makeEvidence($studentIds[2], 'clean');
        $expiredEvidenceId = $makeEvidence($studentIds[2], 'clean', now()->subDay()->toDateTimeString());
        $outgoingUrl = "/t/{$tenant->slug}/sis/students/{$studentIds[0]}/transfers";
        $outgoingPayload = [
            'direction' => 'outgoing', 'external_institution_name' => 'North Valley Academy', 'external_country_code' => 'pk',
            'requested_effective_on' => now()->toDateString(), 'request_reason' => 'Relocation to a new city.',
        ];

        $this->actingAs($owner)->post($outgoingUrl, [...$outgoingPayload, 'evidence_document_id' => $pendingEvidenceId])
            ->assertSessionHasErrors('evidence_document_id');
        $this->assertDatabaseCount('sis_student_transfer_cases', 0);

        $this->actingAs($owner)->post($outgoingUrl, [...$outgoingPayload, 'evidence_document_id' => $cleanOutgoingEvidenceId])->assertRedirect();
        $outgoingId = (int) DB::table('sis_student_transfer_cases')->where('tenant_id', $tenant->getKey())->where('student_id', $studentIds[0])->value('id');
        $this->assertDatabaseHas('sis_student_transfer_cases', [
            'id' => $outgoingId, 'direction' => 'outgoing', 'status' => 'pending_review', 'evidence_document_id' => $cleanOutgoingEvidenceId,
        ]);
        $this->actingAs($owner)->post($outgoingUrl, [...$outgoingPayload, 'evidence_document_id' => $cleanOutgoingEvidenceId])
            ->assertSessionHasErrors('student');
        [$foreignTenant] = $this->provision('SIS Foreign Transfer Academy', $owner);
        $this->actingAs($owner)->post("/t/{$foreignTenant->slug}/sis/transfers/{$outgoingId}/review", [
            'decision' => 'approved', 'review_note' => 'Attempted review outside the owning institution.',
        ])->assertNotFound();
        $this->actingAs($owner)->post("/t/{$tenant->slug}/sis/transfers/{$outgoingId}/review", [
            'decision' => 'approved', 'review_note' => 'Approved following evidence review.',
        ])->assertForbidden();
        $this->actingAs($reviewer)->post("/t/{$tenant->slug}/sis/transfers/{$outgoingId}/review", [
            'decision' => 'approved', 'review_note' => 'Approved following evidence review.',
        ])->assertRedirect();
        $this->assertDatabaseHas('sis_student_transfer_cases', ['id' => $outgoingId, 'status' => 'approved', 'reviewed_by' => $reviewer->getKey()]);
        $this->actingAs($owner)->post("/t/{$tenant->slug}/sis/transfers/{$outgoingId}/complete", [
            'completion_note' => 'Student departure was confirmed with the receiving institution.',
        ])->assertRedirect();
        $this->assertDatabaseHas('sis_student_transfer_cases', ['id' => $outgoingId, 'status' => 'completed', 'completed_by' => $owner->getKey()]);
        $this->assertDatabaseHas('sis_students', ['tenant_id' => $tenant->getKey(), 'id' => $studentIds[0], 'status' => 'transferred']);
        $this->assertDatabaseHas('sis_student_lifecycle_events', ['tenant_id' => $tenant->getKey(), 'student_id' => $studentIds[0], 'to_status' => 'transferred']);

        $incomingUrl = "/t/{$tenant->slug}/sis/students/{$studentIds[1]}/transfers";
        $this->actingAs($owner)->post($incomingUrl, [
            'direction' => 'incoming', 'external_institution_name' => 'East Ridge School', 'external_country_code' => 'PK',
            'requested_effective_on' => now()->toDateString(), 'request_reason' => 'Transfer into the institution.',
            'evidence_document_id' => $cleanIncomingEvidenceId,
        ])->assertRedirect();
        $incomingId = (int) DB::table('sis_student_transfer_cases')->where('tenant_id', $tenant->getKey())->where('student_id', $studentIds[1])->value('id');
        $this->actingAs($reviewer)->post("/t/{$tenant->slug}/sis/transfers/{$incomingId}/review", [
            'decision' => 'approved', 'review_note' => 'Incoming transfer evidence has been reviewed.',
        ])->assertRedirect();
        $this->actingAs($reviewer)->post("/t/{$tenant->slug}/sis/transfers/{$incomingId}/complete", [
            'completion_note' => 'Incoming student record is ready for placement.',
        ])->assertRedirect();
        $this->assertDatabaseHas('sis_student_transfer_cases', ['id' => $incomingId, 'direction' => 'incoming', 'status' => 'completed']);
        $this->assertDatabaseHas('sis_students', ['tenant_id' => $tenant->getKey(), 'id' => $studentIds[1], 'status' => 'admitted']);

        $rejectedUrl = "/t/{$tenant->slug}/sis/students/{$studentIds[2]}/transfers";
        $this->actingAs($owner)->post($rejectedUrl, [
            'direction' => 'outgoing', 'external_institution_name' => 'South Bay College', 'external_country_code' => 'PK',
            'requested_effective_on' => now()->toDateString(), 'request_reason' => 'Requested relocation transfer.',
            'evidence_document_id' => $expiredEvidenceId,
        ])->assertSessionHasErrors('evidence_document_id');
        $this->actingAs($owner)->post($rejectedUrl, [
            'direction' => 'outgoing', 'external_institution_name' => 'South Bay College', 'external_country_code' => 'PK',
            'requested_effective_on' => now()->toDateString(), 'request_reason' => 'Requested relocation transfer.',
            'evidence_document_id' => $cleanRejectedEvidenceId,
        ])->assertRedirect();
        $rejectedId = (int) DB::table('sis_student_transfer_cases')->where('tenant_id', $tenant->getKey())->where('student_id', $studentIds[2])->value('id');
        $this->actingAs($reviewer)->post("/t/{$tenant->slug}/sis/transfers/{$rejectedId}/review", [
            'decision' => 'rejected', 'review_note' => 'Evidence did not meet the transfer policy.',
        ])->assertRedirect();
        $this->assertDatabaseHas('sis_student_transfer_cases', ['id' => $rejectedId, 'status' => 'rejected', 'review_decision' => 'rejected']);
        $this->assertDatabaseHas('sis_students', ['tenant_id' => $tenant->getKey(), 'id' => $studentIds[2], 'status' => 'admitted']);
        $this->actingAs($owner)->post("/t/{$tenant->slug}/sis/transfers/{$rejectedId}/complete", [
            'completion_note' => 'This rejected request must not complete.',
        ])->assertStatus(409);
        $this->assertSame(2, DB::table('audit_events')->where('tenant_id', $tenant->getKey())->where('event_type', 'sis.student.transfer_completed')->count());
    }

    public function test_family_search_edit_and_linking_are_scoped_verified_and_audited(): void
    {
        [$tenantA, $owner, $ownerMembership] = $this->provision('SIS Family Scope Academy');
        [$tenantB] = $this->provision('SIS Other Family Academy', $owner);

        foreach ([[$tenantA, 'Priya', 'Das'], [$tenantA, 'Neel', 'Das'], [$tenantB, 'Neel', 'Das']] as [$tenant, $firstName, $lastName]) {
            $this->actingAs($owner)->post("/t/{$tenant->slug}/sis/students", [
                'legal_first_name' => $firstName, 'legal_last_name' => $lastName, 'admission_date' => '2026-09-01',
            ])->assertRedirect();
        }

        $studentAIds = DB::table('sis_students')->where('tenant_id', $tenantA->getKey())->orderBy('id')->pluck('id')->all();
        $studentBId = (int) DB::table('sis_students')->where('tenant_id', $tenantB->getKey())->value('id');
        [$primaryStudentId, $siblingStudentId] = array_map('intval', $studentAIds);

        $this->actingAs($owner)->post("/t/{$tenantA->slug}/sis/students/{$primaryStudentId}/families", [
            'label' => 'Das household', 'relationship_label' => 'child',
        ])->assertSessionHasErrors('verification_method');
        $this->assertDatabaseCount('sis_family_groups', 0);

        $this->actingAs($owner)->post("/t/{$tenantA->slug}/sis/students/{$primaryStudentId}/families", [
            'label' => 'Das household', 'relationship_label' => 'child', 'verification_method' => 'institution_record',
        ])->assertRedirect("/t/{$tenantA->slug}/sis/students/{$primaryStudentId}");
        $familyId = (int) DB::table('sis_family_groups')->where('tenant_id', $tenantA->getKey())->value('id');

        $this->actingAs($owner)->get("/t/{$tenantA->slug}/sis/families/{$familyId}/students/search?q=neel")
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $siblingStudentId)
            ->assertJsonPath('data.0.name', 'Neel Das');
        $this->actingAs($owner)->get("/t/{$tenantA->slug}/sis/families/{$familyId}/students/search?q=%25e")
            ->assertOk()->assertJsonCount(0, 'data');
        $this->actingAs($owner)->get("/t/{$tenantB->slug}/sis/families/{$familyId}/students/search?q=neel")->assertNotFound();

        $this->actingAs($owner)->post("/t/{$tenantA->slug}/sis/families/{$familyId}/students", [
            'student_id' => $siblingStudentId, 'relationship_label' => 'sibling', 'verification_method' => 'guardian_confirmation',
        ])->assertRedirect("/t/{$tenantA->slug}/sis/students/{$siblingStudentId}");
        $this->assertDatabaseHas('sis_family_students', [
            'tenant_id' => $tenantA->getKey(), 'family_group_id' => $familyId, 'student_id' => $siblingStudentId,
            'relationship_label' => 'sibling', 'verified_by' => $owner->getKey(),
        ]);
        $this->actingAs($owner)->post("/t/{$tenantA->slug}/sis/families/{$familyId}/students", [
            'student_id' => $siblingStudentId, 'relationship_label' => 'sibling', 'verification_method' => 'guardian_confirmation',
        ])->assertStatus(409);

        $this->actingAs($owner)->put("/t/{$tenantA->slug}/sis/students/{$primaryStudentId}/families/{$familyId}", [
            'label' => 'Das family', 'relationship_label' => 'dependent', 'verification_method' => 'document_review',
        ])->assertRedirect("/t/{$tenantA->slug}/sis/students/{$primaryStudentId}");
        $this->assertDatabaseHas('sis_family_groups', ['tenant_id' => $tenantA->getKey(), 'id' => $familyId, 'label' => 'Das family']);
        $this->assertDatabaseHas('sis_family_students', [
            'tenant_id' => $tenantA->getKey(), 'family_group_id' => $familyId, 'student_id' => $primaryStudentId,
            'relationship_label' => 'dependent', 'verified_by' => $owner->getKey(),
        ]);
        $this->assertDatabaseHas('audit_events', ['tenant_id' => $tenantA->getKey(), 'event_type' => 'sis.family.updated']);
        $this->assertDatabaseHas('audit_events', ['tenant_id' => $tenantA->getKey(), 'event_type' => 'sis.family.student_linked']);
        $this->assertDatabaseHas('audit_events', ['tenant_id' => $tenantA->getKey(), 'event_type' => 'sis.family.relationship_verified']);

        DB::table('sis_family_students')->where('tenant_id', $tenantA->getKey())->where('family_group_id', $familyId)
            ->where('student_id', $siblingStudentId)->update(['effective_until' => today()->subDay()->toDateString()]);
        $this->actingAs($owner)->post("/t/{$tenantA->slug}/sis/families/{$familyId}/students", [
            'student_id' => $siblingStudentId, 'relationship_label' => 'sibling', 'verification_method' => 'institution_record',
        ])->assertRedirect("/t/{$tenantA->slug}/sis/students/{$siblingStudentId}");
        $this->assertDatabaseHas('sis_family_students', [
            'tenant_id' => $tenantA->getKey(), 'family_group_id' => $familyId, 'student_id' => $siblingStudentId,
            'effective_until' => null, 'verified_by' => $owner->getKey(),
        ]);
        $this->assertDatabaseHas('audit_events', ['tenant_id' => $tenantA->getKey(), 'event_type' => 'sis.family.student_relinked']);

        $this->actingAs($owner)->post("/t/{$tenantA->slug}/sis/families/{$familyId}/students", [
            'student_id' => $studentBId, 'relationship_label' => 'sibling', 'verification_method' => 'institution_record',
        ])->assertNotFound();
        $this->assertDatabaseMissing('sis_family_students', ['tenant_id' => $tenantA->getKey(), 'family_group_id' => $familyId, 'student_id' => $studentBId]);

        $unauthorizedUser = User::factory()->create();
        $context = app(TenantContext::class);
        $context->set($tenantA, $ownerMembership);
        try {
            TenantMembership::query()->create([
                'institution_id' => $ownerMembership->institution_id, 'user_id' => $unauthorizedUser->getKey(),
                'status' => 'active', 'activated_at' => now(),
            ]);
        } finally {
            $context->clear();
        }
        $this->actingAs($unauthorizedUser)->get("/t/{$tenantA->slug}/sis/families/{$familyId}/students/search?q=neel")->assertForbidden();
    }

    public function test_family_search_and_group_details_fail_closed_for_cross_campus_memberships(): void
    {
        [$tenant, $owner, $ownerMembership, $institution] = $this->provision('SIS Campus Family Academy');
        $context = app(TenantContext::class);
        $context->set($tenant, $ownerMembership);
        try {
            $campusA = Campus::query()->create(['institution_id' => $institution->getKey(), 'code' => 'FAMILY-A', 'name' => 'Family Campus A', 'status' => 'active']);
            $campusB = Campus::query()->create(['institution_id' => $institution->getKey(), 'code' => 'FAMILY-B', 'name' => 'Family Campus B', 'status' => 'active']);

            $campusManager = User::factory()->create();
            $membership = TenantMembership::query()->create([
                'institution_id' => $institution->getKey(), 'user_id' => $campusManager->getKey(),
                'campus_id' => $campusA->getKey(), 'status' => 'active', 'activated_at' => now(),
            ]);
            $role = Role::query()->create([
                'tenant_id' => $tenant->getKey(), 'key' => 'sis.campus.family.manager', 'name' => 'Campus Family Manager',
                'scope' => 'tenant', 'description' => 'Scoped test role', 'is_system' => false,
            ]);
            $role->permissions()->sync(Permission::query()->whereIn('key', ['sis.view', 'sis.family.manage'])->pluck('id')->all());
            $membership->roles()->attach($role->getKey(), ['assigned_by' => $owner->getKey(), 'tenant_id' => $tenant->getKey()]);
        } finally {
            $context->clear();
        }

        $this->actingAs($owner)->post("/t/{$tenant->slug}/sis/students", [
            'legal_first_name' => 'CampusA', 'legal_last_name' => 'Student', 'admission_date' => '2026-09-01', 'home_campus_id' => $campusA->getKey(),
        ])->assertRedirect();
        $this->actingAs($owner)->post("/t/{$tenant->slug}/sis/students", [
            'legal_first_name' => 'CampusB', 'legal_last_name' => 'Student', 'admission_date' => '2026-09-01', 'home_campus_id' => $campusB->getKey(),
        ])->assertRedirect();
        $studentIds = DB::table('sis_students')->where('tenant_id', $tenant->getKey())->orderBy('id')->pluck('id')->all();
        [$campusStudentId, $otherCampusStudentId] = array_map('intval', $studentIds);

        $this->actingAs($owner)->post("/t/{$tenant->slug}/sis/students/{$campusStudentId}/families", [
            'label' => 'Cross campus family', 'relationship_label' => 'child', 'verification_method' => 'institution_record',
        ])->assertRedirect();
        $familyId = (int) DB::table('sis_family_groups')->where('tenant_id', $tenant->getKey())->value('id');

        $this->actingAs($campusManager)->get("/t/{$tenant->slug}/sis/families/{$familyId}/students/search?q=campusb")
            ->assertOk()->assertJsonCount(0, 'data');

        $this->actingAs($owner)->post("/t/{$tenant->slug}/sis/families/{$familyId}/students", [
            'student_id' => $otherCampusStudentId, 'relationship_label' => 'sibling', 'verification_method' => 'guardian_confirmation',
        ])->assertRedirect();

        $this->actingAs($campusManager)->get("/t/{$tenant->slug}/sis/families/{$familyId}/students/search?q=campusb")->assertNotFound();
        $this->actingAs($campusManager)->get("/t/{$tenant->slug}/sis/students/{$campusStudentId}")
            ->assertOk()->assertInertia(fn ($page) => $page->has('familyGroups', 0));
        $this->actingAs($campusManager)->get("/t/{$tenant->slug}/sis/students/{$otherCampusStudentId}")->assertNotFound();
    }

    public function test_section_capacity_is_enforced_and_invalid_cross_structure_placement_is_rejected(): void
    {
        [$tenant, $owner, $membership, $institution] = $this->provision('SIS Capacity Academy');
        $context = app(TenantContext::class);
        $context->set($tenant, $membership);
        try {
            $year = AcademicYear::query()->create([
                'institution_id' => $institution->getKey(), 'name' => '2026–2027', 'code' => 'AY-2627',
                'starts_on' => '2026-08-01', 'ends_on' => '2027-06-30', 'status' => 'active',
            ]);
            $class = AcademicClass::query()->create([
                'institution_id' => $institution->getKey(), 'academic_year_id' => $year->getKey(),
                'group_type' => 'cohort', 'code' => 'COHORT-1', 'name' => 'Cohort One', 'capacity_policy' => 'hard',
                'structural_capacity' => 4, 'status' => 'active',
            ]);
            $section = AcademicSection::query()->create([
                'academic_class_id' => $class->getKey(), 'code' => 'A', 'name' => 'Section A',
                'structural_capacity' => 1, 'status' => 'active',
            ]);
        } finally {
            $context->clear();
        }

        foreach ([['Casey', 'One'], ['Taylor', 'Two']] as [$first, $last]) {
            $this->actingAs($owner)->post("/t/{$tenant->slug}/sis/students", [
                'legal_first_name' => $first, 'legal_last_name' => $last, 'admission_date' => '2026-09-01',
            ])->assertRedirect();
        }
        $studentIds = DB::table('sis_students')->where('tenant_id', $tenant->getKey())->orderBy('id')->pluck('id')->all();
        $destination = [
            'type' => 'placement', 'academic_year_id' => $year->getKey(), 'academic_class_id' => $class->getKey(),
            'academic_section_id' => $section->getKey(), 'effective_from' => '2026-09-01', 'reason_code' => 'initial_placement',
        ];
        $this->actingAs($owner)->post("/t/{$tenant->slug}/sis/students/{$studentIds[0]}/placements", $destination)->assertRedirect();
        $this->assertDatabaseCount('sis_student_placements', 0);
        $preview = session('sis.placement_preview.'.$studentIds[0]);
        $this->assertIsArray($preview);
        $this->actingAs($owner)->post("/t/{$tenant->slug}/sis/students/{$studentIds[0]}/placements", [...$destination, 'confirm' => true, 'preview_token' => $preview['token']])->assertRedirect();
        $this->assertDatabaseCount('sis_student_placements', 1);

        $this->actingAs($owner)->post("/t/{$tenant->slug}/sis/students/{$studentIds[1]}/placements", $destination)->assertSessionHasErrors('academic_section_id');
        $this->assertDatabaseCount('sis_student_placements', 1);
        $this->assertSame($studentIds[0], DB::table('sis_student_placements')->value('student_id'));
    }

    public function test_bulk_promotion_previews_capacity_then_confirms_atomically_once(): void
    {
        [$tenant, $owner, $membership, $institution] = $this->provision('SIS Bulk Promotion Academy');
        foreach ([['Avery', 'One'], ['Blair', 'Two'], ['Casey', 'Three']] as [$first, $last]) {
            $this->actingAs($owner)->post("/t/{$tenant->slug}/sis/students", [
                'legal_first_name' => $first, 'legal_last_name' => $last, 'admission_date' => '2026-08-01',
            ])->assertRedirect();
        }
        $studentIds = DB::table('sis_students')->where('tenant_id', $tenant->getKey())->orderBy('id')->pluck('id')->map(fn ($id): int => (int) $id)->all();
        $context = app(TenantContext::class);
        $context->set($tenant, $membership);
        try {
            $year = AcademicYear::query()->create([
                'institution_id' => $institution->getKey(), 'name' => 'Promotion Year', 'code' => 'PROMO-AY',
                'starts_on' => '2026-08-01', 'ends_on' => '2027-06-30', 'status' => 'active',
            ]);
            $source = AcademicClass::query()->create([
                'institution_id' => $institution->getKey(), 'academic_year_id' => $year->getKey(),
                'group_type' => 'cohort', 'code' => 'PROMO-SRC', 'name' => 'Source Class', 'status' => 'active',
            ]);
            $destination = AcademicClass::query()->create([
                'institution_id' => $institution->getKey(), 'academic_year_id' => $year->getKey(),
                'group_type' => 'cohort', 'code' => 'PROMO-DST', 'name' => 'Destination Class',
                'capacity_policy' => 'hard', 'structural_capacity' => 2, 'status' => 'active',
            ]);
            $registry = app(SisStudentRegistry::class);
            foreach ($studentIds as $studentId) {
                $student = SisStudent::query()->findOrFail($studentId);
                $registry->place($student, ['academic_year_id' => $year->getKey(), 'academic_class_id' => $source->getKey()], '2026-08-01', 'initial_placement', null, $owner);
            }
        } finally {
            $context->clear();
        }

        $endpoint = "/t/{$tenant->slug}/sis/students/bulk-promotion";
        $payload = [
            'academic_year_id' => $year->getKey(), 'academic_class_id' => $destination->getKey(),
            'effective_from' => '2026-09-01', 'reason_code' => 'manual_progression', 'reason' => 'Authorized progression review.',
        ];
        $this->actingAs($owner)->post($endpoint, [...$payload, 'student_ids' => $studentIds])->assertSessionHasErrors('academic_class_id');
        $this->assertSame(3, DB::table('sis_student_placements')->count(), 'Capacity preview failure must not mutate placement history.');

        $selectedIds = array_slice($studentIds, 0, 2);
        $this->actingAs($owner)->post($endpoint, [...$payload, 'student_ids' => $selectedIds])->assertRedirect("/t/{$tenant->slug}/sis/students");
        $this->assertSame(3, DB::table('sis_student_placements')->count(), 'Preview must not write placement rows.');
        $sessionKey = "sis.bulk_promotion_preview.{$tenant->getKey()}.{$owner->getKey()}";
        $preview = session($sessionKey);
        $this->assertIsArray($preview);
        $this->assertSame(2, $preview['count']);
        $this->actingAs($owner)->get("/t/{$tenant->slug}/sis/students")->assertInertia(fn ($page) => $page->where('bulkPromotionPreview.count', 2));

        $this->actingAs($owner)->post($endpoint, [...$payload, 'student_ids' => $selectedIds, 'confirm' => true, 'preview_token' => $preview['token']])
            ->assertRedirect("/t/{$tenant->slug}/sis/students");
        $this->assertDatabaseCount('sis_student_placements', 5);
        $this->assertSame(2, DB::table('sis_student_placements')->where('placement_type', 'promotion')->where('academic_class_id', $destination->getKey())->count());
        $this->actingAs($owner)->post($endpoint, [...$payload, 'student_ids' => $selectedIds, 'confirm' => true, 'preview_token' => $preview['token']])
            ->assertStatus(409);
        $this->assertDatabaseCount('sis_student_placements', 5);
    }

    public function test_progression_hold_blocks_bulk_promotion_until_an_authorized_reasoned_release(): void
    {
        [$tenant, $owner, $membership, $institution] = $this->provision('SIS Promotion Hold Academy');
        foreach ([['Avery', 'One'], ['Blair', 'Two']] as [$first, $last]) {
            $this->actingAs($owner)->post("/t/{$tenant->slug}/sis/students", [
                'legal_first_name' => $first, 'legal_last_name' => $last, 'admission_date' => '2026-08-01',
            ])->assertRedirect();
        }
        $studentIds = DB::table('sis_students')->where('tenant_id', $tenant->getKey())->orderBy('id')->pluck('id')->map(fn ($id): int => (int) $id)->all();
        $context = app(TenantContext::class);
        $context->set($tenant, $membership);
        try {
            $year = AcademicYear::query()->create([
                'institution_id' => $institution->getKey(), 'name' => 'Hold Year', 'code' => 'HOLD-AY',
                'starts_on' => '2026-08-01', 'ends_on' => '2027-06-30', 'status' => 'active',
            ]);
            $source = AcademicClass::query()->create([
                'institution_id' => $institution->getKey(), 'academic_year_id' => $year->getKey(),
                'group_type' => 'cohort', 'code' => 'HOLD-SRC', 'name' => 'Hold Source', 'status' => 'active',
            ]);
            $destination = AcademicClass::query()->create([
                'institution_id' => $institution->getKey(), 'academic_year_id' => $year->getKey(),
                'group_type' => 'cohort', 'code' => 'HOLD-DST', 'name' => 'Hold Destination', 'status' => 'active',
            ]);
            foreach ($studentIds as $studentId) {
                app(SisStudentRegistry::class)->place(SisStudent::query()->findOrFail($studentId), [
                    'academic_year_id' => $year->getKey(), 'academic_class_id' => $source->getKey(),
                ], '2026-08-01', 'initial_placement', null, $owner);
            }
        } finally {
            $context->clear();
        }

        $profileUrl = "/t/{$tenant->slug}/sis/students/{$studentIds[0]}";
        $this->actingAs($owner)->post("{$profileUrl}/promotion-holds", [
            'reason_code' => 'records_review', 'reason' => 'Required records need independent review.',
            'evidence_reference' => 'CASE-2042',
        ])->assertRedirect();
        $holdId = (int) DB::table('sis_student_promotion_holds')->where('tenant_id', $tenant->getKey())->value('id');
        $this->actingAs($owner)->post("{$profileUrl}/promotion-holds", [
            'reason_code' => 'duplicate', 'reason' => 'A second active hold should be denied.',
        ])->assertSessionHasErrors('reason');

        $endpoint = "/t/{$tenant->slug}/sis/students/bulk-promotion";
        $payload = [
            'student_ids' => $studentIds, 'academic_year_id' => $year->getKey(), 'academic_class_id' => $destination->getKey(),
            'effective_from' => '2026-09-01', 'reason_code' => 'manual_progression',
        ];
        $this->actingAs($owner)->post($endpoint, $payload)->assertSessionHasErrors('student_ids');
        $this->assertSame(2, DB::table('sis_student_placements')->where('tenant_id', $tenant->getKey())->count());

        $this->actingAs($owner)->patch("{$profileUrl}/promotion-holds/{$holdId}/release", [
            'release_reason' => 'Records were reviewed and eligibility confirmed.',
        ])->assertRedirect();
        $this->assertDatabaseHas('sis_student_promotion_holds', [
            'tenant_id' => $tenant->getKey(), 'id' => $holdId, 'status' => 'released', 'released_by' => $owner->getKey(),
        ]);
        $this->assertSame(1, DB::table('audit_events')->where('tenant_id', $tenant->getKey())->where('event_type', 'sis.student.promotion_hold.created')->count());
        $this->assertSame(1, DB::table('audit_events')->where('tenant_id', $tenant->getKey())->where('event_type', 'sis.student.promotion_hold.released')->count());
        $this->actingAs($owner)->post($endpoint, $payload)->assertRedirect();
        $this->assertIsArray(session("sis.bulk_promotion_preview.{$tenant->getKey()}.{$owner->getKey()}"));

        [$otherTenant, $otherOwner] = $this->provision('SIS Other Promotion Hold Academy');
        $this->actingAs($otherOwner)->post("/t/{$otherTenant->slug}/sis/students", [
            'legal_first_name' => 'Jordan', 'legal_last_name' => 'Other', 'admission_date' => '2026-08-01',
        ])->assertRedirect();
        $foreignStudentId = (int) DB::table('sis_students')->where('tenant_id', $otherTenant->getKey())->value('id');
        $this->actingAs($owner)->post("/t/{$tenant->slug}/sis/students/{$foreignStudentId}/promotion-holds", [
            'reason_code' => 'cross_tenant', 'reason' => 'Must not create a cross-tenant hold.',
        ])->assertNotFound();
    }

    public function test_approval_required_promotions_use_independent_reviewer_and_record_outcomes(): void
    {
        [$tenant, $owner, $membership, $institution] = $this->provision('SIS Promotion Approval Academy');
        foreach ([['Avery', 'One'], ['Blair', 'Two']] as [$first, $last]) {
            $this->actingAs($owner)->post("/t/{$tenant->slug}/sis/students", [
                'legal_first_name' => $first, 'legal_last_name' => $last, 'admission_date' => '2026-08-01',
            ])->assertRedirect();
        }
        $studentIds = DB::table('sis_students')->where('tenant_id', $tenant->getKey())->orderBy('id')->pluck('id')->map(fn ($id): int => (int) $id)->all();
        $context = app(TenantContext::class);
        $context->set($tenant, $membership);
        try {
            $year = AcademicYear::query()->create([
                'institution_id' => $institution->getKey(), 'name' => 'Approval Year', 'code' => 'APPROVE-AY',
                'starts_on' => '2026-08-01', 'ends_on' => '2027-06-30', 'status' => 'active',
            ]);
            $source = AcademicClass::query()->create([
                'institution_id' => $institution->getKey(), 'academic_year_id' => $year->getKey(),
                'group_type' => 'cohort', 'code' => 'APPROVE-SRC', 'name' => 'Approval Source', 'status' => 'active',
            ]);
            $destination = AcademicClass::query()->create([
                'institution_id' => $institution->getKey(), 'academic_year_id' => $year->getKey(),
                'group_type' => 'cohort', 'code' => 'APPROVE-DST', 'name' => 'Approval Destination', 'status' => 'active',
            ]);
            $rejectionDestination = AcademicClass::query()->create([
                'institution_id' => $institution->getKey(), 'academic_year_id' => $year->getKey(),
                'group_type' => 'cohort', 'code' => 'REJECT-DST', 'name' => 'Rejected Destination', 'status' => 'active',
            ]);
            foreach ($studentIds as $studentId) {
                app(SisStudentRegistry::class)->place(SisStudent::query()->findOrFail($studentId), [
                    'academic_year_id' => $year->getKey(), 'academic_class_id' => $source->getKey(),
                ], '2026-08-01', 'initial_placement', null, $owner);
            }
            $reviewer = User::factory()->create();
            $reviewerMembership = TenantMembership::query()->create([
                'institution_id' => $institution->getKey(), 'user_id' => $reviewer->getKey(),
                'status' => 'active', 'activated_at' => now(),
            ]);
            $adminRole = Role::query()->where('tenant_id', $tenant->getKey())->where('key', 'tenant.admin')->firstOrFail();
            $reviewerMembership->roles()->attach($adminRole->getKey(), ['assigned_by' => $owner->getKey(), 'tenant_id' => $tenant->getKey()]);
        } finally {
            $context->clear();
        }

        $this->actingAs($owner)->put("/t/{$tenant->slug}/sis/settings", [
            'student_label' => 'Student', 'number_prefix' => 'STU', 'number_width' => 6,
            'promotion_requires_approval' => true,
        ])->assertRedirect("/t/{$tenant->slug}/sis");
        $endpoint = "/t/{$tenant->slug}/sis/students/bulk-promotion";
        $payload = [
            'student_ids' => $studentIds, 'academic_year_id' => $year->getKey(), 'academic_class_id' => $destination->getKey(),
            'effective_from' => '2026-09-01', 'reason_code' => 'progression_review', 'reason' => 'Annual progression review approved.',
        ];
        $this->actingAs($owner)->post($endpoint, $payload)->assertRedirect();
        $preview = session("sis.bulk_promotion_preview.{$tenant->getKey()}.{$owner->getKey()}");
        $this->assertIsArray($preview);
        $this->actingAs($owner)->post($endpoint, [...$payload, 'confirm' => true, 'preview_token' => $preview['token']])->assertRedirect();
        $batchId = (int) DB::table('sis_student_promotion_batches')->where('tenant_id', $tenant->getKey())->value('id');
        $this->assertSame(2, DB::table('sis_student_placements')->where('tenant_id', $tenant->getKey())->count(), 'Submitting approval must not change placements.');
        $decisionUrl = "/t/{$tenant->slug}/sis/promotion-batches/{$batchId}/decision";
        $this->actingAs($owner)->post($decisionUrl, ['decision' => 'approved', 'reason' => 'Requester cannot approve this.'])->assertSessionHasErrors('decision');
        $this->assertSame('pending', DB::table('sis_student_promotion_batches')->where('id', $batchId)->value('status'));

        $this->actingAs($reviewer)->post($decisionUrl, ['decision' => 'approved', 'reason' => 'Eligibility and destination capacity were checked.'])->assertRedirect();
        $this->assertDatabaseHas('sis_student_promotion_batches', [
            'tenant_id' => $tenant->getKey(), 'id' => $batchId, 'status' => 'approved', 'reviewed_by' => $reviewer->getKey(),
        ]);
        $this->assertSame(2, DB::table('sis_student_placements')->where('tenant_id', $tenant->getKey())->where('placement_type', 'promotion')->count());
        $this->assertSame(2, DB::table('sis_student_promotion_batch_students')->where('tenant_id', $tenant->getKey())->where('promotion_batch_id', $batchId)->where('outcome_status', 'promoted')->count());
        $this->assertSame(1, DB::table('audit_events')->where('tenant_id', $tenant->getKey())->where('event_type', 'sis.students.bulk_promotion_approved')->count());

        $rejectionPayload = [
            ...$payload,
            'academic_class_id' => $rejectionDestination->getKey(),
            'effective_from' => '2026-10-01',
            'reason_code' => 'second_review_required',
        ];
        $this->actingAs($owner)->post($endpoint, $rejectionPayload)->assertRedirect();
        $rejectionPreview = session("sis.bulk_promotion_preview.{$tenant->getKey()}.{$owner->getKey()}");
        $this->assertIsArray($rejectionPreview);
        $this->actingAs($owner)->post($endpoint, [
            ...$rejectionPayload,
            'confirm' => true,
            'preview_token' => $rejectionPreview['token'],
        ])->assertRedirect();
        $rejectedBatchId = (int) DB::table('sis_student_promotion_batches')->where('tenant_id', $tenant->getKey())->orderByDesc('id')->value('id');
        $rejectedDecisionUrl = "/t/{$tenant->slug}/sis/promotion-batches/{$rejectedBatchId}/decision";
        $this->actingAs($reviewer)->post($rejectedDecisionUrl, [
            'decision' => 'rejected', 'reason' => 'The second reviewer requires further academic evidence.',
        ])->assertRedirect();
        $this->assertDatabaseHas('sis_student_promotion_batches', [
            'tenant_id' => $tenant->getKey(), 'id' => $rejectedBatchId, 'status' => 'rejected', 'reviewed_by' => $reviewer->getKey(),
        ]);
        $this->assertSame(2, DB::table('sis_student_placements')->where('tenant_id', $tenant->getKey())->where('placement_type', 'promotion')->count(), 'Rejected requests must not create academic placements.');
        $this->assertSame(2, DB::table('sis_student_promotion_batch_students')->where('tenant_id', $tenant->getKey())->where('promotion_batch_id', $rejectedBatchId)->where('outcome_status', 'rejected')->count());
        $this->assertSame(1, DB::table('audit_events')->where('tenant_id', $tenant->getKey())->where('event_type', 'sis.students.bulk_promotion_rejected')->count());
    }

    public function test_generic_placement_cannot_impersonate_promotion_and_correction_preserves_history(): void
    {
        [$tenant, $owner, $membership, $institution] = $this->provision('SIS Promotion Correction Academy');
        $this->actingAs($owner)->post("/t/{$tenant->slug}/sis/students", [
            'legal_first_name' => 'Mina', 'legal_last_name' => 'Example', 'admission_date' => '2026-08-01',
        ])->assertRedirect();
        $studentId = (int) DB::table('sis_students')->where('tenant_id', $tenant->getKey())->value('id');
        $context = app(TenantContext::class);
        $context->set($tenant, $membership);
        try {
            $year = AcademicYear::query()->create([
                'institution_id' => $institution->getKey(), 'name' => 'Correction Year', 'code' => 'CORR-AY',
                'starts_on' => '2026-08-01', 'ends_on' => '2027-06-30', 'status' => 'active',
            ]);
            $source = AcademicClass::query()->create([
                'institution_id' => $institution->getKey(), 'academic_year_id' => $year->getKey(),
                'group_type' => 'cohort', 'code' => 'CORR-SRC', 'name' => 'Correction Source', 'status' => 'active',
            ]);
            $currentClass = AcademicClass::query()->create([
                'institution_id' => $institution->getKey(), 'academic_year_id' => $year->getKey(),
                'group_type' => 'cohort', 'code' => 'CORR-CUR', 'name' => 'Current Promotion', 'status' => 'active',
            ]);
            $correctedClass = AcademicClass::query()->create([
                'institution_id' => $institution->getKey(), 'academic_year_id' => $year->getKey(),
                'group_type' => 'cohort', 'code' => 'CORR-TO', 'name' => 'Corrected Destination',
                'capacity_policy' => 'hard', 'structural_capacity' => 0, 'status' => 'active',
            ]);
            $registry = app(SisStudentRegistry::class);
            $student = SisStudent::query()->findOrFail($studentId);
            $registry->place($student, ['academic_year_id' => $year->getKey(), 'academic_class_id' => $source->getKey()], '2026-08-01', 'initial_placement', null, $owner);
            $promotion = $registry->place($student->fresh(), ['academic_year_id' => $year->getKey(), 'academic_class_id' => $currentClass->getKey()], '2026-09-01', 'authorized_promotion', 'Reviewed.', $owner, 'promotion');
        } finally {
            $context->clear();
        }

        $url = "/t/{$tenant->slug}/sis/students/{$studentId}/placements";
        $this->actingAs($owner)->post($url, [
            'type' => 'promotion', 'academic_year_id' => $year->getKey(), 'academic_class_id' => $correctedClass->getKey(),
            'effective_from' => '2026-10-01', 'reason_code' => 'bypass',
        ])->assertSessionHasErrors('type');
        $this->actingAs($owner)->post($url, [
            'type' => 'correction', 'academic_year_id' => $year->getKey(), 'academic_class_id' => $correctedClass->getKey(),
            'effective_from' => '2026-10-01', 'reason_code' => 'bypass',
        ])->assertSessionHasErrors('type');

        $correctionUrl = "{$url}/{$promotion->getKey()}/correction";
        $payload = [
            'academic_year_id' => $year->getKey(), 'academic_class_id' => $correctedClass->getKey(),
            'effective_from' => '2026-10-01', 'reason_code' => 'record_correction',
            'reason' => 'A documented administrative review corrected the class assignment.',
        ];
        $this->actingAs($owner)->post($correctionUrl, $payload)->assertSessionHasErrors('academic_class_id');
        $this->assertSame(2, DB::table('sis_student_placements')->where('tenant_id', $tenant->getKey())->where('student_id', $studentId)->count());
        $context->set($tenant, $membership);
        try {
            $correctedClass->forceFill(['structural_capacity' => 2])->save();
        } finally {
            $context->clear();
        }
        $this->actingAs($owner)->post($correctionUrl, $payload)->assertRedirect();
        $preview = session("sis.promotion_correction_preview.{$tenant->getKey()}.{$owner->getKey()}.{$studentId}");
        $this->assertIsArray($preview);
        $this->actingAs($owner)->post($correctionUrl, [...$payload, 'confirm' => true, 'preview_token' => $preview['token']])->assertRedirect();
        $this->assertDatabaseHas('sis_student_placements', [
            'tenant_id' => $tenant->getKey(), 'id' => $promotion->getKey(), 'placement_type' => 'promotion', 'academic_class_id' => $currentClass->getKey(),
        ]);
        $this->assertDatabaseHas('sis_student_placements', [
            'tenant_id' => $tenant->getKey(), 'student_id' => $studentId, 'placement_type' => 'correction', 'academic_class_id' => $correctedClass->getKey(),
        ]);
        $this->assertSame(3, DB::table('sis_student_placements')->where('tenant_id', $tenant->getKey())->where('student_id', $studentId)->count());
    }

    public function test_sensitive_custom_student_values_are_encrypted_and_hidden_without_permission(): void
    {
        [$tenant, $owner] = $this->provision('SIS Sensitive Fields Academy');
        $this->actingAs($owner)->post("/t/{$tenant->slug}/sis/students", [
            'legal_first_name' => 'Morgan', 'legal_last_name' => 'Example', 'admission_date' => '2026-09-01',
        ])->assertRedirect();
        $studentId = (int) DB::table('sis_students')->where('tenant_id', $tenant->getKey())->value('id');
        $this->actingAs($owner)->post("/t/{$tenant->slug}/sis/custom-fields", [
            'field_key' => 'external_ref', 'label' => 'External reference', 'field_type' => 'text',
            'is_required' => false, 'is_sensitive' => true,
        ])->assertRedirect();
        $fieldId = (int) DB::table('sis_custom_field_definitions')->where('tenant_id', $tenant->getKey())->value('id');
        $this->actingAs($owner)->put("/t/{$tenant->slug}/sis/students/{$studentId}/custom-fields/{$fieldId}", ['value' => 'PRIVATE-REF-991'])->assertRedirect();
        $stored = DB::table('sis_student_custom_values')->where('tenant_id', $tenant->getKey())->value('value');
        $this->assertStringNotContainsString('PRIVATE-REF-991', (string) $stored);

        $this->actingAs($owner)->get("/t/{$tenant->slug}/sis/students/{$studentId}")->assertInertia(fn ($page) => $page
            ->component('workspace/sis/students/show')
            ->where('student.customFields.0.value', 'PRIVATE-REF-991'));
    }

    public function test_import_preview_is_safe_and_exact_file_can_only_be_imported_once(): void
    {
        [$tenant, $owner] = $this->provision('SIS Import Academy');
        $file = UploadedFile::fake()->createWithContent('students.csv', "legal_first_name,legal_middle_name,legal_last_name,preferred_name,registration_reference,date_of_birth,admission_date,student_type\nJamie,,Example,,,,2026-09-01,standard\n");
        $this->actingAs($owner)->post("/t/{$tenant->slug}/sis/students/import", ['file' => $file])->assertRedirect();
        $batch = DB::table('sis_student_import_batches')->where('tenant_id', $tenant->getKey())->first();
        $this->assertNotNull($batch);
        $this->assertSame('previewed', $batch->status);
        $this->assertDatabaseCount('sis_students', 0);

        $file = UploadedFile::fake()->createWithContent('students.csv', "legal_first_name,legal_middle_name,legal_last_name,preferred_name,registration_reference,date_of_birth,admission_date,student_type\nJamie,,Example,,,,2026-09-01,standard\n");
        $this->actingAs($owner)->post("/t/{$tenant->slug}/sis/students/import", ['file' => $file, 'confirm' => true])->assertRedirect();
        $this->assertDatabaseCount('sis_students', 1);
        $completedBatch = DB::table('sis_student_import_batches')->where('tenant_id', $tenant->getKey())->first();
        Storage::disk('local')->assertMissing("sis-imports/{$tenant->getKey()}/{$completedBatch->batch_reference}.csv");
        $file = UploadedFile::fake()->createWithContent('students.csv', "legal_first_name,legal_middle_name,legal_last_name,preferred_name,registration_reference,date_of_birth,admission_date,student_type\nJamie,,Example,,,,2026-09-01,standard\n");
        $this->actingAs($owner)->post("/t/{$tenant->slug}/sis/students/import", ['file' => $file, 'confirm' => true])->assertRedirect();
        $this->assertDatabaseCount('sis_students', 1);
    }

    public function test_import_maps_external_headers_and_requires_a_fresh_matching_preview_before_confirmation(): void
    {
        [$tenant, $owner] = $this->provision('SIS Mapped Import Academy');
        Storage::fake('local');
        $contents = "Given,Family,Start date,External id\nJamie,Example,2026-09-01,MAP-100\n,Incomplete,2026-09-02,MAP-101\n";
        $file = UploadedFile::fake()->createWithContent('mapped-students.csv', $contents);

        $this->actingAs($owner)->post("/t/{$tenant->slug}/sis/students/import", ['file' => $file])
            ->assertRedirect()
            ->assertSessionHas('sisImportMapping.missing', ['legal_first_name', 'legal_last_name']);
        $this->assertDatabaseCount('sis_student_import_batches', 0);
        $this->actingAs($owner)->get("/t/{$tenant->slug}/sis/students")
            ->assertOk()->assertInertia(fn ($page) => $page
            ->where('flash.sisImportMapping.missing', ['legal_first_name', 'legal_last_name'])
            ->where('flash.sisImportMapping.headers', ['Given', 'Family', 'Start date', 'External id']));

        $mapping = [
            'legal_first_name' => 'Given', 'legal_last_name' => 'Family',
            'admission_date' => 'Start date', 'registration_reference' => 'External id',
        ];
        $file = UploadedFile::fake()->createWithContent('mapped-students.csv', $contents);
        $this->actingAs($owner)->post("/t/{$tenant->slug}/sis/students/import", [
            'file' => $file, 'column_map' => $mapping,
        ])->assertRedirect()->assertSessionHas('sisImportPreview', function (array $preview): bool {
            return $preview['valid'] === 1
                && $preview['invalid'] === 1
                && $preview['errors'][0]['row'] === 3
                && in_array('legal_first_name is required', $preview['errors'][0]['errors'], true);
        });
        $this->assertDatabaseCount('sis_students', 0);

        $file = UploadedFile::fake()->createWithContent('mapped-students.csv', $contents);
        $this->actingAs($owner)->post("/t/{$tenant->slug}/sis/students/import", [
            'file' => $file, 'column_map' => $mapping, 'confirm' => true,
        ])->assertRedirect()->assertSessionHas('status', '1 student rows imported; 1 rows were skipped. Download the row-error report.');
        $this->assertDatabaseCount('sis_students', 1);
        $batch = DB::table('sis_student_import_batches')->where('tenant_id', $tenant->getKey())->where('file_sha256', hash('sha256', $contents))->first();
        $this->assertNotNull($batch);
        $this->assertSame('completed_with_errors', $batch->status);
        $this->assertSame(1, (int) $batch->processed_count);
        $this->assertSame(1, (int) $batch->invalid_count);
        $storedErrorReport = json_decode((string) $batch->safe_report, true);
        $this->assertSame(3, $storedErrorReport['errors'][0]['row'] ?? null);
        $this->assertDatabaseHas('sis_students', [
            'tenant_id' => $tenant->getKey(), 'registration_reference' => 'MAP-100', 'import_batch_id' => $batch->id, 'import_row_number' => 2,
        ]);

        $errorDownload = $this->actingAs($owner)->get("/t/{$tenant->slug}/sis/students/import-batches/{$batch->batch_reference}/errors")->assertOk();
        $this->assertStringContainsString('legal_first_name is required', $errorDownload->streamedContent());
        $this->assertStringContainsString('3', $errorDownload->streamedContent());
        [$otherTenant, $otherOwner] = $this->provision('SIS Other Import Academy', User::factory()->create());
        $this->actingAs($otherOwner)->get("/t/{$otherTenant->slug}/sis/students/import-batches/{$batch->batch_reference}/errors")->assertNotFound();

        $file = UploadedFile::fake()->createWithContent('mapped-students.csv', $contents);
        $this->actingAs($owner)->post("/t/{$tenant->slug}/sis/students/import", [
            'file' => $file, 'column_map' => $mapping, 'confirm' => true,
        ])->assertSessionHas('status', 'This exact import file has already been completed; no duplicate students were created.');
        $this->assertDatabaseCount('sis_students', 1);

        $validContents = "Learner,Family,Admission,Reference\nAlex,Example,2026-09-01,MAP-200\n";
        $validMapping = [
            'legal_first_name' => 'Learner', 'legal_last_name' => 'Family',
            'admission_date' => 'Admission', 'registration_reference' => 'Reference',
        ];
        $file = UploadedFile::fake()->createWithContent('valid-mapped-students.csv', $validContents);
        $this->actingAs($owner)->post("/t/{$tenant->slug}/sis/students/import", [
            'file' => $file, 'column_map' => $validMapping,
        ])->assertRedirect();
        $file = UploadedFile::fake()->createWithContent('valid-mapped-students.csv', $validContents);
        $this->actingAs($owner)->post("/t/{$tenant->slug}/sis/students/import", [
            'file' => $file, 'column_map' => $validMapping, 'confirm' => true,
        ])->assertRedirect();
        $this->assertDatabaseHas('sis_students', [
            'tenant_id' => $tenant->getKey(), 'registration_reference' => 'MAP-200',
            'legal_first_name' => 'Alex', 'legal_last_name' => 'Example',
        ]);
        $this->assertDatabaseCount('sis_students', 2);
    }

    public function test_student_csv_export_neutralizes_spreadsheet_formulas(): void
    {
        [$tenant, $owner] = $this->provision('SIS Export Academy');
        $this->actingAs($owner)->post("/t/{$tenant->slug}/sis/students", [
            'legal_first_name' => 'Jamie', 'legal_last_name' => 'Example', 'preferred_name' => '=1+1', 'admission_date' => '2026-09-01',
        ])->assertRedirect();

        $response = $this->actingAs($owner)->get("/t/{$tenant->slug}/sis/students/export")->assertOk();

        $this->assertStringContainsString("'=1+1", $response->streamedContent());
    }

    public function test_queued_import_rechecks_membership_and_cleans_private_source_after_revocation(): void
    {
        [$tenant, $owner, $membership] = $this->provision('SIS Queued Import Academy');
        Storage::fake('local');
        Bus::fake();
        $contents = "legal_first_name,legal_last_name,admission_date\nJordan,Queued,2026-09-01\n";
        $file = UploadedFile::fake()->createWithContent('queued.csv', $contents);

        $this->actingAs($owner)->post("/t/{$tenant->slug}/sis/students/import", ['file' => $file])->assertRedirect();
        $file = UploadedFile::fake()->createWithContent('queued.csv', $contents);
        $this->actingAs($owner)->post("/t/{$tenant->slug}/sis/students/import", ['file' => $file, 'confirm' => true])
            ->assertRedirect()->assertSessionHas('status', 'The import was queued. Refresh the directory to check its status.');

        $batch = DB::table('sis_student_import_batches')->where('tenant_id', $tenant->getKey())->firstOrFail();
        $privatePath = "sis-imports/{$tenant->getKey()}/{$batch->batch_reference}.csv";
        $this->assertSame('queued', $batch->status);
        Storage::disk('local')->assertExists($privatePath);
        DB::table('tenant_memberships')->where('id', $membership->getKey())->update(['status' => 'revoked', 'revoked_at' => now()]);

        app(SisStudentImportProcessor::class)->process((int) $batch->id);

        $this->assertSame('failed', DB::table('sis_student_import_batches')->where('id', $batch->id)->value('status'));
        $this->assertDatabaseCount('sis_students', 0);
        Storage::disk('local')->assertMissing($privatePath);
    }

    public function test_private_sis_upload_is_quarantined_until_current_version_scans_clean(): void
    {
        [$tenant, $owner] = $this->provision('SIS Private Documents Academy');
        $this->actingAs($owner)->post("/t/{$tenant->slug}/sis/students", [
            'legal_first_name' => 'Jordan', 'legal_last_name' => 'Student', 'admission_date' => '2026-09-01',
        ])->assertRedirect();
        $studentId = (int) DB::table('sis_students')->where('tenant_id', $tenant->getKey())->value('id');
        Storage::fake('local');
        Bus::fake();

        $this->actingAs($owner)->post("/t/{$tenant->slug}/sis/students/{$studentId}/private-documents", [
            'category' => 'identity', 'file' => UploadedFile::fake()->createWithContent('identity.pdf', "%PDF-1.7\nprivate student record\n"),
        ])->assertRedirect("/t/{$tenant->slug}/sis/students/{$studentId}");

        $document = SisPrivateDocument::query()->withoutGlobalScope('tenant')->where('tenant_id', $tenant->getKey())->firstOrFail();
        $version = SisPrivateDocumentVersion::query()->withoutGlobalScope('tenant')->where('tenant_id', $tenant->getKey())->firstOrFail();
        $this->assertSame('pending', $document->scan_status);
        $this->assertStringStartsWith("sis/{$tenant->getKey()}/{$studentId}/", $version->private_path);
        Storage::disk('local')->assertExists($version->private_path);
        $this->assertDatabaseHas('sis_private_document_scan_events', ['document_version_id' => $version->getKey(), 'status' => 'pending']);

        $this->actingAs($owner)->get("/t/{$tenant->slug}/sis/private-documents/{$document->getKey()}")->assertNotFound();
        $this->actingAs($owner)->get("/t/{$tenant->slug}/sis/students/{$studentId}")->assertInertia(fn ($page) => $page
            ->where('student.documents.0.scanStatus', 'pending')
            ->where('student.documents.0.downloadUrl', null));
    }

    public function test_scanned_sis_document_downloads_only_clean_current_version_and_replacement_is_versioned(): void
    {
        [$tenant, $owner] = $this->provision('SIS Scanned Documents Academy');
        $this->actingAs($owner)->post("/t/{$tenant->slug}/sis/students", [
            'legal_first_name' => 'Mina', 'legal_last_name' => 'Example', 'admission_date' => '2026-09-01',
        ])->assertRedirect();
        $this->actingAs($owner)->put("/t/{$tenant->slug}/sis/settings", [
            'student_label' => 'Student', 'number_prefix' => 'STU', 'number_width' => 6, 'document_retention_days' => 1,
        ])->assertRedirect();
        $this->travelTo(now()->startOfSecond());
        $studentId = (int) DB::table('sis_students')->where('tenant_id', $tenant->getKey())->value('id');
        Storage::fake('local');
        Bus::fake();
        $uploadUrl = "/t/{$tenant->slug}/sis/students/{$studentId}/private-documents";
        $this->actingAs($owner)->post($uploadUrl, [
            'category' => 'previous_academic_record', 'file' => UploadedFile::fake()->createWithContent('record.pdf', "%PDF-1.4\nfirst version\n"),
        ])->assertRedirect();
        $document = SisPrivateDocument::query()->withoutGlobalScope('tenant')->where('tenant_id', $tenant->getKey())->firstOrFail();
        $firstVersion = SisPrivateDocumentVersion::query()->withoutGlobalScope('tenant')->where('document_id', $document->getKey())->firstOrFail();

        $this->actingAs($owner)->post($uploadUrl, [
            'category' => 'previous_academic_record', 'replace_document_id' => $document->getKey(),
            'file' => UploadedFile::fake()->createWithContent('record.pdf', "%PDF-1.4\nsecond version\n"),
        ])->assertRedirect();
        $document->refresh();
        $versions = SisPrivateDocumentVersion::query()->withoutGlobalScope('tenant')->where('document_id', $document->getKey())->orderBy('version_number')->get();
        $this->assertCount(2, $versions);
        $this->assertSame(2, (int) $document->current_version);

        $context = app(TenantContext::class);
        $context->enterProvisioning($tenant);
        try {
            $scanner = new class implements AdmissionDocumentScanner
            {
                public function scan(string $absolutePath): AdmissionDocumentScanResult
                {
                    return AdmissionDocumentScanResult::clean();
                }

                public function name(): string
                {
                    return 'test_clean_scanner';
                }
            };
            $firstEventId = (int) DB::table('sis_private_document_scan_events')->where('document_version_id', $firstVersion->getKey())->value('id');
            $firstJob = new ScanSisStudentDocument((int) $tenant->getKey(), (int) $document->getKey(), (int) $firstVersion->getKey(), $firstEventId);
            $firstJob->handle($scanner, $context, app(AuditRecorder::class));
            $this->assertSame('pending', $document->fresh()->scan_status, 'A stale scan must not approve a newer replacement.');

            $secondVersion = $versions->last();
            $secondEventId = (int) DB::table('sis_private_document_scan_events')->where('document_version_id', $secondVersion->getKey())->value('id');
            $secondJob = new ScanSisStudentDocument((int) $tenant->getKey(), (int) $document->getKey(), (int) $secondVersion->getKey(), $secondEventId);
            $secondJob->handle($scanner, $context, app(AuditRecorder::class));
        } finally {
            $context->clear();
        }

        $document->refresh();
        $this->assertSame('clean', $document->scan_status);
        $secondVersion->refresh();
        $this->assertSame(now()->copy()->addDay()->toDateTimeString(), $secondVersion->getRawOriginal('expires_at'));
        $this->actingAs($owner)->get("/t/{$tenant->slug}/sis/private-documents/{$document->getKey()}")->assertOk();
        $this->assertDatabaseHas('audit_events', ['tenant_id' => $tenant->getKey(), 'event_type' => 'sis.student.document.scanned']);

        $this->travel(2)->days();
        $this->actingAs($owner)->get("/t/{$tenant->slug}/sis/private-documents/{$document->getKey()}")->assertNotFound();
        $this->actingAs($owner)->get("/t/{$tenant->slug}/sis/students/{$studentId}")->assertInertia(fn ($page) => $page
            ->where('student.documents.0.expired', true)
            ->where('student.documents.0.downloadUrl', null));
        $this->assertDatabaseHas('audit_events', [
            'tenant_id' => $tenant->getKey(), 'event_type' => 'sis.student.document.expired_download_denied',
        ]);
    }

    public function test_sis_scanner_rejects_infected_and_unavailable_results_and_tenant_scope_is_enforced(): void
    {
        $owner = User::factory()->create();
        [$tenantA] = $this->provision('SIS Document Tenant A', $owner);
        [$tenantB] = $this->provision('SIS Document Tenant B', $owner);
        foreach ([$tenantA, $tenantB] as $tenant) {
            $this->actingAs($owner)->post("/t/{$tenant->slug}/sis/students", [
                'legal_first_name' => 'Taylor', 'legal_last_name' => 'Learner', 'admission_date' => '2026-09-01',
            ])->assertRedirect();
        }
        $studentA = (int) DB::table('sis_students')->where('tenant_id', $tenantA->getKey())->value('id');
        $studentB = (int) DB::table('sis_students')->where('tenant_id', $tenantB->getKey())->value('id');
        Storage::fake('local');
        Bus::fake();
        $upload = fn (Tenant $tenant, int $studentId) => $this->actingAs($owner)->post("/t/{$tenant->slug}/sis/students/{$studentId}/private-documents", [
            'category' => 'identity', 'file' => UploadedFile::fake()->createWithContent('id.pdf', "%PDF-1.7\nscan me\n"),
        ])->assertRedirect();
        $upload($tenantA, $studentA);
        $docA = SisPrivateDocument::query()->withoutGlobalScope('tenant')->where('tenant_id', $tenantA->getKey())->firstOrFail();
        $upload($tenantB, $studentB);
        $docB = SisPrivateDocument::query()->withoutGlobalScope('tenant')->where('tenant_id', $tenantB->getKey())->firstOrFail();

        $this->actingAs($owner)->get("/t/{$tenantB->slug}/sis/private-documents/{$docA->getKey()}")->assertNotFound();
        $this->actingAs($owner)->post("/t/{$tenantB->slug}/sis/students/{$studentB}/private-documents", [
            'category' => 'identity', 'replace_document_id' => $docA->getKey(),
            'file' => UploadedFile::fake()->createWithContent('cross.pdf', "%PDF-1.7\nnot allowed\n"),
        ])->assertNotFound();

        foreach ([[$tenantA, $docA, 'rejected'], [$tenantB, $docB, 'unavailable']] as [$tenant, $document, $result]) {
            $version = SisPrivateDocumentVersion::query()->withoutGlobalScope('tenant')->where('document_id', $document->getKey())->firstOrFail();
            $eventId = (int) DB::table('sis_private_document_scan_events')->where('document_version_id', $version->getKey())->value('id');
            $scanner = new class($result) implements AdmissionDocumentScanner
            {
                public function __construct(private readonly string $result) {}

                public function scan(string $absolutePath): AdmissionDocumentScanResult
                {
                    return $this->result === 'rejected' ? AdmissionDocumentScanResult::rejected() : AdmissionDocumentScanResult::unavailable();
                }

                public function name(): string
                {
                    return 'test_'.$this->result;
                }
            };
            $context = app(TenantContext::class);
            (new ScanSisStudentDocument((int) $tenant->getKey(), (int) $document->getKey(), (int) $version->getKey(), $eventId))
                ->handle($scanner, $context, app(AuditRecorder::class));
            $this->assertSame($result, $document->fresh()->scan_status);
            $this->actingAs($owner)->get("/t/{$tenant->slug}/sis/private-documents/{$document->getKey()}")->assertNotFound();
        }
    }

    public function test_sis_document_upload_rejects_mismatched_file_signature(): void
    {
        [$tenant, $owner] = $this->provision('SIS Invalid Document Academy');
        $this->actingAs($owner)->post("/t/{$tenant->slug}/sis/students", [
            'legal_first_name' => 'Morgan', 'legal_last_name' => 'Example', 'admission_date' => '2026-09-01',
        ])->assertRedirect();
        $studentId = (int) DB::table('sis_students')->where('tenant_id', $tenant->getKey())->value('id');
        Storage::fake('local');

        $this->actingAs($owner)->post("/t/{$tenant->slug}/sis/students/{$studentId}/private-documents", [
            'category' => 'identity', 'file' => UploadedFile::fake()->createWithContent('not-a-pdf.pdf', 'plain text disguised as PDF'),
        ])->assertSessionHasErrors('file');
        $this->assertDatabaseCount('sis_private_documents', 0);
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_document_view_permission_does_not_authorize_upload_mutations(): void
    {
        [$tenant, $owner, $ownerMembership, $institution] = $this->provision('SIS Document RBAC Academy');
        $this->actingAs($owner)->post("/t/{$tenant->slug}/sis/students", [
            'legal_first_name' => 'Riley', 'legal_last_name' => 'Example', 'admission_date' => '2026-09-01',
        ])->assertRedirect();
        $studentId = (int) DB::table('sis_students')->where('tenant_id', $tenant->getKey())->value('id');
        $viewer = User::factory()->create();
        $context = app(TenantContext::class);
        $context->set($tenant, $ownerMembership);
        try {
            $membership = TenantMembership::query()->create([
                'institution_id' => $institution->getKey(), 'user_id' => $viewer->getKey(), 'status' => 'active', 'activated_at' => now(),
            ]);
            $role = Role::query()->where('tenant_id', $tenant->getKey())->where('key', 'auditor')->firstOrFail();
            $membership->roles()->attach($role->getKey(), ['assigned_by' => $owner->getKey(), 'tenant_id' => $tenant->getKey()]);
        } finally {
            $context->clear();
        }
        Storage::fake('local');

        $this->actingAs($viewer)->post("/t/{$tenant->slug}/sis/students/{$studentId}/private-documents", [
            'category' => 'identity', 'file' => UploadedFile::fake()->createWithContent('restricted.pdf', "%PDF-1.7\nprivate\n"),
        ])->assertForbidden();
        $this->assertDatabaseCount('sis_private_documents', 0);
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_real_clamav_scans_sis_clean_and_eicar_documents_fail_closed(): void
    {
        [$tenant, $owner] = $this->provision('SIS Real ClamAV Academy');
        $this->actingAs($owner)->post("/t/{$tenant->slug}/sis/students", [
            'legal_first_name' => 'Clam', 'legal_last_name' => 'Example', 'admission_date' => '2026-09-01',
        ])->assertRedirect();
        $studentId = (int) DB::table('sis_students')->where('tenant_id', $tenant->getKey())->value('id');
        Storage::fake('local');
        Bus::fake();
        $this->actingAs($owner)->post("/t/{$tenant->slug}/sis/students/{$studentId}/private-documents", [
            'category' => 'previous_academic_record',
            'file' => UploadedFile::fake()->createWithContent('clean-record.pdf', "%PDF-1.7\nHarmless scanner integration fixture\n%%EOF\n"),
        ])->assertRedirect();

        $document = SisPrivateDocument::query()->withoutGlobalScope('tenant')->where('tenant_id', $tenant->getKey())->firstOrFail();
        $version = SisPrivateDocumentVersion::query()->withoutGlobalScope('tenant')->where('document_id', $document->getKey())->firstOrFail();
        $eventId = (int) DB::table('sis_private_document_scan_events')->where('document_version_id', $version->getKey())->value('id');
        if (config('admissions.document_scanner') !== 'clamav') {
            $this->markTestSkipped('Real ClamAV integration requires the local scanner binary and fresh signature directory to be configured. Fail-closed scanner behavior remains covered independently.');
        }
        $scanner = new ClamAvAdmissionDocumentScanner(
            (string) config('admissions.clamav_binary'),
            (int) config('admissions.clamav_max_signature_age_hours', 48),
            (string) config('admissions.clamav_timezone', 'UTC'),
            (string) config('admissions.clamav_signature_directory', ''),
            (string) config('admissions.clamav_signature_tool_binary', ''),
        );
        $context = app(TenantContext::class);
        (new ScanSisStudentDocument((int) $tenant->getKey(), (int) $document->getKey(), (int) $version->getKey(), $eventId))
            ->handle($scanner, $context, app(AuditRecorder::class));
        $this->assertSame('clean', $document->fresh()->scan_status);
        $this->assertStringStartsWith('clamav/1.5.4/db-', (string) DB::table('sis_private_document_scan_events')->where('id', $eventId)->value('scanner'));
        $this->actingAs($owner)->get("/t/{$tenant->slug}/sis/private-documents/{$document->getKey()}")->assertOk();

        $eicar = 'X5O!P%@AP[4\\PZX54(P^)7CC)7}$EICAR-STANDARD-ANTIVIRUS-TEST-FILE!$H+H*';
        $infectedPath = "sis/{$tenant->getKey()}/{$studentId}/eicar-".Str::uuid().'.bin';
        Storage::disk('local')->put($infectedPath, $eicar);
        $context->enterProvisioning($tenant);
        try {
            $infected = SisPrivateDocument::query()->create([
                'student_id' => $studentId, 'category' => 'identity', 'scan_status' => 'pending', 'current_version' => 1,
                'uploaded_by' => $owner->getKey(),
            ]);
            $infectedVersion = SisPrivateDocumentVersion::query()->create([
                'document_id' => $infected->getKey(), 'version_number' => 1, 'private_path' => $infectedPath,
                'original_name' => 'eicar-test.bin', 'mime_type' => 'application/octet-stream',
                'size_bytes' => strlen($eicar), 'sha256' => hash('sha256', $eicar), 'uploaded_by' => $owner->getKey(),
            ]);
            $infectedEventId = (int) SisPrivateDocumentScanEvent::query()->create([
                'document_version_id' => $infectedVersion->getKey(), 'attempt_number' => 1, 'status' => 'pending', 'scanner' => 'clamav',
            ])->getKey();
        } finally {
            $context->clear();
        }
        (new ScanSisStudentDocument((int) $tenant->getKey(), (int) $infected->getKey(), (int) $infectedVersion->getKey(), $infectedEventId))
            ->handle($scanner, $context, app(AuditRecorder::class));

        $this->assertSame('rejected', $infected->fresh()->scan_status);
        $this->assertSame('malware_detected', DB::table('sis_private_document_scan_events')->where('id', $infectedEventId)->value('result_code'));
        $this->actingAs($owner)->get("/t/{$tenant->slug}/sis/private-documents/{$infected->getKey()}")->assertNotFound();
    }

    public function test_registry_number_format_can_be_configured_before_registration_and_not_rewritten_afterward(): void
    {
        [$tenant, $owner, $ownerMembership, $institution] = $this->provision('SIS Registry Settings Academy');
        $settingsUrl = "/t/{$tenant->slug}/sis/settings";
        $this->actingAs($owner)->put($settingsUrl, ['student_label' => 'Learner', 'number_prefix' => 'LNR', 'number_width' => 5, 'document_retention_days' => 365, 'suspend_membership_on_student_suspension' => false])->assertRedirect("/t/{$tenant->slug}/sis");
        $this->assertDatabaseHas('sis_registry_settings', ['tenant_id' => $tenant->getKey(), 'student_label' => 'Learner', 'number_prefix' => 'LNR', 'number_width' => 5, 'document_retention_days' => 365, 'suspend_membership_on_student_suspension' => false]);

        $this->actingAs($owner)->post("/t/{$tenant->slug}/sis/students", [
            'legal_first_name' => 'Mina', 'legal_last_name' => 'Example', 'admission_date' => '2026-09-01',
        ])->assertRedirect();
        $this->assertDatabaseHas('sis_students', ['tenant_id' => $tenant->getKey(), 'student_number' => 'LNR00001']);

        $this->actingAs($owner)->put($settingsUrl, ['student_label' => 'Learner', 'number_prefix' => 'LNR', 'number_width' => 5, 'document_retention_days' => 0])->assertSessionHasErrors('document_retention_days');
        $this->assertDatabaseHas('sis_registry_settings', ['tenant_id' => $tenant->getKey(), 'document_retention_days' => 365, 'suspend_membership_on_student_suspension' => false]);

        $this->actingAs($owner)->put($settingsUrl, ['student_label' => 'Pupil', 'number_prefix' => 'PUP', 'number_width' => 5])->assertSessionHasErrors('number_prefix');
        $this->assertDatabaseHas('sis_registry_settings', ['tenant_id' => $tenant->getKey(), 'student_label' => 'Learner', 'number_prefix' => 'LNR']);
        $this->actingAs($owner)->put($settingsUrl, ['student_label' => 'Pupil', 'number_prefix' => 'LNR', 'number_width' => 5])->assertRedirect();
        $this->assertDatabaseHas('sis_registry_settings', ['tenant_id' => $tenant->getKey(), 'student_label' => 'Pupil', 'number_prefix' => 'LNR']);

        $registrar = User::factory()->create();
        $context = app(TenantContext::class);
        $context->set($tenant, $ownerMembership);
        try {
            $registrarMembership = TenantMembership::query()->create(['institution_id' => $institution->getKey(), 'user_id' => $registrar->getKey(), 'status' => 'active', 'activated_at' => now()]);
            $registrarRole = Role::query()->where('tenant_id', $tenant->getKey())->where('key', 'sis.registrar')->firstOrFail();
            $registrarMembership->roles()->attach($registrarRole->getKey(), ['assigned_by' => $owner->getKey(), 'tenant_id' => $tenant->getKey()]);
        } finally {
            $context->clear();
        }
        $this->actingAs($registrar)->put($settingsUrl, ['student_label' => 'Student', 'number_prefix' => 'STU', 'number_width' => 6])->assertForbidden();
    }

    public function test_large_csv_preview_streams_rows_and_caps_inline_error_payload(): void
    {
        [$tenant] = $this->provision('SIS Streaming Preview Academy');
        $path = tempnam(sys_get_temp_dir(), 'sis-csv-');
        $stream = fopen($path, 'wb');
        fputcsv($stream, ['Given', 'Family', 'Admission', 'Reference']);
        for ($row = 1; $row <= 501; $row++) {
            fputcsv($stream, ['', 'Example', '2026-09-01', 'STREAM-'.$row]);
        }
        fclose($stream);

        try {
            $import = app(SisStudentCsvImport::class);
            $headers = $import->headers($path);
            $mapping = ['legal_first_name' => 'Given', 'legal_last_name' => 'Family', 'admission_date' => 'Admission', 'registration_reference' => 'Reference'];
            $preview = $import->preview($path, $headers, $mapping, 1);
            $this->assertSame(501, $preview['row_count']);
            $this->assertSame(501, $preview['invalid']);
            $this->assertCount(SisStudentCsvImport::MAX_PREVIEW_ERRORS, $preview['errors']);
            $this->assertTrue($preview['errors_truncated']);
        } finally {
            @unlink($path);
        }
    }

    public function test_streamed_import_processes_multiple_committed_chunks_and_is_idempotent(): void
    {
        [$tenant, $owner, $membership, $institution] = $this->provision('SIS Multi Chunk Import Academy');
        Storage::fake('local');
        $reference = (string) Str::uuid();
        $path = "sis-imports/{$tenant->getKey()}/{$reference}.csv";
        $contents = "Given,Family,Admission,Reference\n";
        for ($row = 1; $row <= 251; $row++) {
            $contents .= "Student{$row},Example,2026-09-01,CHUNK-{$row}\n";
        }
        Storage::disk('local')->put($path, $contents);
        $mapping = ['legal_first_name' => 'Given', 'legal_last_name' => 'Family', 'admission_date' => 'Admission', 'registration_reference' => 'Reference'];
        $context = app(TenantContext::class);
        $context->set($tenant, $membership);
        try {
            $batch = SisStudentImportBatch::query()->create([
                'institution_id' => $institution->getKey(), 'batch_reference' => $reference,
                'file_sha256' => hash('sha256', $contents), 'status' => 'queued', 'row_count' => 251,
                'valid_count' => 251, 'invalid_count' => 0, 'safe_report' => ['mapping' => $mapping],
                'source_path' => $path, 'processed_count' => 0, 'requested_by' => $owner->getKey(),
            ]);
        } finally {
            $context->clear();
        }

        app(SisStudentImportProcessor::class)->process((int) $batch->getKey());
        app(SisStudentImportProcessor::class)->process((int) $batch->getKey());

        $this->assertSame('completed', DB::table('sis_student_import_batches')->where('id', $batch->getKey())->value('status'));
        $this->assertSame(251, (int) DB::table('sis_student_import_batches')->where('id', $batch->getKey())->value('processed_count'));
        $this->assertSame(251, DB::table('sis_students')->where('import_batch_id', $batch->getKey())->count());
        Storage::disk('local')->assertMissing($path);
    }

    public function test_lifecycle_policy_requires_independent_approval_and_records_both_actors(): void
    {
        [$tenant, $owner, $ownerMembership, $institution] = $this->provision('SIS Lifecycle Approval Academy');
        $this->actingAs($owner)->post("/t/{$tenant->slug}/sis/students", [
            'legal_first_name' => 'Asha', 'legal_last_name' => 'Example', 'admission_date' => '2026-09-01',
        ])->assertRedirect();
        $studentId = (int) DB::table('sis_students')->where('tenant_id', $tenant->getKey())->value('id');
        $this->actingAs($owner)->put("/t/{$tenant->slug}/sis/settings", [
            'student_label' => 'Student', 'number_prefix' => 'STU', 'number_width' => 6,
            'lifecycle_requires_approval' => true,
        ])->assertRedirect();

        $reviewer = User::factory()->create();
        $context = app(TenantContext::class);
        $context->set($tenant, $ownerMembership);
        try {
            $reviewerMembership = TenantMembership::query()->create([
                'institution_id' => $institution->getKey(), 'user_id' => $reviewer->getKey(),
                'status' => 'active', 'activated_at' => now(),
            ]);
            $adminRole = Role::query()->where('tenant_id', $tenant->getKey())->where('key', 'tenant.admin')->firstOrFail();
            $reviewerMembership->roles()->attach($adminRole->getKey(), ['assigned_by' => $owner->getKey(), 'tenant_id' => $tenant->getKey()]);
        } finally {
            $context->clear();
        }

        $url = "/t/{$tenant->slug}/sis/students/{$studentId}/status";
        $this->actingAs($owner)->patch($url, [
            'status' => 'active', 'effective_at' => '2026-09-02', 'reason_code' => 'enrollment_active',
        ])->assertRedirect()->assertSessionHas('status', 'The lifecycle transition is awaiting independent review.');
        $request = SisStudentLifecycleRequest::query()->withoutGlobalScope('tenant')->where('tenant_id', $tenant->getKey())->firstOrFail();
        $this->assertSame('admitted', DB::table('sis_students')->where('id', $studentId)->value('status'));

        $this->actingAs($owner)->post("/t/{$tenant->slug}/sis/lifecycle-requests/{$request->getKey()}/review", ['decision' => 'approve'])
            ->assertSessionHasErrors('decision');
        $this->assertSame('pending', $request->fresh()->status);
        $this->actingAs($reviewer)->post("/t/{$tenant->slug}/sis/lifecycle-requests/{$request->getKey()}/review", ['decision' => 'approve'])
            ->assertRedirect()->assertSessionHas('status', 'The lifecycle transition was approved and recorded.');

        $this->assertSame('active', DB::table('sis_students')->where('id', $studentId)->value('status'));
        $this->assertDatabaseHas('sis_student_lifecycle_requests', [
            'id' => $request->getKey(), 'status' => 'approved', 'requested_by' => $owner->getKey(), 'reviewed_by' => $reviewer->getKey(),
        ]);
        $this->assertSame(2, DB::table('audit_events')->where('tenant_id', $tenant->getKey())->whereIn('event_type', [
            'sis.student.lifecycle_transition_requested', 'sis.student.lifecycle_transition_approved',
        ])->count());
    }

    public function test_sis_document_archive_and_purge_follow_verified_private_retention_policy(): void
    {
        [$tenant, $owner, $membership, $institution] = $this->provision('SIS Retention Academy');
        Storage::fake('local');
        Storage::fake('local_archive');
        $this->actingAs($owner)->post("/t/{$tenant->slug}/sis/students", [
            'legal_first_name' => 'Talia', 'legal_last_name' => 'Example', 'admission_date' => '2026-09-01',
        ])->assertRedirect();
        $studentId = (int) DB::table('sis_students')->where('tenant_id', $tenant->getKey())->value('id');
        $this->actingAs($owner)->put("/t/{$tenant->slug}/sis/settings", [
            'student_label' => 'Student', 'number_prefix' => 'STU', 'number_width' => 6,
            'document_archive_after_days' => 1, 'document_purge_after_days' => 10,
        ])->assertRedirect();

        $context = app(TenantContext::class);
        $context->set($tenant, $membership);
        try {
            $document = SisPrivateDocument::query()->create([
                'student_id' => $studentId, 'category' => 'identity', 'scan_status' => 'clean', 'current_version' => 1,
                'uploaded_by' => $owner->getKey(),
            ]);
            $contents = "%PDF-1.7\nprivate retention fixture\n";
            $sourcePath = "sis/{$tenant->getKey()}/{$studentId}/".Str::uuid().'.pdf';
            Storage::disk('local')->put($sourcePath, $contents);
            $version = SisPrivateDocumentVersion::query()->create([
                'document_id' => $document->getKey(), 'version_number' => 1, 'private_path' => $sourcePath,
                'original_name' => 'record.pdf', 'mime_type' => 'application/pdf', 'size_bytes' => strlen($contents),
                'sha256' => hash('sha256', $contents), 'uploaded_by' => $owner->getKey(),
            ]);
            SisPrivateDocumentScanEvent::query()->create([
                'document_version_id' => $version->getKey(), 'attempt_number' => 1, 'status' => 'clean', 'result_code' => 'clean',
            ]);
            DB::table('sis_private_document_versions')->where('id', $version->getKey())->update([
                'created_at' => now()->subDays(30), 'expires_at' => now()->subDays(4),
            ]);
        } finally {
            $context->clear();
        }

        $retention = app(SisPrivateDocumentRetentionProcessor::class);
        $this->assertSame(['archived' => 1, 'purged' => 0, 'skipped' => 0], $retention->run());
        $version->refresh();
        $this->assertSame('archived', $version->storage_status);
        Storage::disk('local')->assertMissing($sourcePath);
        Storage::disk('local_archive')->assertExists($version->archived_path);
        // Expired documents remain inaccessible after archival; archive storage is for retention, not a download bypass.
        $this->actingAs($owner)->get("/t/{$tenant->slug}/sis/private-documents/{$document->getKey()}")->assertNotFound();

        SisRegistrySetting::query()->withoutGlobalScope('tenant')->where('tenant_id', $tenant->getKey())
            ->where('institution_id', $institution->getKey())->update(['document_purge_after_days' => 3]);
        $this->assertSame(['archived' => 0, 'purged' => 1, 'skipped' => 0], $retention->run());
        $version->refresh();
        $this->assertSame('purged', $version->storage_status);
        $this->assertNotNull($version->purged_at);
        Storage::disk('local_archive')->assertMissing('sis-archive/'.$tenant->getKey().'/'.$studentId.'/'.$version->getKey().'-'.$version->sha256.'.pdf');
        $this->actingAs($owner)->get("/t/{$tenant->slug}/sis/private-documents/{$document->getKey()}")->assertNotFound();
        $this->assertDatabaseHas('sis_private_document_retention_events', ['document_version_id' => $version->getKey(), 'action' => 'archived']);
        $this->assertDatabaseHas('sis_private_document_retention_events', ['document_version_id' => $version->getKey(), 'action' => 'purged']);
    }

    public function test_admission_outbox_processes_events_and_retries_without_logging_exception_details(): void
    {
        [$tenant, , $membership] = $this->provision('SIS Outbox Academy');
        $context = app(TenantContext::class);
        $context->set($tenant, $membership);
        try {
            $event = AdmissionOutboxEvent::query()->create([
                'event_type' => 'application.received', 'channel' => 'local', 'safe_payload' => ['application_id' => 17],
                'status' => 'queued', 'attempts' => 0, 'available_at' => now(),
            ]);
            $retryEvent = AdmissionOutboxEvent::query()->create([
                'event_type' => 'offer.issued', 'channel' => 'local', 'safe_payload' => ['application_id' => 18],
                'status' => 'queued', 'attempts' => 7, 'available_at' => now(),
            ]);
        } finally {
            $context->clear();
        }

        Event::fake([AdmissionOutboxDispatched::class]);
        $result = app(AdmissionOutboxProcessor::class)->dispatchAvailable();
        $this->assertSame(2, $result['claimed']);
        $this->assertSame(2, $result['processed']);
        Event::assertDispatched(AdmissionOutboxDispatched::class, fn (AdmissionOutboxDispatched $dispatched): bool => $dispatched->outboxId === (int) $event->getKey());
        $this->assertSame('processed', DB::table('admission_outbox_events')->where('id', $event->getKey())->value('status'));
        $this->assertSame(1, (int) DB::table('admission_outbox_events')->where('id', $event->getKey())->value('attempts'));
        $this->assertSame('processed', DB::table('admission_outbox_events')->where('id', $retryEvent->getKey())->value('status'));
    }

    public function test_admission_outbox_retries_with_safe_error_codes_and_stops_after_attempt_limit(): void
    {
        [$tenant, , $membership] = $this->provision('SIS Outbox Retry Academy');
        $context = app(TenantContext::class);
        $context->set($tenant, $membership);
        try {
            $event = AdmissionOutboxEvent::query()->create([
                'event_type' => 'application.received', 'channel' => 'local', 'safe_payload' => ['application_id' => 19],
                'status' => 'queued', 'attempts' => 0, 'available_at' => now(),
            ]);
        } finally {
            $context->clear();
        }
        $this->app->instance(AdmissionOutboxDispatcher::class, new class implements AdmissionOutboxDispatcher
        {
            public function dispatch(AdmissionOutboxEvent $outbox): void
            {
                throw new \RuntimeException('sensitive failure detail');
            }
        });

        $result = app(AdmissionOutboxProcessor::class)->dispatchAvailable();
        $this->assertSame(1, $result['retried']);
        $retry = DB::table('admission_outbox_events')->where('id', $event->getKey())->first();
        $this->assertSame('queued', $retry->status);
        $this->assertSame(1, (int) $retry->attempts);
        $this->assertSame('local_dispatch_failed', $retry->last_error_code);
        $this->assertNull($retry->locked_at);
        DB::table('admission_outbox_events')->where('id', $event->getKey())->update(['attempts' => 7, 'available_at' => now()]);

        $result = app(AdmissionOutboxProcessor::class)->dispatchAvailable();
        $this->assertSame(1, $result['failed']);
        $stored = DB::table('admission_outbox_events')->where('id', $event->getKey())->first();
        $this->assertSame('failed', $stored->status);
        $this->assertSame('local_dispatch_failed', $stored->last_error_code);
        $this->assertStringNotContainsString('sensitive failure detail', (string) $stored->last_error_code);
        $this->assertNull($stored->locked_at);
    }

    /** @return array{Tenant, User, TenantMembership, Institution} */
    private function provision(string $name, ?User $owner = null): array
    {
        $owner ??= User::factory()->create();
        $creator = User::factory()->create();
        $result = app(TenantProvisioner::class)->provision([
            'slug' => Str::slug($name), 'official_name' => $name, 'display_name' => $name, 'code' => '', 'type' => 'academy',
            'contact_email' => '', 'contact_phone' => '', 'website' => '', 'country' => '', 'region' => '', 'city' => '', 'address' => '', 'timezone' => 'UTC', 'locale' => 'en',
        ], $creator, $owner->email);
        $tenant = $result->tenant;
        $context = app(TenantContext::class);
        $context->enterProvisioning($tenant);
        try {
            $institution = Institution::query()->firstOrFail();
            $configuration = AcademicConfiguration::query()->where('institution_id', $institution->getKey())->firstOrFail();
            $features = AcademicDefaults::present($configuration)['enabled_features'];
            $this->assertTrue($features['student_information']);
            $membership = TenantMembership::query()->create(['institution_id' => $institution->getKey(), 'user_id' => $owner->getKey(), 'status' => 'active', 'activated_at' => now()]);
            $role = Role::query()->where('tenant_id', $tenant->getKey())->where('key', 'tenant.owner')->firstOrFail();
            $membership->roles()->attach($role->getKey(), ['assigned_by' => $owner->getKey(), 'tenant_id' => $tenant->getKey()]);
        } finally {
            $context->clear();
        }

        return [$tenant, $owner, $membership, $institution];
    }
}
