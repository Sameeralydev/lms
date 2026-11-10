<?php

namespace Tests\Feature;

use App\Domain\Admissions\Documents\AdmissionDocumentScanner;
use App\Domain\Admissions\Documents\AdmissionDocumentScanResult;
use App\Domain\Admissions\Documents\ClamAvAdmissionDocumentScanner;
use App\Domain\Assignments\AssignmentOutboxProcessor;
use App\Domain\Assignments\AssignmentWorkflow;
use App\Domain\Audit\AuditRecorder;
use App\Domain\Tenancy\TenantContext;
use App\Domain\Tenancy\TenantProvisioner;
use App\Jobs\ScanAssignmentArtifact;
use App\Models\AcademicCourse;
use App\Models\AcademicPeriod;
use App\Models\AcademicYear;
use App\Models\Assignment;
use App\Models\AssignmentArtifact;
use App\Models\AssignmentAttempt;
use App\Models\AssignmentAttemptRevision;
use App\Models\AssignmentExtension;
use App\Models\AssignmentGrade;
use App\Models\AssignmentGroup;
use App\Models\AssignmentIntegrityCase;
use App\Models\AssignmentNotification;
use App\Models\AssignmentRecipient;
use App\Models\AssignmentRubric;
use App\Models\AssignmentRubricVersion;
use App\Models\AssignmentSubmission;
use App\Models\AssignmentVersion;
use App\Models\Campus;
use App\Models\CourseEnrollment;
use App\Models\CourseOffering;
use App\Models\CourseSection;
use App\Models\EnrollmentPolicy;
use App\Models\Institution;
use App\Models\Role;
use App\Models\SisStudent;
use App\Models\TenantMembership;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Support\GuardedRefreshDatabase;
use Tests\TestCase;

final class AssignmentManagementTest extends TestCase
{
    use GuardedRefreshDatabase;

    public function test_assignment_authoring_requires_independent_review_and_snapshots_only_confirmed_learners(): void
    {
        $fixture = $this->fixture();
        $base = '/t/'.$fixture['tenant']->slug;
        $this->actingAs($fixture['teacher'])->post($base.'/assignments', $this->assignmentPayload($fixture))->assertRedirect();
        $assignment = Assignment::withoutGlobalScope('tenant')->where('tenant_id', $fixture['tenant']->getKey())->firstOrFail();
        $this->assertSame('draft', $assignment->status);

        $this->actingAs($fixture['teacher'])->post($base.'/assignments/'.$assignment->getKey().'/submit-review')->assertRedirect();
        $this->assertSame('under_review', $assignment->fresh()->status);
        $this->actingAs($fixture['reviewer'])->post($base.'/assignments/'.$assignment->getKey().'/review', ['decision' => 'approve'])->assertRedirect();
        $this->assertSame('approved', $assignment->fresh()->status);
        $this->actingAs($fixture['teacher'])->post($base.'/assignments/'.$assignment->getKey().'/publish')->assertRedirect();

        $assignment->refresh();
        $this->assertSame('published', $assignment->status);
        $this->assertSame(1, (int) $assignment->current_version);
        $this->assertSame(1, AssignmentVersion::withoutGlobalScope('tenant')->where('tenant_id', $fixture['tenant']->getKey())->where('assignment_id', $assignment->getKey())->count());
        $this->assertSame(2, AssignmentRecipient::withoutGlobalScope('tenant')->where('tenant_id', $fixture['tenant']->getKey())->where('assignment_id', $assignment->getKey())->count());
        $this->assertDatabaseHas('assignment_outbox_events', ['tenant_id' => $fixture['tenant']->getKey(), 'event_type' => 'assignment.published']);
        $this->assertDatabaseHas('audit_events', ['tenant_id' => $fixture['tenant']->getKey(), 'event_type' => 'assignments.published']);
        $context = app(TenantContext::class);
        $context->enterProvisioning($fixture['tenant']);
        try {
            $processor = app(AssignmentOutboxProcessor::class);
            $first = $processor->dispatchAvailable();
            $second = $processor->dispatchAvailable();
        } finally {
            $context->clear();
        }
        $this->assertSame(1, $first['delivered']);
        $this->assertSame(0, $second['claimed']);
        $this->assertSame(2, AssignmentNotification::query()->where('tenant_id', $fixture['tenant']->getKey())->count());
        $this->assertDatabaseHas('assignment_notifications', ['tenant_id' => $fixture['tenant']->getKey(), 'user_id' => $fixture['student']->getKey(), 'event_type' => 'assignment.published']);
    }

    public function test_published_assignment_can_be_copied_as_an_unscheduled_draft_without_changing_its_history(): void
    {
        $fixture = $this->fixture();
        $source = $this->publishedAssignment($fixture);
        $sourceVersion = $source->current_version;
        $sourceRecipients = AssignmentRecipient::withoutGlobalScope('tenant')->where('assignment_id', $source->getKey())->count();
        $base = '/t/'.$fixture['tenant']->slug;

        $this->actingAs($fixture['teacher'])->post($base.'/assignments/'.$source->getKey().'/copy')->assertRedirect();

        $copy = Assignment::withoutGlobalScope('tenant')->where('tenant_id', $fixture['tenant']->getKey())->where('id', '!=', $source->getKey())->firstOrFail();
        $this->assertSame('draft', $copy->status);
        $this->assertSame(0, (int) $copy->current_version);
        $this->assertSame('Evidence Essay (copy)', $copy->title);
        $this->assertNull($copy->available_at);
        $this->assertNull($copy->due_at);
        $this->assertNull($copy->late_until);
        $this->assertSame(0, AssignmentRecipient::withoutGlobalScope('tenant')->where('assignment_id', $copy->getKey())->count());
        $this->assertSame($sourceVersion, (int) $source->fresh()->current_version);
        $this->assertSame('published', $source->fresh()->status);
        $this->assertSame($sourceRecipients, AssignmentRecipient::withoutGlobalScope('tenant')->where('assignment_id', $source->getKey())->count());
        $this->assertDatabaseHas('audit_events', ['tenant_id' => $fixture['tenant']->getKey(), 'event_type' => 'assignments.copied_as_draft', 'subject_id' => $copy->getKey()]);

        $this->actingAs($fixture['student'])->post($base.'/assignments/'.$source->getKey().'/copy')->assertForbidden();
    }

