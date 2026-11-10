<?php

declare(strict_types=1);

namespace Tests\Support\Phase12Concurrency;

use App\Domain\Assessments\QuizAssessmentWorkflow;
use App\Domain\Assessments\QuizOutboxProcessor;
use App\Domain\Tenancy\TenantContext;
use App\Domain\Tenancy\TenantProvisioner;
use App\Models\AcademicCourse;
use App\Models\AcademicPeriod;
use App\Models\AcademicYear;
use App\Models\Campus;
use App\Models\CourseEnrollment;
use App\Models\CourseOffering;
use App\Models\CourseSection;
use App\Models\EnrollmentPolicy;
use App\Models\Institution;
use App\Models\QuizAttempt;
use App\Models\QuizAttemptResponse;
use App\Models\QuizQuestionVersion;
use App\Models\Role;
use App\Models\SisStudent;
use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Tests\Support\Phase12MySqlTestTarget;
use Tests\Support\Phase12ProbeAuthorization;
use Tests\Support\Phase12ProbeEnvironment;
use Tests\Support\TestDatabaseGuard;
use Throwable;

$base = dirname(__DIR__, 2);
require $base.'/vendor/autoload.php';
$app = require $base.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

try {
    TestDatabaseGuard::assertSafe(Phase12ProbeEnvironment::current(), allowPhase12Runner: true);
    $connection = DB::connection();
    if ($connection->getDriverName() !== 'mysql' || $connection->getDatabaseName() !== Phase12MySqlTestTarget::DATABASE) {
        throw new RuntimeException('Phase 12 probes require the exact guarded MySQL test database.');
    }
    Phase12ProbeAuthorization::assertAuthorized($connection->getPdo(), Phase12ProbeEnvironment::current());
    $runnerLock = $connection->selectOne('SELECT IS_USED_LOCK(?) AS owner', [Phase12MySqlTestTarget::SERVER_LOCK]);
    if ($runnerLock?->owner === null) {
        throw new RuntimeException('Phase 12 probe execution requires the guarded runner server lock.');
    }
} catch (Throwable) {
    fwrite(STDERR, "Phase 12 concurrency probes refused the database identity, privileges, or guarded runner authorization.\n");
    exit(2);
}

$framework = realpath(storage_path('framework'));
if ($framework === false || is_link($framework)) {
    fwrite(STDERR, "Phase 12 concurrency probes require a private framework coordination directory.\n");
    exit(2);
}

$mode = $argv[1] ?? 'orchestrate';
if ($mode === 'worker') {
    runWorker($argv, $framework);
}
if ($mode !== 'orchestrate') {
    fwrite(STDERR, "Unknown Phase 12 probe mode.\n");
    exit(2);
}

