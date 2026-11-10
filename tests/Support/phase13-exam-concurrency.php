<?php

declare(strict_types=1);

use App\Domain\Assessments\QuizAssessmentWorkflow;
use App\Domain\Assessments\QuizOutboxProcessor;
use App\Domain\Examinations\ExamComponentWorkflow;
use App\Domain\Examinations\ExamDeliveryWorkflow;
use App\Domain\Examinations\ExaminationResultService;
use App\Domain\Examinations\ExamOperationsWorkflow;
use App\Domain\Examinations\ExamPaperPublicationWorkflow;
use App\Domain\Tenancy\TenantContext;
use App\Domain\Tenancy\TenantProvisioner;
use App\Models\AcademicCourse;
use App\Models\AcademicPeriod;
use App\Models\AcademicRoom;
use App\Models\AcademicYear;
use App\Models\Campus;
use App\Models\CourseEnrollment;
use App\Models\CourseOffering;
use App\Models\CourseSection;
use App\Models\EnrollmentPolicy;
use App\Models\ExamComponentMarkDraft;
use App\Models\ExamPaperVersion;
use App\Models\Institution;
use App\Models\Role;
use App\Models\SisStudent;
use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Tests\Support\Phase13MySqlTestTarget;
use Tests\Support\Phase13ProbeAuthorization;
use Tests\Support\Phase13ProbeEnvironment;

$root = dirname(__DIR__, 2);
require $root.'/vendor/autoload.php';
$app = require $root.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$environment = Phase13ProbeEnvironment::current();

try {
    Phase13ProbeAuthorization::assertAuthorized(DB::connection()->getPdo(), $environment);
    $identity = DB::selectOne('SELECT DATABASE() AS database_name, CURRENT_USER() AS account_name, CONNECTION_ID() AS connection_id');
    if ($identity?->database_name !== Phase13MySqlTestTarget::DATABASE) {
        throw new RuntimeException('The Phase 13 probe connection is not the exact authorized schema.');
    }
    $lock = DB::selectOne('SELECT IS_USED_LOCK(?) AS owner', [Phase13MySqlTestTarget::SERVER_LOCK]);
    if ($lock?->owner === null) {
        throw new RuntimeException('The guarded runner server lock is required for Phase 13 probes.');
    }
} catch (Throwable) {
    fwrite(STDERR, "Phase 13 probe refused database identity, grant policy, or guarded runner authorization.\n");
    exit(2);
}

$framework = realpath(storage_path('framework'));
if ($framework === false || is_link($framework)) {
    fwrite(STDERR, "Phase 13 probe requires a private framework coordination directory.\n");
    exit(2);
}

$mode = $argv[1] ?? 'orchestrate';
if ($mode === 'worker') {
    phase13Worker($argv, $root, $framework, $environment);
}
if ($mode !== 'orchestrate') {
    fwrite(STDERR, "Unknown Phase 13 probe mode.\n");
    exit(2);
}

