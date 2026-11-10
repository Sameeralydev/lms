<?php

namespace Tests\Feature;

use App\Domain\Academic\AcademicDefaults;
use App\Domain\Identity\PermissionCatalog;
use App\Domain\Tenancy\TenantContext;
use App\Domain\Tenancy\TenantProvisioner;
use App\Models\AcademicClass;
use App\Models\AcademicConfiguration;
use App\Models\AcademicCourse;
use App\Models\AcademicCourseVersion;
use App\Models\AcademicCreditPolicy;
use App\Models\AcademicLevel;
use App\Models\AcademicPolicyProfileVersion;
use App\Models\AcademicSubject;
use App\Models\AcademicYear;
use App\Models\Campus;
use App\Models\EducationQualityObjective;
use App\Models\Institution;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Tests\Support\GuardedRefreshDatabase;
use Tests\TestCase;

class AcademicFoundationTest extends TestCase
{
    use GuardedRefreshDatabase;

    public function test_provisioning_creates_configurable_academic_defaults_without_granting_creator_membership(): void
    {
        $owner = User::factory()->create();
        $tenant = $this->provision($owner, 'Northstar Academy');
        $institution = Institution::query()->withoutGlobalScope('tenant')->where('tenant_id', $tenant->getKey())->firstOrFail();
        $configuration = AcademicConfiguration::query()->withoutGlobalScope('tenant')->where('tenant_id', $tenant->getKey())->where('institution_id', $institution->getKey())->firstOrFail();

        $this->assertSame('academy', $configuration->operating_model);
        $this->assertSame('Training program', data_get($configuration->getAttribute('terminology'), 'program'));
        $this->assertDatabaseHas('academic_configurations', ['tenant_id' => $tenant->getKey(), 'institution_id' => $institution->getKey()]);
        $this->assertDatabaseMissing('tenant_memberships', ['tenant_id' => $tenant->getKey(), 'user_id' => $tenant->created_by]);
        $academicRole = Role::query()->where('tenant_id', $tenant->getKey())->where('key', 'academic.admin')->firstOrFail();
        $this->assertTrue($academicRole->permissions()->where('key', 'academic.calendar.manage')->exists());
    }

    public function test_legacy_migration_configuration_values_are_normalized_for_the_workspace(): void
    {
        $corporate = new AcademicConfiguration([
            'operating_model' => 'corporate',
            'enabled_features' => ['calendar' => true],
            'terminology' => ['group' => 'Training cycle'],
        ]);
        $hybrid = new AcademicConfiguration([
            'operating_model' => 'hybrid',
            'enabled_features' => ['calendar'],
            'terminology' => ['group' => 'Learning group'],
        ]);

        $corporateDefaults = AcademicDefaults::present($corporate);
        $hybridDefaults = AcademicDefaults::present($hybrid);

        $this->assertSame('corporate_learning', $corporateDefaults['operating_model']);
        $this->assertSame('custom', $hybridDefaults['operating_model']);
        $this->assertSame('Training cycle', $corporateDefaults['terminology']['class']);
        $this->assertTrue($corporateDefaults['enabled_features']['calendar']);
        $this->assertTrue($hybridDefaults['enabled_features']['calendar']);
    }

    public function test_calendar_lifecycle_validates_periods_and_records_audit_events(): void
    {
        $owner = User::factory()->create();
        $tenant = $this->provision($owner, 'Calendar Academy');
        $url = "/t/{$tenant->slug}/academics";

        $this->actingAs($owner)->post("{$url}/years", ['name' => '2026–27', 'code' => 'AY-2627', 'starts_on' => '2026-08-01', 'ends_on' => '2027-06-30', 'timezone' => 'Asia/Karachi', 'locale' => 'en']);
        $year = AcademicYear::query()->where('code', 'AY-2627')->firstOrFail();
        $this->actingAs($owner)->post("{$url}/years/{$year->getKey()}/periods", ['name' => 'Autumn term', 'code' => 'AUT', 'period_type' => 'term', 'sequence' => 1, 'starts_on' => '2026-08-01', 'ends_on' => '2026-12-20']);
        $period = $year->periods()->firstOrFail();

        $this->actingAs($owner)->patch("{$url}/periods/{$period->getKey()}/status", ['status' => 'active'])->assertSessionHasErrors('status');
        $this->actingAs($owner)->patch("{$url}/years/{$year->getKey()}/status", ['status' => 'active'])->assertRedirect();
        $this->actingAs($owner)->post("{$url}/years/{$year->getKey()}/current")->assertRedirect();
        $this->actingAs($owner)->patch("{$url}/periods/{$period->getKey()}/status", ['status' => 'active'])->assertRedirect();
        $this->actingAs($owner)->post("{$url}/periods/{$period->getKey()}/current")->assertRedirect();

        $this->assertDatabaseHas('academic_years', ['id' => $year->getKey(), 'status' => 'active', 'is_current' => true]);
        $this->assertDatabaseHas('academic_periods', ['id' => $period->getKey(), 'status' => 'active', 'is_current' => true]);
        $this->assertDatabaseHas('audit_events', ['tenant_id' => $tenant->getKey(), 'event_type' => 'academic.year.current_changed']);
    }