$fixture = [];
$cleanupPaths = [];
$exitStatus = 0;
try {
    $fixture = createFixture();
    $workflow = app(QuizAssessmentWorkflow::class);
    $context = app(TenantContext::class);
    $teacherActor = User::query()->findOrFail((int) $fixture['teacher_id']);
    $reviewerActor = User::query()->findOrFail((int) $fixture['reviewer_id']);

    $attemptStartKey = (string) Str::uuid();
    $startPayload = actorPayload($fixture, 'student_1') + [
        'operation' => 'start', 'quiz_id' => $fixture['quiz_id'], 'idempotency_key' => $attemptStartKey,
    ];
    $startResults = race('attempt_start', $startPayload, $startPayload, $framework, $cleanupPaths);
    requireOutcomes($startResults, ['success', 'success'], 'concurrent start');
    assertProbe((int) $startResults[0]['value'] === (int) $startResults[1]['value'], 'idempotent start did not return the same attempt');
    $startAttemptId = (int) $startResults[0]['value'];
    $startAttemptCount = DB::table('quiz_attempts')->where('tenant_id', $fixture['tenant_id'])->where('quiz_id', $fixture['quiz_id'])->where('membership_id', $fixture['students']['student_1']['membership_id'])->count();
    assertProbe($startAttemptCount === 1, 'concurrent start did not leave exactly one persisted attempt');
    assertProbe(DB::table('audit_events')->where('tenant_id', $fixture['tenant_id'])->where('event_type', 'assessment.attempt.started')->where('subject_id', $startAttemptId)->count() === 1, 'concurrent start emitted duplicate audit events');
    reportProbe('attempt start', 'independent sessions returned the same idempotent attempt; one persisted attempt and one start audit event');

    $student2 = studentContext($fixture, 'student_2');
    $attempt2 = $workflow->startAttempt($fixture['quiz_id'], (string) Str::uuid(), $student2['user']);
    $response2 = responseFor((int) $attempt2->getKey(), $fixture['essay_version_id']);
    $saveResults = race('answer_autosave', actorPayload($fixture, 'student_2') + [
        'operation' => 'save', 'attempt_id' => (int) $attempt2->getKey(), 'question_version_id' => $fixture['essay_version_id'],
        'expected_version' => 0, 'text' => 'First independent answer revision.',
    ], actorPayload($fixture, 'student_2') + [
        'operation' => 'save', 'attempt_id' => (int) $attempt2->getKey(), 'question_version_id' => $fixture['essay_version_id'],
        'expected_version' => 0, 'text' => 'Second concurrent answer revision.',
    ], $framework, $cleanupPaths);
    requireOutcomes($saveResults, ['conflict', 'success'], 'autosave optimistic concurrency', orderIndependent: true);
    $response2->refresh();
    assertProbe((int) $response2->lock_version === 1, 'concurrent autosaves advanced the response more than once');
    assertProbe(DB::table('quiz_response_revisions')->where('tenant_id', $fixture['tenant_id'])->where('response_id', $response2->getKey())->count() === 1, 'concurrent autosaves recorded an inconsistent revision history');
    assertProbe(DB::table('audit_events')->where('tenant_id', $fixture['tenant_id'])->where('event_type', 'assessment.response.saved')->where('subject_id', $response2->getKey())->count() === 1, 'concurrent autosaves emitted duplicate save audit events');
    reportProbe('answer autosave', 'two independent writes at expected version zero produced exactly one revision; the stale writer was rejected');

    $student3 = studentContext($fixture, 'student_3');
    $attempt3 = $workflow->startAttempt($fixture['quiz_id'], (string) Str::uuid(), $student3['user']);
    $saveSubmitResults = race('autosave_submit', actorPayload($fixture, 'student_3') + [
        'operation' => 'save', 'attempt_id' => (int) $attempt3->getKey(), 'question_version_id' => $fixture['essay_version_id'],
        'expected_version' => 0, 'text' => 'The response committed before submission.',
    ], actorPayload($fixture, 'student_3') + ['operation' => 'submit', 'attempt_id' => (int) $attempt3->getKey()], $framework, $cleanupPaths);
    assertProbe(
        $saveSubmitResults[1]['outcome'] === 'success' && in_array($saveSubmitResults[0]['outcome'], ['success', 'conflict'], true),
        'autosave/submit race did not produce a committed submission and a safe save outcome',
    );
    $attempt3->refresh();
    $response3 = responseFor((int) $attempt3->getKey(), $fixture['essay_version_id']);
    assertProbe($attempt3->status === 'needs_manual_review' && $attempt3->submitted_at !== null, 'autosave/submit race left an invalid attempt state');
    assertProbe((int) $response3->lock_version === DB::table('quiz_response_revisions')->where('response_id', $response3->getKey())->count(), 'autosave/submit race lost or duplicated a committed response revision');
    assertProbe(DB::table('audit_events')->where('tenant_id', $fixture['tenant_id'])->where('event_type', 'assessment.attempt.submitted')->where('subject_id', $attempt3->getKey())->count() === 1, 'autosave/submit race finalized the attempt more than once');
    reportProbe('autosave versus submit', 'submission committed once; the response revision count matches its version and no post-submit write was accepted');

    $student4 = studentContext($fixture, 'student_4');
    $attempt4 = $workflow->startAttempt($fixture['quiz_id'], (string) Str::uuid(), $student4['user']);
    DB::table('quiz_attempts')->where('id', $attempt4->getKey())->update(['expires_at' => now()->subSecond()]);
    $timeoutResults = race('timeout_submit', actorPayload($fixture, 'student_4') + ['operation' => 'submit', 'attempt_id' => (int) $attempt4->getKey()], actorPayload($fixture, 'student_4') + ['operation' => 'submit_expired', 'attempt_id' => (int) $attempt4->getKey()], $framework, $cleanupPaths);
    requireOutcomes($timeoutResults, ['success', 'success'], 'deadline versus manual submission', orderIndependent: true);
    $attempt4->refresh();
    assertProbe($attempt4->status === 'needs_manual_review' && $attempt4->submitted_at !== null, 'deadline race did not commit a single final state');
    assertProbe(DB::table('audit_events')->where('tenant_id', $fixture['tenant_id'])->where('event_type', 'assessment.attempt.expired')->where('subject_id', $attempt4->getKey())->count() === 1, 'deadline race did not record exactly one expiry audit event');
    assertProbe(DB::table('quiz_outbox_events')->where('tenant_id', $fixture['tenant_id'])->where('event_key', 'attempt:'.$attempt4->getKey().':expired')->count() === 1, 'deadline race did not produce one idempotent expiry event');
    reportProbe('timeout versus manual submission', 'one deadline-authoritative finalization, one expiry audit event, and one idempotent expiry outbox record');

    $student5 = studentContext($fixture, 'student_5');
    $attempt5 = $workflow->startAttempt($fixture['quiz_id'], (string) Str::uuid(), $student5['user']);
    saveObjectiveResponse($workflow, $fixture, $attempt5, $student5['user']);
    $gradingResults = race('automatic_grading', actorPayload($fixture, 'student_5') + ['operation' => 'submit', 'attempt_id' => (int) $attempt5->getKey()], actorPayload($fixture, 'student_5') + ['operation' => 'submit', 'attempt_id' => (int) $attempt5->getKey()], $framework, $cleanupPaths);
    requireOutcomes($gradingResults, ['success', 'success'], 'concurrent automatic grading');
    $attempt5->refresh();
    $objectiveResponse = responseFor((int) $attempt5->getKey(), $fixture['objective_version_id']);
    assertProbe($attempt5->status === 'needs_manual_review' && $objectiveResponse->grading_status === 'auto_graded' && (string) $objectiveResponse->points_awarded === '1.00', 'concurrent automatic grading changed the objective score or attempt state');
    assertProbe(DB::table('quiz_outbox_events')->where('tenant_id', $fixture['tenant_id'])->where('event_key', 'attempt:'.$attempt5->getKey().':submitted')->count() === 1, 'concurrent automatic grading duplicated the submission event');
    reportProbe('automatic grading', 'both sessions observed one committed submission; objective points and the submission event persisted once');

    $student6 = studentContext($fixture, 'student_6');
    $attempt6 = $workflow->startAttempt($fixture['quiz_id'], (string) Str::uuid(), $student6['user']);
    saveObjectiveResponse($workflow, $fixture, $attempt6, $student6['user']);
    $workflow->saveResponse((int) $attempt6->getKey(), $fixture['essay_version_id'], ['text' => 'Original response for independent regrade.'], 0, $student6['user']);
    $attempt6 = $workflow->submitAttempt((int) $attempt6->getKey(), $student6['user']);
    $essayResponse6 = responseFor((int) $attempt6->getKey(), $fixture['essay_version_id']);
    $context->set($fixture['tenant'], membershipModel($fixture, 'teacher'));
    $workflow->manualGrade((int) $essayResponse6->getKey(), '1.00', 'Original grade.', '', 0, $teacherActor);
    $context->set($fixture['tenant'], membershipModel($fixture, 'reviewer'));
    $workflow->releaseResults($fixture['quiz_id'], $reviewerActor);
    $context->set($fixture['tenant'], membershipModel($fixture, 'student_6'));
    $regrade = $workflow->requestRegrade((int) $attempt6->getKey(), 'A concurrency test regrade request.', $student6['user']);
    $gradeRegradeResults = race('manual_grade_regrade', actorPayload($fixture, 'teacher') + [
        'operation' => 'grade', 'response_id' => (int) $essayResponse6->getKey(), 'points' => '0.50',
        'expected_revision' => 1, 'feedback' => 'Concurrent revised review.',
    ], actorPayload($fixture, 'reviewer') + ['operation' => 'approve_regrade', 'request_id' => (int) $regrade->getKey()], $framework, $cleanupPaths);
    requireOutcomes($gradeRegradeResults, ['success', 'success'], 'manual grade versus regrade decision', orderIndependent: true);
    assertProbe(DB::table('quiz_grading_reviews')->where('tenant_id', $fixture['tenant_id'])->where('response_id', $essayResponse6->getKey())->count() === 2, 'manual grade/regrade race failed to preserve both grade revisions');
    assertProbe(DB::table('quiz_regrade_requests')->where('tenant_id', $fixture['tenant_id'])->where('id', $regrade->getKey())->where('status', 'approved')->exists(), 'independent regrade decision was lost');
    assertProbe(in_array($attempt6->fresh()->status, ['graded', 'needs_manual_review'], true) && $attempt6->fresh()->result_released_at === null, 'approved regrade exposed a result before a new explicit release');
    if ($attempt6->fresh()->status === 'graded') {
        $context->set($fixture['tenant'], membershipModel($fixture, 'reviewer'));
        $workflow->releaseResults($fixture['quiz_id'], $reviewerActor);
    }
    reportProbe('manual grade versus regrade', 'grade revision history and independent approval both committed; the attempt returned to manual review');

    $student7 = studentContext($fixture, 'student_7');
    $attempt7 = $workflow->startAttempt($fixture['quiz_id'], (string) Str::uuid(), $student7['user']);
    saveObjectiveResponse($workflow, $fixture, $attempt7, $student7['user']);
    $essayResponse7 = responseFor((int) $attempt7->getKey(), $fixture['essay_version_id']);
    $workflow->saveResponse((int) $attempt7->getKey(), $fixture['essay_version_id'], ['text' => 'Result release concurrency answer.'], 0, $student7['user']);
    $workflow->submitAttempt((int) $attempt7->getKey(), $student7['user']);
    $context->set($fixture['tenant'], membershipModel($fixture, 'teacher'));
    $workflow->manualGrade((int) $essayResponse7->getKey(), '1.00', 'Ready for result release.', '', 0, $teacherActor);
    $releasePayload = actorPayload($fixture, 'reviewer') + ['operation' => 'release', 'quiz_id' => $fixture['quiz_id']];
    $releaseResults = race('result_publication', $releasePayload, $releasePayload, $framework, $cleanupPaths);
    requireOutcomes($releaseResults, ['success', 'success'], 'concurrent result publication');
    assertProbe(array_sum(array_map(static fn (array $row): int => (int) $row['value'], $releaseResults)) === 1, 'concurrent result publication released a result more than once');
    assertProbe($attempt7->fresh()->result_released_at !== null, 'concurrent result publication did not persist released state');
    assertProbe(DB::table('quiz_outbox_events')->where('tenant_id', $fixture['tenant_id'])->where('event_key', 'attempt:'.$attempt7->getKey().':result:released:grade:1')->count() === 1, 'concurrent result publication duplicated its notification event');
    reportProbe('result publication', 'exactly one worker released the result and one idempotent release event was committed');

    $context->set($fixture['tenant'], membershipModel($fixture, 'reviewer'));
    $outboxPayload = actorPayload($fixture, 'reviewer') + ['operation' => 'dispatch_outbox'];
    $outboxResults = race('notification_generation', $outboxPayload, $outboxPayload, $framework, $cleanupPaths);
    requireOutcomes($outboxResults, ['success', 'success'], 'concurrent outbox dispatch');
    $duplicates = DB::table('quiz_notifications')->select('event_key')->where('tenant_id', $fixture['tenant_id'])->groupBy('event_key')->havingRaw('COUNT(*) > 1')->exists();
    assertProbe(! $duplicates, 'concurrent outbox workers generated duplicate logical notifications');
    assertProbe(! DB::table('quiz_outbox_events')->where('tenant_id', $fixture['tenant_id'])->whereIn('status', ['queued', 'processing'])->exists(), 'concurrent outbox workers left events in an unfinished state');
    reportProbe('notification generation', 'two worker sessions drained the committed outbox with no duplicate recipient event keys');

    fwrite(STDOUT, "Phase 12 independent-session MySQL concurrency probes passed: 8 probes, 16 worker sessions, all committed-state assertions satisfied.\n");
} catch (Throwable $error) {
    fwrite(STDERR, 'Phase 12 concurrency probe failed at '.basename($error->getFile()).':'.$error->getLine().': '.class_basename($error).': '.preg_replace('/[^A-Za-z0-9 _.-]/', '', $error->getMessage()).PHP_EOL);
    $exitStatus = 1;
} finally {
    cleanupCoordinationFiles($cleanupPaths, $framework);
    app(TenantContext::class)->clear();
}
exit($exitStatus);