    public function test_learner_draft_submission_receipt_and_grade_release_are_authorized_and_idempotent(): void
    {
        $fixture = $this->fixture();
        $assignment = $this->publishedAssignment($fixture);
        $recipient = AssignmentRecipient::withoutGlobalScope('tenant')->where('tenant_id', $fixture['tenant']->getKey())->where('assignment_id', $assignment->getKey())->where('membership_id', $fixture['studentMembership']->getKey())->firstOrFail();
        $base = '/t/'.$fixture['tenant']->slug;

        $this->actingAs($fixture['studentTwo'])->get($base.'/my-assignments/recipients/'.$recipient->getKey())->assertNotFound();
        $this->actingAs($fixture['student'])->get($base.'/my-assignments/recipients/'.$recipient->getKey())->assertOk()->assertInertia(fn ($page) => $page->component('workspace/assignments/student-show')->where('assignment.title', 'Evidence Essay'));
        $this->actingAs($fixture['student'])->post($base.'/my-assignments/recipients/'.$recipient->getKey().'/draft', ['response_text' => 'A saved response with a reasoned explanation.', 'idempotency_key' => (string) Str::uuid()])->assertRedirect();
        $attempt = AssignmentAttempt::withoutGlobalScope('tenant')->where('tenant_id', $fixture['tenant']->getKey())->firstOrFail();
        $this->assertSame('draft', $attempt->status);
        $this->assertNull($attempt->submitted_at);
        $this->assertSame(1, AssignmentAttemptRevision::withoutGlobalScope('tenant')->where('attempt_id', $attempt->getKey())->count());
        $this->actingAs($fixture['student'])->post($base.'/my-assignments/recipients/'.$recipient->getKey().'/submit', ['attempt_id' => $attempt->getKey()])->assertRedirect();
        $attempt->refresh();
        $this->assertSame('submitted', $attempt->status);
        $this->assertNotNull($attempt->receipt_reference);
        $this->assertSame(1, (int) $attempt->receipt_snapshot['attempt_number']);
        $this->assertSame(0, (int) $attempt->receipt_snapshot['file_count']);
        $this->actingAs($fixture['student'])->post($base.'/my-assignments/recipients/'.$recipient->getKey().'/submit', ['attempt_id' => $attempt->getKey()])->assertRedirect();
        $this->assertSame(1, AssignmentAttempt::withoutGlobalScope('tenant')->where('tenant_id', $fixture['tenant']->getKey())->count());
        $this->actingAs($fixture['studentTwo'])->get($base.'/my-assignments/receipts/'.$attempt->receipt_reference)->assertNotFound();
        $this->actingAs($fixture['student'])->get($base.'/my-assignments/receipts/'.$attempt->receipt_reference)->assertOk()->assertInertia(fn ($page) => $page->component('workspace/assignments/receipt')->where('receipt.reference', $attempt->receipt_reference));

        $this->actingAs($fixture['teacher'])->post($base.'/assignments/attempts/'.$attempt->getKey().'/grade', ['expected_version' => 0, 'score_points' => 84, 'learner_feedback' => 'Clear reasoning and evidence.', 'private_notes' => 'Moderation note remains private.'])->assertRedirect(route('tenant.assignments.submissions', [$fixture['tenant'], $assignment->getKey()]));
        $grade = AssignmentGrade::withoutGlobalScope('tenant')->where('tenant_id', $fixture['tenant']->getKey())->firstOrFail();
        $this->assertSame('draft', $grade->status);
        $this->assertSame(1, (int) $grade->lock_version);
        $this->actingAs($fixture['teacher'])->post($base.'/assignments/attempts/'.$attempt->getKey().'/grade', ['expected_version' => 0, 'score_points' => 99, 'learner_feedback' => 'Stale overwrite must be rejected.', 'private_notes' => 'Stale private note.'])->assertSessionHasErrors('grade');
        $this->assertSame('84.00', $grade->fresh()->score_points);
        $this->assertSame('Clear reasoning and evidence.', $grade->fresh()->learner_feedback);
        $this->assertSame(1, (int) $grade->fresh()->lock_version);
        $this->actingAs($fixture['student'])->get($base.'/my-assignments')->assertOk()->assertInertia(fn ($page) => $page->where('assignments.0.grade_released', false));
        $this->actingAs($fixture['student'])->get($base.'/my-assignments/recipients/'.$recipient->getKey())->assertOk()->assertDontSee('Moderation note remains private.');
        $this->actingAs($fixture['reviewer'])->post($base.'/assignments/grades/'.$grade->getKey().'/release')->assertRedirect();
        $this->assertSame('released', $grade->fresh()->status);
        $this->actingAs($fixture['student'])->get($base.'/my-assignments')->assertOk()->assertInertia(fn ($page) => $page->where('assignments.0.grade_released', true)->where('assignments.0.score_points', 84));
    }

    public function test_submission_url_rejects_private_network_targets_and_cross_tenant_assignment_ids(): void
    {
        $fixture = $this->fixture();
        $assignment = $this->publishedAssignment($fixture, 'url');
        $recipient = AssignmentRecipient::withoutGlobalScope('tenant')->where('tenant_id', $fixture['tenant']->getKey())->where('assignment_id', $assignment->getKey())->where('membership_id', $fixture['studentMembership']->getKey())->firstOrFail();
        $base = '/t/'.$fixture['tenant']->slug;
        $this->actingAs($fixture['student'])->post($base.'/my-assignments/recipients/'.$recipient->getKey().'/draft', ['response_url' => 'http://127.0.0.1/admin', 'idempotency_key' => (string) Str::uuid()])->assertSessionHasErrors('response_url');
        $this->actingAs($fixture['student'])->get('/t/'.$this->fixture('other')['tenant']->slug.'/my-assignments/recipients/'.$recipient->getKey())->assertNotFound();
        $this->assertSame(0, AssignmentAttempt::withoutGlobalScope('tenant')->where('tenant_id', $fixture['tenant']->getKey())->count());
    }

    public function test_file_submission_stays_quarantined_until_scanned_and_download_is_owner_scoped(): void
    {
        Storage::fake('local');
        Queue::fake();
        $fixture = $this->fixture('Assignment Files');
        $assignment = $this->publishedAssignment($fixture, 'file');
        $recipient = AssignmentRecipient::withoutGlobalScope('tenant')->where('tenant_id', $fixture['tenant']->getKey())->where('assignment_id', $assignment->getKey())->where('membership_id', $fixture['studentMembership']->getKey())->firstOrFail();
        $base = '/t/'.$fixture['tenant']->slug;
        $this->actingAs($fixture['student'])->post($base.'/my-assignments/recipients/'.$recipient->getKey().'/draft', ['idempotency_key' => (string) Str::uuid()])->assertRedirect();
        $attempt = AssignmentAttempt::withoutGlobalScope('tenant')->where('tenant_id', $fixture['tenant']->getKey())->firstOrFail();
        $this->actingAs($fixture['student'])->post($base.'/my-assignments/recipients/'.$recipient->getKey().'/artifacts', ['files' => [UploadedFile::fake()->create('solution.pdf', 120, 'application/pdf')]])->assertRedirect();
        $artifact = AssignmentArtifact::withoutGlobalScope('tenant')->where('tenant_id', $fixture['tenant']->getKey())->firstOrFail();
        $this->assertSame('quarantined', $artifact->scan_status);
        $this->actingAs($fixture['student'])->post($base.'/my-assignments/recipients/'.$recipient->getKey().'/submit', ['attempt_id' => $attempt->getKey()])->assertSessionHasErrors('response_text');
        $scanner = new class implements AdmissionDocumentScanner
        {
            public function scan(string $absolutePath): AdmissionDocumentScanResult
            {
                return is_file($absolutePath) ? AdmissionDocumentScanResult::clean() : AdmissionDocumentScanResult::unavailable();
            }

            public function name(): string
            {
                return 'assignment-test-clean';
            }
        };
        (new ScanAssignmentArtifact((int) $fixture['tenant']->getKey(), (int) $artifact->getKey()))->handle($scanner, app(TenantContext::class), app(AuditRecorder::class));
        $this->assertSame('clean', $artifact->fresh()->scan_status);
        $this->actingAs($fixture['studentTwo'])->get($base.'/my-assignments/artifacts/'.$artifact->getKey().'/download')->assertNotFound();
        $this->actingAs($fixture['student'])->get($base.'/my-assignments/artifacts/'.$artifact->getKey().'/download')->assertOk();
        $this->actingAs($fixture['student'])->post($base.'/my-assignments/recipients/'.$recipient->getKey().'/submit', ['attempt_id' => $attempt->getKey()])->assertRedirect();
        $attempt->refresh();
        $this->assertSame('submitted', $attempt->status);
        $this->assertSame(1, (int) $attempt->receipt_snapshot['file_count']);
        $this->assertSame(2, (int) $attempt->receipt_snapshot['revision_number']);
        $this->assertDatabaseHas('audit_events', ['tenant_id' => $fixture['tenant']->getKey(), 'event_type' => 'assignments.artifact.scanned']);
    }