$cleanup = [];
try {
    $fixture = phase13Fixture();
    $candidate = $fixture['candidates'][0];

    // 1. Two sessions race the same candidate/cycle registration; the unique tenant/cycle/student key is authoritative.
    $registration = phase13Race('candidate_registration', $fixture, [
        'op' => 'register', 'student_id' => $fixture['unregistered_student_id'], 'candidate_number' => 'RACE-'.$fixture['run_id'],
    ], [
        'op' => 'register', 'student_id' => $fixture['unregistered_student_id'], 'candidate_number' => 'RACE-'.$fixture['run_id'],
    ], $framework, $cleanup);
    phase13AssertOutcomes($registration, ['success', 'success']);
    phase13Assert(DB::table('exam_candidates')->where('tenant_id', $fixture['tenant_id'])->where('cycle_id', $fixture['cycle_id'])->where('student_id', $fixture['unregistered_student_id'])->count() === 1, 'candidate registration left duplicate rows');
    phase13Assert(DB::table('audit_events')->where('tenant_id', $fixture['tenant_id'])->where('event_type', 'exams.candidate.registered')->where('subject_id', (string) DB::table('exam_candidates')->where('cycle_id', $fixture['cycle_id'])->where('student_id', $fixture['unregistered_student_id'])->value('id'))->count() === 1, 'idempotent registration did not emit exactly one audit event');
    phase13Say('candidate registration', $registration);

    // 2. Different candidates compete for the same physical seat.
    $seat = phase13Race('seat_allocation', $fixture, [
        'op' => 'seat', 'candidate_id' => $fixture['candidates'][0]['candidate_id'], 'seat_number' => 'RACE-01',
    ], [
        'op' => 'seat', 'candidate_id' => $fixture['candidates'][1]['candidate_id'], 'seat_number' => 'RACE-01',
    ], $framework, $cleanup);
    phase13AssertOutcomes($seat, ['conflict', 'success']);
    phase13Assert(DB::table('exam_seat_allocations')->where('tenant_id', $fixture['tenant_id'])->where('session_id', $fixture['session_id'])->where('room_id', $fixture['room_id'])->where('seat_number', 'RACE-01')->count() === 1, 'seat race occupied one seat more than once');
    phase13Assert(DB::table('audit_events')->where('tenant_id', $fixture['tenant_id'])->where('event_type', 'exams.seat.allocated')->where('metadata->seat_number', 'RACE-01')->count() === 1, 'seat race did not create exactly one allocation audit event');
    phase13Say('seat allocation', $seat);

    // 3. Competing revisions claim the paper's single current publication slot under a row lock.
    $paper = phase13Race('paper_publication', $fixture, [
        'op' => 'publish_paper', 'paper_id' => $fixture['paper_id'], 'version_id' => $fixture['paper_versions'][0], 'actor_id' => $fixture['release_user_id'], 'membership_id' => $fixture['release_membership_id'],
    ], [
        'op' => 'publish_paper', 'paper_id' => $fixture['paper_id'], 'version_id' => $fixture['paper_versions'][1], 'actor_id' => $fixture['release_user_id'], 'membership_id' => $fixture['release_membership_id'],
    ], $framework, $cleanup);
    $paperOutcomes = array_column($paper, 'outcome');
    sort($paperOutcomes);
    phase13Assert(in_array($paperOutcomes, [['conflict', 'success'], ['success', 'success']], true), 'paper publication did not serialize with stale-version rejection: '.implode(',', $paperOutcomes));
    $publishedPaper = DB::table('exam_papers')->where('id', $fixture['paper_id'])->first();
    $publishedCount = DB::table('exam_paper_versions')->where('paper_id', $fixture['paper_id'])->where('status', 'published')->count();
    $latestPublished = DB::table('exam_paper_versions')->where('paper_id', $fixture['paper_id'])->where('version_number', 2)->where('status', 'published')->exists();
    phase13Assert($publishedPaper !== null && (int) $publishedPaper->current_version === 2 && $publishedCount === 1 && $latestPublished, 'paper publication invariant failed: current='.(int) ($publishedPaper->current_version ?? 0).', published='.$publishedCount.', latest_published='.(int) $latestPublished);
    phase13Say('paper publication', $paper);

    phase13ConfigureDelivery($fixture);

    // 4. Competing attempts bind different quiz attempts to one candidate/session; the unique binding wins once.
    $start = phase13Race('exam_attempt_start', $fixture, [
        'op' => 'start_attempt', 'actor_id' => $fixture['candidates'][0]['user_id'], 'membership_id' => $fixture['candidates'][0]['membership_id'],
    ], [
        'op' => 'start_attempt', 'actor_id' => $fixture['candidates'][0]['user_id'], 'membership_id' => $fixture['candidates'][0]['membership_id'],
    ], $framework, $cleanup);
    phase13AssertOutcomes($start, ['success', 'success']);
    phase13Assert(DB::table('exam_attempt_bindings')->where('tenant_id', $fixture['tenant_id'])->where('session_id', $fixture['session_id'])->where('candidate_id', $candidate['candidate_id'])->count() === 1, 'candidate/session acquired multiple authoritative attempts');
    phase13Assert(DB::table('quiz_attempts')->where('tenant_id', $fixture['tenant_id'])->where('quiz_id', $fixture['quiz_id'])->where('membership_id', $candidate['membership_id'])->count() === 1, 'concurrent formal exam start created more than one quiz attempt');
    phase13Assert(DB::table('audit_events')->where('tenant_id', $fixture['tenant_id'])->where('event_type', 'exams.attempt.started')->where('subject_id', (string) DB::table('exam_attempt_bindings')->where('session_id', $fixture['session_id'])->where('candidate_id', $candidate['candidate_id'])->value('id'))->count() === 1, 'formal exam start did not emit exactly one audit event');
    phase13Say('exam attempt start', $start);

    $finalization = phase13CreateFinalizationAttempt($fixture);

    // 5. Answer autosave and finalization serialize on the attempt. Save is accepted only before finalization.
    $saveFinalize = phase13Race('autosave_finalization', $fixture, [
        'op' => 'autosave', 'attempt_id' => $finalization['attempt_id'], 'response_id' => $finalization['response_id'], 'actor_id' => $fixture['candidates'][2]['user_id'], 'membership_id' => $fixture['candidates'][2]['membership_id'], 'expected_version' => 0, 'question_version_id' => $fixture['question_version_id'],
    ], [
        'op' => 'finalize', 'attempt_id' => $finalization['attempt_id'], 'actor_id' => $fixture['candidates'][2]['user_id'], 'membership_id' => $fixture['candidates'][2]['membership_id'],
    ], $framework, $cleanup);
    phase13Say('autosave/submission worker results', $saveFinalize);
    $attempt = DB::table('quiz_attempts')->where('id', $finalization['attempt_id'])->first();
    $response = DB::table('quiz_attempt_responses')->where('id', $finalization['response_id'])->first();
    $revisionCount = DB::table('quiz_response_revisions')->where('response_id', $finalization['response_id'])->count();
    phase13Assert($attempt !== null && in_array($attempt->status, ['graded', 'needs_manual_review'], true) && $attempt->submitted_at !== null, 'autosave/finalization race invariant failed: status='.(string) ($attempt->status ?? 'missing').', submitted='.(string) ($attempt->submitted_at ?? 'null'));
    phase13Assert($response !== null && (int) $response->lock_version === $revisionCount, 'response version and committed revision history diverged');
    if ($revisionCount === 0) {
        phase13Assert(in_array('conflict', array_column($saveFinalize, 'outcome'), true), 'an uncommitted autosave was not reported as a conflict');
    }
    phase13Assert(DB::table('exam_attempt_bindings')->where('attempt_id', $finalization['attempt_id'])->whereNotNull('submitted_at')->count() === 1, 'exam receipt was not committed exactly once');
    phase13Assert(DB::table('quiz_outbox_events')->where('tenant_id', $fixture['tenant_id'])->where('event_type', 'exam.attempt_submitted')->where('event_key', 'exam.attempt_submitted:attempt:'.$finalization['attempt_id'])->count() === 1, 'formal exam submission did not enqueue exactly one idempotent notification event');
    phase13Assert(DB::table('audit_events')->where('tenant_id', $fixture['tenant_id'])->where('event_type', 'assessment.attempt.submitted')->where('subject_id', (string) $finalization['attempt_id'])->count() === 1, 'attempt finalization did not create exactly one audit event');
    phase13Say('autosave versus final submission', $saveFinalize);

    // 6. Two graders update one optimistic draft revision; only one expected-version write may commit.
    $mark = phase13Race('concurrent_marking', $fixture, [
        'op' => 'save_mark', 'draft_id' => $fixture['mark_draft_id'], 'allocation_id' => $fixture['allocation_id'], 'expected_version' => 1, 'actor_id' => $fixture['owner_id'],
    ], [
        'op' => 'save_mark', 'draft_id' => $fixture['mark_draft_id'], 'allocation_id' => $fixture['allocation_id'], 'expected_version' => 1, 'actor_id' => $fixture['owner_id'],
    ], $framework, $cleanup);
    phase13AssertOutcomes($mark, ['success', 'conflict']);
    phase13Assert((int) DB::table('exam_component_mark_drafts')->where('id', $fixture['mark_draft_id'])->value('lock_version') === 2, 'concurrent marking lost optimistic revision control');
    phase13Assert(DB::table('audit_events')->where('tenant_id', $fixture['tenant_id'])->where('event_type', 'exams.marking.draft_saved')->where('subject_id', (string) $fixture['mark_draft_id'])->count() === 1, 'concurrent marking did not audit its one committed revision');
    phase13Say('concurrent script marking', $mark);

    // 7. Approval competes with a proposed amendment. Row lock + lifecycle check permits one transition only.
    $resultRace = phase13Race('result_approval_amendment', $fixture, [
        'op' => 'approve_result', 'result_id' => $fixture['result_id'], 'actor_id' => $fixture['owner_id'],
    ], [
        'op' => 'approve_result', 'result_id' => $fixture['result_id'], 'actor_id' => $fixture['owner_id'],
    ], $framework, $cleanup);
    phase13AssertOutcomes($resultRace, ['conflict', 'success']);
    $result = DB::table('exam_results')->where('id', $fixture['result_id'])->first();
    phase13Assert($result !== null && $result->status === 'approved', 'result race did not leave the approved state');
    phase13Assert(DB::table('exam_result_approvals')->where('result_id', $fixture['result_id'])->count() === 1, 'result race created duplicate approval history');
    phase13Assert(DB::table('audit_events')->where('tenant_id', $fixture['tenant_id'])->where('event_type', 'exams.result.approved')->count() === 1, 'result approval did not create exactly one audit event');
    phase13Say('result approval versus amendment', $resultRace);

    // 8. Concurrent release workers generate one logical outbox key.
    $notice = phase13Race('result_notification', $fixture, [
        'op' => 'release_result', 'result_id' => $fixture['result_id'], 'actor_id' => $fixture['release_user_id'], 'membership_id' => $fixture['release_membership_id'],
    ], [
        'op' => 'release_result', 'result_id' => $fixture['result_id'], 'actor_id' => $fixture['release_user_id'], 'membership_id' => $fixture['release_membership_id'],
    ], $framework, $cleanup);
    phase13AssertOutcomes($notice, ['conflict', 'success']);
    phase13Assert(DB::table('quiz_outbox_events')->where('tenant_id', $fixture['tenant_id'])->where('event_key', 'exam.result.released:'.$fixture['result_id'].':v1')->count() === 1, 'duplicate logical result notification event was persisted');
    phase13Assert(DB::table('audit_events')->where('tenant_id', $fixture['tenant_id'])->where('event_type', 'exams.result.released')->count() === 1, 'result notification release did not audit exactly once');
    $tenant = Tenant::query()->findOrFail($fixture['tenant_id']);
    $context = app(TenantContext::class);
    $context->enterProvisioning($tenant);
    try {
        $delivery = app(QuizOutboxProcessor::class)->dispatchAvailable();
    } finally {
        $context->clear();
    }
    $expectedRecipientId = $fixture['candidates'][1]['user_id'];
    $releaseEvent = DB::table('quiz_outbox_events')->where('tenant_id', $fixture['tenant_id'])->where('event_key', 'exam.result.released:'.$fixture['result_id'].':v1')->first(['status', 'attempts', 'last_error_code']);
    $releaseNotifications = DB::table('quiz_notifications')->where('tenant_id', $fixture['tenant_id'])->where('event_type', 'exam.result_released')->get(['user_id']);
    phase13Assert($releaseEvent !== null && $releaseEvent->status === 'delivered', 'the result release outbox event was not delivered (processor delivered='.$delivery['delivered'].', retried='.$delivery['retried'].', status='.($releaseEvent->status ?? 'missing').', error='.($releaseEvent->last_error_code ?? 'none').')');
    phase13Assert($releaseNotifications->count() === 1 && (int) $releaseNotifications->first()->user_id === $expectedRecipientId, 'result release notification was not delivered exclusively to its eligible candidate');
    phase13Assert(! $releaseNotifications->contains(static fn (object $notification): bool => (int) $notification->user_id === (int) $fixture['release_user_id']), 'result release notification was delivered to the releasing controller');
    phase13Say('result notification idempotency', $notice);

    fwrite(STDOUT, "Phase 13 MySQL concurrency probes passed: 8 probes, 16 worker sessions; every pair used distinct connection IDs and bounded barrier synchronization.\n");
} catch (Throwable $error) {
    fwrite(STDERR, 'Phase 13 probe failed: '.class_basename($error).': '.preg_replace('/[^A-Za-z0-9 _.-]/', '', $error->getMessage()).PHP_EOL);
    exit(1);
} finally {
    phase13Cleanup($cleanup, $framework);
    app(TenantContext::class)->clear();
}

