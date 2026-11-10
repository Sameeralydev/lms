<?php

declare(strict_types=1);

use App\Domain\Assignments\AssignmentWorkflow;
use App\Domain\Tenancy\TenantContext;
use App\Domain\Tenancy\TenantProvisioner;
use App\Models\AcademicCourse;
use App\Models\AcademicPeriod;
use App\Models\AcademicYear;
use App\Models\Assignment;
use App\Models\AssignmentAttempt;
use App\Models\AssignmentExtension;
use App\Models\AssignmentGrade;
use App\Models\AssignmentRecipient;
use App\Models\Campus;
use App\Models\CourseEnrollment;
use App\Models\CourseOffering;
use App\Models\CourseSection;
use App\Models\EnrollmentPolicy;
use App\Models\Institution;
use App\Models\Role;
use App\Models\SisStudent;
use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\Support\Phase11MySqlTestTarget;
use Tests\Support\Phase11ProbeAuthorization;
use Tests\Support\Phase11ProbeEnvironment;
use Tests\Support\TestDatabaseGuard;

$root = dirname(__DIR__, 2);
require $root.'/vendor/autoload.php';
$app = require $root.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$environment = TestDatabaseGuard::currentEnvironment();

try {
    TestDatabaseGuard::assertSafe($environment, allowPhase11Runner: true);
    $connection = DB::connection();
    if ($connection->getDriverName() !== 'mysql' || $connection->getDatabaseName() !== Phase11MySqlTestTarget::DATABASE) {
        throw new RuntimeException('Phase 11 concurrency probes require the exact guarded MySQL test schema.');
    }
    Phase11ProbeAuthorization::assertAuthorized($connection->getPdo(), $environment);
} catch (Throwable) {
    fwrite(STDERR, "Phase 11 concurrency probes refused database identity or runner authorization.\n");
    exit(2);
}

$framework = realpath(storage_path('framework'));
if ($framework === false) {
    fwrite(STDERR, "Phase 11 concurrency probes have no private coordination directory.\n");
    exit(2);
}

$mode = $argv[1] ?? 'orchestrate';
if ($mode === 'worker') {
    phase11AssignmentrunWorker($argv, $framework);
}
if ($mode === 'cleanup_fixture') {
    phase11AssignmentcleanupKnownFixture((string) ($argv[2] ?? ''));
}
if ($mode !== 'orchestrate') {
    if ($mode !== 'cleanup_fixture') {
        fwrite(STDERR, "Unknown Phase 11 concurrency probe mode.\n");
    }

    exit(2);
}

$runId = strtolower((string) Str::uuid());
$fixture = [];
$children = [];
$coordination = [];
$failure = null;
$summary = [];