    public function test_real_clamav_scans_assignment_clean_and_eicar_artifacts_fail_closed(): void
    {
        if (config('admissions.document_scanner') !== 'clamav') {
            $this->markTestSkipped('Real ClamAV assignment verification requires the local scanner and fresh signatures to be configured.');
        }

        Storage::fake('local');
        Queue::fake();
        $fixture = $this->fixture('Assignment Real ClamAV');
        $assignment = $this->publishedAssignment($fixture, 'file');
        $base = '/t/'.$fixture['tenant']->slug;
        $scanner = app(AdmissionDocumentScanner::class);
        $this->assertInstanceOf(ClamAvAdmissionDocumentScanner::class, $scanner);

        $cleanRecipient = AssignmentRecipient::withoutGlobalScope('tenant')
            ->where('tenant_id', $fixture['tenant']->getKey())
            ->where('assignment_id', $assignment->getKey())
            ->where('membership_id', $fixture['studentMembership']->getKey())
            ->firstOrFail();
        $this->actingAs($fixture['student'])->post($base.'/my-assignments/recipients/'.$cleanRecipient->getKey().'/draft', [
            'idempotency_key' => (string) Str::uuid(),
        ])->assertRedirect();
        $cleanAttempt = AssignmentAttempt::withoutGlobalScope('tenant')
            ->where('tenant_id', $fixture['tenant']->getKey())
            ->firstOrFail();
        $this->actingAs($fixture['student'])->post($base.'/my-assignments/recipients/'.$cleanRecipient->getKey().'/artifacts', [
            'files' => [UploadedFile::fake()->createWithContent('clean-assignment.txt', 'A harmless assignment file for real scanner verification.')],
        ])->assertRedirect();
        $cleanArtifact = AssignmentArtifact::withoutGlobalScope('tenant')
            ->where('tenant_id', $fixture['tenant']->getKey())
            ->firstOrFail();
        $this->assertSame('quarantined', $cleanArtifact->scan_status);
        $this->actingAs($fixture['student'])->get($base.'/my-assignments/artifacts/'.$cleanArtifact->getKey().'/download')->assertNotFound();

        (new ScanAssignmentArtifact((int) $fixture['tenant']->getKey(), (int) $cleanArtifact->getKey()))
            ->handle($scanner, app(TenantContext::class), app(AuditRecorder::class));
        $cleanArtifact->refresh();
        $this->assertSame('clean', $cleanArtifact->scan_status);
        $this->assertStringStartsWith('clamav/', (string) $cleanArtifact->scanner);
        $this->actingAs($fixture['student'])->get($base.'/my-assignments/artifacts/'.$cleanArtifact->getKey().'/download')->assertOk();
        $this->actingAs($fixture['student'])->post($base.'/my-assignments/recipients/'.$cleanRecipient->getKey().'/submit', [
            'attempt_id' => $cleanAttempt->getKey(),
        ])->assertRedirect();
        $this->assertSame('submitted', $cleanAttempt->fresh()->status);

        $infectedRecipient = AssignmentRecipient::withoutGlobalScope('tenant')
            ->where('tenant_id', $fixture['tenant']->getKey())
            ->where('assignment_id', $assignment->getKey())
            ->where('membership_id', $fixture['studentTwoMembership']->getKey())
            ->firstOrFail();
        $this->actingAs($fixture['studentTwo'])->post($base.'/my-assignments/recipients/'.$infectedRecipient->getKey().'/draft', [
            'idempotency_key' => (string) Str::uuid(),
        ])->assertRedirect();
        $infectedAttempt = AssignmentAttempt::withoutGlobalScope('tenant')
            ->where('tenant_id', $fixture['tenant']->getKey())
            ->where('submission_id', function ($query) use ($infectedRecipient): void {
                $query->select('id')->from('assignment_submissions')->where('recipient_id', $infectedRecipient->getKey());
            })
            ->firstOrFail();
        $eicar = 'X5O!P%@AP[4\\PZX54(P^)7CC)7}$EICAR-STANDARD-ANTIVIRUS-TEST-FILE!$H+H*';
        $this->actingAs($fixture['studentTwo'])->post($base.'/my-assignments/recipients/'.$infectedRecipient->getKey().'/artifacts', [
            'files' => [UploadedFile::fake()->createWithContent('eicar-assignment-test.txt', $eicar)],
        ])->assertRedirect();
        $infectedArtifact = AssignmentArtifact::withoutGlobalScope('tenant')
            ->where('tenant_id', $fixture['tenant']->getKey())
            ->where('attempt_id', $infectedAttempt->getKey())
            ->firstOrFail();
        $this->assertSame('quarantined', $infectedArtifact->scan_status);

        (new ScanAssignmentArtifact((int) $fixture['tenant']->getKey(), (int) $infectedArtifact->getKey()))
            ->handle($scanner, app(TenantContext::class), app(AuditRecorder::class));
        $infectedArtifact->refresh();
        $this->assertSame('infected', $infectedArtifact->scan_status);
        $this->assertSame('malware_detected', $infectedArtifact->result_code);
        $this->actingAs($fixture['studentTwo'])->get($base.'/my-assignments/artifacts/'.$infectedArtifact->getKey().'/download')->assertNotFound();
        $this->actingAs($fixture['studentTwo'])->post($base.'/my-assignments/recipients/'.$infectedRecipient->getKey().'/submit', [
            'attempt_id' => $infectedAttempt->getKey(),
        ])->assertSessionHasErrors('response_text');
        $this->assertDatabaseHas('audit_events', [
            'tenant_id' => $fixture['tenant']->getKey(), 'event_type' => 'assignments.artifact.scanned',
            'subject_id' => $infectedArtifact->getKey(),
        ]);
    }