/** @param list<string> $argv */
function runWorker(array $argv, string $framework): never
{
    $runId = $argv[2] ?? '';
    $label = $argv[3] ?? '';
    $encoded = $argv[4] ?? '';
    $payload = json_decode(base64_decode($encoded, true) ?: '', true);
    if (! preg_match('/\A[a-f0-9-]{36}\z/i', $runId) || ! in_array($label, ['a', 'b'], true) || ! is_array($payload)) {
        fwrite(STDERR, "Phase 12 worker rejected invalid barrier metadata.\n");
        exit(2);
    }
    $operation = (string) ($payload['race'] ?? '');
    if (! preg_match('/\A[a-z_]{3,48}\z/', $operation)) {
        fwrite(STDERR, "Phase 12 worker rejected an invalid race name.\n");
        exit(2);
    }
    $prefix = 'phase12-quiz-'.$runId.'-'.$operation;
    $readyPath = $framework.DIRECTORY_SEPARATOR.$prefix.'-'.$label.'.ready';
    $goPath = $framework.DIRECTORY_SEPARATOR.$prefix.'.go';
    $resultPath = $framework.DIRECTORY_SEPARATOR.$prefix.'-'.$label.'.result';
    foreach ([$readyPath, $goPath, $resultPath] as $path) {
        if (dirname($path) !== $framework || is_link($path) || file_exists($path)) {
            fwrite(STDERR, "Phase 12 worker refused an unsafe or previously used barrier file.\n");
            exit(2);
        }
    }

    try {
        Phase12ProbeAuthorization::assertAuthorized(DB::connection()->getPdo(), Phase12ProbeEnvironment::current());
        $connectionId = (int) DB::selectOne('SELECT CONNECTION_ID() AS id')->id;
        if (file_put_contents($readyPath, (string) $connectionId, LOCK_EX) === false) {
            throw new RuntimeException('barrier registration failed');
        }
        $deadline = microtime(true) + 30;
        while (! is_file($goPath) && microtime(true) < $deadline) {
            usleep(10_000);
        }
        if (! is_file($goPath) || is_link($goPath)) {
            throw new RuntimeException('barrier timed out');
        }

        $tenant = Tenant::query()->findOrFail((int) $payload['tenant_id']);
        $membership = TenantMembership::query()->withoutGlobalScopes()->findOrFail((int) $payload['membership_id']);
        $actor = User::query()->findOrFail((int) $payload['actor_id']);
        app(TenantContext::class)->set($tenant, $membership);
        $workflow = app(QuizAssessmentWorkflow::class);
        $value = match ((string) ($payload['operation'] ?? '')) {
            'start' => (int) $workflow->startAttempt((int) $payload['quiz_id'], (string) $payload['idempotency_key'], $actor)->getKey(),
            'save' => (int) $workflow->saveResponse((int) $payload['attempt_id'], (int) $payload['question_version_id'], ['text' => (string) $payload['text']], (int) $payload['expected_version'], $actor)->getKey(),
            'save_objective' => (int) $workflow->saveResponse((int) $payload['attempt_id'], (int) $payload['question_version_id'], ['selected_ids' => ['correct']], (int) $payload['expected_version'], $actor)->getKey(),
            'submit' => (int) $workflow->submitAttempt((int) $payload['attempt_id'], $actor)->getKey(),
            'submit_expired' => (int) $workflow->submitAttempt((int) $payload['attempt_id'], $actor, true)->getKey(),
            'grade' => (int) $workflow->manualGrade((int) $payload['response_id'], (string) $payload['points'], (string) $payload['feedback'], '', (int) $payload['expected_revision'], $actor)->getKey(),
            'approve_regrade' => (int) $workflow->decideRegrade((int) $payload['request_id'], true, 'Approved in the concurrent acceptance probe.', $actor)->getKey(),
            'release' => $workflow->releaseResults((int) $payload['quiz_id'], $actor),
            'dispatch_outbox' => app(QuizOutboxProcessor::class)->dispatchAvailable(200)['delivered'],
            default => throw new RuntimeException('unsupported operation'),
        };
        writeJsonExclusive($resultPath, ['outcome' => 'success', 'connection_id' => $connectionId, 'value' => $value]);
        exit(0);
    } catch (ConflictHttpException|ValidationException $error) {
        writeJsonExclusive($resultPath, ['outcome' => 'conflict', 'connection_id' => (int) (DB::selectOne('SELECT CONNECTION_ID() AS id')->id ?? 0), 'error' => class_basename($error)]);
        exit(0);
    } catch (AuthorizationException|HttpResponseException $error) {
        writeJsonExclusive($resultPath, ['outcome' => 'failed', 'connection_id' => (int) (DB::selectOne('SELECT CONNECTION_ID() AS id')->id ?? 0), 'error' => class_basename($error)]);
        fwrite(STDERR, "Phase 12 worker was denied by application authorization.\n");
        exit(1);
    } catch (Throwable $error) {
        writeJsonExclusive($resultPath, ['outcome' => 'failed', 'connection_id' => (int) (DB::selectOne('SELECT CONNECTION_ID() AS id')->id ?? 0), 'error' => class_basename($error)]);
        fwrite(STDERR, 'Phase 12 worker failed: '.class_basename($error).PHP_EOL);
        exit(1);
    } finally {
        app(TenantContext::class)->clear();
    }
}