try {
    $fixture = DB::transaction(static fn (): array => phase11AssignmentcreateFixture($runId));

    // 1: Two independent requests submit the same learner attempt.
    $attempt = $fixture['attempts']['submit'];
    $pair = phase11Assignmentrace($root, $framework, 'same_attempt_submit',
        phase11AssignmentworkerPayload($fixture, 'student', 'submit', ['recipient_id' => $fixture['recipients']['submit'], 'attempt_id' => $attempt]),
        phase11AssignmentworkerPayload($fixture, 'student', 'submit', ['recipient_id' => $fixture['recipients']['submit'], 'attempt_id' => $attempt]), $children, $coordination);
    $attemptRow = DB::table('assignment_attempts')->where('tenant_id', $fixture['tenant_id'])->where('id', $attempt)->first();
    if ($attemptRow === null || $attemptRow->status !== 'submitted' || $attemptRow->receipt_reference === null) {
        throw new RuntimeException('simultaneous submission did not create one authoritative receipt');
    }
    phase11AssignmentassertProbe(DB::table('assignment_outbox_events')->where('tenant_id', $fixture['tenant_id'])->where('event_key', 'submission-received:'.$attempt)->count() === 1, 'simultaneous submission duplicated its logical outbox event');
    phase11AssignmentassertProbe(DB::table('audit_events')->where('tenant_id', $fixture['tenant_id'])->where('event_type', 'assignments.submission.submitted')->where('subject_id', $attempt)->count() === 1, 'simultaneous submission duplicated its audit transition');
    $summary['same_attempt_submit'] = ['sessions' => 2, 'receipt' => true, 'outbox' => 1, 'audit' => 1, 'outcomes' => phase11AssignmentoutcomeNames($pair)];

    // 2: Two draft edits race with different content and retain an ordered revision history.
    $recipient = $fixture['recipients']['draft_race'];
    $key = (string) Str::uuid();
    $pair = phase11Assignmentrace($root, $framework, 'draft_edit',
        phase11AssignmentworkerPayload($fixture, 'student', 'draft', ['recipient_id' => $recipient, 'response_text' => 'Concurrent draft version A.', 'idempotency_key' => $key]),
        phase11AssignmentworkerPayload($fixture, 'student', 'draft', ['recipient_id' => $recipient, 'response_text' => 'Concurrent draft version B.', 'idempotency_key' => $key]), $children, $coordination);
    $submissionId = (int) DB::table('assignment_submissions')->where('tenant_id', $fixture['tenant_id'])->where('recipient_id', $recipient)->value('id');
    $draftAttempt = DB::table('assignment_attempts')->where('tenant_id', $fixture['tenant_id'])->where('submission_id', $submissionId)->where('status', 'draft')->first();
    if ($draftAttempt === null) {
        throw new RuntimeException('draft race did not produce a draft attempt');
    }
    $draftRevisions = DB::table('assignment_attempt_revisions')->where('tenant_id', $fixture['tenant_id'])->where('attempt_id', $draftAttempt->id)->orderBy('revision_number')->get();
    phase11AssignmentassertProbe(in_array($draftAttempt->response_text, ['Concurrent draft version A.', 'Concurrent draft version B.'], true), 'draft race lost both submitted values');
    phase11AssignmentassertProbe($draftRevisions->count() === 2 && (int) $draftRevisions[0]->revision_number === 1 && (int) $draftRevisions[1]->revision_number === 2, 'draft race did not retain both ordered content revisions');
    $summary['draft_edit'] = ['sessions' => 2, 'revisions' => $draftRevisions->count(), 'outcomes' => phase11AssignmentoutcomeNames($pair)];

    // 3: Submission and closure meet at the same authoritative cutoff instant.
    $clockAt = $fixture['boundary_clock'];
    $pair = phase11Assignmentrace($root, $framework, 'deadline_boundary',
        phase11AssignmentworkerPayload($fixture, 'student', 'submit', ['recipient_id' => $fixture['recipients']['deadline'], 'attempt_id' => $fixture['attempts']['deadline'], 'clock_at' => $clockAt]),
        phase11AssignmentworkerPayload($fixture, 'teacher', 'close', ['clock_at' => $clockAt]), $children, $coordination);
    $deadlineAssignment = DB::table('assignments')->where('tenant_id', $fixture['tenant_id'])->where('id', $fixture['assignments']['deadline'])->first();
    $deadlineAttempt = DB::table('assignment_attempts')->where('tenant_id', $fixture['tenant_id'])->where('id', $fixture['attempts']['deadline'])->first();
    if ($deadlineAssignment === null || $deadlineAttempt === null || $deadlineAssignment->status !== 'closed' || $deadlineAttempt->status !== 'submitted' || $deadlineAttempt->submitted_at === null) {
        throw new RuntimeException('deadline boundary did not preserve inclusive-cutoff submission and closure');
    }
    $summary['deadline_boundary'] = ['sessions' => 2, 'assignment_status' => $deadlineAssignment->status, 'attempt_status' => $deadlineAttempt->status, 'outcomes' => phase11AssignmentoutcomeNames($pair)];

    // 4: Two independent reviewers decide one pending extension differently.
    $extensionId = $fixture['extension_id'];
    $pair = phase11Assignmentrace($root, $framework, 'extension_decision',
        phase11AssignmentworkerPayload($fixture, 'reviewer', 'decide_extension', ['extension_id' => $extensionId, 'approve' => true]),
        phase11AssignmentworkerPayload($fixture, 'owner', 'decide_extension', ['extension_id' => $extensionId, 'approve' => false]), $children, $coordination);
    $extension = DB::table('assignment_extensions')->where('tenant_id', $fixture['tenant_id'])->where('id', $extensionId)->first();
    if ($extension === null || ! in_array($extension->status, ['approved', 'rejected'], true)) {
        throw new RuntimeException('extension race did not produce one final decision');
    }
    phase11AssignmentassertProbe(DB::table('assignment_outbox_events')->where('tenant_id', $fixture['tenant_id'])->where('event_key', 'extension-decision:'.$extensionId)->count() === 1, 'extension race duplicated its decision event');
    phase11AssignmentassertProbe(DB::table('audit_events')->where('tenant_id', $fixture['tenant_id'])->whereIn('event_type', ['assignments.extension.approved', 'assignments.extension.rejected'])->where('subject_id', $extensionId)->count() === 1, 'extension race duplicated its decision audit');
    $summary['extension_decision'] = ['sessions' => 2, 'final_status' => $extension->status, 'outbox' => 1, 'audit' => 1, 'outcomes' => phase11AssignmentoutcomeNames($pair)];

    // 5: Both active group members submit the same shared group attempt.
    $groupAttempt = $fixture['attempts']['group'];
    $pair = phase11Assignmentrace($root, $framework, 'group_submit',
        phase11AssignmentworkerPayload($fixture, 'student', 'submit', ['recipient_id' => $fixture['recipients']['group_student'], 'attempt_id' => $groupAttempt]),
        phase11AssignmentworkerPayload($fixture, 'student_two', 'submit', ['recipient_id' => $fixture['recipients']['group_student_two'], 'attempt_id' => $groupAttempt]), $children, $coordination);
    $groupSubmission = DB::table('assignment_submissions')->where('tenant_id', $fixture['tenant_id'])->where('group_id', $fixture['group_id'])->first();
    $groupAttemptRow = DB::table('assignment_attempts')->where('tenant_id', $fixture['tenant_id'])->where('id', $groupAttempt)->first();
    if ($groupSubmission === null || $groupAttemptRow === null || $groupAttemptRow->status !== 'submitted' || count(json_decode((string) $groupSubmission->group_membership_snapshot, true) ?: []) !== 2) {
        throw new RuntimeException('group submission did not freeze both eligible members');
    }
    phase11AssignmentassertProbe(DB::table('assignment_outbox_events')->where('tenant_id', $fixture['tenant_id'])->where('event_key', 'submission-received:'.$groupAttempt)->count() === 1, 'group submission duplicated its logical outbox event');
    $summary['group_submit'] = ['sessions' => 2, 'members_snapshotted' => 2, 'receipt' => $groupAttemptRow->receipt_reference !== null, 'outcomes' => phase11AssignmentoutcomeNames($pair)];

    // 6: A draft mark races an authorized learner resubmission after return for revision.
    $pair = phase11Assignmentrace($root, $framework, 'grade_vs_resubmission',
        phase11AssignmentworkerPayload($fixture, 'teacher', 'grade', ['attempt_id' => $fixture['attempts']['revision'], 'expected_version' => 1]),
        phase11AssignmentworkerPayload($fixture, 'student', 'draft', ['recipient_id' => $fixture['recipients']['revision'], 'response_text' => 'A revised answer following instructor feedback.', 'idempotency_key' => (string) Str::uuid()]), $children, $coordination);
    $revisionSubmission = DB::table('assignment_submissions')->where('tenant_id', $fixture['tenant_id'])->where('recipient_id', $fixture['recipients']['revision'])->first();
    $revisionGrade = DB::table('assignment_grades')->where('tenant_id', $fixture['tenant_id'])->where('attempt_id', $fixture['attempts']['revision'])->first();
    if ($revisionSubmission === null) {
        throw new RuntimeException('grading/resubmission race lost its submission.');
    }
    $nextAttempt = DB::table('assignment_attempts')->where('tenant_id', $fixture['tenant_id'])->where('submission_id', $revisionSubmission->id)->where('attempt_number', 2)->first();
    if ($revisionGrade === null || $revisionGrade->score_points !== '80.00' || $nextAttempt === null || $nextAttempt->status !== 'draft') {
        throw new RuntimeException('grading/resubmission race lost the grade or next attempt');
    }
    phase11AssignmentassertProbe((int) $revisionSubmission->attempt_count === 1 && $revisionSubmission->status === 'draft', 'grading/resubmission race corrupted submission attempt state');
    $summary['grade_vs_resubmission'] = ['sessions' => 2, 'grade' => 'draft', 'next_attempt' => 2, 'outcomes' => phase11AssignmentoutcomeNames($pair)];

    // 7: Two separate reviewers race grade publication.
    $gradeId = (int) DB::table('assignment_grades')->where('tenant_id', $fixture['tenant_id'])->where('attempt_id', $fixture['attempts']['revision'])->value('id');
    $pair = phase11Assignmentrace($root, $framework, 'grade_release',
        phase11AssignmentworkerPayload($fixture, 'reviewer', 'release_grade', ['grade_id' => $gradeId]),
        phase11AssignmentworkerPayload($fixture, 'owner', 'release_grade', ['grade_id' => $gradeId]), $children, $coordination);
    phase11AssignmentassertProbe(DB::table('assignment_grades')->where('tenant_id', $fixture['tenant_id'])->where('id', $gradeId)->value('status') === 'released', 'concurrent grade release did not reach released state');
    phase11AssignmentassertProbe(DB::table('assignment_outbox_events')->where('tenant_id', $fixture['tenant_id'])->where('event_key', 'grade-released:'.$gradeId)->count() === 1, 'concurrent grade release duplicated its outbox event');
    phase11AssignmentassertProbe(DB::table('audit_events')->where('tenant_id', $fixture['tenant_id'])->where('event_type', 'assignments.grade.released')->where('subject_id', $gradeId)->count() === 1, 'concurrent grade release duplicated its audit');
    $summary['grade_release'] = ['sessions' => 2, 'released' => true, 'outbox' => 1, 'audit' => 1, 'outcomes' => phase11AssignmentoutcomeNames($pair)];

    // 8: Two publication requests race and may create only one version/outbox event.
    $publishId = $fixture['assignments']['publish_race'];
    $pair = phase11Assignmentrace($root, $framework, 'publish_outbox',
        phase11AssignmentworkerPayload($fixture, 'teacher', 'publish', ['assignment_id' => $publishId]),
        phase11AssignmentworkerPayload($fixture, 'teacher', 'publish', ['assignment_id' => $publishId]), $children, $coordination);
    phase11AssignmentassertProbe(DB::table('assignments')->where('tenant_id', $fixture['tenant_id'])->where('id', $publishId)->value('current_version') === 1, 'concurrent publication created multiple assignment versions');
    phase11AssignmentassertProbe(DB::table('assignment_versions')->where('tenant_id', $fixture['tenant_id'])->where('assignment_id', $publishId)->count() === 1, 'concurrent publication duplicated the immutable snapshot');
    phase11AssignmentassertProbe(DB::table('assignment_outbox_events')->where('tenant_id', $fixture['tenant_id'])->where('event_key', 'assignment-published:'.$publishId.':1')->count() === 1, 'concurrent publication duplicated its outbox event');
    phase11AssignmentassertProbe(DB::table('audit_events')->where('tenant_id', $fixture['tenant_id'])->where('event_type', 'assignments.published')->where('subject_id', $publishId)->count() === 1, 'concurrent publication duplicated its audit');
    $summary['publish_outbox'] = ['sessions' => 2, 'versions' => 1, 'outbox' => 1, 'audit' => 1, 'outcomes' => phase11AssignmentoutcomeNames($pair)];
} catch (Throwable $exception) {
    $failure = $exception;
} finally {
    phase11AssignmentstopChildren($children);
    phase11AssignmentremoveCoordination($coordination, $framework);
    if (isset($fixture['tenant_id'])) {
        try {
            phase11AssignmentcleanupFixture($fixture);
        } catch (Throwable $cleanupError) {
            $failure ??= new RuntimeException('Phase 11 concurrency fixture cleanup failed; preserve and inspect the run-scoped tenant.', previous: $cleanupError);
        }
    }
}