    public function test_infected_assignment_artifact_remains_undownloadable_and_unsubmittable(): void
    {
        Storage::fake('local');
        Queue::fake();
        $fixture = $this->fixture('Infected Assignment Files');
        $assignment = $this->publishedAssignment($fixture, 'file');
        $recipient = AssignmentRecipient::withoutGlobalScope('tenant')->where('tenant_id', $fixture['tenant']->getKey())->where('assignment_id', $assignment->getKey())->where('membership_id', $fixture['studentMembership']->getKey())->firstOrFail();
        $base = '/t/'.$fixture['tenant']->slug;
        $this->actingAs($fixture['student'])->post($base.'/my-assignments/recipients/'.$recipient->getKey().'/draft', ['idempotency_key' => (string) Str::uuid()])->assertRedirect();
        $attempt = AssignmentAttempt::withoutGlobalScope('tenant')->where('tenant_id', $fixture['tenant']->getKey())->firstOrFail();
        $this->actingAs($fixture['student'])->post($base.'/my-assignments/recipients/'.$recipient->getKey().'/artifacts', ['files' => [UploadedFile::fake()->create('suspect.pdf', 100, 'application/pdf')]])->assertRedirect();
        $artifact = AssignmentArtifact::withoutGlobalScope('tenant')->where('tenant_id', $fixture['tenant']->getKey())->firstOrFail();
        $scanner = new class implements AdmissionDocumentScanner
        {
            public function scan(string $absolutePath): AdmissionDocumentScanResult
            {
                return AdmissionDocumentScanResult::rejected('malware_detected');
            }

            public function name(): string
            {
                return 'assignment-test-infected';
            }
        };
        (new ScanAssignmentArtifact((int) $fixture['tenant']->getKey(), (int) $artifact->getKey()))->handle($scanner, app(TenantContext::class), app(AuditRecorder::class));
        $this->assertSame('infected', $artifact->fresh()->scan_status);
        $this->actingAs($fixture['student'])->get($base.'/my-assignments/artifacts/'.$artifact->getKey().'/download')->assertNotFound();
        $this->actingAs($fixture['student'])->post($base.'/my-assignments/recipients/'.$recipient->getKey().'/submit', ['attempt_id' => $attempt->getKey()])->assertSessionHasErrors('response_text');
    }

    public function test_instructor_can_return_work_for_revision_and_learner_can_submit_a_preserved_next_attempt(): void
    {
        $fixture = $this->fixture('Assignment Revisions');
        $assignment = $this->publishedAssignment($fixture);
        $recipient = AssignmentRecipient::withoutGlobalScope('tenant')->where('tenant_id', $fixture['tenant']->getKey())->where('assignment_id', $assignment->getKey())->where('membership_id', $fixture['studentMembership']->getKey())->firstOrFail();
        $base = '/t/'.$fixture['tenant']->slug;
        $this->actingAs($fixture['student'])->post($base.'/my-assignments/recipients/'.$recipient->getKey().'/draft', ['response_text' => 'First submitted draft.', 'idempotency_key' => (string) Str::uuid()])->assertRedirect();
        $firstAttempt = AssignmentAttempt::withoutGlobalScope('tenant')->where('tenant_id', $fixture['tenant']->getKey())->firstOrFail();
        $this->actingAs($fixture['student'])->post($base.'/my-assignments/recipients/'.$recipient->getKey().'/submit', ['attempt_id' => $firstAttempt->getKey()])->assertRedirect();
        $this->actingAs($fixture['teacher'])->post($base.'/assignments/attempts/'.$firstAttempt->getKey().'/return', ['learner_feedback' => 'Add a source citation and clarify the evidence.'])->assertRedirect();
        $this->assertSame('returned_for_revision', $firstAttempt->submission->fresh()->status);
        $this->actingAs($fixture['student'])->get($base.'/my-assignments')->assertOk()->assertInertia(fn ($page) => $page->where('assignments.0.status', 'returned_for_revision')->where('assignments.0.feedback', 'Add a source citation and clarify the evidence.'));
        $this->actingAs($fixture['student'])->post($base.'/my-assignments/recipients/'.$recipient->getKey().'/draft', ['response_text' => 'Revised work with the requested citation.', 'idempotency_key' => (string) Str::uuid()])->assertRedirect();
        $secondAttempt = AssignmentAttempt::withoutGlobalScope('tenant')->where('tenant_id', $fixture['tenant']->getKey())->orderByDesc('attempt_number')->firstOrFail();
        $this->assertSame(2, (int) $secondAttempt->attempt_number);
        $this->actingAs($fixture['student'])->post($base.'/my-assignments/recipients/'.$recipient->getKey().'/submit', ['attempt_id' => $secondAttempt->getKey()])->assertRedirect();
        $this->assertSame(2, AssignmentAttempt::withoutGlobalScope('tenant')->where('tenant_id', $fixture['tenant']->getKey())->count());
        $this->assertSame('submitted', $secondAttempt->fresh()->status);
    }

    public function test_assignment_windows_reject_nonexistent_and_ambiguous_daylight_saving_times(): void
    {
        $fixture = $this->fixture('Assignment Timezones');
        $base = '/t/'.$fixture['tenant']->slug.'/assignments';
        $payload = $this->assignmentPayload($fixture);
        $payload['timezone'] = 'America/New_York';
        $payload['due_at'] = '2026-03-08T02:30';
        $this->actingAs($fixture['teacher'])->post($base, $payload)->assertSessionHasErrors('due_at');
        $payload['due_at'] = '2026-11-01T01:30';
        $this->actingAs($fixture['teacher'])->post($base, $payload)->assertSessionHasErrors('due_at');
        $payload['due_at'] = '2026-11-01T01:30-04:00';
        $this->actingAs($fixture['teacher'])->post($base, $payload)->assertRedirect();
    }