/**
 * Each worker establishes its own PDO session, verifies identity/grants, and waits at a file barrier.
 *
 * @param  list<string>  $argv
 * @param  array<string,string>  $environment
 */
function phase13Worker(array $argv, string $root, string $framework, array $environment): never
{
    $run = (string) ($argv[2] ?? '');
    $label = (string) ($argv[3] ?? '');
    $encoded = (string) ($argv[4] ?? '');
    $payload = json_decode(base64_decode($encoded, true) ?: '', true);
    if (! preg_match('/\A[a-f0-9-]{36}\z/i', $run) || ! in_array($label, ['a', 'b'], true) || ! is_array($payload)) {
        fwrite(STDERR, "Invalid Phase 13 worker metadata.\n");
        exit(2);
    }
    $pdo = null;
    $prefix = 'phase13-exam-'.$run.'-'.preg_replace('/[^a-z_]/', '', (string) ($payload['probe'] ?? ''));
    $ready = $framework.DIRECTORY_SEPARATOR.$prefix.'-'.$label.'.ready';
    $go = $framework.DIRECTORY_SEPARATOR.$prefix.'.go';
    $resultFile = $framework.DIRECTORY_SEPARATOR.$prefix.'-'.$label.'.result';
    foreach ([$ready, $go, $resultFile] as $path) {
        if (dirname($path) !== $framework || is_link($path) || file_exists($path)) {
            fwrite(STDERR, "Unsafe or reused Phase 13 coordination path.\n");
            exit(2);
        }
    }
    try {
        $pdo = DB::connection()->getPdo();
        Phase13ProbeAuthorization::assertAuthorized($pdo, $environment);
        $id = (int) DB::scalar('SELECT CONNECTION_ID()');
        DB::statement('SET SESSION innodb_lock_wait_timeout=12');
        phase13WriteExclusive($ready, (string) $id);
        $deadline = microtime(true) + 30;
        while (! is_file($go) && microtime(true) < $deadline) {
            usleep(10000);
        }
        if (! is_file($go) || is_link($go)) {
            throw new RuntimeException('synchronized barrier timed out');
        }
        $outcome = phase13ApplicationOperation($payload);
        phase13WriteExclusive($resultFile, json_encode(['connection_id' => $id, 'outcome' => $outcome], JSON_THROW_ON_ERROR));
        exit(0);
    } catch (PDOException $error) {
        $code = (int) ($error->errorInfo[1] ?? 0);
        $outcome = in_array($code, [1062, 1205, 1213], true) ? 'conflict' : 'failed';
        phase13WriteExclusive($resultFile, json_encode(['connection_id' => $pdo instanceof PDO ? (int) DB::scalar('SELECT CONNECTION_ID()') : 0, 'outcome' => $outcome, 'error' => $code], JSON_THROW_ON_ERROR));
        exit($outcome === 'failed' ? 1 : 0);
    } catch (Throwable $error) {
        $reason = substr((string) preg_replace('/[^A-Za-z0-9 _.-]/', '', $error->getMessage()), 0, 160);
        phase13WriteExclusive($resultFile, json_encode(['connection_id' => $pdo instanceof PDO ? (int) DB::scalar('SELECT CONNECTION_ID()') : 0, 'outcome' => 'failed', 'error' => class_basename($error).($reason === '' ? '' : ':'.$reason)], JSON_THROW_ON_ERROR));
        exit(1);
    }
}