if ($failure !== null) {
    fwrite(STDERR, 'Phase 11 concurrency probes failed: '.class_basename($failure).': '.$failure->getMessage().PHP_EOL);
    exit(1);
}

fwrite(STDOUT, 'Phase 11 independent MySQL concurrency probes passed: '.json_encode($summary, JSON_THROW_ON_ERROR).PHP_EOL);

/** @param list<string> $argv */
function phase11AssignmentrunWorker(array $argv, string $framework): never
{
    $raceId = (string) ($argv[2] ?? '');
    $label = (string) ($argv[3] ?? '');
    $payload = json_decode(base64_decode((string) ($argv[4] ?? ''), true) ?: '', true);
    if (! preg_match('/\A[a-f0-9-]{36}\z/iD', $raceId) || ! in_array($label, ['a', 'b'], true) || ! is_array($payload)) {
        fwrite(STDERR, "Phase 11 worker rejected invalid coordination metadata.\n");
        exit(2);
    }
    $prefix = $framework.DIRECTORY_SEPARATOR.'phase11-assignment-'.$raceId;
    $ready = $prefix.'-'.$label.'.ready';
    $go = $prefix.'.go';
    $result = $prefix.'-'.$label.'.result';
    try {
        Phase11ProbeAuthorization::assertAuthorized(DB::connection()->getPdo(), TestDatabaseGuard::currentEnvironment());
        DB::statement('SET SESSION innodb_lock_wait_timeout = 12');
        DB::beginTransaction();
        $connectionId = (int) DB::selectOne('SELECT CONNECTION_ID() AS id')->id;
        foreach ([$ready, $go, $result] as $path) {
            if (dirname($path) !== $framework || is_link($path) || file_exists($path)) {
                throw new RuntimeException('Unsafe or pre-existing Phase 11 barrier path.');
            }
        }
        if (file_put_contents($ready, (string) $connectionId, LOCK_EX) === false) {
            throw new RuntimeException('Could not register Phase 11 worker at its barrier.');
        }
        $deadline = microtime(true) + 30;
        while (! is_file($go) && microtime(true) < $deadline) {
            usleep(5_000);
        }
        if (! is_file($go) || is_link($go)) {
            throw new RuntimeException('Phase 11 worker barrier timed out.');
        }
        if (isset($payload['clock_at'])) {
            Carbon::setTestNow(Carbon::parse((string) $payload['clock_at']));
        }
        $tenant = Tenant::query()->findOrFail((int) $payload['tenant_id']);
        $membership = TenantMembership::query()->withoutGlobalScope('tenant')->findOrFail((int) $payload['membership_id']);
        $actor = User::query()->findOrFail((int) $payload['actor_id']);
        app(TenantContext::class)->set($tenant, $membership);
        try {
            $actionResult = phase11AssignmentperformWorkerAction((string) $payload['action'], $payload, $actor);
            DB::commit();
            phase11AssignmentwriteResult($result, ['outcome' => 'success', 'connection_id' => $connectionId] + $actionResult);
            exit(0);
        } catch (ValidationException|AuthorizationException $exception) {
            phase11AssignmentrollbackWorker();
            phase11AssignmentwriteResult($result, ['outcome' => 'conflict', 'connection_id' => $connectionId, 'conflict' => class_basename($exception)]);
            exit(0);
        } catch (QueryException $exception) {
            phase11AssignmentrollbackWorker();
            $sqlState = (string) ($exception->errorInfo[0] ?? '');
            $driverCode = (int) ($exception->errorInfo[1] ?? 0);
            if ($sqlState !== '40001' && ! in_array($driverCode, [1205, 1213], true)) {
                throw $exception;
            }
            phase11AssignmentwriteResult($result, ['outcome' => 'conflict', 'connection_id' => $connectionId, 'conflict' => $driverCode === 1205 ? 'lock_timeout' : 'deadlock_retry']);
            exit(0);
        } finally {
            app(TenantContext::class)->clear();
            Carbon::setTestNow();
        }
    } catch (Throwable $exception) {
        phase11AssignmentrollbackWorker();
        fwrite(STDERR, 'Phase 11 worker failed: '.class_basename($exception).PHP_EOL);
        exit(1);
    }
}