    public function test_scheduled_publication_and_closure_are_processed_without_a_browser_session(): void
    {
        $fixture = $this->fixture('Assignment Scheduling');
        $base = '/t/'.$fixture['tenant']->slug;
        $start = now()->toImmutable();
        Carbon::setTestNow($start);
        try {
            $payload = $this->assignmentPayload($fixture);
            $payload['available_at'] = $start->addMinutes(10)->format('Y-m-d\TH:i');
            $this->actingAs($fixture['teacher'])->post($base.'/assignments', $payload)->assertRedirect();
            $assignment = Assignment::withoutGlobalScope('tenant')->where('tenant_id', $fixture['tenant']->getKey())->firstOrFail();
            $this->actingAs($fixture['teacher'])->post($base.'/assignments/'.$assignment->getKey().'/submit-review')->assertRedirect();
            $this->actingAs($fixture['reviewer'])->post($base.'/assignments/'.$assignment->getKey().'/review', ['decision' => 'approve'])->assertRedirect();
            $this->actingAs($fixture['teacher'])->post($base.'/assignments/'.$assignment->getKey().'/publish')->assertRedirect();
            $this->assertSame('scheduled', $assignment->fresh()->status);
            $this->assertSame(0, AssignmentRecipient::withoutGlobalScope('tenant')->where('assignment_id', $assignment->getKey())->count());
            $context = app(TenantContext::class);
            $context->enterProvisioning($fixture['tenant']);
            try {
                Carbon::setTestNow($start->addMinutes(11));
                $published = app(AssignmentWorkflow::class)->processAvailabilityTransitions();
                $this->assertSame(['published' => 1, 'closed' => 0], $published);
                $this->assertSame('published', $assignment->fresh()->status);
                $this->assertSame(2, AssignmentRecipient::withoutGlobalScope('tenant')->where('assignment_id', $assignment->getKey())->count());
                Carbon::setTestNow($start->addDays(4));
                $closed = app(AssignmentWorkflow::class)->processAvailabilityTransitions();
                $this->assertSame(['published' => 0, 'closed' => 1], $closed);
                $this->assertSame('closed', $assignment->fresh()->status);
            } finally {
                $context->clear();
            }
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_group_submission_is_shared_idempotent_and_freezes_membership(): void
    {
        $fixture = $this->fixture('Assignment Group Work');
        $base = '/t/'.$fixture['tenant']->slug;
        $payload = $this->assignmentPayload($fixture);
        $payload['collaboration_mode'] = 'group';
        $this->actingAs($fixture['teacher'])->post($base.'/assignments', $payload)->assertRedirect();
        $assignment = Assignment::withoutGlobalScope('tenant')->where('tenant_id', $fixture['tenant']->getKey())->firstOrFail();
        $this->actingAs($fixture['teacher'])->post($base.'/assignments/'.$assignment->getKey().'/submit-review')->assertRedirect();
        $this->actingAs($fixture['reviewer'])->post($base.'/assignments/'.$assignment->getKey().'/review', ['decision' => 'approve'])->assertRedirect();
        $this->actingAs($fixture['teacher'])->post($base.'/assignments/'.$assignment->getKey().'/publish')->assertRedirect();
        $this->actingAs($fixture['teacher'])->post($base.'/assignments/'.$assignment->getKey().'/groups', [
            'section_id' => $fixture['section']->getKey(), 'name' => 'Evidence Team',
            'membership_ids' => [$fixture['studentMembership']->getKey(), $fixture['studentTwoMembership']->getKey()],
            'leader_membership_id' => $fixture['studentMembership']->getKey(),
        ])->assertRedirect();
        $group = AssignmentGroup::withoutGlobalScope('tenant')->where('tenant_id', $fixture['tenant']->getKey())->firstOrFail();
        $recipientOne = AssignmentRecipient::withoutGlobalScope('tenant')->where('assignment_id', $assignment->getKey())->where('membership_id', $fixture['studentMembership']->getKey())->firstOrFail();
        $recipientTwo = AssignmentRecipient::withoutGlobalScope('tenant')->where('assignment_id', $assignment->getKey())->where('membership_id', $fixture['studentTwoMembership']->getKey())->firstOrFail();
        $this->actingAs($fixture['student'])->post($base.'/my-assignments/recipients/'.$recipientOne->getKey().'/draft', ['response_text' => 'Shared work started.', 'idempotency_key' => (string) Str::uuid()])->assertRedirect();
        $this->actingAs($fixture['studentTwo'])->post($base.'/my-assignments/recipients/'.$recipientTwo->getKey().'/draft', ['response_text' => 'Shared work improved.', 'idempotency_key' => (string) Str::uuid()])->assertRedirect();
        $submission = AssignmentSubmission::withoutGlobalScope('tenant')->where('assignment_id', $assignment->getKey())->where('group_id', $group->getKey())->firstOrFail();
        $this->assertSame(1, AssignmentAttempt::withoutGlobalScope('tenant')->where('submission_id', $submission->getKey())->count());
        $attempt = AssignmentAttempt::withoutGlobalScope('tenant')->where('submission_id', $submission->getKey())->firstOrFail();
        $this->assertSame('Shared work improved.', $attempt->response_text);
        $this->actingAs($fixture['studentTwo'])->post($base.'/my-assignments/recipients/'.$recipientTwo->getKey().'/submit', ['attempt_id' => $attempt->getKey()])->assertRedirect();
        $this->assertSame(2, count($submission->fresh()->group_membership_snapshot));
        $this->assertNotNull($group->fresh()->frozen_at);
        $this->actingAs($fixture['student'])->get($base.'/my-assignments/receipts/'.$attempt->fresh()->receipt_reference)->assertOk();
        $this->actingAs($fixture['studentTwo'])->get($base.'/my-assignments/receipts/'.$attempt->fresh()->receipt_reference)->assertOk();
    }

    public function test_extension_approval_is_scoped_audited_notifies_and_can_be_revoked_before_reliance(): void
    {
        $fixture = $this->fixture('Assignment Extensions');
        $assignment = $this->publishedAssignment($fixture);
        $recipient = AssignmentRecipient::withoutGlobalScope('tenant')->where('assignment_id', $assignment->getKey())->where('membership_id', $fixture['studentMembership']->getKey())->firstOrFail();
        $base = '/t/'.$fixture['tenant']->slug;
        $requestedDue = $assignment->due_at->addDays(2)->format('Y-m-d\TH:i');
        $this->actingAs($fixture['student'])->post($base.'/my-assignments/recipients/'.$recipient->getKey().'/extensions', ['reason' => 'I need time to complete a documented medical accommodation.', 'requested_due_at' => $requestedDue])->assertRedirect();
        $extension = AssignmentExtension::withoutGlobalScope('tenant')->where('recipient_id', $recipient->getKey())->firstOrFail();
        $this->actingAs($fixture['teacher'])->get($base.'/assignments/extensions')->assertOk();
        $this->actingAs($fixture['teacher'])->post($base.'/assignments/extensions/'.$extension->getKey().'/decision', ['decision' => 'approve'])->assertRedirect();
        $this->assertSame('approved', $extension->fresh()->status);
        $this->assertTrue($recipient->fresh()->effective_due_at->greaterThan($assignment->due_at));
        $this->actingAs($fixture['teacher'])->post($base.'/assignments/extensions/'.$extension->getKey().'/revoke', ['reason' => 'The institution confirmed the accommodation was entered in error.'])->assertRedirect();
        $this->assertSame('revoked', $extension->fresh()->status);
        $this->assertSame($assignment->due_at->toDateTimeString(), $recipient->fresh()->effective_due_at->toDateTimeString());
        $this->assertDatabaseHas('audit_events', ['tenant_id' => $fixture['tenant']->getKey(), 'event_type' => 'assignments.extension.revoked']);
    }

    public function test_approved_extension_is_the_authoritative_submission_cutoff_and_ui_decision(): void
    {
        $fixture = $this->fixture('Assignment Extension Cutoff');
        $assignment = $this->publishedAssignment($fixture);
        $recipient = AssignmentRecipient::withoutGlobalScope('tenant')->where('assignment_id', $assignment->getKey())->where('membership_id', $fixture['studentMembership']->getKey())->firstOrFail();
        $base = '/t/'.$fixture['tenant']->slug;
        $extendedDue = $assignment->due_at->addDays(2);

        $this->actingAs($fixture['student'])->post($base.'/my-assignments/recipients/'.$recipient->getKey().'/extensions', [
            'reason' => 'A documented accommodation requires a little more completion time.',
            'requested_due_at' => $extendedDue->format('Y-m-d\\TH:i'),
        ])->assertRedirect();
        $extension = AssignmentExtension::withoutGlobalScope('tenant')->where('recipient_id', $recipient->getKey())->firstOrFail();
        $this->actingAs($fixture['teacher'])->post($base.'/assignments/extensions/'.$extension->getKey().'/decision', ['decision' => 'approve'])->assertRedirect();
        $this->actingAs($fixture['student'])->post($base.'/my-assignments/recipients/'.$recipient->getKey().'/draft', [
            'response_text' => 'The work is complete under the approved extension.', 'idempotency_key' => (string) Str::uuid(),
        ])->assertRedirect();
        $attempt = AssignmentAttempt::withoutGlobalScope('tenant')->where('tenant_id', $fixture['tenant']->getKey())->firstOrFail();

        Carbon::setTestNow($assignment->due_at->addHour());
        try {
            $this->actingAs($fixture['student'])->get($base.'/my-assignments/recipients/'.$recipient->getKey())->assertOk()->assertInertia(fn ($page) => $page->where('can_submit', true));
            $this->actingAs($fixture['student'])->get($base.'/my-assignments')->assertOk()->assertInertia(fn ($page) => $page->where('assignments.0.due_at', $recipient->fresh()->effective_due_at->toIso8601String())->where('assignments.0.late', false));
            $this->actingAs($fixture['student'])->post($base.'/my-assignments/recipients/'.$recipient->getKey().'/submit', ['attempt_id' => $attempt->getKey()])->assertRedirect();
            $this->assertSame('submitted', $attempt->fresh()->status);
            $this->assertFalse((bool) $attempt->fresh()->is_late);

            Carbon::setTestNow($recipient->fresh()->effective_due_at->addSecond());
            $this->actingAs($fixture['student'])->get($base.'/my-assignments/recipients/'.$recipient->getKey())->assertOk()->assertInertia(fn ($page) => $page->where('can_submit', false));
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_rubric_versions_are_immutable_and_criterion_scores_are_server_calculated(): void
    {
        $fixture = $this->fixture('Assignment Rubric Academy');
        $base = '/t/'.$fixture['tenant']->slug;
        $criteria = [
            ['title' => 'Evidence', 'description' => 'Use relevant sources.', 'maximum_points' => 60],
            ['title' => 'Reasoning', 'description' => 'Explain the claim.', 'maximum_points' => 40],
        ];
        $this->actingAs($fixture['teacher'])->post($base.'/assignments/rubrics', ['name' => 'Analytical Writing', 'maximum_points' => 100, 'criteria' => $criteria])->assertRedirect();
        $rubric = AssignmentRubric::withoutGlobalScope('tenant')->where('tenant_id', $fixture['tenant']->getKey())->firstOrFail();
        $version = AssignmentRubricVersion::withoutGlobalScope('tenant')->where('rubric_id', $rubric->getKey())->firstOrFail();
        $this->actingAs($fixture['teacher'])->post($base.'/assignments/rubric-versions/'.$version->getKey().'/publish')->assertRedirect();
        $version->refresh();
        $this->assertSame('published', $version->status);

        $payload = $this->assignmentPayload($fixture);
        $payload['rubric_version_id'] = $version->getKey();
        $this->actingAs($fixture['teacher'])->post($base.'/assignments', $payload)->assertRedirect();
        $assignment = Assignment::withoutGlobalScope('tenant')->where('tenant_id', $fixture['tenant']->getKey())->firstOrFail();
        $this->assertSame((int) $version->getKey(), (int) $assignment->rubric_version_id);
        $this->actingAs($fixture['teacher'])->post($base.'/assignments/'.$assignment->getKey().'/submit-review')->assertRedirect();
        $this->actingAs($fixture['reviewer'])->post($base.'/assignments/'.$assignment->getKey().'/review', ['decision' => 'approve'])->assertRedirect();
        $this->actingAs($fixture['teacher'])->post($base.'/assignments/'.$assignment->getKey().'/publish')->assertRedirect();
        $recipient = AssignmentRecipient::withoutGlobalScope('tenant')->where('assignment_id', $assignment->getKey())->where('membership_id', $fixture['studentMembership']->getKey())->firstOrFail();
        $this->actingAs($fixture['student'])->post($base.'/my-assignments/recipients/'.$recipient->getKey().'/draft', ['response_text' => 'A response that can be assessed against published criteria.', 'idempotency_key' => (string) Str::uuid()])->assertRedirect();
        $attempt = AssignmentAttempt::withoutGlobalScope('tenant')->where('tenant_id', $fixture['tenant']->getKey())->latest('id')->firstOrFail();
        $this->actingAs($fixture['student'])->post($base.'/my-assignments/recipients/'.$recipient->getKey().'/submit', ['attempt_id' => $attempt->getKey()])->assertRedirect();
        $criterionIds = $version->criteria()->pluck('id')->all();
        $this->actingAs($fixture['teacher'])->post($base.'/assignments/attempts/'.$attempt->getKey().'/grade', ['expected_version' => 0, 'criterion_scores' => [$criterionIds[0] => 61, $criterionIds[1] => 31], 'learner_feedback' => 'Some criterion points exceed their published maximum.'])->assertSessionHasErrors('criterion_scores.'.$criterionIds[0]);
        $this->actingAs($fixture['teacher'])->post($base.'/assignments/attempts/'.$attempt->getKey().'/grade', ['expected_version' => 0, 'criterion_scores' => [$criterionIds[0] => 56, $criterionIds[1] => 34], 'learner_feedback' => 'Good evidence and careful reasoning.'])->assertRedirect();
        $grade = AssignmentGrade::withoutGlobalScope('tenant')->where('attempt_id', $attempt->getKey())->firstOrFail();
        $this->assertSame('90.00', $grade->score_points);
        $this->assertSame((int) $version->getKey(), (int) $grade->rubric_version_id);
        $this->assertSame(['56.00', '34.00'], array_values($grade->criterion_scores ?? []));
        $this->actingAs($fixture['reviewer'])->post($base.'/assignments/grades/'.$grade->getKey().'/release')->assertRedirect();

        $this->actingAs($fixture['teacher'])->post($base.'/assignments/rubrics/'.$rubric->getKey().'/versions', ['maximum_points' => 100, 'criteria' => [
            ['title' => 'Evidence revised', 'description' => 'Updated source standard.', 'maximum_points' => 50],
            ['title' => 'Reasoning revised', 'description' => 'Updated explanation standard.', 'maximum_points' => 50],
        ]])->assertRedirect();
        $secondVersion = AssignmentRubricVersion::withoutGlobalScope('tenant')->where('rubric_id', $rubric->getKey())->orderByDesc('version_number')->firstOrFail();
        $this->actingAs($fixture['teacher'])->post($base.'/assignments/rubric-versions/'.$secondVersion->getKey().'/publish')->assertRedirect();
        $this->assertSame((int) $version->getKey(), (int) $assignment->fresh()->rubric_version_id);
        $this->assertSame((int) $version->getKey(), (int) $grade->fresh()->rubric_version_id);
    }

    public function test_integrity_concern_requires_learner_response_and_independent_human_decision(): void
    {
        $fixture = $this->fixture('Assignment Integrity Academy');
        $assignment = $this->publishedAssignment($fixture);
        $recipient = AssignmentRecipient::withoutGlobalScope('tenant')->where('assignment_id', $assignment->getKey())->where('membership_id', $fixture['studentMembership']->getKey())->firstOrFail();
        $base = '/t/'.$fixture['tenant']->slug;
        $this->actingAs($fixture['student'])->post($base.'/my-assignments/recipients/'.$recipient->getKey().'/draft', ['response_text' => 'Submitted answer with an evidence-based paragraph.', 'idempotency_key' => (string) Str::uuid()])->assertRedirect();
        $attempt = AssignmentAttempt::withoutGlobalScope('tenant')->where('tenant_id', $fixture['tenant']->getKey())->firstOrFail();
        $this->actingAs($fixture['student'])->post($base.'/my-assignments/recipients/'.$recipient->getKey().'/submit', ['attempt_id' => $attempt->getKey()])->assertRedirect();
        $this->actingAs($fixture['teacher'])->post($base.'/assignments/attempts/'.$attempt->getKey().'/integrity-cases', ['case_type' => 'source_use', 'concern_summary' => 'Please explain the source attribution visible in this submitted response.'])->assertRedirect(route('tenant.assignments.submissions', [$fixture['tenant'], $assignment->getKey()]));
        $case = AssignmentIntegrityCase::withoutGlobalScope('tenant')->where('attempt_id', $attempt->getKey())->firstOrFail();
        $this->assertSame('awaiting_learner', $case->status);
        $this->assertSame('not_configured', $case->similarity_status);
        $this->assertNotNull($case->response_due_at);
        $this->assertDatabaseHas('assignment_outbox_events', ['tenant_id' => $fixture['tenant']->getKey(), 'event_type' => 'assignment.integrity.concerned']);
        $this->actingAs($fixture['studentTwo'])->post($base.'/my-assignments/integrity-cases/'.$case->getKey().'/response', ['response' => 'This is not the learner assigned to this submission.'])->assertNotFound();
        $this->actingAs($fixture['reviewer'])->post($base.'/assignments/integrity-cases/'.$case->getKey().'/decision', ['disposition' => 'substantiated', 'review_notes' => 'Evidence reviewed independently.'])->assertSessionHasErrors('disposition');
        $this->actingAs($fixture['student'])->post($base.'/my-assignments/integrity-cases/'.$case->getKey().'/response', ['response' => 'I used the cited source only to verify a definition and wrote the analysis myself.'])->assertRedirect(route('tenant.assignments.mine.show', [$fixture['tenant'], $recipient->getKey()]));
        $this->assertSame('under_review', $case->fresh()->status);
        $this->actingAs($fixture['reviewer'])->post($base.'/assignments/integrity-cases/'.$case->getKey().'/decision', ['disposition' => 'cleared', 'review_notes' => 'The cited source supports a definition; the learner response is consistent with independent work.'])->assertRedirect();
        $this->assertSame('resolved', $case->fresh()->status);
        $this->assertSame('cleared', $case->fresh()->disposition);
        $this->assertDatabaseHas('assignment_outbox_events', ['tenant_id' => $fixture['tenant']->getKey(), 'event_type' => 'assignment.integrity.response']);
        $this->assertDatabaseHas('assignment_outbox_events', ['tenant_id' => $fixture['tenant']->getKey(), 'event_type' => 'assignment.integrity.decision']);
        $this->assertDatabaseHas('audit_events', ['tenant_id' => $fixture['tenant']->getKey(), 'event_type' => 'assignments.integrity.decided']);

        $this->actingAs($fixture['teacher'])->post($base.'/assignments/attempts/'.$attempt->getKey().'/integrity-cases', ['case_type' => 'authorship', 'concern_summary' => 'Please describe how the submitted analysis was prepared.'])->assertRedirect();
        $overdueCase = AssignmentIntegrityCase::withoutGlobalScope('tenant')->where('attempt_id', $attempt->getKey())->orderByDesc('id')->firstOrFail();
        $overdueCase->forceFill(['response_due_at' => now()->subMinute()])->save();
        $context = app(TenantContext::class);
        $context->enterProvisioning($fixture['tenant']);
        try {
            $this->assertSame(1, app(AssignmentWorkflow::class)->processOverdueIntegrityResponses());
        } finally {
            $context->clear();
        }
        $this->assertSame('under_review', $overdueCase->fresh()->status);
        $this->actingAs($fixture['reviewer'])->post($base.'/assignments/integrity-cases/'.$overdueCase->getKey().'/decision', ['disposition' => 'substantiated', 'review_notes' => 'The learner did not submit a response before the notice deadline. The evidence was reviewed by an independent administrator.'])->assertRedirect(route('tenant.assignments.submissions', [$fixture['tenant'], $assignment->getKey()]));
        $this->assertSame('substantiated', $overdueCase->fresh()->disposition);
        $this->assertDatabaseHas('audit_events', ['tenant_id' => $fixture['tenant']->getKey(), 'event_type' => 'assignments.integrity.response_deadline_expired']);
        $context = app(TenantContext::class);
        $context->enterProvisioning($fixture['tenant']);
        try {
            $dispatch = app(AssignmentOutboxProcessor::class)->dispatchAvailable();
        } finally {
            $context->clear();
        }
        $this->assertGreaterThan(0, $dispatch['delivered']);
        $this->assertDatabaseHas('assignment_notifications', ['tenant_id' => $fixture['tenant']->getKey(), 'user_id' => $fixture['student']->getKey(), 'event_type' => 'assignment.integrity.concerned']);
        $this->assertDatabaseHas('assignment_notifications', ['tenant_id' => $fixture['tenant']->getKey(), 'user_id' => $fixture['teacher']->getKey(), 'event_type' => 'assignment.integrity.response']);
        $this->assertDatabaseHas('assignment_notifications', ['tenant_id' => $fixture['tenant']->getKey(), 'user_id' => $fixture['student']->getKey(), 'event_type' => 'assignment.integrity.decision']);
    }

    public function test_assignment_analytics_filters_use_real_scoped_counts_and_csv_neutralizes_formulas(): void
    {
        $fixture = $this->fixture('Assignment Reports');
        $base = '/t/'.$fixture['tenant']->slug;
        $payload = $this->assignmentPayload($fixture);
        $payload['title'] = '=HYPERLINK("https://example.test")';
        $this->actingAs($fixture['teacher'])->post($base.'/assignments', $payload)->assertRedirect();
        $this->actingAs($fixture['teacher'])->get($base.'/assignments/analytics?status=draft')->assertOk()->assertInertia(fn ($page) => $page->where('metrics.assignments', 1)->where('metrics.eligible_learners', 0)->where('assignments.data.0.title', '=HYPERLINK("https://example.test")'));
        $response = $this->actingAs($fixture['teacher'])->get($base.'/assignments/analytics.csv?status=draft')->assertOk()->assertHeader('content-type', 'text/csv; charset=UTF-8');
        $this->assertStringContainsString("'=HYPERLINK(", $response->streamedContent());
    }

    /** @return array<string,mixed> */
    /** @param array<string, mixed> $fixture
     * @return array<string, mixed>
     */
    private function assignmentPayload(array $fixture, string $mode = 'text'): array
    {
        return [
            'offering_id' => $fixture['offering']->getKey(), 'section_ids' => [$fixture['section']->getKey()],
            'title' => 'Evidence Essay', 'assignment_type' => 'essay', 'category' => 'Writing',
            'summary' => 'Support a conclusion with credible evidence.', 'instructions' => 'Write a concise response using your own words.',
            'submission_mode' => $mode, 'maximum_attempts' => 2, 'maximum_points' => 100,
            'timezone' => 'UTC', 'due_at' => now()->addDays(3)->format('Y-m-d\TH:i'), 'review_required' => true,
        ];
    }

    /** @param array<string, mixed> $fixture */
    private function publishedAssignment(array $fixture, string $mode = 'text'): Assignment
    {
        $base = '/t/'.$fixture['tenant']->slug;
        $this->actingAs($fixture['teacher'])->post($base.'/assignments', $this->assignmentPayload($fixture, $mode))->assertRedirect();
        $assignment = Assignment::withoutGlobalScope('tenant')->where('tenant_id', $fixture['tenant']->getKey())->latest('id')->firstOrFail();
        $this->actingAs($fixture['teacher'])->post($base.'/assignments/'.$assignment->getKey().'/submit-review')->assertRedirect();
        $this->actingAs($fixture['reviewer'])->post($base.'/assignments/'.$assignment->getKey().'/review', ['decision' => 'approve'])->assertRedirect();
        $this->actingAs($fixture['teacher'])->post($base.'/assignments/'.$assignment->getKey().'/publish')->assertRedirect();

        return $assignment->refresh();
    }

    /** @return array<string,mixed> */
    private function fixture(string $name = 'Assignment Academy'): array
    {
        $creator = User::factory()->create();
        $owner = User::factory()->create();
        $teacher = User::factory()->create();
        $student = User::factory()->create();
        $studentTwo = User::factory()->create();
        $reviewer = User::factory()->create();
        $tenant = app(TenantProvisioner::class)->provision([
            'slug' => Str::slug($name).'-'.Str::lower(Str::random(6)), 'official_name' => $name, 'display_name' => $name,
            'code' => '', 'type' => 'academy', 'contact_email' => '', 'contact_phone' => '', 'website' => '', 'country' => '',
            'region' => '', 'city' => '', 'address' => '', 'timezone' => 'UTC', 'locale' => 'en',
        ], $creator, $owner->email)->tenant;
        $context = app(TenantContext::class);
        $context->enterProvisioning($tenant);
        try {
            $institution = Institution::query()->firstOrFail();
            $campus = Campus::query()->create(['institution_id' => $institution->getKey(), 'code' => 'MAIN', 'name' => 'Main Campus', 'status' => 'active', 'is_primary' => true]);
            $memberships = [];
            foreach ([[$teacher, 'teacher'], [$student, 'student'], [$studentTwo, 'student'], [$reviewer, 'academic.admin']] as [$user, $roleKey]) {
                $membership = TenantMembership::query()->create(['institution_id' => $institution->getKey(), 'user_id' => $user->getKey(), 'status' => 'active', 'activated_at' => now()]);
                $role = Role::query()->where('tenant_id', $tenant->getKey())->where('key', $roleKey)->firstOrFail();
                $membership->roles()->attach($role->getKey(), ['assigned_by' => $creator->getKey(), 'tenant_id' => $tenant->getKey()]);
                $memberships[$roleKey.':'.$user->getKey()] = $membership;
            }
            $year = AcademicYear::query()->create(['institution_id' => $institution->getKey(), 'name' => 'Year', 'code' => 'AY-'.Str::upper(Str::random(6)), 'starts_on' => today()->subMonth(), 'ends_on' => today()->addMonths(5), 'timezone' => 'UTC', 'locale' => 'en', 'status' => 'active']);
            $period = AcademicPeriod::query()->create(['academic_year_id' => $year->getKey(), 'name' => 'Term', 'code' => 'TERM-'.Str::upper(Str::random(6)), 'period_type' => 'term', 'sequence' => 1, 'starts_on' => today()->subMonth(), 'ends_on' => today()->addMonths(4), 'status' => 'active']);
            $course = AcademicCourse::query()->create(['institution_id' => $institution->getKey(), 'code' => 'ENG-'.Str::upper(Str::random(6)), 'title' => 'Academic Writing', 'status' => 'active']);
            $policy = EnrollmentPolicy::query()->create(['institution_id' => $institution->getKey(), 'name' => 'Writing Policy']);
            $offering = CourseOffering::query()->create(['institution_id' => $institution->getKey(), 'campus_id' => $campus->getKey(), 'academic_year_id' => $year->getKey(), 'academic_period_id' => $period->getKey(), 'academic_course_id' => $course->getKey(), 'policy_id' => $policy->getKey(), 'offering_code' => 'WR-'.Str::upper(Str::random(6)), 'status' => 'open']);
            $section = CourseSection::query()->create(['offering_id' => $offering->getKey(), 'campus_id' => $campus->getKey(), 'section_code' => 'A', 'capacity' => 20, 'status' => 'open']);
            DB::table('course_section_instructors')->insert(['tenant_id' => $tenant->getKey(), 'section_id' => $section->getKey(), 'user_id' => $teacher->getKey(), 'role' => 'instructor', 'created_at' => now(), 'updated_at' => now()]);
            $students = [];
            foreach ([[$student, 'One'], [$studentTwo, 'Two']] as [$user, $suffix]) {
                $sis = SisStudent::query()->create(['institution_id' => $institution->getKey(), 'home_campus_id' => $campus->getKey(), 'user_id' => $user->getKey(), 'student_number' => 'ST-'.Str::upper(Str::random(8)), 'registration_reference' => 'REF-'.Str::upper(Str::random(8)), 'legal_first_name' => 'Learner', 'legal_last_name' => $suffix, 'admission_date' => today()->subYear(), 'status' => 'active', 'student_type' => 'standard']);
                CourseEnrollment::query()->create(['institution_id' => $institution->getKey(), 'student_id' => $sis->getKey(), 'offering_id' => $offering->getKey(), 'section_id' => $section->getKey(), 'status' => 'confirmed', 'registration_source' => 'registrar', 'requested_at' => now()->subDay(), 'confirmed_at' => now()->subDay()]);
                $students[$user->getKey()] = $sis;
            }
        } finally {
            $context->clear();
        }

        return ['tenant' => $tenant, 'owner' => $owner, 'teacher' => $teacher, 'student' => $student, 'studentTwo' => $studentTwo, 'reviewer' => $reviewer, 'studentMembership' => $memberships['student:'.$student->getKey()], 'studentTwoMembership' => $memberships['student:'.$studentTwo->getKey()], 'institution' => $institution, 'offering' => $offering, 'section' => $section, 'sis' => $students[$student->getKey()]];
    }
}