/** @param array<string,mixed> $payload */
function phase13ApplicationOperation(array $payload): string
{
    if (! in_array($payload['op'] ?? null, ['register', 'seat', 'publish_paper', 'start_attempt', 'autosave', 'finalize', 'save_mark', 'approve_result', 'release_result'], true)) {
        throw new RuntimeException('A concurrency probe attempted to invoke an unreviewed application operation.');
    }
    $tenant = Tenant::query()->where('slug', $payload['tenant_slug'])->firstOrFail();
    $membership = TenantMembership::query()->withoutGlobalScope('tenant')->where('tenant_id', $tenant->getKey())->whereKey($payload['membership_id'])->where('status', 'active')->firstOrFail();
    $actor = User::query()->findOrFail($payload['actor_id']);
    $context = app(TenantContext::class);
    $context->set($tenant, $membership);
    try {
        $workflow = app(ExamOperationsWorkflow::class);
        if ($payload['op'] === 'register') {
            $workflow->registerCandidate((int) $payload['cycle_id'], (int) $payload['student_id'], $actor);
        } elseif ($payload['op'] === 'seat') {
            $workflow->allocateSeat((int) $payload['session_id'], (int) $payload['candidate_id'], (int) $payload['room_id'], (string) $payload['seat_number'], $actor);
        } elseif ($payload['op'] === 'publish_paper') {
            app(ExamPaperPublicationWorkflow::class)->publish((int) $payload['version_id'], $actor);
        } elseif ($payload['op'] === 'start_attempt') {
            Carbon::setTestNow(Carbon::parse(DB::table('exam_sessions')->where('id', $payload['session_id'])->value('starts_at_utc'))->addMinutes(5));
            app(ExamDeliveryWorkflow::class)->start((int) $payload['session_id'], $actor);
        } elseif ($payload['op'] === 'autosave') {
            app(QuizAssessmentWorkflow::class)->saveResponse((int) $payload['attempt_id'], (int) $payload['question_version_id'], ['text' => 'saved through the assessment workflow'], (int) $payload['expected_version'], $actor);
        } elseif ($payload['op'] === 'finalize') {
            app(QuizAssessmentWorkflow::class)->submitAttempt((int) $payload['attempt_id'], $actor);
        } elseif ($payload['op'] === 'save_mark') {
            app(ExamComponentWorkflow::class)->saveDraft((int) $payload['allocation_id'], [], 'Concurrency probe', '', (int) $payload['expected_version'], $actor);
        } elseif ($payload['op'] === 'approve_result') {
            app(ExaminationResultService::class)->approve((int) $payload['result_id'], ['scores_verified' => true, 'attendance_verified' => true, 'paper_version_verified' => true], 'Concurrent production workflow approval', $actor);
        } else {
            app(ExaminationResultService::class)->release((int) $payload['result_id'], $actor);
        }

        return 'success';
    } catch (HttpExceptionInterface $error) {
        if (in_array($error->getStatusCode(), [409, 422], true)) {
            return 'conflict';
        }
        throw new RuntimeException('http-'.$error->getStatusCode().'-'.$error->getMessage(), previous: $error);
    } catch (QueryException $error) {
        return in_array((int) ($error->errorInfo[1] ?? 0), [1062, 1205, 1213], true) ? 'conflict' : throw $error;
    } finally {
        $context->clear();
    }
}

/** @param array<string,mixed> $fixture */
function phase13ConfigureDelivery(array $fixture): void
{
    $tenant = Tenant::query()->whereKey($fixture['tenant_id'])->firstOrFail();
    $membership = TenantMembership::query()->withoutGlobalScope('tenant')->where('tenant_id', $fixture['tenant_id'])->whereKey($fixture['owner_membership_id'])->firstOrFail();
    $actor = User::query()->findOrFail($fixture['owner_id']);
    $context = app(TenantContext::class);
    $context->set($tenant, $membership);
    try {
        app(ExamDeliveryWorkflow::class)->configure(
            (int) $fixture['session_id'], (int) $fixture['quiz_id'], (int) $fixture['paper_versions'][1], $actor,
        );
    } finally {
        $context->clear();
    }
}

/** @param array<string,mixed> $fixture
 * @return array{attempt_id:int,response_id:int}
 */