/** @param array<string,mixed> $payload
 * @return array<string,mixed>
 */
function phase11AssignmentperformWorkerAction(string $action, array $payload, User $actor): array
{
    $workflow = app(AssignmentWorkflow::class);

    return match ($action) {
        'submit' => ['attempt_id' => (int) $workflow->submit(AssignmentRecipient::query()->findOrFail((int) $payload['recipient_id']), $actor, (int) $payload['attempt_id'])->getKey()],
        'draft' => ['attempt_id' => (int) $workflow->createOrUpdateDraft(AssignmentRecipient::query()->findOrFail((int) $payload['recipient_id']), $actor, ['response_text' => (string) $payload['response_text']], (string) $payload['idempotency_key'])->getKey()],
        'close' => ['closed' => (int) $workflow->processAvailabilityTransitions()['closed']],
        'decide_extension' => ['extension_id' => (int) $workflow->decideExtension(AssignmentExtension::query()->findOrFail((int) $payload['extension_id']), $actor, (bool) $payload['approve'])->getKey()],
        'grade' => ['grade_id' => (int) $workflow->grade(AssignmentAttempt::query()->findOrFail((int) $payload['attempt_id']), $actor, ['expected_version' => (int) $payload['expected_version'], 'score_points' => 80, 'learner_feedback' => 'Concurrency probe marking.'])->getKey()],
        'release_grade' => ['grade_id' => (int) $workflow->releaseGrade(AssignmentGrade::query()->findOrFail((int) $payload['grade_id']), $actor)->getKey()],
        'publish' => ['assignment_id' => (int) $workflow->publish(Assignment::query()->findOrFail((int) $payload['assignment_id']), $actor)->getKey()],
        default => throw new RuntimeException('Unsupported Phase 11 worker action.'),
    };
}

/** @param array<string,mixed> $left
 * @param  array<string,mixed>  $right
 * @param  list<array<string,mixed>>  $children
 * @param  list<string>  $coordination
 * @return array{array<string,mixed>,array<string,mixed>}
 */
function phase11Assignmentrace(string $root, string $framework, string $name, array $left, array $right, array &$children, array &$coordination): array
{
    $raceId = strtolower((string) Str::uuid());
    $prefix = $framework.DIRECTORY_SEPARATOR.'phase11-assignment-'.$raceId;
    $go = $prefix.'.go';
    $coordination[] = $go;
    $entries = [];
    foreach (['a' => $left, 'b' => $right] as $label => $payload) {
        $ready = $prefix.'-'.$label.'.ready';
        $result = $prefix.'-'.$label.'.result';
        $coordination[] = $ready;
        $coordination[] = $result;
        $command = [PHP_BINARY, __FILE__, 'worker', $raceId, $label, base64_encode(json_encode($payload, JSON_THROW_ON_ERROR))];
        $process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $root, Phase11ProbeEnvironment::current(), ['bypass_shell' => true]);
        if (! is_resource($process)) {
            throw new RuntimeException("Could not start {$name} independent MySQL worker.");
        }
        fclose($pipes[0]);
        $entry = ['process' => $process, 'stdout' => $pipes[1], 'stderr' => $pipes[2], 'ready' => $ready, 'result' => $result];
        $entries[] = $entry;
        $children[] = $entry;
    }
    $deadline = microtime(true) + 30;
    while ((! is_file($entries[0]['ready']) || ! is_file($entries[1]['ready'])) && microtime(true) < $deadline) {
        usleep(5_000);
    }
    if (! is_file($entries[0]['ready']) || ! is_file($entries[1]['ready'])) {
        throw new RuntimeException("Both {$name} workers did not reach the active-transaction barrier.");
    }
    $connectionIds = [(int) file_get_contents($entries[0]['ready']), (int) file_get_contents($entries[1]['ready'])];
    if ($connectionIds[0] < 1 || $connectionIds[1] < 1 || $connectionIds[0] === $connectionIds[1]) {
        throw new RuntimeException("{$name} did not use distinct MySQL sessions.");
    }
    if (file_put_contents($go, 'go', LOCK_EX) !== 2) {
        throw new RuntimeException("Could not release {$name} worker barrier.");
    }
    $outcomes = [];
    foreach ($entries as $entry) {
        $deadline = microtime(true) + 45;
        do {
            $status = proc_get_status($entry['process']);
            if (! $status['running']) {
                break;
            }
            usleep(5_000);
        } while (microtime(true) < $deadline);
        if ($status['running']) {
            proc_terminate($entry['process']);
            throw new RuntimeException("{$name} worker exceeded its bounded runtime.");
        }
        $stderr = trim((string) stream_get_contents($entry['stderr']));
        stream_get_contents($entry['stdout']);
        fclose($entry['stderr']);
        fclose($entry['stdout']);
        $exit = proc_close($entry['process']);
        $outcome = is_file($entry['result']) && ! is_link($entry['result']) ? json_decode((string) file_get_contents($entry['result']), true) : null;
        if ($exit !== 0 || ! is_array($outcome) || ! in_array($outcome['outcome'] ?? null, ['success', 'conflict'], true)) {
            throw new RuntimeException("{$name} worker failed: ".($stderr !== '' ? $stderr : 'no valid result receipt'));
        }
        $outcomes[] = $outcome;
    }
    if (($outcomes[0]['connection_id'] ?? 0) === ($outcomes[1]['connection_id'] ?? 0)) {
        throw new RuntimeException("{$name} workers did not retain independent session identities.");
    }

    return [$outcomes[0], $outcomes[1]];
}