/** @return array<string,mixed> */
/** @return array<string,mixed> */
function createFixture(): array
{
    $creator = User::factory()->create();
    $owner = User::factory()->create();
    $teacher = User::factory()->create();
    $reviewer = User::factory()->create();
    $name = 'Phase 12 MySQL concurrency '.Str::random(8);
    $tenant = app(TenantProvisioner::class)->provision([
        'slug' => Str::slug($name), 'official_name' => $name, 'display_name' => $name, 'code' => '', 'type' => 'academy',
        'contact_email' => '', 'contact_phone' => '', 'website' => '', 'country' => '', 'region' => '', 'city' => '', 'address' => '', 'timezone' => 'UTC', 'locale' => 'en',
    ], $creator, $owner->email)->tenant;
    $context = app(TenantContext::class);
    $context->enterProvisioning($tenant);
    try {
        $institution = Institution::query()->firstOrFail();
        $campus = Campus::query()->create(['institution_id' => $institution->getKey(), 'code' => 'MAIN', 'name' => 'Concurrency Test Campus', 'status' => 'active', 'is_primary' => true]);
        $memberships = [
            'teacher' => createMembership($tenant, $institution, $teacher, 'teacher', $creator),
            'reviewer' => createMembership($tenant, $institution, $reviewer, 'academic.admin', $creator),
        ];
        $students = [];
        for ($index = 1; $index <= 8; $index++) {
            $user = User::factory()->create();
            $key = 'student_'.$index;
            $students[$key] = ['user' => $user, 'membership' => createMembership($tenant, $institution, $user, 'student', $creator)];
        }

        $year = AcademicYear::query()->create(['institution_id' => $institution->getKey(), 'name' => 'Concurrency Test Year', 'code' => 'CY-'.Str::upper(Str::random(7)), 'starts_on' => today()->subMonth(), 'ends_on' => today()->addMonths(5), 'timezone' => 'UTC', 'locale' => 'en', 'status' => 'active']);
        $period = AcademicPeriod::query()->create(['academic_year_id' => $year->getKey(), 'name' => 'Concurrency Test Term', 'code' => 'CT-'.Str::upper(Str::random(7)), 'period_type' => 'term', 'sequence' => 1, 'starts_on' => today()->subMonth(), 'ends_on' => today()->addMonths(4), 'status' => 'active']);
        $course = AcademicCourse::query()->create(['institution_id' => $institution->getKey(), 'code' => 'CQ-'.Str::upper(Str::random(7)), 'title' => 'Concurrency Quiz', 'status' => 'active']);
        $policy = EnrollmentPolicy::query()->create(['institution_id' => $institution->getKey(), 'name' => 'Concurrency Quiz Policy']);
        $offering = CourseOffering::query()->create(['institution_id' => $institution->getKey(), 'campus_id' => $campus->getKey(), 'academic_year_id' => $year->getKey(), 'academic_period_id' => $period->getKey(), 'academic_course_id' => $course->getKey(), 'policy_id' => $policy->getKey(), 'offering_code' => 'CQ-'.Str::upper(Str::random(7)), 'status' => 'open']);
        $section = CourseSection::query()->create(['offering_id' => $offering->getKey(), 'campus_id' => $campus->getKey(), 'section_code' => 'A', 'capacity' => 20, 'status' => 'open']);
        DB::table('course_section_instructors')->insert(['tenant_id' => $tenant->getKey(), 'section_id' => $section->getKey(), 'user_id' => $teacher->getKey(), 'role' => 'instructor', 'created_at' => now(), 'updated_at' => now()]);
        foreach ($students as $key => $student) {
            $sis = SisStudent::query()->create(['institution_id' => $institution->getKey(), 'home_campus_id' => $campus->getKey(), 'user_id' => $student['user']->getKey(), 'student_number' => 'CS-'.Str::upper(Str::random(8)), 'registration_reference' => 'CR-'.Str::upper(Str::random(8)), 'legal_first_name' => 'Probe', 'legal_last_name' => $key, 'admission_date' => today()->subYear(), 'status' => 'active', 'student_type' => 'standard']);
            CourseEnrollment::query()->create(['institution_id' => $institution->getKey(), 'student_id' => $sis->getKey(), 'offering_id' => $offering->getKey(), 'section_id' => $section->getKey(), 'status' => 'confirmed', 'registration_source' => 'registrar', 'requested_at' => now()->subDay(), 'confirmed_at' => now()->subDay()]);
            $students[$key]['membership_id'] = (int) $student['membership']->getKey();
        }
        $context->clear();
        $context->set($tenant, $memberships['teacher']);
        $workflow = app(QuizAssessmentWorkflow::class);
        $bank = $workflow->createBank(['code' => 'RACE', 'name' => 'Concurrency Probe Bank', 'campus_id' => $campus->getKey(), 'sharing_scope' => 'campus'], $teacher);
        $objective = $workflow->createQuestion((int) $bank->getKey(), [
            'question_type' => 'single_choice', 'content' => ['prompt' => 'Choose the correct test option.', 'options' => [['id' => 'correct', 'text' => 'Correct'], ['id' => 'wrong', 'text' => 'Wrong']]],
            'answer_key' => ['correct_option_ids' => ['correct']], 'default_points' => '1.00', 'difficulty' => 'easy', 'estimated_seconds' => 30,
        ], $teacher);
        $essay = $workflow->createQuestion((int) $bank->getKey(), [
            'question_type' => 'long_response', 'content' => ['prompt' => 'Explain one concurrency invariant.'], 'answer_key' => null,
            'default_points' => '1.00', 'difficulty' => 'moderate', 'estimated_seconds' => 60,
        ], $teacher);
        foreach ([$objective, $essay] as $question) {
            $workflow->submitQuestionForReview((int) $question->getKey(), $teacher);
            $context->set($tenant, $memberships['reviewer']);
            $workflow->reviewQuestion((int) $question->getKey(), $reviewer, true);
            $context->set($tenant, $memberships['teacher']);
        }
        $objectiveVersion = QuizQuestionVersion::query()->where('question_id', $objective->getKey())->where('status', 'active')->firstOrFail();
        $essayVersion = QuizQuestionVersion::query()->where('question_id', $essay->getKey())->where('status', 'active')->firstOrFail();
        $quiz = $workflow->createQuiz([
            'offering_id' => $offering->getKey(), 'section_ids' => [$section->getKey()],
            'questions' => [['question_version_id' => $objectiveVersion->getKey(), 'points' => '1.00'], ['question_version_id' => $essayVersion->getKey(), 'points' => '1.00']],
            'title' => 'Phase 12 concurrency assessment', 'instructions' => 'Test transactional concurrency behavior.', 'mode' => 'graded',
            'maximum_attempts' => 3, 'time_limit_minutes' => 30, 'grading_policy' => 'latest', 'feedback_release' => 'manual',
            'result_visibility' => 'score_and_feedback', 'navigation' => 'free',
        ], $teacher);
        $workflow->submitQuizForReview((int) $quiz->getKey(), $teacher);
        $context->set($tenant, $memberships['reviewer']);
        $workflow->reviewQuiz((int) $quiz->getKey(), $reviewer, true);
        $workflow->publishQuiz((int) $quiz->getKey(), $reviewer);
        $context->clear();

        return [
            'tenant' => $tenant, 'tenant_id' => (int) $tenant->getKey(), 'quiz_id' => (int) $quiz->getKey(),
            'objective_version_id' => (int) $objectiveVersion->getKey(), 'essay_version_id' => (int) $essayVersion->getKey(),
            'teacher_id' => (int) $teacher->getKey(), 'reviewer_id' => (int) $reviewer->getKey(),
            'memberships' => ['teacher' => (int) $memberships['teacher']->getKey(), 'reviewer' => (int) $memberships['reviewer']->getKey()],
            'students' => array_map(static fn (array $student): array => ['actor_id' => (int) $student['user']->getKey(), 'membership_id' => (int) $student['membership_id']], $students),
        ];
    } finally {
        $context->clear();
    }
}

