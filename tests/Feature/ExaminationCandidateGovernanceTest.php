<?php

namespace Tests\Feature;

use App\Domain\Admissions\Documents\AdmissionDocumentScanner;
use App\Domain\Admissions\Documents\AdmissionDocumentScanResult;
use App\Domain\Assessments\QuizOutboxProcessor;
use App\Domain\Audit\AuditRecorder;
use App\Domain\Identity\PermissionCatalog;
use App\Domain\Tenancy\TenantContext;
use App\Domain\Tenancy\TenantProvisioner;
use App\Jobs\ScanExamIncidentEvidence;
use App\Models\AcademicCourse;
use App\Models\AcademicPeriod;
use App\Models\AcademicYear;
use App\Models\Campus;
use App\Models\CourseEnrollment;
use App\Models\CourseOffering;
use App\Models\CourseSection;
use App\Models\EnrollmentPolicy;
use App\Models\ExamCandidate;
use App\Models\ExamCycle;
use App\Models\ExamSession;
use App\Models\Institution;
use App\Models\Role;
use App\Models\SisStudent;
use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\GuardedRefreshDatabase;
use Tests\TestCase;

final class ExaminationCandidateGovernanceTest extends TestCase
{
    use GuardedRefreshDatabase;

    public function test_invigilator_role_has_only_its_assigned_duty_access_family(): void
    {
        $f = $this->fixture();
        $role = Role::query()->where('tenant_id', $f['tenant']->getKey())->where('key', 'exam.invigilator')->firstOrFail();
        $permissions = $role->permissions()->pluck('key')->all();

        self::assertContains('exams.invigilators.view-own', $permissions);
        self::assertContains('exams.attendance.record', $permissions);
        self::assertContains('exams.incidents.report', $permissions);
        self::assertNotContains('exams.manage', $permissions);
        self::assertNotContains('exams.results.release', $permissions);
        self::assertNotContains('exams.paper.confidential.view', $permissions);
    }