/** @param array<string,mixed> $fixture
 * @param  array<string,mixed>  $operation
 * @return array<string,mixed>
 */
function phase11AssignmentworkerPayload(array $fixture, string $role, string $action, array $operation): array
{
    return ['tenant_id' => $fixture['tenant_id'], 'membership_id' => $fixture['memberships'][$role], 'actor_id' => $fixture['users'][$role], 'action' => $action] + $operation;
}

/** @return array<string,mixed> */
function phase11AssignmentcreateFixture(string $runId): array
{
    $stableCode = substr(str_replace('-', '', $runId), 0, 12);
    $creator = User::factory()->create();
    $users = [];
    foreach (['owner', 'teacher', 'reviewer', 'reviewer_two', 'student', 'student_two'] as $role) {
        $users[$role] = User::factory()->create();
    }
    $tenant = app(TenantProvisioner::class)->provision([
        'slug' => 'phase11-race-'.$runId,
        'official_name' => 'Phase 11 concurrency '.$runId,
        'display_name' => 'Phase 11 concurrency', 'code' => '', 'type' => 'academy', 'contact_email' => '',
        'contact_phone' => '', 'website' => '', 'country' => '', 'region' => '', 'city' => '', 'address' => '',
        'timezone' => 'UTC', 'locale' => 'en',
    ], $creator, $users['owner']->email)->tenant;
    $context = app(TenantContext::class);
    $context->enterProvisioning($tenant);
    try {
        $institution = Institution::query()->firstOrFail();
        $campus = Campus::query()->create(['institution_id' => $institution->getKey(), 'code' => 'RACE', 'name' => 'Concurrency Campus', 'status' => 'active', 'is_primary' => true]);
        $roleKeys = ['owner' => 'tenant.owner', 'teacher' => 'teacher', 'reviewer' => 'academic.admin', 'reviewer_two' => 'academic.admin', 'student' => 'student', 'student_two' => 'student'];
        $memberships = [];
        foreach ($roleKeys as $name => $roleKey) {
            $membership = TenantMembership::query()->create(['institution_id' => $institution->getKey(), 'user_id' => $users[$name]->getKey(), 'status' => 'active', 'activated_at' => now()]);
            $role = Role::query()->where('tenant_id', $tenant->getKey())->where('key', $roleKey)->firstOrFail();
            $membership->roles()->attach($role->getKey(), ['assigned_by' => $creator->getKey(), 'tenant_id' => $tenant->getKey()]);
            $memberships[$name] = (int) $membership->getKey();
        }
        $year = AcademicYear::query()->create(['institution_id' => $institution->getKey(), 'name' => 'Concurrency Year', 'code' => 'R'.$stableCode, 'starts_on' => today()->subMonth(), 'ends_on' => today()->addMonths(4), 'timezone' => 'UTC', 'locale' => 'en', 'status' => 'active']);
        $period = AcademicPeriod::query()->create(['academic_year_id' => $year->getKey(), 'name' => 'Concurrency Term', 'code' => 'R'.$stableCode, 'period_type' => 'term', 'sequence' => 1, 'starts_on' => today()->subMonth(), 'ends_on' => today()->addMonths(3), 'status' => 'active']);
        $course = AcademicCourse::query()->create(['institution_id' => $institution->getKey(), 'code' => 'R'.$stableCode, 'title' => 'Concurrency Course', 'status' => 'active']);
        $policy = EnrollmentPolicy::query()->create(['institution_id' => $institution->getKey(), 'name' => 'Concurrency Policy', 'maximum_courses' => 10]);
        $offering = CourseOffering::query()->create(['institution_id' => $institution->getKey(), 'campus_id' => $campus->getKey(), 'academic_year_id' => $year->getKey(), 'academic_period_id' => $period->getKey(), 'academic_course_id' => $course->getKey(), 'policy_id' => $policy->getKey(), 'offering_code' => 'R'.$stableCode, 'status' => 'open', 'created_by' => $users['owner']->getKey()]);
        $section = CourseSection::query()->create(['offering_id' => $offering->getKey(), 'campus_id' => $campus->getKey(), 'section_code' => 'RACE', 'capacity' => 20, 'reserved_seats' => 0, 'status' => 'open']);
        DB::table('course_section_instructors')->insert(['tenant_id' => $tenant->getKey(), 'section_id' => $section->getKey(), 'user_id' => $users['teacher']->getKey(), 'role' => 'instructor', 'created_at' => now(), 'updated_at' => now()]);
        foreach (['student', 'student_two'] as $name) {
            $student = SisStudent::query()->create(['institution_id' => $institution->getKey(), 'home_campus_id' => $campus->getKey(), 'user_id' => $users[$name]->getKey(), 'student_number' => substr($runId, 0, 12).($name === 'student' ? '1' : '2'), 'registration_reference' => substr($runId, 0, 12).($name === 'student' ? 'A' : 'B'), 'legal_first_name' => 'Race', 'legal_last_name' => $name, 'admission_date' => today()->subYear(), 'status' => 'active', 'student_type' => 'standard']);
            CourseEnrollment::query()->create(['institution_id' => $institution->getKey(), 'student_id' => $student->getKey(), 'offering_id' => $offering->getKey(), 'section_id' => $section->getKey(), 'status' => 'confirmed', 'registration_source' => 'registrar', 'requested_at' => now()->subDay(), 'confirmed_at' => now()->subDay()]);
        }

        $fixture = [
            'tenant_id' => (int) $tenant->getKey(), 'users' => array_map(static fn (User $user): int => (int) $user->getKey(), $users),
            'memberships' => $memberships, 'offering_id' => (int) $offering->getKey(), 'section_id' => (int) $section->getKey(),
            'assignments' => [], 'recipients' => [], 'attempts' => [],
        ];
    } finally {
        $context->clear();
    }

    $boundaryClock = now()->addMinutes(60)->startOfMinute();
    $assignmentSpecs = [
        'submit' => ['individual', now()->addDays(3), null],
        'draft_race' => ['individual', now()->addDays(3), null],
        'deadline' => ['individual', $boundaryClock->copy()->subMinutes(30), $boundaryClock],
        'extension' => ['individual', now()->addDays(3), null],
        'group' => ['group', now()->addDays(3), null],
        'revision' => ['individual', now()->addDays(3), null],
        'publish_race' => ['individual', now()->addDays(3), null],
    ];
    $tenantModel = Tenant::query()->findOrFail($fixture['tenant_id']);
    $teacherMembership = TenantMembership::query()->withoutGlobalScope('tenant')->findOrFail($memberships['teacher']);
    $teacher = User::query()->findOrFail($users['teacher']->getKey());
    $context->set($tenantModel, $teacherMembership);
    try {
        $workflow = app(AssignmentWorkflow::class);
        foreach ($assignmentSpecs as $name => [$collaboration, $due, $lateUntil]) {
            $payload = [
                'offering_id' => $fixture['offering_id'], 'section_ids' => [$fixture['section_id']],
                'title' => 'Concurrency '.$name, 'assignment_type' => 'coursework', 'submission_mode' => 'text',
                'collaboration_mode' => $collaboration, 'maximum_attempts' => 2, 'maximum_points' => 100,
                'timezone' => 'UTC', 'due_at' => $due->format('Y-m-d\TH:i'), 'late_until' => $lateUntil?->format('Y-m-d\TH:i'),
                'review_required' => false,
            ];
            if ($name === 'publish_race') {
                unset($payload['due_at']);
            }
            $assignment = $workflow->createDraft($payload, $teacher);
            $workflow->sendForReview($assignment, $teacher);
            if ($name === 'publish_race') {
                $fixture['assignments'][$name] = (int) $assignment->getKey();

                continue;
            }
            $assignment = $workflow->publish($assignment, $teacher);
            $fixture['assignments'][$name] = (int) $assignment->getKey();
            foreach (['student', 'student_two'] as $studentName) {
                $recipientId = (int) AssignmentRecipient::query()->where('assignment_id', $assignment->getKey())->where('membership_id', $memberships[$studentName])->value('id');
                $recipientKey = $name === 'group'
                    ? 'group_'.$studentName
                    : $name.($studentName === 'student_two' ? '_student_two' : '');
                $fixture['recipients'][$recipientKey] = $recipientId;
                if ($name === 'group' && $studentName === 'student') {
                    $fixture['recipients']['group'] = $recipientId;
                }
            }
        }
        $context->clear();

        createDraftAndSubmit($context, $tenantModel, $fixture, 'submit', 'Initial learner response.');
        createDraftAndSubmit($context, $tenantModel, $fixture, 'deadline', 'Response at the deadline boundary.');
        createDraftAndSubmit($context, $tenantModel, $fixture, 'revision', 'First submitted response for grading.');
        $context->set($tenantModel, $teacherMembership);
        try {
            $revisionAttempt = AssignmentAttempt::query()->whereHas('submission', fn ($query) => $query->where('recipient_id', $fixture['recipients']['revision']))->firstOrFail();
        } finally {
            $context->clear();
        }
        $fixture['attempts']['revision'] = (int) $revisionAttempt->getKey();

        $context->set($tenantModel, $teacherMembership);
        $group = $workflow->createGroup(phase11AssignmentworkflowAssignment($fixture['assignments']['group']), $teacher, $fixture['section_id'], 'Race Group', [$memberships['student'], $memberships['student_two']]);
        $fixture['group_id'] = (int) $group->getKey();
        $context->clear();
        createDraftAndSubmit($context, $tenantModel, $fixture, 'group', 'Shared group response.', submit: false);
        $groupSubmission = DB::table('assignment_submissions')->where('tenant_id', $fixture['tenant_id'])->where('group_id', $fixture['group_id'])->first();
        $fixture['attempts']['group'] = (int) DB::table('assignment_attempts')->where('tenant_id', $fixture['tenant_id'])->where('submission_id', $groupSubmission->id)->value('id');

        $studentMembership = TenantMembership::query()->withoutGlobalScope('tenant')->findOrFail($memberships['student']);
        $context->set($tenantModel, $studentMembership);
        $extensionRecipient = AssignmentRecipient::query()->findOrFail($fixture['recipients']['extension']);
        $extension = $workflow->requestExtension($extensionRecipient, User::query()->findOrFail($users['student']->getKey()), 'Concurrency probe extension request for an authorized learner.', $extensionRecipient->assignment->due_at->addDay());
        $fixture['extension_id'] = (int) $extension->getKey();
        $context->clear();

        $teacher = User::query()->findOrFail($users['teacher']->getKey());
        $context->set($tenantModel, $teacherMembership);
        $revisionAttempt = AssignmentAttempt::query()->findOrFail($fixture['attempts']['revision']);
        $workflow->returnForRevision($revisionAttempt, $teacher, 'Revise the evidence before resubmission.');
        $returnedGrade = AssignmentGrade::query()->where('attempt_id', $revisionAttempt->getKey())->firstOrFail();
        $context->clear();
        $fixture['revision_grade_version'] = (int) $returnedGrade->lock_version;
        if ($fixture['assignments']['deadline']) {
            $fixture['boundary_clock'] = $boundaryClock->format(DATE_ATOM);
        }

        return $fixture;
    } finally {
        $context->clear();
    }
}