function phase13CreateFinalizationAttempt(array $fixture): array
{
    $now = now();
    $candidate = $fixture['candidates'][2];
    $snapshot = [['question_version_id' => $fixture['question_version_id'], 'question_type' => 'short_text', 'content' => ['prompt' => 'Concurrency'], 'points' => '10.00']];
    $attemptId = DB::table('quiz_attempts')->insertGetId([
        'tenant_id' => $fixture['tenant_id'], 'quiz_id' => $fixture['quiz_id'], 'quiz_version_id' => $fixture['quiz_version_id'],
        'student_id' => $candidate['student_id'], 'membership_id' => $candidate['membership_id'], 'attempt_number' => 1,
        'status' => 'in_progress', 'started_at' => $now, 'expires_at' => $now->copy()->addHour(),
        'question_snapshot' => json_encode($snapshot, JSON_THROW_ON_ERROR), 'lock_version' => 1,
        'idempotency_key' => (string) Str::uuid(), 'maximum_points' => '10.00', 'created_at' => $now, 'updated_at' => $now,
    ]);
    $responseId = DB::table('quiz_attempt_responses')->insertGetId([
        'tenant_id' => $fixture['tenant_id'], 'attempt_id' => $attemptId, 'question_version_id' => $fixture['question_version_id'],
        'response_payload' => null, 'lock_version' => 0, 'grading_status' => 'ungraded', 'created_at' => $now, 'updated_at' => $now,
    ]);
    DB::table('exam_attempt_bindings')->insert([
        'tenant_id' => $fixture['tenant_id'], 'session_id' => $fixture['session_id'], 'candidate_id' => $candidate['candidate_id'],
        'attempt_id' => $attemptId, 'started_at' => $now, 'expires_at' => $now->copy()->addHour(), 'created_at' => $now, 'updated_at' => $now,
    ]);

    return ['attempt_id' => $attemptId, 'response_id' => $responseId];
}

/**
 * Create all FK-valid rows used by the eight concurrency races.
 *
 * @return array<string,mixed>
 */