    public function test_student_requests_receive_independent_review_and_admit_documents_are_private_revocable_and_verifiable(): void
    {
        $f = $this->fixture();
        $root = '/t/'.$f['tenant']->slug;
        $studentPermissions = ['exams.student.view-own', 'exams.accommodation.request-own'];
        $this->grant($f, $f['studentUser'], $studentPermissions);
        $this->grant($f, $f['studentUser'], ['exams.accommodation.manage']);

        $this->actingAs($f['studentUser'])->get($root.'/examinations/my-governance')->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('workspace/examinations/candidate-governance')->where('mode', 'student')->has('candidates', 1));
        $this->actingAs($f['owner'])->get($root.'/academics/examinations/candidate-governance')->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('workspace/examinations/candidate-governance')->where('mode', 'review')->has('candidates', 1));
        $accommodationUrl = $root.'/examinations/my-governance/cycles/'.$f['cycle']->id.'/candidates/'.$f['candidate']->id.'/accommodations';
        $this->actingAs($f['studentUser'])->post($accommodationUrl, ['accommodation_type' => 'extra_time', 'extra_time_minutes' => 20, 'confidential_details' => 'Private support detail'])->assertRedirect();
        $request = DB::table('exam_accommodation_requests')->where('candidate_id', $f['candidate']->id)->firstOrFail();
        self::assertNotSame('Private support detail', $request->confidential_details);
        $this->actingAs($f['studentUser'])->post($root.'/academics/examinations/accommodation-requests/'.$request->id.'/decision', ['decision' => 'approved', 'reason' => 'Self review should be denied.', 'expires_at' => now()->addDays(3)->toDateTimeString()])->assertForbidden();
        $decisionResponse = $this->actingAs($f['owner'])->post($root.'/academics/examinations/accommodation-requests/'.$request->id.'/decision', ['decision' => 'approved', 'reason' => 'Supporting documentation checked.', 'expires_at' => now()->addDays(3)->toDateTimeString()]);
        $decisionResponse->assertRedirect();
        $active = DB::table('exam_candidate_accommodations')->where('candidate_id', $f['candidate']->id)->where('status', 'active')->firstOrFail();
        self::assertSame('Private support detail', decrypt($active->confidential_details, false));
        $this->actingAs($f['owner'])->put($root.'/academics/examinations/candidate-governance/policy', ['name' => 'Default eligibility', 'active' => true, 'require_active_student' => true, 'require_confirmed_enrollment' => true])->assertRedirect();

        $issueUrl = $root.'/academics/examinations/admit-documents/sessions/'.$f['session']->id.'/candidates/'.$f['candidate']->id;
        $this->actingAs($f['owner'])->post($issueUrl)->assertRedirect()->assertSessionHas('success', 'Admit document issued and made available to the registered student.');
        $document = DB::table('exam_admit_documents')->where('candidate_id', $f['candidate']->id)->firstOrFail();
        $this->actingAs($f['studentUser'])->get($root.'/examinations/my-governance/admit-documents/'.$document->id)->assertOk()->assertInertia(fn (Assert $page) => $page->component('workspace/examinations/admit-document')->where('snapshot.candidate_number', $f['candidate']->candidate_number));
        $code = decrypt($document->verification_code_encrypted);
        $this->post('/admit-documents/verify', ['verification_code' => $code])->assertRedirect()->assertSessionHas('status', 'This admit document is valid.');
        DB::table('exam_candidates')->where('id', $f['candidate']->id)->update(['withdrawn_at' => now(), 'status' => 'withdrawn']);
        $this->post('/admit-documents/verify', ['verification_code' => $code])->assertRedirect()->assertSessionHas('status', 'This admit document could not be verified.');
        DB::table('exam_candidates')->where('id', $f['candidate']->id)->update(['withdrawn_at' => null, 'status' => 'registered']);
        $this->actingAs($f['owner'])->post($root.'/academics/examinations/admit-documents/'.$document->id.'/revoke', ['reason' => 'Candidate record corrected.'])->assertRedirect();
        $this->post('/admit-documents/verify', ['verification_code' => $code])->assertRedirect()->assertSessionHas('status', 'This admit document could not be verified.');
        $this->actingAs($f['studentUser'])->get($root.'/examinations/my-governance/admit-documents/'.$document->id)->assertNotFound();
        $this->assertDatabaseHas('audit_events', ['event_type' => 'exams.accommodation.approved', 'tenant_id' => $f['tenant']->id]);
        $this->assertDatabaseHas('audit_events', ['event_type' => 'exams.admit_document.revoked', 'tenant_id' => $f['tenant']->id]);
    }

    public function test_eligibility_exception_is_limited_to_a_failed_allowed_rule_and_requires_expiring_independent_decision(): void
    {
        $f = $this->fixture();
        $this->grant($f, $f['studentUser'], ['exams.student.view-own', 'exams.accommodation.request-own']);
        DB::table('course_enrollments')->where('tenant_id', $f['tenant']->id)->where('student_id', $f['student']->id)->delete();
        $url = '/t/'.$f['tenant']->slug.'/examinations/my-governance/cycles/'.$f['cycle']->id.'/candidates/'.$f['candidate']->id.'/eligibility-exceptions';
        $this->actingAs($f['studentUser'])->post($url, ['rule_key' => 'confirmed_course_enrollment', 'reason' => 'My enrollment record has not synchronized yet.'])->assertRedirect();
        $exception = DB::table('exam_eligibility_exceptions')->where('candidate_id', $f['candidate']->id)->firstOrFail();
        $reviewUrl = '/t/'.$f['tenant']->slug.'/academics/examinations/eligibility-exceptions/'.$exception->id.'/decision';
        $this->actingAs($f['studentUser'])->post($reviewUrl, ['decision' => 'approved', 'reason' => 'Not authorized'])->assertForbidden();
        $this->actingAs($f['owner'])->post($reviewUrl, ['decision' => 'approved', 'reason' => 'Registrar verified the pending enrollment correction.', 'expires_at' => now()->addDays(2)->toDateTimeString()])->assertRedirect();
        self::assertSame('approved', DB::table('exam_eligibility_exceptions')->where('id', $exception->id)->value('status'));
        $this->actingAs($f['owner'])->post('/t/'.$f['tenant']->slug.'/academics/examinations/eligibility-exceptions/'.$exception->id.'/revoke', ['reason' => 'Correction was not completed.'])->assertRedirect();
        self::assertSame('revoked', DB::table('exam_eligibility_exceptions')->where('id', $exception->id)->value('status'));
        $this->actingAs($f['owner'])->post($reviewUrl, ['decision' => 'approved', 'reason' => 'Duplicate decision.', 'expires_at' => now()->addDays(2)->toDateTimeString()])->assertStatus(409);
    }

    public function test_admit_issue_rejects_an_ineligible_candidate_and_outbox_targets_only_authorized_reviewers(): void
    {
        $f = $this->fixture();
        $this->grant($f, $f['studentUser'], ['exams.student.view-own', 'exams.accommodation.request-own']);
        DB::table('course_enrollments')->where('tenant_id', $f['tenant']->id)->where('student_id', $f['student']->id)->delete();
        $this->actingAs($f['owner'])->post('/t/'.$f['tenant']->slug.'/academics/examinations/admit-documents/sessions/'.$f['session']->id.'/candidates/'.$f['candidate']->id)->assertStatus(422);
        self::assertSame(0, DB::table('exam_admit_documents')->where('tenant_id', $f['tenant']->id)->count());

        $this->actingAs($f['studentUser'])->post('/t/'.$f['tenant']->slug.'/examinations/my-governance/cycles/'.$f['cycle']->id.'/candidates/'.$f['candidate']->id.'/accommodations', ['accommodation_type' => 'reader', 'extra_time_minutes' => 0])->assertRedirect();
        app(TenantContext::class)->enterProvisioning($f['tenant']);
        try {
            $result = app(QuizOutboxProcessor::class)->dispatchAvailable();
            self::assertGreaterThanOrEqual(1, $result['delivered']);
            $this->assertDatabaseHas('quiz_notifications', ['tenant_id' => $f['tenant']->id, 'user_id' => $f['owner']->id, 'event_type' => 'exam.accommodation.requested']);
        } finally {
            app(TenantContext::class)->clear();
        }
        $this->assertDatabaseMissing('quiz_notifications', ['tenant_id' => $f['tenant']->id, 'user_id' => $f['owner']->id, 'event_type' => 'exam.accommodation.decided']);
    }

    public function test_incident_evidence_stays_quarantined_until_clean_scan_and_is_private_tenant_scoped_and_audited(): void
    {
        $f = $this->fixture();
        $root = '/t/'.$f['tenant']->slug.'/academics/examinations';
        $this->grant($f, $f['owner'], ['exams.view', 'exams.incidents.report', 'exams.incidents.review', 'exams.results.compile']);
        $outsider = User::factory()->create();
        $this->grant($f, $outsider, ['exams.view']);
        $reporter = User::factory()->create();
        $this->grant($f, $reporter, ['exams.view', 'exams.incidents.report']);
        Storage::fake('local');
        Queue::fake();

        $this->actingAs($reporter)->post($root.'/incidents', ['session_id' => $f['session']->id, 'candidate_id' => $f['candidate']->id, 'incident_type' => 'technical', 'severity' => 'urgent', 'description' => 'The approved exam interface stopped responding during the session.'])->assertRedirect();
        $incident = DB::table('exam_incidents')->where('tenant_id', $f['tenant']->id)->firstOrFail();
        $this->deliverExaminationOutbox($f['tenant']);
        $this->assertDatabaseHas('quiz_notifications', ['tenant_id' => $f['tenant']->id, 'user_id' => $f['owner']->id, 'event_type' => 'exam.incident.reported']);
        $this->assertDatabaseMissing('quiz_notifications', ['tenant_id' => $f['tenant']->id, 'user_id' => $reporter->id, 'event_type' => 'exam.incident.reported']);
        $this->actingAs($reporter)->post($root.'/incidents/'.$incident->id.'/evidence', ['evidence' => UploadedFile::fake()->create('evidence.pdf', 40, 'application/pdf')])->assertRedirect();
        $evidence = DB::table('exam_incident_evidence')->where('tenant_id', $f['tenant']->id)->firstOrFail();
        self::assertSame('quarantined', $evidence->scan_status);
        self::assertStringContainsString('/quarantine/', $evidence->private_path);
        Storage::disk('local')->assertExists($evidence->private_path);
        Queue::assertPushed(ScanExamIncidentEvidence::class);
        $this->actingAs($outsider)->get($root.'/incident-evidence/'.$evidence->id.'/download')->assertForbidden();

        $cleanScanner = new class implements AdmissionDocumentScanner
        {
            public function scan(string $absolutePath): AdmissionDocumentScanResult
            {
                return AdmissionDocumentScanResult::clean();
            }

            public function name(): string
            {
                return 'test-clean-scanner';
            }
        };
        (new ScanExamIncidentEvidence((int) $f['tenant']->id, (int) $evidence->id))->handle($cleanScanner, app(TenantContext::class), app(AuditRecorder::class));
        self::assertSame('clean', DB::table('exam_incident_evidence')->where('id', $evidence->id)->value('scan_status'));
        $this->actingAs($f['owner'])->get($root.'/incident-evidence/'.$evidence->id.'/download')->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->assertDatabaseHas('audit_events', ['tenant_id' => $f['tenant']->id, 'event_type' => 'exams.incident.evidence.accessed']);
        $this->actingAs($f['owner'])->post($root.'/incidents/'.$incident->id.'/review', ['decision' => 'resolved', 'notes' => 'The assessment service recovered and evidence was reviewed.'])->assertRedirect();
        $this->deliverExaminationOutbox($f['tenant']);
        $this->assertDatabaseHas('quiz_notifications', ['tenant_id' => $f['tenant']->id, 'user_id' => $reporter->id, 'event_type' => 'exam.incident.reviewed']);
        $this->assertDatabaseMissing('quiz_notifications', ['tenant_id' => $f['tenant']->id, 'user_id' => $outsider->id, 'event_type' => 'exam.incident.reviewed']);

        $infectedScanner = new class implements AdmissionDocumentScanner
        {
            public function scan(string $absolutePath): AdmissionDocumentScanResult
            {
                return AdmissionDocumentScanResult::rejected('malware_detected');
            }

            public function name(): string
            {
                return 'test-infected-scanner';
            }
        };
        $this->actingAs($reporter)->post($root.'/incidents/'.$incident->id.'/evidence', ['evidence' => UploadedFile::fake()->create('suspect.pdf', 30, 'application/pdf')])->assertRedirect();
        $infected = DB::table('exam_incident_evidence')->where('incident_id', $incident->id)->where('scan_status', 'quarantined')->orderByDesc('id')->firstOrFail();
        (new ScanExamIncidentEvidence((int) $f['tenant']->id, (int) $infected->id))->handle($infectedScanner, app(TenantContext::class), app(AuditRecorder::class));
        self::assertSame('infected', DB::table('exam_incident_evidence')->where('id', $infected->id)->value('scan_status'));
        $this->actingAs($f['owner'])->get($root.'/incident-evidence/'.$infected->id.'/download')->assertNotFound();
    }

    public function test_incident_assignment_escalation_investigation_closure_and_reopening_are_authorized_and_immutable(): void
    {
        $f = $this->fixture();
        $this->grant($f, $f['owner'], ['exams.view', 'exams.incidents.report', 'exams.incidents.review']);
        $reporter = User::factory()->create();
        $this->grant($f, $reporter, ['exams.view', 'exams.incidents.report']);
        $outsider = User::factory()->create();
        $root = '/t/'.$f['tenant']->slug.'/academics/examinations';

        $this->actingAs($reporter)->post($root.'/incidents', [
            'session_id' => $f['session']->id,
            'incident_type' => 'interruption',
            'severity' => 'urgent',
            'description' => 'The examination room network was interrupted.',
        ])->assertRedirect();
        $incident = DB::table('exam_incidents')->where('tenant_id', $f['tenant']->id)->firstOrFail();

        $this->actingAs($reporter)->post($root.'/incidents/'.$incident->id.'/transition', [
            'action' => 'resolve', 'notes' => 'A reporter cannot review their own incident.',
        ])->assertForbidden();
        $this->actingAs($f['owner'])->post($root.'/incidents/'.$incident->id.'/transition', [
            'action' => 'assign', 'assignee_id' => $outsider->id, 'notes' => 'Assigning to an unverified reviewer.',
        ])->assertUnprocessable();
        $this->actingAs($f['owner'])->post($root.'/incidents/'.$incident->id.'/transition', [
            'action' => 'assign', 'assignee_id' => $f['owner']->id, 'notes' => 'Assigned to the authorized controller.',
        ])->assertRedirect();
        foreach ([
            ['escalate', 'Escalated for urgent operational review.'],
            ['investigate', 'Investigation started with the session team.'],
            ['resolve', 'The network service was restored and the event documented.'],
            ['close', 'The corrective action was verified and the record is closed.'],
        ] as [$action, $notes]) {
            $this->actingAs($f['owner'])->post($root.'/incidents/'.$incident->id.'/transition', compact('action', 'notes'))->assertRedirect();
        }

        $this->actingAs($f['owner'])->post($root.'/incidents/'.$incident->id.'/evidence', [
            'evidence' => UploadedFile::fake()->create('closed-incident.pdf', 20, 'application/pdf'),
        ])->assertStatus(409);
        $action = 'reopen';
        $notes = 'New verified evidence requires a follow-up investigation.';
        $this->actingAs($f['owner'])->post($root.'/incidents/'.$incident->id.'/transition', compact('action', 'notes'))->assertRedirect();

        $this->assertDatabaseHas('exam_incidents', ['id' => $incident->id, 'tenant_id' => $f['tenant']->id, 'status' => 'investigating', 'assigned_to' => $f['owner']->id]);
        $history = DB::table('exam_incident_history')->where('tenant_id', $f['tenant']->id)->where('incident_id', $incident->id)->orderBy('id')->get();
        self::assertSame(['reported', 'assign', 'escalate', 'investigation_started', 'resolve', 'close', 'reopened'], $history->pluck('event_type')->all());
        self::assertSame(['reported', 'reported', 'escalated', 'investigating', 'resolved', 'closed', 'investigating'], $history->pluck('to_status')->all());
        $this->assertDatabaseHas('audit_events', ['tenant_id' => $f['tenant']->id, 'event_type' => 'exams.incident.reopened']);
        self::assertSame(6, DB::table('quiz_outbox_events')->where('tenant_id', $f['tenant']->id)->where('event_type', 'exam.incident.updated')->count());
        self::assertSame(0, DB::table('exam_results')->where('tenant_id', $f['tenant']->id)->count(), 'Incident lifecycle operations must not mutate examination results.');
        $this->deliverExaminationOutbox($f['tenant']);
        $this->deliverExaminationOutbox($f['tenant']);
        self::assertSame(6, DB::table('quiz_notifications')->where('tenant_id', $f['tenant']->id)->where('user_id', $reporter->id)->count(), 'Six lifecycle updates must deliver once per event to the reporter.');
        self::assertSame(7, DB::table('quiz_notifications')->where('tenant_id', $f['tenant']->id)->where('user_id', $f['owner']->id)->count(), 'The reviewer receives one report plus six deduplicated lifecycle updates.');

        $this->actingAs($f['owner'])->post($root.'/incidents/'.$incident->id.'/transition', [
            'action' => 'close', 'notes' => 'Invalid close from investigation state.',
        ])->assertStatus(409);
        self::assertSame(7, DB::table('exam_incident_history')->where('incident_id', $incident->id)->count(), 'Rejected transitions must not append history.');
    }

    private function deliverExaminationOutbox(Tenant $tenant): void
    {
        $context = app(TenantContext::class);
        $context->enterProvisioning($tenant);
        try {
            app(QuizOutboxProcessor::class)->dispatchAvailable();
        } finally {
            $context->clear();
        }
    }

    /** @return array<string, mixed> */
    private function fixture(): array
    {
        $creator = User::factory()->create();
        $owner = User::factory()->create();
        $studentUser = User::factory()->create();
        $label = 'Candidate Governance '.Str::random(5);
        $tenant = app(TenantProvisioner::class)->provision(['slug' => Str::slug($label), 'official_name' => $label, 'display_name' => $label, 'code' => '', 'type' => 'academy', 'contact_email' => '', 'contact_phone' => '', 'website' => '', 'country' => '', 'region' => '', 'city' => '', 'address' => '', 'timezone' => 'UTC', 'locale' => 'en'], $creator, $owner->email)->tenant;
        $context = app(TenantContext::class);
        $context->enterProvisioning($tenant);
        try {
            $institution = Institution::query()->firstOrFail();
            $campus = Campus::query()->create(['institution_id' => $institution->id, 'code' => 'MAIN', 'name' => 'Main', 'status' => 'active', 'is_primary' => true]);
            $ownerMembership = TenantMembership::query()->create(['institution_id' => $institution->id, 'user_id' => $owner->id, 'status' => 'active', 'activated_at' => now()]);
            $ownerRole = Role::query()->where('tenant_id', $tenant->id)->where('key', 'tenant.owner')->firstOrFail();
            $ownerMembership->roles()->attach($ownerRole->id, ['assigned_by' => $creator->id, 'tenant_id' => $tenant->id]);
            $year = AcademicYear::query()->create(['institution_id' => $institution->id, 'name' => 'Year', 'code' => 'Y-'.Str::random(5), 'starts_on' => today()->subMonth(), 'ends_on' => today()->addMonths(4), 'timezone' => 'UTC', 'locale' => 'en', 'status' => 'active']);
            $period = AcademicPeriod::query()->create(['academic_year_id' => $year->id, 'name' => 'Term', 'code' => 'T-'.Str::random(5), 'period_type' => 'term', 'sequence' => 1, 'starts_on' => today()->subMonth(), 'ends_on' => today()->addMonths(3), 'status' => 'active']);
            $course = AcademicCourse::query()->create(['institution_id' => $institution->id, 'code' => 'C-'.Str::random(5), 'title' => 'Candidate Governance', 'status' => 'active']);
            $enrollmentPolicy = EnrollmentPolicy::query()->create(['institution_id' => $institution->id, 'name' => 'Exam enrollment']);
            $offering = CourseOffering::query()->create(['institution_id' => $institution->id, 'campus_id' => $campus->id, 'academic_year_id' => $year->id, 'academic_period_id' => $period->id, 'academic_course_id' => $course->id, 'policy_id' => $enrollmentPolicy->id, 'offering_code' => 'O-'.Str::random(5), 'status' => 'open']);
            $section = CourseSection::query()->create(['offering_id' => $offering->id, 'campus_id' => $campus->id, 'section_code' => 'A', 'capacity' => 10, 'status' => 'open']);
            $student = SisStudent::query()->create(['institution_id' => $institution->id, 'home_campus_id' => $campus->id, 'user_id' => $studentUser->id, 'student_number' => 'S-'.Str::upper(Str::random(7)), 'registration_reference' => 'R-'.Str::upper(Str::random(7)), 'legal_first_name' => 'Test', 'legal_last_name' => 'Learner', 'admission_date' => today()->subYear(), 'status' => 'active', 'student_type' => 'standard']);
            CourseEnrollment::query()->create(['institution_id' => $institution->id, 'student_id' => $student->id, 'offering_id' => $offering->id, 'section_id' => $section->id, 'status' => 'confirmed', 'registration_source' => 'registrar', 'requested_at' => now()->subDay(), 'confirmed_at' => now()->subDay()]);
            $cycle = ExamCycle::query()->create(['institution_id' => $institution->id, 'campus_id' => $campus->id, 'academic_period_id' => $period->id, 'created_by' => $owner->id, 'code' => 'EX-'.Str::upper(Str::random(5)), 'title' => 'Candidate governance exam', 'category' => 'final', 'status' => 'published', 'starts_on' => today(), 'ends_on' => today()->addDay(), 'published_at' => now()]);
            $session = ExamSession::query()->create(['cycle_id' => $cycle->id, 'campus_id' => $campus->id, 'academic_course_id' => $course->id, 'created_by' => $owner->id, 'code' => 'SES-'.Str::upper(Str::random(5)), 'title' => 'Governance final', 'mode' => 'onsite', 'status' => 'published', 'starts_at_utc' => now()->addDays(2), 'ends_at_utc' => now()->addDays(2)->addHour(), 'duration_minutes' => 60, 'capacity' => 10]);
            $candidate = ExamCandidate::query()->create(['cycle_id' => $cycle->id, 'student_id' => $student->id, 'registered_by' => $owner->id, 'candidate_number' => 'CAN-'.Str::upper(Str::random(6)), 'status' => 'registered', 'registered_at' => now()]);
        } finally {
            $context->clear();
        }

        return compact('tenant', 'owner', 'studentUser', 'institution', 'campus', 'course', 'student', 'cycle', 'session', 'candidate');
    }

    /**
     * @param  array<string, mixed>  $f
     * @param  list<string>  $permissions
     */
    private function grant(array $f, User $user, array $permissions): void
    {
        PermissionCatalog::ensure();
        $context = app(TenantContext::class);
        $context->enterProvisioning($f['tenant']);
        try {
            $membership = TenantMembership::query()->firstOrCreate(['user_id' => $user->id], ['institution_id' => $f['institution']->id, 'status' => 'active', 'activated_at' => now()]);
            $role = Role::query()->create(['tenant_id' => $f['tenant']->id, 'key' => 'test.exam.candidate.'.Str::random(8), 'name' => 'Candidate test', 'scope' => 'tenant', 'is_system' => false]);
            $role->permissions()->sync(DB::table('permissions')->whereIn('key', $permissions)->pluck('id'));
            $membership->roles()->attach($role->id, ['assigned_by' => $f['owner']->id, 'tenant_id' => $f['tenant']->id]);
        } finally {
            $context->clear();
        }
    }
}