/** @param array<string,mixed> $fixture */
function createDraftAndSubmit(TenantContext $context, Tenant $tenant, array &$fixture, string $name, string $response, bool $submit = true): void
{
    $membershipName = 'student';
    $membership = TenantMembership::query()->withoutGlobalScope('tenant')->findOrFail($fixture['memberships'][$membershipName]);
    $context->set($tenant, $membership);
    try {
        $recipient = AssignmentRecipient::query()->findOrFail($fixture['recipients'][$name]);
        $attempt = app(AssignmentWorkflow::class)->createOrUpdateDraft($recipient, User::query()->findOrFail($fixture['users'][$membershipName]), ['response_text' => $response], (string) Str::uuid());
        $fixture['attempts'][$name] = (int) $attempt->getKey();
        if ($submit) {
            app(AssignmentWorkflow::class)->submit($recipient, User::query()->findOrFail($fixture['users'][$membershipName]), (int) $attempt->getKey());
        }
    } finally {
        $context->clear();
    }
}

function phase11AssignmentworkflowAssignment(int $assignmentId): Assignment
{
    return Assignment::query()->findOrFail($assignmentId);
}

/** @param array<string,mixed> $fixture */
function phase11AssignmentcleanupFixture(array $fixture): void
{
    $tenantId = (int) $fixture['tenant_id'];
    $tenant = DB::table('tenants')->where('id', $tenantId)->first();
    if ($tenant === null || ! str_starts_with((string) $tenant->slug, 'phase11-race-') || (string) $tenant->name !== 'Phase 11 concurrency') {
        throw new RuntimeException('Phase 11 fixture identity did not match its run-scoped tenant; no cleanup was attempted.');
    }
    $users = DB::table('tenant_memberships')->where('tenant_id', $tenantId)->pluck('user_id')->map(static fn ($id): int => (int) $id)->all();
    $creator = (int) $tenant->created_by;
    $users = array_values(array_unique([...$users, $creator, ...array_map('intval', $fixture['users'] ?? [])]));
    DB::transaction(function () use ($tenantId, $users): void {
        $assignmentIds = DB::table('assignments')->where('tenant_id', $tenantId)->pluck('id');
        $submissionIds = DB::table('assignment_submissions')->where('tenant_id', $tenantId)->pluck('id');
        $attemptIds = DB::table('assignment_attempts')->where('tenant_id', $tenantId)->pluck('id');
        $groupIds = DB::table('assignment_groups')->where('tenant_id', $tenantId)->pluck('id');
        $recipientIds = DB::table('assignment_recipients')->where('tenant_id', $tenantId)->pluck('id');
        DB::table('assignment_notifications')->where('tenant_id', $tenantId)->delete();
        DB::table('assignment_outbox_events')->where('tenant_id', $tenantId)->delete();
        DB::table('audit_events')->where('tenant_id', $tenantId)->delete();
        DB::table('assignment_integrity_cases')->where('tenant_id', $tenantId)->delete();
        DB::table('assignment_artifacts')->where('tenant_id', $tenantId)->delete();
        DB::table('assignment_grades')->whereIn('attempt_id', $attemptIds)->delete();
        DB::table('assignment_attempt_revisions')->whereIn('attempt_id', $attemptIds)->delete();
        DB::table('assignment_attempts')->whereIn('id', $attemptIds)->delete();
        DB::table('assignment_submissions')->whereIn('id', $submissionIds)->delete();
        DB::table('assignment_group_members')->where('tenant_id', $tenantId)->delete();
        DB::table('assignment_groups')->whereIn('id', $groupIds)->delete();
        DB::table('assignment_extensions')->whereIn('recipient_id', $recipientIds)->delete();
        DB::table('assignment_recipients')->whereIn('id', $recipientIds)->delete();
        DB::table('assignment_versions')->whereIn('assignment_id', $assignmentIds)->delete();
        DB::table('assignment_sections')->whereIn('assignment_id', $assignmentIds)->delete();
        DB::table('assignments')->whereIn('id', $assignmentIds)->delete();
        DB::table('course_enrollment_events')->where('tenant_id', $tenantId)->delete();
        DB::table('course_enrollments')->where('tenant_id', $tenantId)->delete();
        DB::table('course_section_instructors')->where('tenant_id', $tenantId)->delete();
        DB::table('sis_students')->where('tenant_id', $tenantId)->delete();
        DB::table('course_sections')->where('tenant_id', $tenantId)->delete();
        DB::table('course_offerings')->where('tenant_id', $tenantId)->delete();
        DB::table('academic_courses')->where('tenant_id', $tenantId)->delete();
        DB::table('academic_periods')->where('tenant_id', $tenantId)->delete();
        DB::table('academic_years')->where('tenant_id', $tenantId)->delete();
        DB::table('enrollment_policies')->where('tenant_id', $tenantId)->delete();
        DB::table('admissions_settings')->where('tenant_id', $tenantId)->delete();
        DB::table('academic_configurations')->where('tenant_id', $tenantId)->delete();
        DB::table('tenant_invitations')->where('tenant_id', $tenantId)->delete();
        DB::table('tenant_brandings')->where('tenant_id', $tenantId)->delete();
        DB::table('tenant_settings')->where('tenant_id', $tenantId)->delete();
        DB::table('campuses')->where('tenant_id', $tenantId)->delete();
        DB::table('membership_role')->where('tenant_id', $tenantId)->delete();
        $roleIds = DB::table('roles')->where('tenant_id', $tenantId)->pluck('id');
        DB::table('permission_role')->whereIn('role_id', $roleIds)->delete();
        DB::table('roles')->where('tenant_id', $tenantId)->delete();
        DB::table('tenant_memberships')->where('tenant_id', $tenantId)->delete();
        DB::table('institutions')->where('tenant_id', $tenantId)->delete();
        DB::table('tenants')->where('id', $tenantId)->delete();
        DB::table('users')->whereIn('id', $users)->delete();
    });
}