function phase13Fixture(): array
{
    $run = strtolower((string) Str::uuid());
    $creator = User::factory()->create();
    $owner = User::factory()->create();
    $name = 'Phase 13 concurrency '.$run;
    $tenant = app(TenantProvisioner::class)->provision([
        'slug' => 'phase13-concurrency-'.substr(str_replace('-', '', $run), 0, 12), 'official_name' => $name, 'display_name' => $name, 'code' => '', 'type' => 'academy',
        'contact_email' => '', 'contact_phone' => '', 'website' => '', 'country' => '', 'region' => '', 'city' => '', 'address' => '', 'timezone' => 'UTC', 'locale' => 'en',
    ], $creator, $owner->email)->tenant;
    $context = app(TenantContext::class);
    $context->enterProvisioning($tenant);
    try {
        $institution = Institution::query()->firstOrFail();
        $campus = Campus::query()->create(['institution_id' => $institution->getKey(), 'code' => 'MAIN', 'name' => 'Concurrency Campus', 'status' => 'active', 'is_primary' => true]);
        $ownerMembership = TenantMembership::query()->create(['institution_id' => $institution->getKey(), 'user_id' => $owner->getKey(), 'status' => 'active', 'activated_at' => now()]);
        $ownerRole = Role::query()->where('tenant_id', $tenant->getKey())->where('key', 'tenant.owner')->firstOrFail();
        $ownerMembership->roles()->attach($ownerRole->getKey(), ['assigned_by' => $creator->getKey(), 'tenant_id' => $tenant->getKey()]);
        $year = AcademicYear::query()->create(['institution_id' => $institution->getKey(), 'name' => 'Probe Year', 'code' => 'P13-'.substr($run, 0, 8), 'starts_on' => today()->subMonth(), 'ends_on' => today()->addMonths(5), 'timezone' => 'UTC', 'locale' => 'en', 'status' => 'active']);
        $period = AcademicPeriod::query()->create(['academic_year_id' => $year->getKey(), 'name' => 'Probe Term', 'code' => 'P13-'.substr($run, 0, 8), 'period_type' => 'term', 'sequence' => 1, 'starts_on' => today()->subMonth(), 'ends_on' => today()->addMonths(4), 'status' => 'active']);
        $course = AcademicCourse::query()->create(['institution_id' => $institution->getKey(), 'code' => 'P13-'.substr($run, 0, 8), 'title' => 'Concurrency Course', 'status' => 'active']);
        $policy = EnrollmentPolicy::query()->create(['institution_id' => $institution->getKey(), 'name' => 'Probe eligibility']);
        $offering = CourseOffering::query()->create(['institution_id' => $institution->getKey(), 'campus_id' => $campus->getKey(), 'academic_year_id' => $year->getKey(), 'academic_period_id' => $period->getKey(), 'academic_course_id' => $course->getKey(), 'policy_id' => $policy->getKey(), 'offering_code' => 'P13-'.substr($run, 0, 8), 'status' => 'open']);
        $section = CourseSection::query()->create(['offering_id' => $offering->getKey(), 'campus_id' => $campus->getKey(), 'section_code' => 'A', 'capacity' => 10, 'status' => 'open']);
        $room = AcademicRoom::query()->create(['institution_id' => $institution->getKey(), 'campus_id' => $campus->getKey(), 'code' => 'P13-'.substr($run, 0, 8), 'name' => 'Probe Hall', 'room_type' => 'classroom', 'capacity' => 10, 'status' => 'active']);
        $students = [];
        for ($i = 0; $i < 3; $i++) {
            $user = User::factory()->create();
            $membership = TenantMembership::query()->create(['institution_id' => $institution->getKey(), 'user_id' => $user->getKey(), 'status' => 'active', 'activated_at' => now()]);
            $studentRole = Role::query()->where('tenant_id', $tenant->getKey())->where('key', 'student')->firstOrFail();
            $membership->roles()->attach($studentRole->getKey(), ['assigned_by' => $owner->getKey(), 'tenant_id' => $tenant->getKey()]);
            $sis = SisStudent::query()->create(['institution_id' => $institution->getKey(), 'home_campus_id' => $campus->getKey(), 'user_id' => $user->getKey(), 'student_number' => 'P13-'.$i.'-'.substr($run, 0, 7), 'registration_reference' => 'P13-'.$i.'-'.substr($run, 0, 7), 'legal_first_name' => 'Probe', 'legal_last_name' => 'Student'.$i, 'admission_date' => today()->subYear(), 'status' => 'active', 'student_type' => 'standard']);
            CourseEnrollment::query()->create(['institution_id' => $institution->getKey(), 'student_id' => $sis->getKey(), 'offering_id' => $offering->getKey(), 'section_id' => $section->getKey(), 'status' => 'confirmed', 'registration_source' => 'registrar', 'requested_at' => now()->subDay(), 'confirmed_at' => now()->subDay()]);
            $students[] = ['user' => $user, 'membership' => $membership, 'sis' => $sis];
        }
        $now = now();
        $cycleId = DB::table('exam_cycles')->insertGetId(['tenant_id' => $tenant->getKey(), 'institution_id' => $institution->getKey(), 'campus_id' => $campus->getKey(), 'academic_period_id' => $period->getKey(), 'created_by' => $owner->getKey(), 'code' => 'P13-'.$run, 'title' => 'Concurrency Cycle', 'category' => 'final', 'status' => 'published', 'starts_on' => today(), 'ends_on' => today()->addDay(), 'published_at' => $now, 'created_at' => $now, 'updated_at' => $now]);
        $sessionId = DB::table('exam_sessions')->insertGetId(['tenant_id' => $tenant->getKey(), 'cycle_id' => $cycleId, 'campus_id' => $campus->getKey(), 'academic_course_id' => $course->getKey(), 'room_id' => $room->getKey(), 'created_by' => $owner->getKey(), 'code' => 'P13-'.$run, 'title' => 'Concurrency Session', 'mode' => 'online', 'status' => 'published', 'starts_at_utc' => $now->copy()->addHour(), 'ends_at_utc' => $now->copy()->addHours(2), 'duration_minutes' => 60, 'capacity' => 10, 'created_at' => $now, 'updated_at' => $now]);
        $candidates = [];
        foreach ($students as $index => $student) {
            $candidateId = DB::table('exam_candidates')->insertGetId(['tenant_id' => $tenant->getKey(), 'cycle_id' => $cycleId, 'student_id' => $student['sis']->getKey(), 'registered_by' => $owner->getKey(), 'candidate_number' => 'C-'.$index.'-'.substr($run, 0, 8), 'status' => 'registered', 'registered_at' => $now, 'created_at' => $now, 'updated_at' => $now]);
            $candidates[] = ['candidate_id' => $candidateId, 'student_id' => (int) $student['sis']->getKey(), 'user_id' => (int) $student['user']->getKey(), 'membership_id' => (int) $student['membership']->getKey()];
        }
        $registrationUser = User::factory()->create();
        $releaseUser = User::factory()->create();
        $releaseMembership = TenantMembership::query()->create(['institution_id' => $institution->getKey(), 'user_id' => $releaseUser->getKey(), 'status' => 'active', 'activated_at' => now()]);
        $controllerRole = Role::query()->where('tenant_id', $tenant->getKey())->where('key', 'exam.controller')->firstOrFail();
        $releaseMembership->roles()->attach($controllerRole->getKey(), ['assigned_by' => $owner->getKey(), 'tenant_id' => $tenant->getKey()]);
        $registrationStudent = SisStudent::query()->create(['institution_id' => $institution->getKey(), 'home_campus_id' => $campus->getKey(), 'user_id' => $registrationUser->getKey(), 'student_number' => 'P13-R-'.substr($run, 0, 7), 'registration_reference' => 'P13-R-'.substr($run, 0, 7), 'legal_first_name' => 'Registration', 'legal_last_name' => 'Race', 'admission_date' => today()->subYear(), 'status' => 'active', 'student_type' => 'standard']);
        $quizId = DB::table('quizzes')->insertGetId(['tenant_id' => $tenant->getKey(), 'institution_id' => $institution->getKey(), 'offering_id' => $offering->getKey(), 'created_by' => $owner->getKey(), 'status' => 'published', 'current_version' => 1, 'published_at' => $now, 'created_at' => $now, 'updated_at' => $now]);
        $quizVersionId = DB::table('quiz_versions')->insertGetId(['tenant_id' => $tenant->getKey(), 'quiz_id' => $quizId, 'version_number' => 1, 'title' => 'Concurrency Quiz', 'category' => 'exam', 'mode' => 'graded', 'instructions' => 'Probe', 'settings' => json_encode(['maximum_attempts' => 1, 'time_limit_minutes' => 60], JSON_THROW_ON_ERROR), 'total_points' => '10.00', 'status' => 'published', 'created_by' => $owner->getKey(), 'published_at' => $now, 'created_at' => $now, 'updated_at' => $now]);
        $bankId = DB::table('quiz_question_banks')->insertGetId(['tenant_id' => $tenant->getKey(), 'institution_id' => $institution->getKey(), 'campus_id' => $campus->getKey(), 'academic_course_id' => $course->getKey(), 'created_by' => $owner->getKey(), 'code' => 'P13-'.$run, 'name' => 'Concurrency Bank', 'sharing_scope' => 'campus', 'status' => 'active', 'created_at' => $now, 'updated_at' => $now]);
        $questionId = DB::table('quiz_questions')->insertGetId(['tenant_id' => $tenant->getKey(), 'bank_id' => $bankId, 'status' => 'active', 'current_version' => 1, 'created_by' => $owner->getKey(), 'created_at' => $now, 'updated_at' => $now]);
        $questionVersionId = DB::table('quiz_question_versions')->insertGetId(['tenant_id' => $tenant->getKey(), 'question_id' => $questionId, 'version_number' => 1, 'question_type' => 'short_text', 'difficulty' => 'moderate', 'estimated_seconds' => 60, 'default_points' => '10.00', 'content' => json_encode(['prompt' => 'Concurrency'], JSON_THROW_ON_ERROR), 'answer_key' => null, 'status' => 'active', 'created_by' => $owner->getKey(), 'reviewed_by' => $owner->getKey(), 'reviewed_at' => $now, 'activated_at' => $now, 'created_at' => $now, 'updated_at' => $now]);
        DB::table('quiz_version_questions')->insert(['tenant_id' => $tenant->getKey(), 'quiz_version_id' => $quizVersionId, 'question_version_id' => $questionVersionId, 'position' => 1, 'points' => '10.00', 'created_at' => $now, 'updated_at' => $now]);
        DB::table('quiz_sections')->insert(['tenant_id' => $tenant->getKey(), 'quiz_id' => $quizId, 'section_id' => $section->getKey(), 'created_at' => $now, 'updated_at' => $now]);
        $paperId = DB::table('exam_papers')->insertGetId(['tenant_id' => $tenant->getKey(), 'session_id' => $sessionId, 'created_by' => $owner->getKey(), 'current_version' => 0, 'status' => 'draft', 'created_at' => $now, 'updated_at' => $now]);
        $paperVersions = [];
        $questions = [['question_version_id' => (int) $questionVersionId, 'position' => 1, 'points' => '10.00', 'question_type' => 'short_text', 'topic' => null, 'difficulty' => 'moderate', 'content' => ['prompt' => 'Concurrency']]];
        foreach ([1, 2] as $version) {
            $blueprint = ['title' => 'Probe Paper', 'instructions' => 'Probe', 'duration_minutes' => 60, 'question_count' => 1, 'set_code' => 'A'];
            $hash = hash('sha256', json_encode(['blueprint' => $blueprint, 'questions' => $questions, 'total_marks' => '10.00'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
            $paperVersion = ExamPaperVersion::query()->create(['tenant_id' => $tenant->getKey(), 'paper_id' => $paperId, 'version_number' => $version, 'set_code' => 'A', 'title' => 'Probe Paper', 'instructions' => 'Probe', 'blueprint_snapshot' => $blueprint, 'question_snapshot' => $questions, 'total_marks' => '10.00', 'duration_minutes' => 60, 'content_hash' => $hash, 'created_by' => $owner->getKey(), 'status' => 'approved', 'submitted_at' => $now, 'approved_at' => $now]);
            $paperVersionId = (int) $paperVersion->getKey();
            DB::table('exam_paper_reviews')->insert(['tenant_id' => $tenant->getKey(), 'paper_version_id' => $paperVersionId, 'reviewer_id' => $creator->getKey(), 'decision' => 'approved', 'notes' => 'Probe fixture independent approval.', 'decided_at' => $now, 'created_at' => $now, 'updated_at' => $now]);
            $paperVersions[] = $paperVersionId;
        }
        $componentId = DB::table('exam_components')->insertGetId(['tenant_id' => $tenant->getKey(), 'session_id' => $sessionId, 'component_type' => 'oral', 'code' => 'P13-'.$run, 'title' => 'Concurrency Component', 'maximum_marks' => '10.00', 'status' => 'published', 'created_by' => $owner->getKey(), 'created_at' => $now, 'updated_at' => $now]);
        $allocationId = DB::table('exam_component_allocations')->insertGetId(['tenant_id' => $tenant->getKey(), 'component_id' => $componentId, 'candidate_id' => $candidates[1]['candidate_id'], 'marking_round' => 1, 'examiner_id' => $owner->getKey(), 'status' => 'in_progress', 'assigned_by' => $owner->getKey(), 'assigned_at' => $now, 'created_at' => $now, 'updated_at' => $now]);
        $draft = ExamComponentMarkDraft::query()->create(['allocation_id' => $allocationId, 'rubric_marks' => [], 'feedback' => '', 'private_notes' => '', 'lock_version' => 1, 'updated_by' => $owner->getKey()]);
        $draftId = (int) $draft->getKey();
        $resultPolicyId = DB::table('exam_result_policies')->insertGetId(['tenant_id' => $tenant->getKey(), 'session_id' => $sessionId, 'version' => 1, 'written_weight' => '100.00', 'pass_percent' => '50.00', 'recheck_window_days' => 14, 'effective_at' => $now, 'configured_by' => $owner->getKey(), 'created_at' => $now, 'updated_at' => $now]);
        $resultId = DB::table('exam_results')->insertGetId(['tenant_id' => $tenant->getKey(), 'session_id' => $sessionId, 'candidate_id' => $candidates[1]['candidate_id'], 'policy_id' => $resultPolicyId, 'version' => 1, 'status' => 'approval_pending', 'outcome' => 'pending', 'total_marks' => '50.00', 'maximum_marks' => '100.00', 'percentage' => '50.00', 'source_snapshot' => json_encode(['total_marks' => '50.00', 'missing' => [], 'review_pending' => [], 'moderation_pending' => []], JSON_THROW_ON_ERROR), 'source_hash' => hash('sha256', $run), 'policy_version' => 'phase13-probe', 'compiled_by' => $registrationUser->getKey(), 'created_at' => $now, 'updated_at' => $now]);

        return ['run_id' => $run, 'tenant_id' => (int) $tenant->getKey(), 'tenant_slug' => $tenant->slug, 'owner_membership_id' => (int) $ownerMembership->getKey(), 'release_membership_id' => (int) $releaseMembership->getKey(), 'release_user_id' => (int) $releaseUser->getKey(), 'institution_id' => (int) $institution->getKey(), 'campus_id' => (int) $campus->getKey(), 'owner_id' => (int) $owner->getKey(), 'second_user_id' => (int) $creator->getKey(), 'cycle_id' => $cycleId, 'session_id' => $sessionId, 'room_id' => (int) $room->getKey(), 'unregistered_student_id' => (int) $registrationStudent->getKey(), 'candidates' => $candidates, 'paper_id' => $paperId, 'paper_versions' => $paperVersions, 'quiz_id' => $quizId, 'quiz_version_id' => $quizVersionId, 'question_version_id' => $questionVersionId, 'mark_draft_id' => $draftId, 'allocation_id' => $allocationId, 'result_id' => $resultId];
    } finally {
        $context->clear();
    }
}

/**
 * @param  array<string,mixed>  $fixture
 * @param  array<string,mixed>  $left
 * @param  array<string,mixed>  $right
 * @param  list<string>  $cleanup
 * @return list<array<string,mixed>>
 */
function phase13Race(string $name, array $fixture, array $left, array $right, string $framework, array &$cleanup): array
{
    $run = strtolower((string) Str::uuid());
    $prefix = 'phase13-exam-'.$run.'-'.$name;
    $go = $framework.DIRECTORY_SEPARATOR.$prefix.'.go';
    $paths = [];
    $processes = [];
    $pipes = [];
    foreach ([['a', $left], ['b', $right]] as [$label, $payload]) {
        $data = $payload + ['probe' => $name, 'tenant_id' => $fixture['tenant_id'], 'tenant_slug' => $fixture['tenant_slug'], 'membership_id' => $fixture['owner_membership_id'], 'cycle_id' => $fixture['cycle_id'], 'session_id' => $fixture['session_id'], 'room_id' => $fixture['room_id'], 'candidate_id' => $fixture['candidates'][0]['candidate_id'], 'actor_id' => $fixture['owner_id']];
        $proc = proc_open([PHP_BINARY, __FILE__, 'worker', $run, $label, base64_encode(json_encode($data, JSON_THROW_ON_ERROR))], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $workerPipes, dirname(__DIR__, 2), Phase13ProbeEnvironment::current(), ['bypass_shell' => true]);
        if (! is_resource($proc)) {
            throw new RuntimeException('unable to launch independent worker session');
        }
        fclose($workerPipes[0]);
        stream_set_blocking($workerPipes[1], false);
        stream_set_blocking($workerPipes[2], false);
        $processes[$label] = $proc;
        $pipes[$label] = $workerPipes;
        $paths[$label] = $framework.DIRECTORY_SEPARATOR.$prefix.'-'.$label.'.ready';
        $paths[$label.'_result'] = $framework.DIRECTORY_SEPARATOR.$prefix.'-'.$label.'.result';
        $cleanup = [...$cleanup, $paths[$label], $paths[$label.'_result']];
    }
    $cleanup[] = $go;
    try {
        $deadline = microtime(true) + 30;
        while ((! is_file($paths['a']) || ! is_file($paths['b'])) && microtime(true) < $deadline) {
            usleep(10000);
        }
        if (! is_file($paths['a']) || ! is_file($paths['b'])) {
            throw new RuntimeException($name.' workers did not reach the barrier');
        }
        $ids = [(int) file_get_contents($paths['a']), (int) file_get_contents($paths['b'])];
        if ($ids[0] < 1 || $ids[1] < 1 || $ids[0] === $ids[1]) {
            throw new RuntimeException($name.' did not establish two independent MySQL sessions');
        }
        phase13WriteExclusive($go, gmdate(DATE_ATOM));
        $deadline = microtime(true) + 45;
        $running = ['a' => true, 'b' => true];
        while (($running['a'] || $running['b']) && microtime(true) < $deadline) {
            foreach (['a', 'b'] as $label) {
                if ($running[$label]) {
                    $running[$label] = (bool) proc_get_status($processes[$label])['running'];
                }
            }
            if ($running['a'] || $running['b']) {
                usleep(10000);
            }
        }
        if ($running['a'] || $running['b']) {
            throw new RuntimeException($name.' workers exceeded bounded execution time');
        }
        $results = [];
        foreach (['a', 'b'] as $index => $label) {
            proc_close($processes[$label]);
            if (! is_file($paths[$label.'_result'])) {
                throw new RuntimeException($name.' worker did not return committed outcome');
            }
            $result = json_decode((string) file_get_contents($paths[$label.'_result']), true);
            if (! is_array($result) || (int) $result['connection_id'] !== $ids[$index]) {
                throw new RuntimeException($name.' worker changed connection identity after barrier');
            }
            $results[] = $result;
        }

        return $results;
    } finally {
        foreach ($processes as $label => $proc) {
            if (is_resource($proc)) {
                if (proc_get_status($proc)['running']) {
                    proc_terminate($proc);
                }
                @proc_close($proc);
            }
            foreach ($pipes[$label] ?? [] as $pipe) {
                if (is_resource($pipe)) {
                    fclose($pipe);
                }
            }
        }
    }
}

function phase13Assert(bool $condition, string $message): void
{
    if (! $condition) {
        throw new RuntimeException($message);
    }
}

/**
 * @param  list<array<string,mixed>>  $results
 * @param  list<string>  $expected
 */
function phase13AssertOutcomes(array $results, array $expected): void
{
    $actual = array_column($results, 'outcome');
    sort($actual);
    sort($expected);
    $errors = array_values(array_filter(array_map(static fn (array $result): string => (string) ($result['error'] ?? ''), $results)));
    phase13Assert($actual === $expected, 'unexpected concurrent operation outcomes: '.implode(',', $actual).($errors === [] ? '' : '; worker errors='.implode(',', $errors)));
}

/** @param list<array<string,mixed>> $results */
function phase13Say(string $name, array $results): void
{
    $ids = array_column($results, 'connection_id');
    $errors = array_values(array_filter(array_map(static fn (array $result): string => (string) ($result['error'] ?? ''), $results)));
    fwrite(STDOUT, 'RESULT '.$name.'; independent MySQL CONNECTION_IDs='.implode(',', $ids).'; outcomes='.implode(',', array_column($results, 'outcome')).($errors === [] ? '' : '; worker errors='.implode(',', $errors)).PHP_EOL);
}

function phase13WriteExclusive(string $path, string $contents): void
{
    $handle = @fopen($path, 'x');
    if (! is_resource($handle)) {
        throw new RuntimeException('coordination evidence path already exists');
    }
    try {
        if (fwrite($handle, $contents) !== strlen($contents) || ! fflush($handle)) {
            throw new RuntimeException('coordination evidence write failed');
        }
    } finally {
        fclose($handle);
    }
}

/** @param list<string> $paths */
function phase13Cleanup(array $paths, string $framework): void
{
    foreach (array_unique($paths) as $path) {
        if (dirname($path) === $framework && ! is_link($path) && is_file($path)) {
            @unlink($path);
        }
    }
}