function createMembership(Tenant $tenant, Institution $institution, User $user, string $roleKey, User $assignedBy): TenantMembership
{
    $membership = TenantMembership::query()->create(['institution_id' => $institution->getKey(), 'user_id' => $user->getKey(), 'status' => 'active', 'activated_at' => now()]);
    $role = Role::query()->where('tenant_id', $tenant->getKey())->where('key', $roleKey)->firstOrFail();
    $membership->roles()->attach($role->getKey(), ['assigned_by' => $assignedBy->getKey(), 'tenant_id' => $tenant->getKey()]);

    return $membership;
}

/** @param array<string,mixed> $fixture */
function membershipModel(array $fixture, string $key): TenantMembership
{
    $membershipId = str_starts_with($key, 'student_')
        ? (int) $fixture['students'][$key]['membership_id']
        : (int) $fixture['memberships'][$key];

    return TenantMembership::query()->withoutGlobalScopes()->findOrFail($membershipId);
}

/**
 * @param  array<string,mixed>  $fixture
 * @return array{user:User,membership_id:int}
 */
function studentContext(array $fixture, string $key): array
{
    $membership = TenantMembership::query()->withoutGlobalScopes()->findOrFail($fixture['students'][$key]['membership_id']);
    $user = User::query()->findOrFail($fixture['students'][$key]['actor_id']);
    app(TenantContext::class)->set(Tenant::query()->findOrFail($fixture['tenant_id']), $membership);

    return ['user' => $user, 'membership_id' => (int) $membership->getKey()];
}