function phase11AssignmentcleanupKnownFixture(string $runId): never
{
    if (! preg_match('/\A[a-f0-9]{8}-(?:[a-f0-9]{4}-){3}[a-f0-9]{12}\z/iD', $runId)) {
        fwrite(STDERR, "Phase 11 cleanup refused an invalid run UUID.\n");
        exit(2);
    }
    $slug = 'phase11-race-'.strtolower($runId);
    $tenant = DB::table('tenants')->where('slug', $slug)->first();
    if ($tenant === null || (string) $tenant->name !== 'Phase 11 concurrency') {
        fwrite(STDERR, "Phase 11 cleanup refused because the exact run-scoped tenant marker did not match.\n");
        exit(2);
    }
    $tenantId = (int) $tenant->id;
    $membershipUsers = DB::table('tenant_memberships')->where('tenant_id', $tenantId)->pluck('user_id')->map(static fn ($id): int => (int) $id)->all();
    if (count($membershipUsers) !== 6 || count(array_unique($membershipUsers)) !== 6) {
        fwrite(STDERR, "Phase 11 cleanup refused because the fixture membership inventory differs from the generated test fixture.\n");
        exit(2);
    }
    foreach ([...$membershipUsers, (int) $tenant->created_by] as $userId) {
        if (DB::table('tenant_memberships')->where('user_id', $userId)->where('tenant_id', '<>', $tenantId)->exists()) {
            fwrite(STDERR, "Phase 11 cleanup refused because a fixture user is linked to another tenant.\n");
            exit(2);
        }
    }
    $expected = [
        'academic_configurations' => 1, 'academic_courses' => 1, 'academic_periods' => 1, 'academic_years' => 1,
        'admissions_settings' => 1, 'assignment_attempt_revisions' => 3, 'assignment_attempts' => 3,
        'assignment_outbox_events' => 9, 'assignment_recipients' => 12, 'assignment_sections' => 7,
        'assignment_submissions' => 3, 'assignment_versions' => 6, 'assignments' => 7, 'audit_events' => 25,
        'campuses' => 1, 'course_enrollments' => 2, 'course_offerings' => 1, 'course_section_instructors' => 1,
        'course_sections' => 1, 'enrollment_policies' => 1, 'institutions' => 1, 'membership_role' => 6,
        'roles' => 12, 'sis_students' => 2, 'tenant_brandings' => 1, 'tenant_invitations' => 1,
        'tenant_memberships' => 6, 'tenant_settings' => 2,
    ];
    $tables = DB::select('SELECT TABLE_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND COLUMN_NAME = ? GROUP BY TABLE_NAME ORDER BY TABLE_NAME', ['tenant_id']);
    $actual = [];
    foreach ($tables as $table) {
        $tableName = (string) $table->TABLE_NAME;
        if (! preg_match('/\A[a-zA-Z0-9_]+\z/D', $tableName)) {
            fwrite(STDERR, "Phase 11 cleanup refused an unexpected schema table identifier.\n");
            exit(2);
        }
        $count = (int) DB::table($tableName)->where('tenant_id', $tenantId)->count();
        if ($count > 0) {
            $actual[$tableName] = $count;
        }
    }
    ksort($actual);
    ksort($expected);
    if ($actual !== $expected) {
        fwrite(STDERR, "Phase 11 cleanup refused because the run-scoped tenant data inventory differs from the known partial-fixture signature.\n");
        exit(2);
    }

    phase11AssignmentcleanupFixture(['tenant_id' => $tenantId, 'users' => []]);
    if (DB::table('tenants')->where('id', $tenantId)->exists()) {
        fwrite(STDERR, "Phase 11 cleanup did not remove the exact run-scoped fixture tenant.\n");
        exit(1);
    }
    fwrite(STDOUT, "Cleaned one explicitly identified Phase 11 partial concurrency fixture after identity, membership, and data-inventory checks.\n");
    exit(0);
}