    public function test_academic_year_period_dates_and_overlap_are_rejected(): void
    {
        $owner = User::factory()->create();
        $tenant = $this->provision($owner, 'Dates Academy');
        $url = "/t/{$tenant->slug}/academics";
        $this->actingAs($owner)->post("{$url}/years", ['name' => 'Year A', 'code' => 'YA', 'starts_on' => '2026-08-01', 'ends_on' => '2027-06-30']);
        $year = AcademicYear::query()->where('code', 'YA')->firstOrFail();

        $this->actingAs($owner)->post("{$url}/years/{$year->getKey()}/periods", ['name' => 'Period A', 'code' => 'PA', 'period_type' => 'term', 'sequence' => 1, 'starts_on' => '2026-08-01', 'ends_on' => '2026-12-20'])->assertRedirect();
        $this->actingAs($owner)->post("{$url}/years/{$year->getKey()}/periods", ['name' => 'Overlapping Period', 'code' => 'PB', 'period_type' => 'term', 'sequence' => 2, 'starts_on' => '2026-12-01', 'ends_on' => '2027-04-01'])->assertSessionHasErrors('starts_on');
        $this->actingAs($owner)->post("{$url}/years/{$year->getKey()}/periods", ['name' => 'Outside Period', 'code' => 'PC', 'period_type' => 'term', 'sequence' => 3, 'starts_on' => '2027-07-01', 'ends_on' => '2027-08-01'])->assertSessionHasErrors('ends_on');
    }