function responseFor(int $attemptId, int $questionVersionId): QuizAttemptResponse
{
    return QuizAttemptResponse::query()->where('attempt_id', $attemptId)->where('question_version_id', $questionVersionId)->firstOrFail();
}

/** @param array<string,mixed> $fixture */
function saveObjectiveResponse(QuizAssessmentWorkflow $workflow, array $fixture, QuizAttempt $attempt, User $actor): void
{
    $workflow->saveResponse((int) $attempt->getKey(), $fixture['objective_version_id'], ['selected_ids' => ['correct']], 0, $actor);
}

/**
 * @param  array<string,mixed>  $fixture
 * @return array{tenant_id:int,membership_id:int,actor_id:int}
 */
function actorPayload(array $fixture, string $key): array
{
    if ($key === 'teacher' || $key === 'reviewer') {
        return ['tenant_id' => (int) $fixture['tenant_id'], 'membership_id' => (int) $fixture['memberships'][$key], 'actor_id' => (int) $fixture[$key.'_id']];
    }

    return ['tenant_id' => (int) $fixture['tenant_id'], 'membership_id' => (int) $fixture['students'][$key]['membership_id'], 'actor_id' => (int) $fixture['students'][$key]['actor_id']];
}

/**
 * @param  array<string,mixed>  $left
 * @param  array<string,mixed>  $right
 * @param  list<string>  $cleanupPaths
 * @return list<array<string,mixed>>
 */
