<?php

namespace Tests\Feature;

use App\Domain\Admissions\AdmissionApplicationWorkflow;
use App\Domain\Admissions\AdmissionFormDefinition;
use App\Domain\Admissions\Documents\AdmissionDocumentScanner;
use App\Domain\Admissions\Documents\AdmissionDocumentScanResult;
use App\Domain\Admissions\Documents\ClamAvAdmissionDocumentScanner;
use App\Domain\Sis\AdmissionToSisHandoff;
use App\Domain\Tenancy\TenantContext;
use App\Domain\Tenancy\TenantProvisioner;
use App\Jobs\ScanAdmissionDocument;
use App\Mail\AdmissionGuardianInvitation;
use App\Models\AcademicConfiguration;
use App\Models\AdmissionDecision;
use App\Models\AdmissionDocument;
use App\Models\AdmissionDocumentRequirement;
use App\Models\AdmissionDocumentScanEvent;
use App\Models\AdmissionDocumentVersion;
use App\Models\AdmissionFormTemplate;
use App\Models\AdmissionFormVersion;
use App\Models\AdmissionGuardianAuthorization;
use App\Models\AdmissionInquiry;
use App\Models\AdmissionIntake;
use App\Models\AdmissionReviewerAssignment;
use App\Models\AdmissionSetting;
use App\Models\Institution;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\User;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\Support\GuardedRefreshDatabase;
use Tests\Support\Phase06ProbeEnvironment;
use Tests\TestCase;

class AdmissionsFoundationTest extends TestCase
{
    use GuardedRefreshDatabase;

    public function test_mysql_guardian_authorization_schema_has_required_indexes_and_foreign_keys(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            $this->markTestSkipped('This assertion verifies MySQL information_schema metadata.');
        }

        $indexes = DB::select(
            "SELECT INDEX_NAME, NON_UNIQUE, GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX SEPARATOR ',') AS columns_list
             FROM information_schema.STATISTICS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?
             GROUP BY INDEX_NAME, NON_UNIQUE",
            ['admission_guardian_authorizations'],
        );
        $indexesByName = collect($indexes)->keyBy('INDEX_NAME');

        foreach ([
            'adm_guardian_token_hash_uq' => ['token_hash', '0'],
            'adm_guardian_tenant_id_uq' => ['tenant_id,id', '0'],
            'adm_guardian_app_status_idx' => ['tenant_id,application_id,status', '1'],
            'adm_guardian_user_status_idx' => ['tenant_id,guardian_user_id,status', '1'],
            'adm_guardian_email_status_idx' => ['tenant_id,guardian_email,status', '1'],
        ] as $indexName => [$columns, $nonUnique]) {
            $this->assertTrue($indexesByName->has($indexName), "Missing MySQL index {$indexName}.");
            $this->assertSame($columns, (string) $indexesByName->get($indexName)->columns_list);
            $this->assertSame($nonUnique, (string) $indexesByName->get($indexName)->NON_UNIQUE);
        }

        $foreignKeys = DB::select(
            "SELECT CONSTRAINT_NAME, GROUP_CONCAT(COLUMN_NAME ORDER BY ORDINAL_POSITION SEPARATOR ',') AS columns_list,
                    REFERENCED_TABLE_NAME, GROUP_CONCAT(REFERENCED_COLUMN_NAME ORDER BY ORDINAL_POSITION SEPARATOR ',') AS referenced_columns
             FROM information_schema.KEY_COLUMN_USAGE
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND REFERENCED_TABLE_NAME IS NOT NULL
             GROUP BY CONSTRAINT_NAME, REFERENCED_TABLE_NAME",
            ['admission_guardian_authorizations'],
        );
        $foreignKeysByName = collect($foreignKeys)->keyBy('CONSTRAINT_NAME');
        $this->assertTrue($foreignKeysByName->has('adm_guardian_app_fk'));
        $applicationForeignKey = $foreignKeysByName->get('adm_guardian_app_fk');
        $this->assertSame('tenant_id,application_id', (string) $applicationForeignKey->columns_list);
        $this->assertSame('admission_applications', (string) $applicationForeignKey->REFERENCED_TABLE_NAME);
        $this->assertSame('tenant_id,id', (string) $applicationForeignKey->referenced_columns);