/** @param list<array<string,mixed>> $children */
function phase11AssignmentstopChildren(array $children): void
{
    foreach ($children as $entry) {
        if (! isset($entry['process']) || ! is_resource($entry['process'])) {
            continue;
        }
        $status = proc_get_status($entry['process']);
        if ($status['running']) {
            proc_terminate($entry['process']);
        }
    }
}

/** @param list<string> $paths */
function phase11AssignmentremoveCoordination(array $paths, string $framework): void
{
    foreach (array_unique($paths) as $path) {
        if (dirname($path) === $framework && str_contains(basename($path), 'phase11-assignment-') && ! is_link($path) && is_file($path)) {
            @unlink($path);
        }
    }
}

function phase11AssignmentrollbackWorker(): void
{
    while (DB::transactionLevel() > 0) {
        DB::rollBack();
    }
}

/** @param array<string,mixed> $result */
function phase11AssignmentwriteResult(string $path, array $result): void
{
    if (is_link($path) || file_exists($path) || file_put_contents($path, json_encode($result, JSON_THROW_ON_ERROR), LOCK_EX) === false) {
        throw new RuntimeException('Phase 11 worker could not safely write its result receipt.');
    }
}

/** @param array{array<string,mixed>,array<string,mixed>} $pair
 * @return list<string>
 */
function phase11AssignmentoutcomeNames(array $pair): array
{
    return array_map(static fn (array $outcome): string => (string) $outcome['outcome'], $pair);
}

/** @phpstan-assert-if-true true $condition */
function phase11AssignmentassertProbe(bool $condition, string $message): void
{
    if (! $condition) {
        throw new RuntimeException($message);
    }
}