function race(string $name, array $left, array $right, string $framework, array &$cleanupPaths): array
{
    $runId = strtolower((string) Str::uuid());
    $prefix = 'phase12-quiz-'.$runId.'-'.$name;
    $goPath = $framework.DIRECTORY_SEPARATOR.$prefix.'.go';
    $profiles = [];
    $processes = [];
    $pipes = [];
    $environment = Phase12ProbeEnvironment::current();
    foreach ([['a', $left], ['b', $right]] as [$label, $payload]) {
        $workerPayload = base64_encode(json_encode($payload + ['race' => $name], JSON_THROW_ON_ERROR));
        $process = proc_open([PHP_BINARY, __FILE__, 'worker', $runId, $label, $workerPayload], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $workerPipes, dirname(__DIR__, 2), $environment, ['bypass_shell' => true]);
        if (! is_resource($process)) {
            throw new RuntimeException('Could not start both independent Phase 12 MySQL sessions.');
        }
        fclose($workerPipes[0]);
        stream_set_blocking($workerPipes[1], false);
        stream_set_blocking($workerPipes[2], false);
        $processes[$label] = $process;
        $pipes[$label] = $workerPipes;
        $profiles[$label] = $framework.DIRECTORY_SEPARATOR.$prefix.'-'.$label.'.ready';
        $profiles[$label.'_result'] = $framework.DIRECTORY_SEPARATOR.$prefix.'-'.$label.'.result';
        $profiles[$label.'_log'] = [$framework.DIRECTORY_SEPARATOR.$prefix.'-'.$label.'.stdout', $framework.DIRECTORY_SEPARATOR.$prefix.'-'.$label.'.stderr'];
        array_push($cleanupPaths, $profiles[$label], $profiles[$label.'_result'], ...$profiles[$label.'_log']);
    }
    $cleanupPaths[] = $goPath;

    try {
        $deadline = microtime(true) + 30;
        while ((! is_file($profiles['a']) || ! is_file($profiles['b'])) && microtime(true) < $deadline) {
            usleep(10_000);
        }
        if (! is_file($profiles['a']) || ! is_file($profiles['b'])) {
            throw new RuntimeException('Independent MySQL workers did not reach the bounded start barrier.');
        }
        $connectionIds = [(int) file_get_contents($profiles['a']), (int) file_get_contents($profiles['b'])];
        if ($connectionIds[0] < 1 || $connectionIds[1] < 1 || $connectionIds[0] === $connectionIds[1]) {
            throw new RuntimeException('Concurrency workers did not use two distinct authenticated MySQL sessions.');
        }
        $go = @fopen($goPath, 'x');
        if (! is_resource($go)) {
            throw new RuntimeException('Could not release the synchronized worker barrier.');
        }
        fwrite($go, gmdate(DATE_ATOM));
        fflush($go);
        fclose($go);

        $deadline = microtime(true) + 45;
        $running = ['a' => true, 'b' => true];
        while (($running['a'] || $running['b']) && microtime(true) < $deadline) {
            foreach (['a', 'b'] as $label) {
                if ($running[$label]) {
                    $status = proc_get_status($processes[$label]);
                    $running[$label] = (bool) $status['running'];
                    if (! $running[$label]) {
                        stream_get_contents($pipes[$label][1]);
                        stream_get_contents($pipes[$label][2]);
                    }
                }
            }
            if ($running['a'] || $running['b']) {
                usleep(10_000);
            }
        }
        if ($running['a'] || $running['b']) {
            foreach (['a', 'b'] as $label) {
                if ($running[$label]) {
                    proc_terminate($processes[$label]);
                }
            }
            throw new RuntimeException('A bounded Phase 12 concurrency worker timed out.');
        }
        foreach (['a', 'b'] as $label) {
            proc_close($processes[$label]);
            if (! is_file($profiles[$label.'_result'])) {
                throw new RuntimeException('A Phase 12 concurrency worker exited without a committed result record.');
            }
        }
        $results = [json_decode((string) file_get_contents($profiles['a_result']), true), json_decode((string) file_get_contents($profiles['b_result']), true)];
        foreach ($results as $index => $result) {
            if (! is_array($result) || (int) ($result['connection_id'] ?? 0) !== $connectionIds[$index]) {
                throw new RuntimeException('A worker changed MySQL sessions after completing the synchronization barrier.');
            }
        }

        return $results;
    } finally {
        foreach ($processes as $label => $process) {
            if (is_resource($process)) {
                $status = proc_get_status($process);
                if ($status['running']) {
                    proc_terminate($process);
                }
                @proc_close($process);
            }
            foreach ($pipes[$label] ?? [] as $pipe) {
                if (is_resource($pipe)) {
                    fclose($pipe);
                }
            }
        }
    }
}