    public function test_period_edits_preserve_before_and_after_values_in_the_audit_trail(): void
    {
        $owner = User::factory()->create();
        $tenant = $this->provision($owner, 'Period Edit Academy');
        $url = "/t/{$tenant->slug}/academics";
        $this->actingAs($owner)->post("{$url}/years", ['name' => 'Year Edit', 'code' => 'YE', 'starts_on' => '2026-08-01', 'ends_on' => '2027-06-30']);
        $year = AcademicYear::query()->where('tenant_id', $tenant->getKey())->where('code', 'YE')->firstOrFail();
        $this->actingAs($owner)->post("{$url}/years/{$year->getKey()}/periods", ['name' => 'Term One', 'code' => 'T1', 'period_type' => 'term', 'sequence' => 1, 'starts_on' => '2026-08-01', 'ends_on' => '2026-12-20']);
        $period = $year->periods()->firstOrFail();

        $this->actingAs($owner)->put("{$url}/periods/{$period->getKey()}", ['name' => 'Autumn Term', 'code' => 'AUT', 'period_type' => 'semester', 'sequence' => 1, 'starts_on' => '2026-08-01', 'ends_on' => '2026-12-21', 'teaching_starts_on' => '2026-08-10', 'teaching_ends_on' => '2026-12-10', 'assessment_starts_on' => null, 'assessment_ends_on' => null])->assertRedirect();
        $period->refresh();
        $this->assertSame('Autumn Term', $period->name);
        $audit = DB::table('audit_events')->where('tenant_id', $tenant->getKey())->where('event_type', 'academic.period.updated')->first();
        self::assertNotNull($audit);
        $metadata = json_decode((string) $audit->metadata, true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame('Term One', $metadata['before']['name']);
        $this->assertSame('Autumn Term', $metadata['after']['name']);
    }

    public function test_academic_level_hierarchy_rejects_multi_node_cycles(): void
    {
        $owner = User::factory()->create();
        $tenant = $this->provision($owner, 'Level Hierarchy Academy');
        $url = "/t/{$tenant->slug}/academics";
        $this->actingAs($owner)->post("{$url}/levels", ['name' => 'Foundation', 'code' => 'FND', 'category' => 'school', 'sequence' => 1]);
        $foundation = AcademicLevel::query()->where('tenant_id', $tenant->getKey())->where('code', 'FND')->firstOrFail();
        $this->actingAs($owner)->post("{$url}/levels", ['name' => 'Primary', 'code' => 'PRI', 'category' => 'school', 'sequence' => 2, 'parent_level_id' => $foundation->getKey()]);
        $primary = AcademicLevel::query()->where('tenant_id', $tenant->getKey())->where('code', 'PRI')->firstOrFail();

        $this->actingAs($owner)->put("{$url}/levels/{$foundation->getKey()}", ['name' => 'Foundation', 'code' => 'FND', 'category' => 'school', 'sequence' => 1, 'parent_level_id' => $primary->getKey()])->assertSessionHasErrors('parent_level_id');
        $this->assertDatabaseHas('academic_levels', ['id' => $foundation->getKey(), 'parent_level_id' => null]);
    }

    public function test_another_tenant_academic_record_cannot_be_updated_by_id(): void
    {
        $owner = User::factory()->create();
        $tenantA = $this->provision($owner, 'Tenant A Academy');
        $tenantB = $this->provision($owner, 'Tenant B Academy');
        $this->actingAs($owner)->post("/t/{$tenantB->slug}/academics/years", ['name' => 'Tenant B Year', 'code' => 'TB-Y1', 'starts_on' => '2026-01-01', 'ends_on' => '2026-12-31']);
        $foreign = AcademicYear::query()->where('code', 'TB-Y1')->firstOrFail();

        $this->actingAs($owner)->put("/t/{$tenantA->slug}/academics/years/{$foreign->getKey()}", ['name' => 'Attempted change', 'code' => 'TB-Y1', 'starts_on' => '2026-01-01', 'ends_on' => '2026-12-31'])->assertNotFound();
        $this->assertDatabaseHas('academic_years', ['id' => $foreign->getKey(), 'tenant_id' => $tenantB->getKey(), 'name' => 'Tenant B Year']);
    }

    public function test_credit_policy_and_course_version_use_exact_credit_arithmetic_and_explicit_publication(): void
    {
        $owner = User::factory()->create();
        $tenant = $this->provision($owner, 'Catalog Academy');
        $this->enableFeatures($tenant, ['credits']);
        $url = "/t/{$tenant->slug}/academics";
        $this->actingAs($owner)->post("{$url}/levels", ['name' => 'Level 1', 'code' => 'L1', 'category' => 'vocational', 'sequence' => 1]);
        $level = AcademicLevel::query()->withoutGlobalScope('tenant')->where('tenant_id', $tenant->getKey())->where('code', 'L1')->firstOrFail();
        $this->actingAs($owner)->post("{$url}/credit-policies", ['policy_key' => 'ACADEMY-CREDIT', 'name' => 'Academy credit basis', 'credit_hours_enabled' => true, 'separate_theory_lab' => true, 'allowed_increment' => '0.250', 'maximum_credits' => '6.000']);
        $policy = AcademicCreditPolicy::query()->withoutGlobalScope('tenant')->where('tenant_id', $tenant->getKey())->where('policy_key', 'ACADEMY-CREDIT')->firstOrFail();
        $this->actingAs($owner)->post("{$url}/credit-policies/{$policy->getKey()}/activate")->assertRedirect();
        $this->actingAs($owner)->post("{$url}/subjects", ['code' => 'MATH', 'name' => 'Applied Mathematics', 'category' => 'core', 'academic_level_id' => $level->getKey()]);
        $subject = AcademicSubject::query()->withoutGlobalScope('tenant')->where('tenant_id', $tenant->getKey())->where('code', 'MATH')->firstOrFail();
        $this->actingAs($owner)->post("{$url}/courses", ['code' => 'MATH-101', 'title' => 'Quantitative Foundations', 'academic_subject_id' => $subject->getKey(), 'academic_level_id' => $level->getKey()]);
        $course = AcademicCourse::query()->withoutGlobalScope('tenant')->where('tenant_id', $tenant->getKey())->where('code', 'MATH-101')->firstOrFail();

        $baseVersion = ['title' => $course->title, 'credit_bearing' => true, 'theory_credits' => '1.250', 'laboratory_credits' => '0.250', 'credit_policy_id' => $policy->getKey(), 'delivery_modes' => ['on_campus']];
        $this->actingAs($owner)->post("{$url}/courses/{$course->getKey()}/versions", [...$baseVersion, 'theory_credits' => '1.200'])->assertSessionHasErrors('theory_credits');
        $this->actingAs($owner)->post("{$url}/courses/{$course->getKey()}/versions", $baseVersion)->assertSessionHasNoErrors()->assertRedirect();
        $version = AcademicCourseVersion::query()->withoutGlobalScope('tenant')->where('tenant_id', $tenant->getKey())->where('academic_course_id', $course->getKey())->firstOrFail();
        $this->assertSame('1.500', $version->total_credits);
        $this->assertSame('draft', $version->status);
        $this->actingAs($owner)->post("{$url}/course-versions/{$version->getKey()}/publish")->assertRedirect();
        $this->assertDatabaseHas('academic_course_versions', ['id' => $version->getKey(), 'status' => 'published', 'is_current' => true]);
        $this->assertDatabaseHas('audit_events', ['tenant_id' => $tenant->getKey(), 'event_type' => 'academic.course_version.published']);
        $this->actingAs($owner)->post("{$url}/courses/{$course->getKey()}/retire")->assertRedirect();
        $this->assertDatabaseHas('academic_courses', ['id' => $course->getKey(), 'status' => 'retired']);
        $this->assertDatabaseHas('academic_course_versions', ['id' => $version->getKey(), 'status' => 'published', 'is_current' => true]);
        $this->assertDatabaseHas('audit_events', ['tenant_id' => $tenant->getKey(), 'event_type' => 'academic.course.retired']);
        $this->actingAs($owner)->post("{$url}/courses/{$course->getKey()}/retire")->assertStatus(409);
        $this->actingAs($owner)->post("{$url}/courses/{$course->getKey()}/versions", $baseVersion)->assertStatus(409);
    }

    public function test_policy_profile_review_needs_dated_authority_evidence_and_a_mapped_requirement(): void
    {
        $owner = User::factory()->create();
        $tenant = $this->provision($owner, 'Policy Academy');
        $url = "/t/{$tenant->slug}/academics";
        $this->actingAs($owner)->post("{$url}/policies", ['code' => 'REG-BASE', 'name' => 'Regional baseline', 'country' => 'GB', 'authority_name' => 'Education Authority', 'academic_framework' => 'Framework reference', 'credit_hours_applicable' => false]);
        $profileVersion = AcademicPolicyProfileVersion::query()->withoutGlobalScope('tenant')->where('tenant_id', $tenant->getKey())->firstOrFail();
        $this->actingAs($owner)->post("{$url}/policy-versions/{$profileVersion->getKey()}/evidence", ['source_title' => 'Public framework', 'source_authority' => 'Education Authority', 'source_url' => 'https://authority.example.test/framework', 'source_published_on' => '2025-02-01', 'retrieved_on' => '2026-10-22']);
        $evidenceId = DB::table('academic_policy_evidence')->value('id');
        $this->actingAs($owner)->post("{$url}/policy-versions/{$profileVersion->getKey()}/verify")->assertSessionHasErrors('version');
        $this->actingAs($owner)->post("{$url}/policy-versions/{$profileVersion->getKey()}/mappings", ['evidence_id' => $evidenceId, 'requirement_reference' => 'A.1', 'policy_area' => 'Calendar', 'requirement_summary' => 'The institution documents its calendar structure.', 'software_capability' => 'Configurable academic periods', 'mapping_status' => 'mapped']);
        $this->actingAs($owner)->post("{$url}/policy-versions/{$profileVersion->getKey()}/verify")->assertRedirect();

        $this->assertDatabaseHas('academic_policy_profile_versions', ['id' => $profileVersion->getKey(), 'review_status' => 'verified']);
        $this->assertDatabaseHas('academic_configurations', ['active_policy_profile_version_id' => $profileVersion->getKey()]);
        $this->assertDatabaseHas('audit_events', ['tenant_id' => $tenant->getKey(), 'event_type' => 'academic.policy_profile.evidence_reviewed']);
    }

    public function test_academic_pages_are_membership_scoped_and_auditors_cannot_mutate_calendar(): void
    {
        $owner = User::factory()->create();
        $tenant = $this->provision($owner, 'Auditor Academy');
        $auditor = User::factory()->create();
        $this->makeMembership($tenant, $auditor, 'auditor');
        $url = "/t/{$tenant->slug}/academics";

        $this->actingAs($owner)->post("{$url}/years", ['name' => 'Audit year', 'code' => 'AUDIT-Y', 'starts_on' => '2026-08-01', 'ends_on' => '2027-06-30'])->assertRedirect();
        $this->actingAs($auditor)->get($url)->assertOk()->assertInertia(fn ($page) => $page->component('workspace/academics/index'));
        $this->actingAs($auditor)->get("{$url}/audit")->assertOk()->assertInertia(fn ($page) => $page
            ->component('workspace/academics/audit')
            ->has('events.data', 1)
            ->where('events.data.0.event_type', 'academic.year.created'));
        $this->actingAs($auditor)->post("{$url}/years", ['name' => 'No Access', 'code' => 'NO-ACCESS', 'starts_on' => '2026-01-01', 'ends_on' => '2026-12-31'])->assertForbidden();
        $this->assertDatabaseMissing('academic_years', ['tenant_id' => $tenant->getKey(), 'code' => 'NO-ACCESS']);
    }

    public function test_campus_scoped_academic_membership_cannot_read_or_mutate_other_campus_records(): void
    {
        $owner = User::factory()->create();
        $tenant = $this->provision($owner, 'Campus Isolation Academy');
        $url = "/t/{$tenant->slug}";
        $this->actingAs($owner)->post("{$url}/campuses", ['code' => 'NORTH', 'name' => 'North Campus', 'is_primary' => true])->assertRedirect();
        $this->actingAs($owner)->post("{$url}/campuses", ['code' => 'SOUTH', 'name' => 'South Campus'])->assertRedirect();
        $north = Campus::query()->where('code', 'NORTH')->firstOrFail();
        $south = Campus::query()->where('code', 'SOUTH')->firstOrFail();
        $academicUrl = "{$url}/academics";

        foreach ([[$north, 'NORTH-YEAR'], [$south, 'SOUTH-YEAR']] as [$campus, $code]) {
            $this->actingAs($owner)->post("{$academicUrl}/years", ['campus_id' => $campus->getKey(), 'name' => $code, 'code' => $code, 'starts_on' => '2026-08-01', 'ends_on' => '2027-06-30'])->assertRedirect();
            $this->actingAs($owner)->post("{$academicUrl}/classes", ['campus_id' => $campus->getKey(), 'group_type' => 'grade', 'code' => $code.'-CLASS', 'name' => $code.' class', 'capacity_policy' => 'advisory'])->assertRedirect();
        }
        $northYear = AcademicYear::query()->where('code', 'NORTH-YEAR')->firstOrFail();
        $southYear = AcademicYear::query()->where('code', 'SOUTH-YEAR')->firstOrFail();
        $southClass = AcademicClass::query()->where('campus_id', $south->getKey())->firstOrFail();
        $campusAdmin = User::factory()->create();
        $this->makeMembership($tenant, $campusAdmin, 'academic.admin', (int) $north->getKey());

        $this->actingAs($campusAdmin)->get($academicUrl)->assertNotFound();
        $this->actingAs($campusAdmin)->get("{$academicUrl}/catalog")->assertNotFound();
        $this->actingAs($campusAdmin)->get("{$academicUrl}/policies")->assertNotFound();
        $this->actingAs($campusAdmin)->get("{$academicUrl}/quality")->assertNotFound();
        $this->actingAs($campusAdmin)->get("{$academicUrl}/calendar")->assertOk()->assertInertia(fn ($page) => $page
            ->component('workspace/academics/calendar')
            ->where('campusScope', $north->getKey())
            ->has('years.data', 1)
            ->where('years.data.0.code', 'NORTH-YEAR')
            ->has('campuses', 1));
        $this->actingAs($campusAdmin)->get("{$academicUrl}/structure")->assertOk()->assertInertia(fn ($page) => $page
            ->has('classes.data', 1)
            ->where('classes.data.0.code', 'NORTH-YEAR-CLASS')
            ->where('campusScope', $north->getKey()));

        $this->actingAs($campusAdmin)->put("{$academicUrl}/years/{$southYear->getKey()}", ['campus_id' => $north->getKey(), 'name' => 'Changed', 'code' => 'SOUTH-YEAR', 'starts_on' => '2026-08-01', 'ends_on' => '2027-06-30'])->assertNotFound();
        $this->actingAs($campusAdmin)->patch("{$academicUrl}/years/{$southYear->getKey()}/status", ['status' => 'active'])->assertNotFound();
        $this->actingAs($campusAdmin)->put("{$academicUrl}/classes/{$southClass->getKey()}", ['campus_id' => $north->getKey(), 'group_type' => 'grade', 'code' => 'CHANGED', 'name' => 'Changed', 'capacity_policy' => 'advisory'])->assertNotFound();
        $this->actingAs($campusAdmin)->post("{$academicUrl}/years", ['campus_id' => $south->getKey(), 'name' => 'Unauthorized', 'code' => 'NO-CAMPUS', 'starts_on' => '2026-08-01', 'ends_on' => '2027-06-30'])->assertNotFound();
        $this->actingAs($campusAdmin)->post("{$academicUrl}/years", ['name' => 'No Campus', 'code' => 'NO-CAMPUS-NULL', 'starts_on' => '2026-08-01', 'ends_on' => '2027-06-30'])->assertNotFound();
        $this->actingAs($campusAdmin)->post("{$academicUrl}/levels", ['name' => 'Unauthorized', 'code' => 'NO-LEVEL', 'category' => 'school', 'sequence' => 1])->assertNotFound();
        $this->actingAs($campusAdmin)->put("{$academicUrl}/years/{$northYear->getKey()}", ['campus_id' => $north->getKey(), 'name' => 'North Year Updated', 'code' => 'NORTH-YEAR', 'starts_on' => '2026-08-01', 'ends_on' => '2027-06-30'])->assertRedirect();

        $this->assertDatabaseHas('academic_years', ['id' => $southYear->getKey(), 'name' => 'SOUTH-YEAR']);
        $this->assertDatabaseMissing('academic_years', ['tenant_id' => $tenant->getKey(), 'code' => 'NO-CAMPUS']);
    }

    public function test_tenant_composite_key_constraints_reject_academic_records_with_foreign_institution_ids(): void
    {
        $ownerA = User::factory()->create();
        $ownerB = User::factory()->create();
        $tenantA = $this->provision($ownerA, 'Constraint A');
        $tenantB = $this->provision($ownerB, 'Constraint B');
        $institutionB = Institution::query()->withoutGlobalScope('tenant')->where('tenant_id', $tenantB->getKey())->firstOrFail();
        $context = app(TenantContext::class);
        $context->enterProvisioning($tenantA);

        try {
            DB::table('academic_years')->insert(['tenant_id' => $tenantA->getKey(), 'institution_id' => $institutionB->getKey(), 'name' => 'Cross tenant', 'code' => 'CROSS', 'starts_on' => '2026-01-01', 'ends_on' => '2026-12-31', 'timezone' => 'UTC', 'locale' => 'en', 'status' => 'draft', 'is_current' => false, 'publication_status' => 'draft', 'created_at' => now(), 'updated_at' => now()]);
            $this->fail('Composite academic ownership constraint should reject the mismatched institution.');
        } catch (QueryException) {
            $this->assertDatabaseMissing('academic_years', ['code' => 'CROSS']);
        } finally {
            $context->clear();
        }
    }

    public function test_controlled_documents_stay_private_and_require_independent_approval(): void
    {
        Storage::fake('local');
        $owner = User::factory()->create();
        $tenant = $this->provision($owner, 'Documents Academy');
        $this->enableFeatures($tenant, ['quality']);
        $approver = User::factory()->create();
        $this->makeMembership($tenant, $approver, 'academic.admin');
        $url = "/t/{$tenant->slug}/academics";
        $upload = UploadedFile::fake()->create('academic-policy.pdf', 20, 'application/pdf');
        $this->actingAs($owner)->post("{$url}/documents", ['document_type' => 'academic_policy', 'code' => 'ACA-POL-1', 'title' => 'Academic policy', 'summary' => 'Synthetic test document.', 'file' => $upload])->assertRedirect();
        $documentId = (int) DB::table('academic_documents')->value('id');
        $privatePath = (string) DB::table('academic_documents')->where('id', $documentId)->value('private_file_path');
        $this->assertTrue(Storage::disk('local')->exists($privatePath));
        $this->assertTrue(Storage::disk('public')->missing($privatePath));
        $this->actingAs($owner)->post("{$url}/documents/{$documentId}/submit")->assertRedirect();
        $this->actingAs($owner)->post("{$url}/documents/{$documentId}/decision", ['decision' => 'approved'])->assertSessionHasErrors('document');
        $this->actingAs($approver)->post("{$url}/documents/{$documentId}/decision", ['decision' => 'approved', 'comment' => 'Reviewed'])->assertRedirect();
        $this->actingAs($approver)->get("{$url}/documents/{$documentId}/download")->assertOk();
        $this->assertDatabaseHas('academic_documents', ['id' => $documentId, 'status' => 'approved']);
        $this->assertDatabaseHas('academic_document_approvals', ['academic_document_id' => $documentId, 'decision' => 'approved']);
    }

    public function test_quality_reviews_require_separation_of_duties_and_drive_audited_corrective_actions(): void
    {
        $owner = User::factory()->create();
        $tenant = $this->provision($owner, 'Quality Academy');
        $this->enableFeatures($tenant, ['quality']);
        $reviewer = User::factory()->create();
        $this->makeMembership($tenant, $reviewer, 'academic.admin');
        $url = "/t/{$tenant->slug}/academics";
        $this->actingAs($owner)->post("{$url}/quality/objectives", [
            'code' => 'QUALITY-01', 'name' => 'Improve learner support response', 'description' => 'Track institution-level response time.',
            'quality_area' => 'learner support', 'metric_definition' => 'Median response time in business hours.', 'metric_unit' => 'hours',
            'target_value' => '24.0000', 'review_every_months' => 3, 'next_review_on' => '2027-01-31',
        ])->assertRedirect();
        $objective = EducationQualityObjective::query()->withoutGlobalScope('tenant')->where('tenant_id', $tenant->getKey())->where('code', 'QUALITY-01')->firstOrFail();
        $this->actingAs($owner)->post("{$url}/quality/objectives/{$objective->getKey()}/activate")->assertRedirect();
        $this->actingAs($owner)->post("{$url}/quality/objectives/{$objective->getKey()}/reviews", ['decision' => 'not_met', 'measured_value' => '36.5000', 'summary' => 'Synthetic review evidence.'])->assertRedirect();
        $reviewId = (int) DB::table('education_quality_reviews')->where('quality_objective_id', $objective->getKey())->value('id');
        $this->actingAs($owner)->post("{$url}/quality/reviews/{$reviewId}/approve", ['approval_note' => 'Self approval is blocked.'])->assertSessionHasErrors('review');
        $this->actingAs($reviewer)->post("{$url}/quality/reviews/{$reviewId}/approve", ['approval_note' => 'Reviewed independently.'])->assertRedirect();
        $this->actingAs($owner)->post("{$url}/quality/objectives/{$objective->getKey()}/actions", ['code' => 'ACTION-01', 'title' => 'Review response process', 'description' => 'Document the service owner and escalation path.', 'due_on' => '2027-01-15'])->assertRedirect();
        $actionId = (int) DB::table('education_corrective_actions')->where('quality_objective_id', $objective->getKey())->value('id');
        $this->actingAs($owner)->patch("{$url}/quality/actions/{$actionId}", ['status' => 'in_progress'])->assertRedirect();
        $this->actingAs($owner)->post("{$url}/quality/actions/{$actionId}/close", ['resolution_note' => 'Process updated and reviewed.'])->assertRedirect();

        $this->assertDatabaseHas('education_quality_reviews', ['id' => $reviewId, 'decision' => 'not_met', 'approved_by' => $reviewer->getKey()]);
        $this->assertDatabaseHas('education_corrective_actions', ['id' => $actionId, 'status' => 'closed', 'closed_by' => $owner->getKey()]);
        $this->assertDatabaseHas('audit_events', ['tenant_id' => $tenant->getKey(), 'event_type' => 'academic.corrective_action.closed']);
    }

    public function test_academic_configuration_accepts_only_supported_operating_models_and_features(): void
    {
        $owner = User::factory()->create();
        $tenant = $this->provision($owner, 'Settings Academy');
        $institution = Institution::query()->withoutGlobalScope('tenant')->where('tenant_id', $tenant->getKey())->firstOrFail();
        $url = "/t/{$tenant->slug}/academics";
        $this->actingAs($owner)->put("{$url}/configuration", [
            'operating_model' => 'custom', 'timezone' => 'Europe/London', 'locale' => 'en-GB',
            'terminology' => ['class' => 'Cohort', 'period' => 'Module', 'course' => 'Learning unit'],
            'enabled_features' => ['calendar' => true, 'organization' => true, 'programs' => true, 'classes' => true, 'catalog' => true, 'credits' => false, 'quality' => true, 'student_information' => true],
        ])->assertRedirect();
        $this->assertDatabaseHas('academic_configurations', ['tenant_id' => $tenant->getKey(), 'institution_id' => $institution->getKey(), 'operating_model' => 'custom', 'timezone' => 'Europe/London']);
        $this->actingAs($owner)->put("{$url}/configuration", [
            'operating_model' => 'unsupported', 'timezone' => 'UTC', 'locale' => 'en', 'terminology' => [], 'enabled_features' => ['dangerous' => true],
        ])->assertSessionHasErrors('operating_model');
    }

    public function test_academic_feature_flags_gate_routes_and_credit_writes(): void
    {
        $owner = User::factory()->create();
        $tenant = $this->provision($owner, 'Feature Gate Academy');
        $url = "/t/{$tenant->slug}/academics";

        $this->actingAs($owner)->post("{$url}/courses", ['code' => 'NONCREDIT-1', 'title' => 'Non-credit course'])->assertRedirect();
        $course = AcademicCourse::query()->where('code', 'NONCREDIT-1')->firstOrFail();
        $this->actingAs($owner)->post("{$url}/courses/{$course->getKey()}/versions", [
            'title' => 'Credit version', 'credit_bearing' => true, 'theory_credits' => '1.000', 'laboratory_credits' => '0.000',
            'delivery_modes' => ['on_campus'],
        ])->assertSessionHasErrors('credit_bearing');
        $this->assertDatabaseMissing('academic_course_versions', ['academic_course_id' => $course->getKey()]);

        $features = ['calendar' => true, 'organization' => true, 'programs' => true, 'classes' => true, 'catalog' => false, 'credits' => false, 'quality' => false];
        $this->actingAs($owner)->put("{$url}/configuration", [
            'operating_model' => 'academy', 'timezone' => 'UTC', 'locale' => 'en',
            'terminology' => ['class' => 'Cohort', 'period' => 'Training cycle', 'course' => 'Course'],
            'enabled_features' => $features,
        ])->assertRedirect();

        $this->actingAs($owner)->get("{$url}/calendar")->assertOk()->assertInertia(fn ($page) => $page
            ->where('academicConfig.enabledFeatures.catalog', false)
            ->where('academicConfig.enabledFeatures.quality', false));
        $this->actingAs($owner)->get($url)->assertOk()->assertInertia(fn ($page) => $page
            ->where('metrics.courses', 0));
        $this->actingAs($owner)->get("{$url}/catalog")->assertNotFound();
        $this->actingAs($owner)->get("{$url}/policies")->assertNotFound();
        $this->actingAs($owner)->get("{$url}/quality")->assertNotFound();
        $this->actingAs($owner)->post("{$url}/subjects", ['code' => 'BLOCKED', 'name' => 'Blocked subject', 'category' => 'general'])->assertNotFound();
        $this->assertDatabaseMissing('academic_subjects', ['code' => 'BLOCKED']);
    }

    public function test_academic_permission_upgrade_backfills_system_roles_without_changing_custom_roles(): void
    {
        $owner = User::factory()->create();
        $tenant = $this->provision($owner, 'Permission Upgrade Academy');
        $academicKeys = array_keys(PermissionCatalog::tenantPermissions());
        $academicKeys = array_values(array_filter($academicKeys, static fn (string $key): bool => str_starts_with($key, 'academic.')
            && ! str_starts_with($key, 'academic.curriculum.')
            && ! in_array($key, ['academic.records.view-own', 'academic.records.guardian.view'], true)));
        Role::query()->where('tenant_id', $tenant->getKey())->whereIn('key', ['tenant.owner', 'tenant.admin', 'academic.admin', 'auditor'])->get()->each(fn (Role $role) => $role->permissions()->detach());
        $customRole = Role::query()->create(['tenant_id' => $tenant->getKey(), 'key' => 'custom.support', 'name' => 'Custom Support', 'scope' => 'tenant', 'description' => 'Synthetic custom role.', 'is_system' => false]);
        $customRole->permissions()->attach(Permission::query()->where('key', 'campus.view')->value('id'));

        $migration = require database_path('migrations/2026_10_22_000006_backfill_academic_permissions_for_existing_tenants.php');
        $migration->up();
        $gradingPermissions = require database_path('migrations/2026_11_06_000002_backfill_phase14_grading_permissions.php');
        $gradingPermissions->up();
        $recordPermissions = require database_path('migrations/2026_11_10_000005_add_official_record_permissions.php');
        $recordPermissions->up();

        $ownerRole = Role::query()->where('tenant_id', $tenant->getKey())->where('key', 'tenant.owner')->firstOrFail();
        $academicRole = Role::query()->where('tenant_id', $tenant->getKey())->where('key', 'academic.admin')->firstOrFail();
        $auditorRole = Role::query()->where('tenant_id', $tenant->getKey())->where('key', 'auditor')->firstOrFail();
        $studentRole = Role::query()->where('tenant_id', $tenant->getKey())->where('key', 'student')->firstOrFail();
        $guardianRole = Role::query()->where('tenant_id', $tenant->getKey())->where('key', 'parent.guardian')->firstOrFail();
        $this->assertCount(count($academicKeys), $ownerRole->permissions()->whereIn('key', $academicKeys)->get());
        $this->assertCount(count($academicKeys), $academicRole->permissions()->whereIn('key', $academicKeys)->get());
        $this->assertTrue($auditorRole->permissions()->where('key', 'academic.view')->exists());
        $this->assertFalse($auditorRole->permissions()->where('key', 'academic.calendar.manage')->exists());
        $this->assertTrue($studentRole->permissions()->where('key', 'academic.records.view-own')->exists());
        $this->assertTrue($guardianRole->permissions()->where('key', 'academic.records.guardian.view')->exists());
        $this->assertFalse($ownerRole->permissions()->whereIn('key', ['academic.records.view-own', 'academic.records.guardian.view'])->exists());
        $this->assertSame(['campus.view'], $customRole->permissions()->pluck('key')->all());
    }

    private function provision(User $owner, string $name): Tenant
    {
        $creator = User::factory()->create();
        $slug = Str::slug($name);
        $result = app(TenantProvisioner::class)->provision([
            'slug' => $slug, 'official_name' => $name, 'display_name' => $name, 'code' => '', 'type' => 'academy', 'contact_email' => '', 'contact_phone' => '', 'website' => '', 'country' => '', 'region' => '', 'city' => '', 'address' => '', 'timezone' => 'UTC', 'locale' => 'en',
        ], $creator, $owner->email);
        $acceptanceUrl = URL::temporarySignedRoute('invitations.accept', $result->ownerInvitation->expires_at, ['token' => $result->ownerInvitationToken]);
        $this->actingAs($owner)->post($acceptanceUrl)->assertRedirect("/t/{$result->tenant->slug}/dashboard");

        return $result->tenant;
    }

    /** @param list<string> $featureNames */
    private function enableFeatures(Tenant $tenant, array $featureNames): void
    {
        $configuration = AcademicConfiguration::query()->withoutGlobalScope('tenant')->where('tenant_id', $tenant->getKey())->firstOrFail();
        $features = AcademicDefaults::present($configuration)['enabled_features'];
        foreach ($featureNames as $featureName) {
            $features[$featureName] = true;
        }

        $configuration->forceFill(['enabled_features' => $features])->save();
    }

    private function makeMembership(Tenant $tenant, User $user, string $roleKey, ?int $campusId = null): TenantMembership
    {
        $context = app(TenantContext::class);
        $context->enterProvisioning($tenant);
        try {
            $institution = Institution::query()->firstOrFail();
            $membership = TenantMembership::query()->create(['institution_id' => $institution->getKey(), 'campus_id' => $campusId, 'user_id' => $user->getKey(), 'status' => 'active', 'activated_at' => now()]);
            $role = Role::query()->where('tenant_id', $tenant->getKey())->where('key', $roleKey)->firstOrFail();
            $membership->roles()->attach($role->getKey(), ['assigned_by' => $tenant->created_by, 'tenant_id' => $tenant->getKey()]);

            return $membership;
        } finally {
            $context->clear();
        }
    }
}