        foreach (['relationship_verified_by', 'revoked_by'] as $column) {
            $this->assertTrue(collect($foreignKeys)->contains(
                static fn (object $foreignKey): bool => $foreignKey->columns_list === $column && $foreignKey->REFERENCED_TABLE_NAME === 'users',
            ), "Missing user foreign key for {$column}.");
        }
    }

    public function test_provisioning_adds_disabled_admissions_defaults_and_supported_role_permissions(): void
    {
        [$tenant, $owner, $membership] = $this->provisionTenant('Northstar Admissions');
        $context = app(TenantContext::class);
        $context->set($tenant, $membership);

        try {
            $settings = AdmissionSetting::query()->firstOrFail();
            $this->assertFalse($settings->enabled);
            $this->assertFalse($settings->public_applications_enabled);
            $this->assertTrue($membership->hasPermission('admissions.intakes.manage'));
            $this->assertTrue($membership->hasPermission('admissions.decisions.approve'));
            $officer = Role::query()->where('tenant_id', $tenant->getKey())->where('key', 'admissions.officer')->firstOrFail();
            $this->assertTrue($officer->permissions()->where('key', 'admissions.decisions.recommend')->exists());
            $this->assertFalse($officer->permissions()->where('key', 'admissions.decisions.approve')->exists());
        } finally {
            $context->clear();
        }

        $this->assertSame((int) $tenant->getKey(), (int) $membership->tenant_id);
        $this->assertNotNull($owner->getKey());
    }

    public function test_applicant_draft_is_versioned_then_submission_is_idempotent(): void
    {
        [$tenant, $owner, $membership] = $this->provisionTenant('Cedar Admissions');
        $intake = $this->createOpenIntake($tenant, $membership, 'CEDAR-2027');
        $applicant = User::factory()->create();
        $context = app(TenantContext::class);
        $context->enterApplicant($tenant);

        try {
            $workflow = app(AdmissionApplicationWorkflow::class);
            $application = $workflow->createOrResume($intake, $applicant, [
                'given_name' => 'Jordan', 'family_name' => 'Rivera', 'birth_date' => '2007-03-10',
            ]);
            $workflow->saveDraft($application, $applicant, ['motivation' => 'A carefully considered application.', 'information_consent' => true], 2);
            $submitted = $workflow->submit($application->fresh(), $applicant);
            $again = $workflow->submit($submitted, $applicant);

            $this->assertSame('submitted', $submitted->status);
            $this->assertSame($submitted->getKey(), $again->getKey());
            $this->assertSame(2, $submitted->revisions()->count());
            $this->assertSame(1, $submitted->lifecycleEvents()->where('to_status', 'submitted')->count());
            $this->assertSame(1, $submitted->outboxEvents()->where('event_type', 'application.received')->count());
        } finally {
            $context->clear();
        }

        $this->assertDatabaseHas('admission_applications', ['tenant_id' => $tenant->getKey(), 'applicant_user_id' => $applicant->getKey(), 'status' => 'submitted']);
        $this->assertNotNull($owner->getKey());
    }

    public function test_minor_application_requires_verified_guardian_authority_and_revocation_removes_access(): void
    {
        Mail::fake();
        [$tenant, $owner, $membership] = $this->provisionTenant('Guardian Verification Admissions');
        $intake = $this->createOpenIntake($tenant, $membership, 'GUARDIAN-2027');
        $applicant = User::factory()->create(['email_verified_at' => now()]);
        $guardian = User::factory()->create(['email' => 'verified-guardian@example.test', 'email_verified_at' => now()]);
        $other = User::factory()->create(['email' => 'wrong-guardian@example.test', 'email_verified_at' => now()]);
        $context = app(TenantContext::class);
        $context->enterApplicant($tenant);
        try {
            $workflow = app(AdmissionApplicationWorkflow::class);
            $application = $workflow->createOrResume($intake, $applicant, [
                'given_name' => 'Taylor', 'birth_date' => now()->subYears(13)->toDateString(),
            ]);
            $workflow->saveDraft($application, $applicant, ['motivation' => 'A submitted application.', 'information_consent' => true], 2);
            try {
                $workflow->submit($application->fresh(), $applicant);
                $this->fail('A minor application must not submit without verified guardian authority.');
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('guardian', $exception->errors());
            }
        } finally {
            $context->clear();
        }

        $this->actingAs($applicant)->post('/admissions/'.$tenant->slug.'/applications/'.$application->application_number.'/guardians', [
            'guardian_name' => 'Casey Rivera', 'guardian_email' => 'verified-guardian@example.test', 'relationship' => 'parent',
        ])->assertRedirect();

        $invitationUrl = null;
        Mail::assertSent(AdmissionGuardianInvitation::class, function (AdmissionGuardianInvitation $mail) use (&$invitationUrl): bool {
            $invitationUrl = $mail->invitationUrl;

            return str_starts_with($invitationUrl, 'http');
        });
        $this->assertIsString($invitationUrl);
        $invitationToken = parse_url($invitationUrl, PHP_URL_FRAGMENT);
        $this->assertIsString($invitationToken);
        $this->actingAs($other)->get($invitationUrl)->assertOk();
        $this->actingAs($other)->post($invitationUrl, ['token' => $invitationToken])->assertNotFound();
        $this->actingAs($guardian)->get($invitationUrl)->assertOk()
            ->assertInertia(fn ($page) => $page->component('public/admissions/guardian-invitation'));
        $this->actingAs($guardian)->post($invitationUrl, ['token' => $invitationToken])->assertRedirect(route('public.admissions.applications.show', [
            'tenant' => $tenant->slug, 'applicationNumber' => $application->application_number,
        ]));

        $authorization = AdmissionGuardianAuthorization::query()->where('application_id', $application->getKey())->firstOrFail();
        $this->assertSame('guardian_verified', $authorization->status);
        $this->actingAs($guardian)->get('/admissions/'.$tenant->slug.'/applications/'.$application->application_number)->assertNotFound();
        $this->actingAs($applicant)->post('/admissions/'.$tenant->slug.'/applications/'.$application->application_number.'/submit')->assertSessionHasErrors('guardian');

        $this->actingAs($owner)->post(route('tenant.admissions.applications.guardians.verify', [
            'tenant' => $tenant->slug, 'applicationId' => $application->getKey(), 'authorizationId' => $authorization->getKey(),
        ]), ['verification_method' => 'document_review'])->assertRedirect();
        $this->assertSame('active', $authorization->fresh()->status);
        $this->assertNotNull($authorization->fresh()->relationship_verified_at);

        $this->actingAs($guardian)->get('/admissions/'.$tenant->slug.'/applications/'.$application->application_number)
            ->assertOk()->assertInertia(fn ($page) => $page->where('canManage', false));
        $this->actingAs($guardian)->put('/admissions/'.$tenant->slug.'/applications/'.$application->application_number, [
            'answers' => ['motivation' => 'Unauthorized edit', 'information_consent' => true], 'step' => 2,
        ])->assertNotFound();
        [$otherTenant, $otherOwner] = $this->provisionTenant('Foreign Guardian Admissions');
        $this->actingAs($guardian)->get('/admissions/'.$otherTenant->slug.'/applications/'.$application->application_number)->assertNotFound();
        $this->actingAs($otherOwner)->post(route('tenant.admissions.applications.guardians.verify', [
            'tenant' => $otherTenant->slug,
            'applicationId' => $application->getKey(),
            'authorizationId' => $authorization->getKey(),
        ]), ['verification_method' => 'document_review'])->assertNotFound();
        $this->actingAs($applicant)->post('/admissions/'.$tenant->slug.'/applications/'.$application->application_number.'/submit')->assertRedirect();

        $this->actingAs($guardian)->post('/admissions/'.$tenant->slug.'/applications/'.$application->application_number.'/guardians/'.$authorization->getKey().'/revoke')
            ->assertRedirect();
        $this->assertSame('revoked', $authorization->fresh()->status);
        $this->actingAs($guardian)->get('/admissions/'.$tenant->slug.'/applications/'.$application->application_number)->assertNotFound();
    }

    public function test_inquiry_directory_is_permission_gated_and_status_changes_are_audited_and_tenant_scoped(): void
    {
        [$tenant, $owner, $membership] = $this->provisionTenant('Inquiry Operations Admissions');
        $this->createOpenIntake($tenant, $membership, 'INQUIRY-2027');
        $context = app(TenantContext::class);
        $context->set($tenant, $membership);
        try {
            AdmissionSetting::query()->firstOrFail()->forceFill(['inquiries_enabled' => true])->save();
        } finally {
            $context->clear();
        }

        $this->post('/admissions/'.$tenant->slug.'/inquiries', [
            'name' => 'Synthetic Inquiry', 'email' => 'inquiry@example.test', 'intake_code' => 'INQUIRY-2027',
            'message' => 'Synthetic request for program details.', 'consent' => true,
        ])->assertRedirect();
        $inquiry = AdmissionInquiry::query()->where('email', 'inquiry@example.test')->firstOrFail();
        $this->actingAs($owner)->get('/t/'.$tenant->slug.'/admissions')
            ->assertOk()->assertInertia(fn ($page) => $page->where('canManageInquiries', true)
            ->where('inquiries.data.0.reference', $inquiry->reference)
            ->where('inquiries.data.0.status', 'new'));

        $statusUrl = route('tenant.admissions.inquiries.status', ['tenant' => $tenant->slug, 'inquiryId' => $inquiry->getKey()]);
        $this->actingAs($owner)->patch($statusUrl, ['status' => 'contacted'])->assertRedirect();
        $this->actingAs($owner)->patch($statusUrl, ['status' => 'qualified'])->assertRedirect();
        $inquiry->refresh();
        $this->assertSame('qualified', $inquiry->status);
        $this->assertSame((int) $owner->getKey(), (int) $inquiry->qualified_by);
        $this->assertNotNull($inquiry->qualified_at);
        $this->assertDatabaseHas('audit_events', [
            'tenant_id' => $tenant->getKey(), 'actor_user_id' => $owner->getKey(),
            'event_type' => 'admissions.inquiry.qualified', 'subject_id' => (string) $inquiry->getKey(),
        ]);
        $this->actingAs($owner)->patch($statusUrl, ['status' => 'contacted'])->assertStatus(409);

        [$otherTenant, $otherOwner] = $this->provisionTenant('Other Inquiry Tenant');
        $this->actingAs($otherOwner)->patch(route('tenant.admissions.inquiries.status', [
            'tenant' => $otherTenant->slug, 'inquiryId' => $inquiry->getKey(),
        ]), ['status' => 'closed'])->assertNotFound();
        $this->assertSame('qualified', $inquiry->fresh()->status);
    }

    public function test_public_admissions_is_disabled_by_default_and_tenant_routes_do_not_leak_applications(): void
    {
        [$tenantA, $ownerA, $membershipA] = $this->provisionTenant('Maple Admissions');
        [$tenantB, , $membershipB] = $this->provisionTenant('Harbor Admissions');
        $this->get('/admissions/'.$tenantA->slug)->assertNotFound();
        $this->createOpenIntake($tenantA, $membershipA, 'MAPLE-2027');
        $intakeB = $this->createOpenIntake($tenantB, $membershipB, 'HARBOR-2027');
        $applicant = User::factory()->create();
        $context = app(TenantContext::class);
        $context->enterApplicant($tenantA);
        try {
            $foreignCandidate = app(AdmissionApplicationWorkflow::class)->createOrResume($this->intake('MAPLE-2027'), $applicant, ['given_name' => 'Taylor']);
        } finally {
            $context->clear();
        }

        $this->actingAs($ownerA)->get('/t/'.$tenantA->slug.'/admissions')->assertOk()
            ->assertInertia(fn ($page) => $page->component('workspace/admissions/index'));
        $this->get('/admissions/'.$tenantA->slug)->assertOk()
            ->assertInertia(fn ($page) => $page->component('public/admissions/index'));
        $this->actingAs($ownerA)->get('/t/'.$tenantB->slug.'/admissions')->assertNotFound();
        $this->actingAs($applicant)->get('/admissions/'.$tenantB->slug.'/applications/'.$foreignCandidate->application_number)->assertNotFound();
        $this->assertSame('HARBOR-2027', $intakeB->code);
    }

    public function test_tenant_administrator_can_update_and_filter_only_scoped_intakes(): void
    {
        [$tenant, $owner, $membership] = $this->provisionTenant('Cobalt Admissions');
        $context = app(TenantContext::class);
        $context->set($tenant, $membership);
        try {
            AdmissionSetting::query()->firstOrFail()->forceFill(['enabled' => true])->save();
            $intake = AdmissionIntake::query()->create([
                'institution_id' => Institution::query()->firstOrFail()->getKey(),
                'code' => 'COBALT-FALL-2027', 'name' => 'Fall 2027', 'cycle' => 'Fall 2027',
                'status' => 'draft', 'delivery_mode' => 'on_campus', 'locale' => 'en', 'timezone' => 'UTC',
                'is_public' => false, 'capacity' => 30, 'reserved_capacity' => 4,
            ]);
            AcademicConfiguration::query()->firstOrFail()->forceFill(['enabled_features' => [
                'calendar' => true, 'organization' => true, 'programs' => true, 'classes' => true,
                'catalog' => true, 'credits' => false, 'quality' => false,
            ]])->save();
        } finally {
            $context->clear();
        }

        $this->actingAs($owner)->put(route('tenant.admissions.intakes.update', [
            'tenant' => $tenant->slug, 'intakeId' => $intake->getKey(),
        ]), [
            'name' => 'Fall 2027 Cohort', 'cycle' => 'Autumn 2027', 'academic_year_id' => null,
            'campus_id' => null, 'academic_program_id' => null, 'delivery_mode' => 'hybrid',
            'locale' => 'en', 'timezone' => 'UTC', 'applications_open_at' => null,
            'application_deadline' => now()->addMonth()->toDateTimeString(), 'documents_deadline' => null,
            'offer_deadline' => null, 'capacity' => 40, 'reserved_capacity' => 5,
            'application_fee_amount' => null, 'application_fee_currency' => null,
            'public_description' => 'Applications open this autumn.', 'is_public' => true,
            'eligibility_rules' => [],
        ])->assertRedirect(route('tenant.admissions.index', ['tenant' => $tenant->slug]));

        $this->assertDatabaseHas('admission_intakes', [
            'id' => $intake->getKey(), 'tenant_id' => $tenant->getKey(), 'code' => 'COBALT-FALL-2027',
            'name' => 'Fall 2027 Cohort', 'status' => 'draft', 'capacity' => 40,
        ]);
        $this->assertDatabaseHas('audit_events', [
            'tenant_id' => $tenant->getKey(), 'actor_user_id' => $owner->getKey(), 'event_type' => 'admissions.intake.updated',
        ]);

        $this->actingAs($owner)->get(route('tenant.admissions.index', [
            'tenant' => $tenant->slug, 'intake_q' => 'COBALT', 'intake_status' => 'draft',
        ]))->assertOk()->assertInertia(fn ($page) => $page->component('workspace/admissions/index')
            ->where('intakes.data.0.id', $intake->getKey())
            ->where('intakes.data.0.name', 'Fall 2027 Cohort')
            ->where('intakes.total', 1));
    }

    public function test_applicant_registration_requires_signed_email_verification_before_application_access(): void
    {
        Notification::fake();
        [$tenant, , $membership] = $this->provisionTenant('Verified Applicant Admissions');
        $this->createOpenIntake($tenant, $membership, 'VERIFIED-2027');
        $context = app(TenantContext::class);
        $context->set($tenant, $membership);

        try {
            AdmissionSetting::query()->firstOrFail()->forceFill(['registration_mode' => 'open_registration'])->save();
        } finally {
            $context->clear();
        }

        $email = 'new-applicant@example.test';
        $this->post('/admissions/'.$tenant->slug.'/register', [
            'name' => 'New Applicant', 'email' => $email, 'password' => 'A-Long-Test-Password-492!',
            'password_confirmation' => 'A-Long-Test-Password-492!',
        ])->assertRedirect(route('login'))
            ->assertSessionHas('status', 'If registration can be completed, check your email. Existing account holders can sign in to continue.');

        $user = User::query()->where('email', $email)->firstOrFail();
        $this->assertNull($user->email_verified_at);
        Notification::assertSentTo($user, VerifyEmail::class);

        $this->actingAs($user)->get('/admissions/'.$tenant->slug.'/my-applications')->assertRedirect(route('verification.notice'));
        $this->get(route('verification.notice'))->assertInertia(fn ($page) => $page->component('auth/verify-email')->where('email', $email));

        $verificationUrl = URL::temporarySignedRoute('verification.verify', now()->addMinutes(30), [
            'id' => $user->getKey(), 'hash' => sha1($user->getEmailForVerification()),
        ]);
        $this->get($verificationUrl)->assertRedirect(route('workspaces.index'));
        $this->assertNotNull($user->fresh()?->email_verified_at);
        $this->get($verificationUrl)->assertRedirect(route('workspaces.index'));
    }

    public function test_unscanned_private_applicant_upload_is_quarantined_and_download_is_denied(): void
    {
        Bus::fake();
        Storage::fake('local');
        [$tenant, , $membership] = $this->provisionTenant('Quarantine Admissions');
        $intake = $this->createOpenIntake($tenant, $membership, 'QUARANTINE-2027');
        $applicant = User::factory()->create(['email_verified_at' => now()]);
        $context = app(TenantContext::class);
        $context->enterProvisioning($tenant);
        try {
            $requirement = AdmissionDocumentRequirement::query()->create([
                'institution_id' => Institution::query()->firstOrFail()->getKey(),
                'intake_id' => $intake->getKey(), 'code' => 'transcript', 'name' => 'Transcript',
                'allowed_mime_types' => ['application/pdf'], 'maximum_bytes' => 1048576, 'is_required' => false,
            ]);
        } finally {
            $context->clear();
        }

        $context->enterApplicant($tenant);
        try {
            $application = app(AdmissionApplicationWorkflow::class)->createOrResume($intake, $applicant, ['given_name' => 'Quinn']);
        } finally {
            $context->clear();
        }

        $this->actingAs($applicant)->post('/admissions/'.$tenant->slug.'/applications/'.$application->application_number.'/documents/'.$requirement->code, [
            'file' => UploadedFile::fake()->createWithContent('student-transcript.pdf', "%PDF-1.7\nSynthetic fixture\n"),
        ])->assertRedirect();

        $document = AdmissionDocument::query()->where('application_id', $application->getKey())->firstOrFail();
        $this->assertSame('pending', $document->scan_status);
        $this->assertDatabaseHas('admission_document_scan_events', [
            'tenant_id' => $tenant->getKey(), 'document_version_id' => $document->versions()->firstOrFail()->getKey(),
            'attempt_number' => 1, 'status' => 'pending',
        ]);
        Bus::assertDispatched(ScanAdmissionDocument::class);

        $this->actingAs($applicant)->get('/admissions/'.$tenant->slug.'/applications/'.$application->application_number)
            ->assertOk()->assertInertia(fn ($page) => $page->where('documents.0.scanStatus', 'pending')->where('documents.0.downloadUrl', null));
        $this->actingAs($applicant)->get('/admissions/'.$tenant->slug.'/applications/'.$application->application_number.'/documents/'.$document->getKey())
            ->assertNotFound();
    }

    public function test_document_scanner_result_is_audited_and_stale_versions_never_unlock_downloads(): void
    {
        Storage::fake('local');
        [$tenant, , $membership] = $this->provisionTenant('Scanner Workflow Admissions');
        $intake = $this->createOpenIntake($tenant, $membership, 'SCANNER-2027');
        $applicant = User::factory()->create(['email_verified_at' => now()]);
        $context = app(TenantContext::class);
        $context->enterApplicant($tenant);
        try {
            $application = app(AdmissionApplicationWorkflow::class)->createOrResume($intake, $applicant, [
                'given_name' => 'Jordan', 'birth_date' => now()->subYears(20)->toDateString(),
            ]);
        } finally {
            $context->clear();
        }

        $path = 'admissions/'.$tenant->getKey().'/'.$application->getKey().'/'.Str::uuid().'.pdf';
        Storage::disk('local')->put($path, "%PDF-1.7\nSynthetic test fixture\n");
        $context->enterProvisioning($tenant);
        try {
            $document = AdmissionDocument::query()->create([
                'application_id' => $application->getKey(), 'requirement_id' => null,
                'uploaded_by' => $applicant->getKey(), 'status' => 'pending', 'scan_status' => 'pending', 'current_version' => 1,
            ]);
            $version = AdmissionDocumentVersion::query()->create([
                'document_id' => $document->getKey(), 'version_number' => 1, 'private_path' => $path,
                'original_name' => 'record.pdf', 'mime_type' => 'application/pdf', 'size_bytes' => 30,
                'sha256' => hash('sha256', "%PDF-1.7\nSynthetic test fixture\n"), 'uploaded_by' => $applicant->getKey(),
            ]);
            $event = AdmissionDocumentScanEvent::query()->create([
                'document_version_id' => $version->getKey(), 'attempt_number' => 1, 'status' => 'pending', 'scanner' => 'fake-test-scanner',
            ]);
        } finally {
            $context->clear();
        }

        $scanner = new class implements AdmissionDocumentScanner
        {
            public function scan(string $absolutePath): AdmissionDocumentScanResult
            {
                return AdmissionDocumentScanResult::clean();
            }

            public function name(): string
            {
                return 'fake-test-scanner';
            }
        };
        app()->instance(AdmissionDocumentScanner::class, $scanner);
        (new ScanAdmissionDocument((int) $tenant->getKey(), (int) $document->getKey(), (int) $version->getKey(), (int) $event->getKey()))
            ->handle($scanner, $context);

        $this->assertSame('clean', $event->fresh()->status);
        $this->assertSame('fake-test-scanner', $event->fresh()->scanner);
        $this->assertNotNull($event->fresh()->completed_at);
        $this->assertSame('clean', $document->fresh()->scan_status);

        $stalePath = 'admissions/'.$tenant->getKey().'/'.$application->getKey().'/'.Str::uuid().'.pdf';
        Storage::disk('local')->put($stalePath, "%PDF-1.7\nStale version fixture\n");
        $context->enterProvisioning($tenant);
        try {
            $staleDocument = AdmissionDocument::query()->create([
                'application_id' => $application->getKey(), 'requirement_id' => null,
                'uploaded_by' => $applicant->getKey(), 'status' => 'pending', 'scan_status' => 'pending', 'current_version' => 2,
            ]);
            $staleVersion = AdmissionDocumentVersion::query()->create([
                'document_id' => $staleDocument->getKey(), 'version_number' => 1, 'private_path' => $stalePath,
                'original_name' => 'old-record.pdf', 'mime_type' => 'application/pdf', 'size_bytes' => 28,
                'sha256' => hash('sha256', "%PDF-1.7\nStale version fixture\n"), 'uploaded_by' => $applicant->getKey(),
            ]);
            $staleEvent = AdmissionDocumentScanEvent::query()->create([
                'document_version_id' => $staleVersion->getKey(), 'attempt_number' => 1, 'status' => 'pending', 'scanner' => 'fake-test-scanner',
            ]);
        } finally {
            $context->clear();
        }
        (new ScanAdmissionDocument((int) $tenant->getKey(), (int) $staleDocument->getKey(), (int) $staleVersion->getKey(), (int) $staleEvent->getKey()))
            ->handle($scanner, $context);
        $this->assertSame('clean', $staleEvent->fresh()->status);
        $this->assertSame('pending', $staleDocument->fresh()->scan_status, 'A clean scan for a stale version must not unlock the current file.');
    }

    public function test_real_clamav_scans_private_admission_uploads_and_only_releases_clean_files(): void
    {
        $binary = (string) config('admissions.clamav_binary');
        $signatureDirectory = (string) config('admissions.clamav_signature_directory');
        if (DB::connection()->getDriverName() === 'sqlite'
            && (config('admissions.document_scanner') !== 'clamav' || ! is_file($binary) || ! is_dir($signatureDirectory))) {
            $this->markTestSkipped('The real ClamAV integration requires an explicitly configured local scanner; it is mandatory in the guarded MySQL acceptance environment.');
        }
        $this->assertSame('clamav', config('admissions.document_scanner'));
        $this->assertFileExists($binary);
        $this->assertDirectoryExists($signatureDirectory);

        Storage::fake('local');
        Bus::fake();
        [$tenant, , $membership] = $this->provisionTenant('Real ClamAV Admissions');
        $intake = $this->createOpenIntake($tenant, $membership, 'REAL-CLAMAV-2027');
        $applicant = User::factory()->create(['email_verified_at' => now()]);
        $context = app(TenantContext::class);
        $context->enterProvisioning($tenant);
        try {
            $requirement = AdmissionDocumentRequirement::query()->create([
                'institution_id' => Institution::query()->firstOrFail()->getKey(),
                'intake_id' => $intake->getKey(), 'code' => 'transcript', 'name' => 'Transcript',
                'allowed_mime_types' => ['application/pdf'], 'maximum_bytes' => 1048576, 'is_required' => false,
            ]);
        } finally {
            $context->clear();
        }

        $context->enterApplicant($tenant);
        try {
            $application = app(AdmissionApplicationWorkflow::class)->createOrResume($intake, $applicant, [
                'given_name' => 'Clam', 'birth_date' => now()->subYears(20)->toDateString(),
            ]);
        } finally {
            $context->clear();
        }

        $uploadUrl = route('public.admissions.applications.documents.upload', [
            'tenant' => $tenant->slug, 'applicationNumber' => $application->application_number, 'requirementCode' => $requirement->code,
        ]);
        $downloadUrl = fn (AdmissionDocument $document): string => route('public.admissions.applications.documents.download', [
            'tenant' => $tenant->slug, 'applicationNumber' => $application->application_number, 'documentId' => $document->getKey(),
        ]);

        $this->actingAs($applicant)->post($uploadUrl, [
            'file' => UploadedFile::fake()->create('unsupported.exe', 1, 'application/octet-stream'),
        ])->assertSessionHasErrors('file');
        $this->assertDatabaseCount('admission_documents', 0);

        $cleanContent = "%PDF-1.7\nHarmless ClamAV integration fixture\n%%EOF\n";
        $this->actingAs($applicant)->post($uploadUrl, [
            'file' => UploadedFile::fake()->createWithContent('clean-transcript.pdf', $cleanContent),
        ])->assertRedirect();

        $document = AdmissionDocument::query()->where('application_id', $application->getKey())->firstOrFail();
        $version = $document->versions()->firstOrFail();
        $event = AdmissionDocumentScanEvent::query()->where('document_version_id', $version->getKey())->firstOrFail();
        $scanner = app(AdmissionDocumentScanner::class);
        $this->assertInstanceOf(ClamAvAdmissionDocumentScanner::class, $scanner);
        (new ScanAdmissionDocument((int) $tenant->getKey(), (int) $document->getKey(), (int) $version->getKey(), (int) $event->getKey()))
            ->handle($scanner, $context);

        $cleanScanEvent = $event->fresh();
        $this->assertSame('clean', $cleanScanEvent->status, 'ClamAV result code: '.(string) $cleanScanEvent->result_code.'; scanner metadata: '.(string) $cleanScanEvent->scanner);
        $this->assertSame('clean', $document->fresh()->scan_status);
        $this->assertStringStartsWith('clamav/1.5.4/db-', (string) $event->fresh()->scanner);
        $this->assertNotNull($event->fresh()->completed_at);
        $this->assertDatabaseHas('audit_events', [
            'tenant_id' => $tenant->getKey(), 'actor_user_id' => $applicant->getKey(), 'event_type' => 'admissions.document.uploaded',
        ]);
        $this->actingAs($applicant)->get(route('public.admissions.applications.show', [
            'tenant' => $tenant->slug, 'applicationNumber' => $application->application_number,
        ]))->assertOk()->assertInertia(fn ($page) => $page->where('documents.0.scanStatus', 'clean')->where('documents.0.downloadUrl', $downloadUrl($document)));
        $this->actingAs($applicant)->get($downloadUrl($document))->assertOk();

        [$otherTenant] = $this->provisionTenant('Other Real ClamAV Tenant');
        $this->actingAs($applicant)->get(route('public.admissions.applications.documents.download', [
            'tenant' => $otherTenant->slug, 'applicationNumber' => $application->application_number, 'documentId' => $document->getKey(),
        ]))->assertNotFound();

        $eicar = 'X5O!P%@AP[4\\PZX54(P^)7CC)7}$EICAR-STANDARD-ANTIVIRUS-TEST-FILE!$H+H*';
        $infectedPath = 'admissions/'.$tenant->getKey().'/'.$application->getKey().'/'.Str::uuid().'.pdf';
        Storage::disk('local')->put($infectedPath, $eicar);
        $context->enterProvisioning($tenant);
        try {
            $infectedDocument = AdmissionDocument::query()->create([
                'application_id' => $application->getKey(), 'requirement_id' => null,
                'uploaded_by' => $applicant->getKey(), 'status' => 'pending', 'scan_status' => 'pending', 'current_version' => 1,
            ]);
            $infectedVersion = AdmissionDocumentVersion::query()->create([
                'document_id' => $infectedDocument->getKey(), 'version_number' => 1, 'private_path' => $infectedPath,
                'original_name' => 'eicar-test.pdf', 'mime_type' => 'application/pdf', 'size_bytes' => strlen($eicar),
                'sha256' => hash('sha256', $eicar), 'uploaded_by' => $applicant->getKey(),
            ]);
            $infectedEvent = AdmissionDocumentScanEvent::query()->create([
                'document_version_id' => $infectedVersion->getKey(), 'attempt_number' => 1,
                'status' => 'pending', 'scanner' => 'clamav',
            ]);
        } finally {
            $context->clear();
        }
        (new ScanAdmissionDocument((int) $tenant->getKey(), (int) $infectedDocument->getKey(), (int) $infectedVersion->getKey(), (int) $infectedEvent->getKey()))
            ->handle($scanner, $context);

        $this->assertSame('rejected', $infectedEvent->fresh()->status);
        $this->assertSame('malware_detected', $infectedEvent->fresh()->result_code);
        $this->assertSame('rejected', $infectedDocument->fresh()->scan_status);
        $this->actingAs($applicant)->get(route('public.admissions.applications.documents.download', [
            'tenant' => $tenant->slug, 'applicationNumber' => $application->application_number, 'documentId' => $infectedDocument->getKey(),
        ]))->assertNotFound();
    }

    public function test_admissions_decision_requires_independent_approval_before_offer(): void
    {
        [$tenant, $owner, $membership] = $this->provisionTenant('Independent Review Admissions');
        $intake = $this->createOpenIntake($tenant, $membership, 'INDEPENDENT-2027');
        $applicant = User::factory()->create();
        $context = app(TenantContext::class);
        $context->enterApplicant($tenant);

        try {
            $workflow = app(AdmissionApplicationWorkflow::class);
            $application = $workflow->createOrResume($intake, $applicant, ['given_name' => 'Avery', 'birth_date' => now()->subYears(20)->toDateString()]);
            $workflow->saveDraft($application, $applicant, ['motivation' => 'A suitable candidate.', 'information_consent' => true], 2);
            $application = $workflow->submit($application->fresh(), $applicant);
        } finally {
            $context->clear();
        }

        $context->set($tenant, $membership);
        try {
            $workflow = app(AdmissionApplicationWorkflow::class);
            $workflow->transition($application, 'under_review', $owner);
            $application = $workflow->transition($application->fresh(), 'decision_pending', $owner);
            $recommender = User::factory()->create();
            $recommendation = $workflow->recommendDecision($application, 'admit', $recommender, 'Meets criteria.', 'Internal review note.');

            $this->assertNull($recommendation->approved_at);
            $this->assertNull($recommendation->approved_by);
            $this->assertSame('decision_pending', $application->fresh()->status);

            try {
                $workflow->approveDecision($application, $recommendation, $recommender);
                $this->fail('A recommender must not approve their own recommendation.');
            } catch (ValidationException) {
                $this->assertNull($recommendation->fresh()->approved_at);
            }

            try {
                $workflow->issueOffer($application, $owner, null, null);
                $this->fail('An offer must not be issued from an unapproved recommendation.');
            } catch (ValidationException) {
                $this->assertSame('decision_pending', $application->fresh()->status);
            }

            $approved = $workflow->approveDecision($application, $recommendation, $owner);
            $this->assertSame((int) $owner->getKey(), (int) $approved->approved_by);
            $this->assertSame('admit', $approved->decision);
            $this->assertNotNull($approved->approved_at);

            $offer = $workflow->issueOffer($application->fresh(), $owner, 'Standard conditions.', now()->addDays(10));
            $this->assertSame((int) $approved->getKey(), (int) $offer->decision_id);
            $this->assertSame('offered', $application->fresh()->status);

            $context->clear();
            $context->enterApplicant($tenant);
            $acceptedOffer = $workflow->respondToOffer($offer->fresh(), $applicant, 'accepted');
            $this->assertSame('accepted', $acceptedOffer->status);

            $context->clear();
            $context->set($tenant, $membership);
            $firstHandoff = $workflow->createSisHandoff($application->fresh(), $owner);
            $secondHandoff = $workflow->createSisHandoff($application->fresh(), $owner);
            $this->assertSame((int) $firstHandoff->getKey(), (int) $secondHandoff->getKey());
            $this->assertSame('ready_for_sis_handoff', $application->fresh()->status);
            $this->assertDatabaseCount('admission_sis_handoffs', 1);
        } finally {
            $context->clear();
        }
    }

    public function test_approved_admissions_handoff_creates_one_student_and_links_the_student_role_idempotently(): void
    {
        [$tenant, $owner, $membership] = $this->provisionTenant('Admissions to SIS Conversion');
        $intake = $this->createOpenIntake($tenant, $membership, 'SIS-CONVERT-2027');
        $applicant = User::factory()->create();
        $context = app(TenantContext::class);
        $context->enterApplicant($tenant);
        try {
            $workflow = app(AdmissionApplicationWorkflow::class);
            $application = $workflow->createOrResume($intake, $applicant, [
                'given_name' => 'Morgan', 'middle_name' => 'Lee', 'family_name' => 'Student',
                'birth_date' => now()->subYears(18)->toDateString(),
            ]);
            $workflow->saveDraft($application, $applicant, ['motivation' => 'Ready for an approved conversion.', 'information_consent' => true], 2);
            $application = $workflow->submit($application->fresh(), $applicant);
        } finally {
            $context->clear();
        }

        $context->set($tenant, $membership);
        try {
            $workflow = app(AdmissionApplicationWorkflow::class);
            $workflow->transition($application, 'under_review', $owner);
            $application = $workflow->transition($application->fresh(), 'decision_pending', $owner);
            $recommendation = $workflow->recommendDecision($application, 'admit', User::factory()->create(), 'Meets criteria.', 'Review complete.');
            $approved = $workflow->approveDecision($application, $recommendation, $owner);
            $offer = $workflow->issueOffer($application->fresh(), $owner, 'Approved.', now()->addDays(10));
            $context->clear();
            $context->enterApplicant($tenant);
            $workflow->respondToOffer($offer->fresh(), $applicant, 'accepted');
            $context->clear();
            $context->set($tenant, $membership);
            $handoff = $workflow->createSisHandoff($application->fresh(), $owner);

            $converter = app(AdmissionToSisHandoff::class);
            $first = $converter->process($handoff, $owner);
            $second = $converter->process($handoff->fresh(), $owner);

            $this->assertSame($first->getKey(), $second->getKey());
            $this->assertSame($applicant->getKey(), $first->user_id);
            $this->assertSame($application->getKey(), $first->admission_application_id);
            $this->assertSame($offer->getKey(), $first->admission_offer_id);
            $this->assertSame($handoff->getKey(), $first->admission_handoff_id);
            $this->assertDatabaseCount('sis_students', 1);
            $this->assertDatabaseHas('admission_sis_handoffs', ['id' => $handoff->getKey(), 'status' => 'processed', 'processed_by' => $owner->getKey()]);
            $applicantMembership = TenantMembership::query()->where('tenant_id', $tenant->getKey())->where('user_id', $applicant->getKey())->firstOrFail();
            $this->assertTrue($applicantMembership->roles()->where('key', 'student')->exists());
            $this->assertDatabaseHas('audit_events', ['tenant_id' => $tenant->getKey(), 'event_type' => 'sis.admissions_handoff.processed']);
        } finally {
            $context->clear();
        }
    }

    public function test_capacity_guard_refuses_an_offer_when_all_intake_seats_are_reserved(): void
    {
        [$tenant, $owner, $membership] = $this->provisionTenant('Admission Seat Capacity');
        $intake = $this->createOpenIntake($tenant, $membership, 'CAPACITY-2027');
        $intake->forceFill(['capacity' => 1])->save();
        $context = app(TenantContext::class);
        $workflow = app(AdmissionApplicationWorkflow::class);
        $applications = [];
        $context->enterApplicant($tenant);

        try {
            foreach (['Taylor', 'Jordan'] as $givenName) {
                $applicant = User::factory()->create();
                $application = $workflow->createOrResume($intake, $applicant, [
                    'given_name' => $givenName,
                    'birth_date' => now()->subYears(20)->toDateString(),
                ]);
                $workflow->saveDraft($application, $applicant, [
                    'motivation' => 'A complete capacity test application.',
                    'information_consent' => true,
                ], 2);
                $applications[] = $workflow->submit($application->fresh(), $applicant);
            }
        } finally {
            $context->clear();
        }

        $context->set($tenant, $membership);
        try {
            foreach ($applications as $application) {
                $application = $workflow->transition($application, 'under_review', $owner);
                $application = $workflow->transition($application, 'decision_pending', $owner);
                $reviewer = User::factory()->create();
                $decision = $workflow->recommendDecision($application, 'admit', $reviewer, 'Meets criteria.', 'Capacity test review.');
                $workflow->approveDecision($application, $decision, $owner);
            }

            $firstOffer = $workflow->issueOffer($applications[0]->fresh(), $owner, null, now()->addDays(10));
            $this->assertDatabaseHas('admission_seat_reservations', [
                'intake_id' => $intake->getKey(), 'offer_id' => $firstOffer->getKey(), 'status' => 'pending',
            ]);

            try {
                $workflow->issueOffer($applications[1]->fresh(), $owner, null, now()->addDays(10));
                $this->fail('An intake at capacity must not issue another offer or reserve another seat.');
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('application', $exception->errors());
            }

            $this->assertDatabaseCount('admission_offers', 1);
            $this->assertDatabaseCount('admission_seat_reservations', 1);
            $this->assertSame('decision_pending', $applications[1]->fresh()->status);
        } finally {
            $context->clear();
        }
    }

    public function test_mysql_row_lock_serializes_a_competing_session_on_a_committed_record(): void
    {
        if (DB::getDriverName() !== 'mysql' || ! function_exists('proc_open')) {
            $this->markTestSkipped('Simultaneous row-lock verification requires a MySQL connection and process support.');
        }

        $primaryConnection = DB::connection();
        $lockedRow = $primaryConnection->table('migrations')->orderBy('id')->first();
        $this->assertNotNull($lockedRow, 'The guarded migration ledger must contain a committed row for lock verification.');
        $rowId = (int) $lockedRow->id;
        $this->assertGreaterThan(0, $primaryConnection->transactionLevel(), 'The primary MySQL session must be inside the isolated test transaction.');
        $this->assertTrue($primaryConnection->getPdo()->inTransaction(), 'The primary MySQL PDO transaction must be active.');
        $this->assertSame(1, $primaryConnection->table('migrations')->where('id', $rowId)->update(['batch' => DB::raw('batch + 1000000')]));

        $probePath = base_path('tests/Support/phase04-concurrent-lock-probe.php');
        $phasePrefix = match (DB::getDatabaseName()) {
            'techmentors_lms_phase06_test' => 'phase06',
            'techmentors_lms_phase05_test' => 'phase05',
            'techmentors_lms_phase07_test' => 'phase07',
            default => 'phase04',
        };
        $signalName = $phasePrefix.'-row-lock-'.Str::uuid().'.ready';
        $signalPath = storage_path('framework/'.$signalName);
        $this->assertFileDoesNotExist($signalPath);
        $process = proc_open(
            [PHP_BINARY, $probePath, (string) $rowId, $signalName],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            base_path(),
            Phase06ProbeEnvironment::current(),
            ['bypass_shell' => true],
        );
        $this->assertIsResource($process, 'The second isolated PHP process must start for lock verification.');
        fclose($pipes[0]);

        $lockWaitObserved = false;
        $readyReceived = false;
        $finished = false;
        $childExitCode = null;
        $output = '';
        $errorOutput = '';
        try {
            $readyDeadline = microtime(true) + 5;
            do {
                $readyReceived = is_file($signalPath) && ! is_link($signalPath);
                if ($readyReceived) {
                    break;
                }

                $status = proc_get_status($process);
                if (! $status['running']) {
                    $childExitCode = $status['exitcode'];
                    break;
                }
                usleep(50000);
            } while (microtime(true) < $readyDeadline);

            if ($readyReceived) {
                usleep(350000);
                $lockWaitObserved = proc_get_status($process)['running'];
            }
        } finally {
            if ($primaryConnection->transactionLevel() > 0) {
                $primaryConnection->rollBack();
            }

            $deadline = microtime(true) + 8;
            do {
                $status = proc_get_status($process);
                $finished = ! $status['running'];
                if ($finished) {
                    $childExitCode = $status['exitcode'];
                    break;
                }
                usleep(100000);
            } while (microtime(true) < $deadline);

            if (! $finished) {
                proc_terminate($process);
            }

            $output = stream_get_contents($pipes[1]);
            $errorOutput = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            $closeExitCode = proc_close($process);
            $childExitCode = $childExitCode >= 0 ? $childExitCode : $closeExitCode;

            if (is_file($signalPath) && ! is_link($signalPath)) {
                unlink($signalPath);
            }
        }

        $this->assertTrue(
            $lockWaitObserved,
            sprintf('The second MySQL session must wait while the first holds the committed row lock (ready=%s, stdout=%s, stderr=%s).', $readyReceived ? 'yes' : 'no', trim($output), trim($errorOutput)),
        );
        $this->assertTrue($readyReceived, 'The second MySQL session must confirm its verified connection before the lock wait is measured.');
        $this->assertTrue($finished, 'The second MySQL session must resume after the first transaction releases its lock.');
        $this->assertSame(0, $childExitCode, 'The concurrent locking read must finish without a MySQL error.');
        $this->assertSame('rows=1', trim($output), 'The competing session must resume and read the committed row after the lock is released.');
        $this->assertSame('', trim($errorOutput), 'The isolated lock probe must not emit diagnostics.');
    }

    public function test_mysql_simultaneous_offer_issuance_preserves_capacity_one(): void
    {
        if (DB::getDriverName() !== 'mysql' || ! function_exists('proc_open')) {
            $this->markTestSkipped('Simultaneous offer-capacity verification requires the guarded MySQL test database and process support.');
        }

        $process = proc_open(
            [PHP_BINARY, base_path('tests/Support/phase04-offer-capacity-race.php')],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            base_path(),
            Phase06ProbeEnvironment::current(),
            ['bypass_shell' => true],
        );
        $this->assertIsResource($process, 'The isolated MySQL offer concurrency probe must start.');
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);
        $summary = json_decode(trim((string) $stdout), true);

        $this->assertSame(0, $exitCode, 'The guarded MySQL offer race must complete and clean its synthetic records: '.trim((string) $stderr));
        $this->assertIsArray($summary);
        $this->assertSame(1, $summary['issued'] ?? null);
        $this->assertSame(1, $summary['capacity_rejected'] ?? null);
        $this->assertSame(1, $summary['offer_rows'] ?? null);
        $this->assertSame(1, $summary['active_reservations'] ?? null);
        $this->assertSame(1, $summary['offered_applications'] ?? null);
    }

    public function test_waitlisted_application_promotion_requires_a_new_independently_approved_admit_decision(): void
    {
        [$tenant, $owner, $membership] = $this->provisionTenant('Waitlist Promotion Admissions');
        $intake = $this->createOpenIntake($tenant, $membership, 'WAITLIST-PROMOTION-2027');
        $applicant = User::factory()->create();
        $context = app(TenantContext::class);
        $context->enterApplicant($tenant);
        try {
            $workflow = app(AdmissionApplicationWorkflow::class);
            $application = $workflow->createOrResume($intake, $applicant, [
                'given_name' => 'Riley', 'birth_date' => now()->subYears(20)->toDateString(),
            ]);
            $workflow->saveDraft($application, $applicant, [
                'motivation' => 'A complete waitlist application.', 'information_consent' => true,
            ], 2);
            $application = $workflow->submit($application->fresh(), $applicant);
        } finally {
            $context->clear();
        }

        $context->set($tenant, $membership);
        try {
            $workflow = app(AdmissionApplicationWorkflow::class);
            $workflow->transition($application, 'under_review', $owner);
            $application = $workflow->transition($application->fresh(), 'decision_pending', $owner);
            $reviewer = User::factory()->create();
            $waitlistDecision = $workflow->recommendDecision($application, 'waitlist', $reviewer, 'Seat limit reached.', 'Initial review.');
            $workflow->approveDecision($application, $waitlistDecision, $owner);
            $this->assertSame('waitlisted', $application->fresh()->status);
            $this->actingAs($owner)->get(route('tenant.admissions.index', [
                'tenant' => $tenant->slug, 'status' => 'waitlisted',
            ]))->assertOk()->assertInertia(fn ($page) => $page
                ->where('applications.data.0.waitlistPosition', 1)
                ->where('applications.data.0.number', $application->application_number));

            $promotion = $workflow->recommendDecision($application->fresh(), 'admit', $reviewer, 'A seat is available.', 'Promoted after capacity review.');
            try {
                $workflow->issueOffer($application->fresh(), $owner, null, null);
                $this->fail('A waitlist promotion cannot issue an offer before independent approval.');
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('decision', $exception->errors());
            }

            $approved = $workflow->approveDecision($application->fresh(), $promotion, $owner);
            $this->assertSame('admit', $approved->decision);
            $this->assertNotNull($approved->approved_at);
            $this->assertSame('waitlisted', $application->fresh()->status, 'Promotion does not issue an offer implicitly.');

            $offer = $workflow->issueOffer($application->fresh(), $owner, 'Promoted from the waitlist.', now()->addDays(7));
            $this->assertDatabaseHas('admission_offers', [
                'id' => $offer->getKey(), 'application_id' => $application->getKey(), 'decision_id' => $approved->getKey(),
                'status' => 'issued', 'version_number' => 1,
            ]);
            $this->assertDatabaseHas('admission_seat_reservations', [
                'application_id' => $application->getKey(), 'intake_id' => $intake->getKey(), 'status' => 'pending',
            ]);
        } finally {
            $context->clear();
        }
    }

    public function test_assigned_reviewer_can_recommend_and_another_tenant_owner_can_approve(): void
    {
        [$tenant, $owner, $ownerMembership] = $this->provisionTenant('Reviewer Admissions');
        $intake = $this->createOpenIntake($tenant, $ownerMembership, 'REVIEWER-2027');
        $applicant = User::factory()->create();
        $context = app(TenantContext::class);
        $context->enterApplicant($tenant);

        try {
            $workflow = app(AdmissionApplicationWorkflow::class);
            $application = $workflow->createOrResume($intake, $applicant, ['given_name' => 'Morgan', 'birth_date' => now()->subYears(20)->toDateString()]);
            $workflow->saveDraft($application, $applicant, ['motivation' => 'A considered application.', 'information_consent' => true], 2);
            $application = $workflow->submit($application->fresh(), $applicant);
        } finally {
            $context->clear();
        }

        $reviewer = User::factory()->create();
        $context->enterProvisioning($tenant);
        try {
            $reviewerMembership = TenantMembership::query()->create([
                'institution_id' => Institution::query()->firstOrFail()->getKey(),
                'user_id' => $reviewer->getKey(),
                'status' => 'active',
                'activated_at' => now(),
            ]);
            $reviewerRole = Role::query()->where('tenant_id', $tenant->getKey())->where('key', 'admissions.officer')->firstOrFail();
            $reviewerMembership->roles()->attach($reviewerRole->getKey(), ['assigned_by' => $owner->getKey(), 'tenant_id' => $tenant->getKey()]);
        } finally {
            $context->clear();
        }

        $context->set($tenant, $ownerMembership);
        try {
            $workflow = app(AdmissionApplicationWorkflow::class);
            $workflow->transition($application, 'under_review', $owner);
            $application = $workflow->transition($application->fresh(), 'decision_pending', $owner);
        } finally {
            $context->clear();
        }

        $context->set($tenant, $reviewerMembership);
        try {
            AdmissionReviewerAssignment::query()->create([
                'application_id' => $application->getKey(),
                'reviewer_user_id' => $reviewer->getKey(),
                'assignment_type' => 'review',
                'status' => 'assigned',
                'assigned_by' => $owner->getKey(),
            ]);
        } finally {
            $context->clear();
        }

        $this->actingAs($reviewer)->post(route('tenant.admissions.decisions.recommend', [
            'tenant' => $tenant->slug,
            'applicationId' => $application->getKey(),
        ]), [
            'decision' => 'admit',
            'applicant_reason' => 'Recommended based on submitted materials.',
            'private_reason' => 'Independent approval is required.',
        ])->assertRedirect(route('tenant.admissions.applications.show', [
            'tenant' => $tenant->slug,
            'applicationId' => $application->getKey(),
        ]));

        $recommendation = AdmissionDecision::query()->where('application_id', $application->getKey())->firstOrFail();
        $this->assertSame((int) $reviewer->getKey(), (int) $recommendation->recommended_by);
        $this->assertNull($recommendation->approved_at);

        $this->actingAs($owner)->post(route('tenant.admissions.decisions.approve', [
            'tenant' => $tenant->slug,
            'applicationId' => $application->getKey(),
            'decisionId' => $recommendation->getKey(),
        ]))->assertRedirect(route('tenant.admissions.applications.show', [
            'tenant' => $tenant->slug,
            'applicationId' => $application->getKey(),
        ]));

        $this->assertSame((int) $owner->getKey(), (int) $recommendation->fresh()->approved_by);
    }

    public function test_form_definition_rejects_duplicate_keys_and_unknown_conditional_references(): void
    {
        $forms = app(AdmissionFormDefinition::class);
        $valid = [
            'title' => 'Application',
            'sections' => [[
                'key' => 'profile', 'title' => 'Profile', 'fields' => [
                    ['key' => 'motivation', 'type' => 'long_text', 'label' => 'Why apply?', 'required' => true],
                    ['key' => 'consent', 'type' => 'consent', 'label' => 'I agree', 'required' => true],
                ],
            ]],
        ];

        $normalized = $forms->normalize($valid);
        $answers = $forms->validateAnswers(['motivation' => 'For this program.', 'consent' => true], $normalized);
        $this->assertSame('For this program.', $answers['motivation']);

        $duplicate = $valid;
        $duplicate['sections'][0]['fields'][1]['key'] = 'motivation';
        $this->assertThrows(fn () => $forms->normalize($duplicate));

        $unknownCondition = $valid;
        $unknownCondition['sections'][0]['fields'][0]['visible_when'] = ['field' => 'missing', 'operator' => 'equals', 'value' => 'yes'];
        $this->assertThrows(fn () => $forms->normalize($unknownCondition));
    }

    /** @return array{Tenant, User, TenantMembership} */
    private function provisionTenant(string $name): array
    {
        $owner = User::factory()->create();
        $details = [
            'official_name' => $name, 'display_name' => $name, 'slug' => Str::slug($name), 'code' => '',
            'type' => 'academy', 'contact_email' => '', 'contact_phone' => '', 'website' => '', 'country' => '',
            'region' => '', 'city' => '', 'address' => '', 'timezone' => 'UTC', 'locale' => 'en',
        ];
        $provisioned = app(TenantProvisioner::class)->provision($details, $owner, $owner->email);
        $tenant = $provisioned->tenant;
        $context = app(TenantContext::class);
        $context->enterProvisioning($tenant);

        try {
            $institution = Institution::query()->firstOrFail();
            $membership = TenantMembership::query()->create([
                'institution_id' => $institution->getKey(), 'user_id' => $owner->getKey(), 'status' => 'active', 'activated_at' => now(),
            ]);
            $role = Role::query()->where('tenant_id', $tenant->getKey())->where('key', 'tenant.owner')->firstOrFail();
            $membership->roles()->attach($role->getKey(), ['assigned_by' => $owner->getKey(), 'tenant_id' => $tenant->getKey()]);
        } finally {
            $context->clear();
        }

        return [$tenant, $owner, $membership];
    }

    private function createOpenIntake(Tenant $tenant, TenantMembership $membership, string $code): AdmissionIntake
    {
        $context = app(TenantContext::class);
        $context->set($tenant, $membership);

        try {
            $institution = Institution::query()->firstOrFail();
            $settings = AdmissionSetting::query()->firstOrFail();
            $settings->forceFill(['enabled' => true, 'public_applications_enabled' => true])->save();
            $intake = AdmissionIntake::query()->create([
                'institution_id' => $institution->getKey(), 'code' => $code, 'name' => 'Autumn intake',
                'status' => 'open', 'is_public' => true, 'delivery_mode' => 'on_campus', 'locale' => 'en', 'timezone' => 'UTC',
                'application_deadline' => now()->addMonth(), 'capacity' => 10,
            ]);
            $template = AdmissionFormTemplate::query()->create([
                'institution_id' => $institution->getKey(), 'intake_id' => $intake->getKey(), 'code' => 'application', 'name' => 'Application form', 'status' => 'draft',
            ]);
            AdmissionFormVersion::query()->create([
                'form_template_id' => $template->getKey(), 'version_number' => 1, 'definition' => [
                    'title' => 'Application', 'sections' => [[
                        'key' => 'application_details', 'title' => 'Application details', 'fields' => [
                            ['key' => 'motivation', 'type' => 'long_text', 'label' => 'Why are you interested?', 'required' => true],
                            ['key' => 'information_consent', 'type' => 'consent', 'label' => 'I confirm accuracy.', 'required' => true],
                        ],
                    ]],
                ],
                'status' => 'published', 'published_at' => now(), 'published_by' => $membership->user_id,
            ]);

            return $intake;
        } finally {
            $context->clear();
        }
    }

    private function intake(string $code): AdmissionIntake
    {
        return AdmissionIntake::query()->where('code', $code)->firstOrFail();
    }
}