/**
 * @param  list<array<string,mixed>>  $results
 * @param  list<string>  $expected
 */
function requireOutcomes(array $results, array $expected, string $label, bool $orderIndependent = false): void
{
    $outcomes = array_column($results, 'outcome');
    if ($orderIndependent) {
        sort($outcomes);
        sort($expected);
    }
    assertProbe($outcomes === $expected, $label.' outcomes were '.implode(',', $outcomes));
}

function assertProbe(bool $condition, string $failure): void
{
    if (! $condition) {
        throw new RuntimeException($failure);
    }
}

function reportProbe(string $name, string $result): void
{
    fwrite(STDOUT, 'PASS '.$name.': '.$result.PHP_EOL);
}

/** @param list<string> $paths */
function cleanupCoordinationFiles(array $paths, string $framework): void
{
    foreach (array_unique($paths) as $path) {
        if (dirname($path) === $framework && ! is_link($path) && is_file($path)) {
            @unlink($path);
        }
    }
}

/** @param array<string,mixed> $contents */
function writeJsonExclusive(string $path, array $contents): void
{
    $handle = @fopen($path, 'x');
    if (! is_resource($handle)) {
        throw new RuntimeException('Worker result file already exists or could not be created.');
    }
    try {
        $json = json_encode($contents, JSON_THROW_ON_ERROR);
        if (fwrite($handle, $json) !== strlen($json) || ! fflush($handle)) {
            throw new RuntimeException('Worker result file could not be persisted.');
        }
    } finally {
        fclose($handle);
    }
}
