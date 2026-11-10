<?php

declare(strict_types=1);

use App\Domain\Academic\Attendance\AttendanceWorkflow;
use App\Domain\Enrollment\CourseEnrollmentWorkflow;
use App\Domain\Tenancy\TenantContext;
use App\Domain\Tenancy\TenantProvisioner;
use App\Models\AcademicCourse;
use App\Models\AcademicPeriod;
use App\Models\AcademicTimetable;
use App\Models\AcademicTimetableMeeting;
use App\Models\AcademicYear;
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
use Tests\Support\Phase10MySqlTestTarget;
use Tests\Support\Phase10ProbeAuthorization;
use Tests\Support\Phase10ProbeEnvironment;
use Tests\Support\TestDatabaseGuard;

$root = dirname(__DIR__, 2);
require $root.'/vendor/autoload.php';
$app = require $root.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$environment = TestDatabaseGuard::currentEnvironment();
try {
    TestDatabaseGuard::assertSafe($environment, allowPhase10Runner: true);
    $connection = DB::connection();
    if ($connection->getDriverName() !== 'mysql' || $connection->getDatabaseName() !== Phase10MySqlTestTarget::DATABASE) {
        throw new RuntimeException('Phase 10 concurrency probes require the exact guarded MySQL schema.');
    }
    Phase10ProbeAuthorization::assertAuthorized($connection->getPdo(), $environment);
} catch (Throwable) {
    fwrite(STDERR, "Phase 10 concurrency probes refused database identity or runner authorization.\n");
    exit(2);
}

$framework = realpath(storage_path('framework'));
if ($framework === false) {
    fwrite(STDERR, "Phase 10 concurrency probes have no private coordination directory.\n");
    exit(2);
}

$mode = $argv[1] ?? 'orchestrate';
if ($mode === 'worker') {
    runWorker($argv, $framework);
}
if ($mode !== 'orchestrate') {
    fwrite(STDERR, "Unknown Phase 10 concurrency probe mode.\n");
    exit(2);
}

$runId = strtolower((string) Str::uuid());
$slug = 'phase10-race-'.$runId;
$fixture = [];
$children = [];
$coordination = [];
$failure = null;
$summary = [];

try {
    cleanupStalePhase10ProbeFixtures();
    $fixture = createFixture($slug, $runId);
    $meetingIds = $fixture['meeting_ids'];
    $registerIds = [];
    $context = app(TenantContext::class);
    $tenant = Tenant::query()->findOrFail($fixture['tenant_id']);
    $teacherMembership = TenantMembership::query()->withoutGlobalScope('tenant')->findOrFail($fixture['memberships']['teacher']);
    $teacher = User::query()->findOrFail($fixture['users']['teacher']);
    $context->set($tenant, $teacherMembership);
    try {
        $workflow = app(AttendanceWorkflow::class);
        foreach ([1, 2, 3, 7] as $index) {
            $register = $workflow->createRegister($teacher, $meetingIds[$index], $fixture['dates'][$index]);
            $registerIds[$index] = (int) $register->getKey();
            if (! in_array($index, [1, 2, 3], true)) {
                markAll($workflow, $teacher, $registerIds[$index]);
            }
        }
        // Probe 4, 5, 6 and 8 require registers in submitted state.
        foreach ([4, 5, 6, 8] as $index) {
            $register = $workflow->createRegister($teacher, $meetingIds[$index], $fixture['dates'][$index]);
            $registerIds[$index] = (int) $register->getKey();
            markAll($workflow, $teacher, $registerIds[$index]);
            $current = $register->fresh();
            $workflow->submit($teacher, $registerIds[$index], (int) $current->version);
        }
    } finally {
        $context->clear();
    }

    $entries = [];
    foreach ($registerIds as $number => $registerId) {
        $entries[$number] = DB::table('attendance_entries')->where('register_id', $registerId)->orderBy('student_id')->pluck('id')->map(static fn ($id): int => (int) $id)->all();
    }

    // 1: unique register and roster snapshot under simultaneous creation.
    $pair = race($root, $framework, $runId, 'create_register', workerPayload($fixture, 'teacher', 'create_register', [
        'meeting_id' => $meetingIds[0], 'date' => $fixture['dates'][0],
    ]), workerPayload($fixture, 'teacher', 'create_register', [
        'meeting_id' => $meetingIds[0], 'date' => $fixture['dates'][0],
    ]), $children, $coordination);
    $registerOne = (int) ($pair[0]['register_id'] ?? 0);
    $registerTwo = (int) ($pair[1]['register_id'] ?? 0);
    assertProbe($registerOne > 0 && $registerOne === $registerTwo, 'simultaneous register creation did not converge on one register');
    assertProbe(DB::table('attendance_registers')->where('meeting_id', $meetingIds[0])->whereDate('occurrence_date', $fixture['dates'][0])->count() === 1, 'register unique invariant failed');
    assertProbe(DB::table('attendance_entries')->where('register_id', $registerOne)->count() === 2, 'register creation duplicated or omitted roster entries');
    assertProbe(DB::table('audit_events')->where('tenant_id', $fixture['tenant_id'])->where('event_type', 'attendance.register.created')->where('subject_id', $registerOne)->count() === 1, 'register creation audit invariant failed');
    $summary['register_creation'] = ['sessions' => 2, 'registers' => 1, 'entries' => 2];

    // 2: same expected version; one editor wins and no update is silently lost.
    $entry = $entries[2][0];
    race($root, $framework, $runId, 'competing_edits',
        workerPayload($fixture, 'teacher', 'mark', ['register_id' => $registerIds[2], 'version' => 1, 'items' => [['entry_id' => $entry, 'status_code' => 'PRESENT']]]),
        workerPayload($fixture, 'teacher', 'mark', ['register_id' => $registerIds[2], 'version' => 1, 'items' => [['entry_id' => $entry, 'status_code' => 'LATE']]]), $children, $coordination);
    $summary['competing_edits'] = assertSingleOptimisticWinner($registerIds[2], [$entry]);

    // 3: overlapping bulk writes are atomic; the register version admits one batch.
    $itemsA = [['entry_id' => $entries[3][0], 'status_code' => 'PRESENT'], ['entry_id' => $entries[3][1], 'status_code' => 'ABSENT']];
    $itemsB = [['entry_id' => $entries[3][0], 'status_code' => 'LATE'], ['entry_id' => $entries[3][1], 'status_code' => 'PRESENT']];
    race($root, $framework, $runId, 'bulk_marking', workerPayload($fixture, 'teacher', 'mark', ['register_id' => $registerIds[3], 'version' => 1, 'items' => $itemsA]), workerPayload($fixture, 'teacher', 'mark', ['register_id' => $registerIds[3], 'version' => 1, 'items' => $itemsB]), $children, $coordination);
    $summary['bulk_marking'] = assertSingleOptimisticWinner($registerIds[3], $entries[3]);

    // 4: submit and correction contend on the same register/entry lifecycle.
    race($root, $framework, $runId, 'submit_vs_correction', workerPayload($fixture, 'teacher', 'submit', ['register_id' => $registerIds[4], 'version' => 3]), workerPayload($fixture, 'teacher', 'request_correction', ['entry_id' => $entries[4][0], 'status_id' => $fixture['status_ids']['LATE']]), $children, $coordination);
    assertProbe(DB::table('attendance_registers')->where('id', $registerIds[4])->value('status') === 'submitted', 'submit-vs-correction lost the submission transition');
    assertProbe(DB::table('attendance_entries')->whereIn('id', $entries[4])->count() === 2, 'submit-vs-correction changed roster membership');
    assertProbe(DB::table('attendance_correction_requests')->where('entry_id', $entries[4][0])->where('status', 'pending')->count() <= 1, 'submit-vs-correction duplicated correction request');
    $summary['submit_vs_correction'] = ['sessions' => 2, 'submitted' => true, 'pending_corrections' => (int) DB::table('attendance_correction_requests')->where('entry_id', $entries[4][0])->where('status', 'pending')->count()];

    // 5: finalize and edit race, followed by a locked-state immutability check.
    race($root, $framework, $runId, 'lock_vs_update', workerPayload($fixture, 'owner', 'finalize', ['register_id' => $registerIds[5], 'version' => 3]), workerPayload($fixture, 'teacher', 'mark', ['register_id' => $registerIds[5], 'version' => 3, 'items' => [['entry_id' => $entries[5][0], 'status_code' => 'LATE']]]), $children, $coordination);
    $locked = DB::table('attendance_registers')->where('id', $registerIds[5])->first();
    if ($locked->status === 'submitted') {
        $context->set($tenant, TenantMembership::query()->withoutGlobalScope('tenant')->findOrFail($fixture['memberships']['owner']));
        try {
            app(AttendanceWorkflow::class)->finalize(User::query()->findOrFail($fixture['users']['owner']), $registerIds[5], (int) $locked->version);
        } finally {
            $context->clear();
        }
    }
    $locked = DB::table('attendance_registers')->where('id', $registerIds[5])->first();
    assertProbe($locked->status === 'locked', 'lock-vs-update did not reach locked final state');
    $beforeRevisions = DB::table('attendance_entry_revisions')->whereIn('entry_id', $entries[5])->count();
    teacherContext($context, $tenant, $fixture, 'teacher');
    try {
        $attemptBlocked = false;
        try {
            app(AttendanceWorkflow::class)->mark(User::query()->findOrFail($fixture['users']['teacher']), $registerIds[5], (int) $locked->version, [['entry_id' => $entries[5][0], 'status_code' => 'ABSENT']]);
        } catch (ValidationException|AuthorizationException) {
            $attemptBlocked = true;
        }
        assertProbe($attemptBlocked, 'locked attendance accepted an edit');
    } finally {
        $context->clear();
    }
    assertProbe(DB::table('attendance_entry_revisions')->whereIn('entry_id', $entries[5])->count() === $beforeRevisions, 'locked edit created a revision');
    $summary['lock_vs_update'] = ['sessions' => 2, 'status' => 'locked', 'post_lock_edit_rejected' => true];

    // 6: two independent reviewers race the same correction decision.
    teacherContext($context, $tenant, $fixture, 'teacher');
    try {
        $correction = app(AttendanceWorkflow::class)->requestCorrection(User::query()->findOrFail($fixture['users']['teacher']), $entries[6][0], $fixture['status_ids']['LATE'], 'Concurrent review probe correction');
        $correctionId = (int) $correction->getKey();
    } finally {
        $context->clear();
    }
    race($root, $framework, $runId, 'duplicate_correction_approval', workerPayload($fixture, 'reviewer', 'review_correction', ['correction_id' => $correctionId]), workerPayload($fixture, 'reviewer_two', 'review_correction', ['correction_id' => $correctionId]), $children, $coordination);
    assertProbe(DB::table('attendance_correction_requests')->where('id', $correctionId)->value('status') === 'approved', 'correction did not reach approved state');
    assertProbe(DB::table('attendance_entry_revisions')->where('entry_id', $entries[6][0])->where('change_source', 'correction')->count() === 1, 'duplicate correction created multiple effective revisions');
    assertProbe(DB::table('audit_events')->where('tenant_id', $fixture['tenant_id'])->where('event_type', 'attendance.correction.approved')->where('subject_id', $correctionId)->count() === 1, 'duplicate correction emitted multiple approval audits');
    assertProbe(DB::table('attendance_outbox_events')->where('tenant_id', $fixture['tenant_id'])->where('event_key', 'correction-reviewed-'.$correctionId)->count() === 1, 'duplicate correction emitted duplicate logical outbox event');
    $summary['duplicate_correction_approval'] = ['sessions' => 2, 'approved_transitions' => 1, 'revisions' => 1, 'outbox_events' => 1];

    // 7: section transfer races submission; the attendance snapshot remains historical.
    $destination = $fixture['destination_section_id'];
    studentContext($context, $tenant, $fixture);
    try {
        $transferId = app(CourseEnrollmentWorkflow::class)->requestSectionTransfer($fixture['enrollment_id'], $destination, 'Phase 10 concurrent transfer probe', User::query()->findOrFail($fixture['users']['student']));
    } finally {
        $context->clear();
    }
    race($root, $framework, $runId, 'transfer_vs_submission', workerPayload($fixture, 'owner', 'approve_transfer', ['transfer_id' => $transferId]), workerPayload($fixture, 'teacher', 'submit', ['register_id' => $registerIds[7], 'version' => 2]), $children, $coordination);
    assertProbe(DB::table('attendance_registers')->where('id', $registerIds[7])->value('status') === 'submitted', 'transfer race lost attendance submission');
    assertProbe(DB::table('attendance_entries')->where('register_id', $registerIds[7])->where('student_id', $fixture['student_id'])->exists(), 'transfer race erased historical roster snapshot');
    assertProbe((int) DB::table('course_enrollments')->where('id', $fixture['enrollment_id'])->value('section_id') === $destination, 'transfer race did not preserve approved destination placement');
    assertProbe(DB::table('attendance_entries')->where('register_id', $registerIds[7])->where('student_id', $fixture['student_id'])->count() === 1, 'transfer race leaked duplicate learner entry');
    $summary['transfer_vs_submission'] = ['sessions' => 2, 'register_status' => 'submitted', 'placement' => 'destination', 'historical_entry_preserved' => true];

    // 8: duplicate submit attempts generate one durable logical notification event.
    race($root, $framework, $runId, 'outbox_generation', workerPayload($fixture, 'teacher', 'submit', ['register_id' => $registerIds[8], 'version' => 3]), workerPayload($fixture, 'teacher', 'submit', ['register_id' => $registerIds[8], 'version' => 3]), $children, $coordination);
    $outbox = DB::table('attendance_outbox_events')->where('tenant_id', $fixture['tenant_id'])->where('event_key', 'register-submitted-'.$registerIds[8].'-3')->get();
    assertProbe($outbox->count() === 1, 'duplicate submit emitted zero or multiple outbox records');
    assertProbe((int) data_get(json_decode((string) $outbox->first()->safe_payload, true), 'register_id') === $registerIds[8], 'outbox payload does not identify the authorized register');
    assertProbe(DB::table('audit_events')->where('tenant_id', $fixture['tenant_id'])->where('event_type', 'attendance.register.submitted')->where('subject_id', $registerIds[8])->count() === 1, 'duplicate submit emitted multiple audits');
    $summary['outbox_generation'] = ['sessions' => 2, 'logical_events' => 1, 'scoped_payload' => true];
} catch (Throwable $exception) {
    $failure = $exception;
} finally {
    stopChildren($children);
    removeCoordination($coordination, $framework);
    if (isset($fixture['tenant_id'])) {
        try {
            cleanupFixture($fixture);
        } catch (Throwable $cleanupError) {
            $failure ??= new RuntimeException('Phase 10 concurrency fixture cleanup failed; inspect the run-scoped tenant before cleanup.', previous: $cleanupError);
        }
    }
}

if ($failure !== null) {
    fwrite(STDERR, 'Phase 10 concurrency probes failed: '.class_basename($failure).': '.$failure->getMessage()."\n");
    exit(1);
}

fwrite(STDOUT, 'Phase 10 independent MySQL concurrency probes passed: '.json_encode($summary, JSON_THROW_ON_ERROR)."\n");

/** @param list<string> $argv */
function runWorker(array $argv, string $framework): never
{
    $runId = (string) ($argv[2] ?? '');
    $label = (string) ($argv[3] ?? '');
    $encoded = (string) ($argv[4] ?? '');
    $payload = json_decode(base64_decode($encoded, true) ?: '', true);
    if (! preg_match('/^[a-f0-9-]{36}$/', $runId) || ! in_array($label, ['a', 'b'], true) || ! is_array($payload)) {
        fwrite(STDERR, "Phase 10 worker rejected invalid coordination metadata.\n");
        exit(2);
    }
    try {
        $pdo = DB::connection()->getPdo();
        Phase10ProbeAuthorization::assertAuthorized($pdo, TestDatabaseGuard::currentEnvironment());
        DB::statement('SET SESSION innodb_lock_wait_timeout = 12');
        DB::beginTransaction();
        $connectionId = (int) DB::selectOne('SELECT CONNECTION_ID() AS id')->id;
        $prefix = $framework.DIRECTORY_SEPARATOR.'phase10-attendance-'.$runId;
        $ready = $prefix.'-'.$label.'.ready';
        $go = $prefix.'.go';
        $result = $prefix.'-'.$label.'.result';
        foreach ([$ready, $go, $result] as $path) {
            if (dirname($path) !== $framework || is_link($path) || file_exists($path)) {
                throw new RuntimeException('Unsafe or pre-existing Phase 10 barrier path.');
            }
        }
        if (file_put_contents($ready, (string) $connectionId, LOCK_EX) === false) {
            throw new RuntimeException('Could not register MySQL worker at barrier.');
        }
        $deadline = microtime(true) + 30;
        while (! is_file($go) && microtime(true) < $deadline) {
            usleep(5_000);
        }
        if (! is_file($go) || is_link($go)) {
            throw new RuntimeException('Phase 10 worker barrier timed out.');
        }
        $tenant = Tenant::query()->findOrFail((int) $payload['tenant_id']);
        $membership = TenantMembership::query()->withoutGlobalScope('tenant')->findOrFail((int) $payload['membership_id']);
        $actor = User::query()->findOrFail((int) $payload['actor_id']);
        app(TenantContext::class)->set($tenant, $membership);
        try {
            $resultData = performWorkerAction((string) $payload['action'], $payload, $actor);
            DB::commit();
            file_put_contents($result, json_encode(['outcome' => 'success', 'connection_id' => $connectionId] + $resultData, JSON_THROW_ON_ERROR), LOCK_EX);
            exit(0);
        } catch (ValidationException|AuthorizationException $exception) {
            while (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
            $conflictType = class_basename($exception);
            file_put_contents($result, json_encode(['outcome' => 'conflict', 'connection_id' => $connectionId, 'conflict' => preg_match('/^[A-Za-z0-9_-]{1,40}$/', $conflictType) ? $conflictType : 'conflict'], JSON_THROW_ON_ERROR), LOCK_EX);
            exit(0);
        } catch (QueryException $exception) {
            while (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
            $state = (string) ($exception->errorInfo[0] ?? '');
            $driverCode = (int) ($exception->errorInfo[1] ?? 0);
            if ($state !== '40001' && ! in_array($driverCode, [1205, 1213], true)) {
                throw $exception;
            }
            file_put_contents($result, json_encode(['outcome' => 'conflict', 'connection_id' => $connectionId, 'conflict' => $driverCode === 1205 ? 'lock_timeout' : 'deadlock_retry'], JSON_THROW_ON_ERROR), LOCK_EX);
            exit(0);
        } finally {
            app(TenantContext::class)->clear();
        }
    } catch (Throwable $exception) {
        while (DB::transactionLevel() > 0) {
            DB::rollBack();
        }
        fwrite(STDERR, 'Phase 10 worker failed: '.class_basename($exception)."\n");
        exit(1);
    }
}

/**
 * @param  array<string,mixed>  $payload
 * @return array<string,int>
 */
function performWorkerAction(string $action, array $payload, User $actor): array
{
    $attendance = app(AttendanceWorkflow::class);

    return match ($action) {
        'create_register' => ['register_id' => (int) $attendance->createRegister($actor, (int) $payload['meeting_id'], (string) $payload['date'])->getKey()],
        'mark' => ['version' => (int) $attendance->mark($actor, (int) $payload['register_id'], (int) $payload['version'], $payload['items'])->version],
        'submit' => ['version' => (int) $attendance->submit($actor, (int) $payload['register_id'], (int) $payload['version'])->version],
        'finalize' => ['version' => (int) $attendance->finalize($actor, (int) $payload['register_id'], (int) $payload['version'])->version],
        'request_correction' => ['correction_id' => (int) $attendance->requestCorrection($actor, (int) $payload['entry_id'], (int) $payload['status_id'], 'Concurrent submit/correction probe')->getKey()],
        'review_correction' => ['correction_id' => (int) $attendance->reviewCorrection($actor, (int) $payload['correction_id'], true, 'Concurrent independent approval probe')->getKey()],
        'approve_transfer' => ['enrollment_id' => (int) app(CourseEnrollmentWorkflow::class)->decideSectionTransfer((int) $payload['transfer_id'], 'approve', 'Concurrent placement transition probe', $actor)->getKey()],
        default => throw new RuntimeException('Unsupported Phase 10 worker action.'),
    };
}

/**
 * @param  array<string,mixed>  $left
 * @param  array<string,mixed>  $right
 * @param  list<array<string,mixed>>  $children
 * @param  list<string>  $coordination
 * @return array{array<string,mixed>,array<string,mixed>}
 */
function race(string $root, string $framework, string $runId, string $name, array $left, array $right, array &$children, array &$coordination): array
{
    $barrierId = strtolower((string) Str::uuid());
    $prefix = $framework.DIRECTORY_SEPARATOR.'phase10-attendance-'.$barrierId;
    $go = $prefix.'.go';
    $coordination[] = $go;
    $entries = [];
    foreach (['a' => $left, 'b' => $right] as $label => $payload) {
        $ready = $prefix.'-'.$label.'.ready';
        $result = $prefix.'-'.$label.'.result';
        $coordination[] = $ready;
        $coordination[] = $result;
        $command = [PHP_BINARY, __FILE__, 'worker', $barrierId, $label, base64_encode(json_encode($payload, JSON_THROW_ON_ERROR))];
        $process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $root, Phase10ProbeEnvironment::current(), ['bypass_shell' => true]);
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
    $ids = [(int) file_get_contents($entries[0]['ready']), (int) file_get_contents($entries[1]['ready'])];
    if ($ids[0] < 1 || $ids[1] < 1 || $ids[0] === $ids[1]) {
        throw new RuntimeException("{$name} did not use distinct MySQL connections.");
    }
    if (file_put_contents($go, 'go', LOCK_EX) !== 2) {
        throw new RuntimeException("Could not release {$name} synchronization barrier.");
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
            throw new RuntimeException("{$name} worker exceeded bounded runtime.");
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

/**
 * @param  array<string,mixed>  $fixture
 * @param  array<string,mixed>  $operation
 * @return array<string,mixed>
 */
function workerPayload(array $fixture, string $role, string $action, array $operation): array
{
    return ['tenant_id' => $fixture['tenant_id'], 'membership_id' => $fixture['memberships'][$role], 'actor_id' => $fixture['users'][$role], 'action' => $action] + $operation;
}

function markAll(AttendanceWorkflow $workflow, User $teacher, int $registerId): void
{
    $entries = DB::table('attendance_entries')->where('register_id', $registerId)->orderBy('student_id')->pluck('id');
    $items = $entries->map(static fn ($id): array => ['entry_id' => (int) $id, 'status_code' => 'PRESENT'])->all();
    $workflow->mark($teacher, $registerId, 1, $items);
}

/**
 * @param  list<int>  $entryIds
 * @return array<string,int|bool>
 */
function assertSingleOptimisticWinner(int $registerId, array $entryIds): array
{
    $register = DB::table('attendance_registers')->where('id', $registerId)->first();
    assertProbe((int) $register->version === 2, 'optimistic edit race did not increment the register version exactly once');
    assertProbe(DB::table('attendance_entry_revisions')->whereIn('entry_id', $entryIds)->count() === count($entryIds), 'bulk edit was partially applied or revisions are inconsistent');
    foreach ($entryIds as $entryId) {
        assertProbe(DB::table('attendance_entries')->where('id', $entryId)->value('status_definition_id') !== null, 'bulk race left an unmarked entry');
    }

    return ['sessions' => 2, 'register_version' => (int) $register->version, 'revisions' => count($entryIds), 'atomic' => true];
}

/** @return array<string,mixed> */
function createFixture(string $slug, string $runId): array
{
    $creator = User::factory()->create();
    $users = [];
    foreach (['owner', 'teacher', 'reviewer', 'reviewer_two', 'student', 'student_two'] as $name) {
        $users[$name] = User::factory()->create();
    }
    $tenant = app(TenantProvisioner::class)->provision([
        'slug' => $slug, 'official_name' => 'Phase 10 concurrency '.$runId, 'display_name' => 'Phase 10 concurrency',
        'code' => '', 'type' => 'academy', 'contact_email' => '', 'contact_phone' => '', 'website' => '', 'country' => '',
        'region' => '', 'city' => '', 'address' => '', 'timezone' => 'UTC', 'locale' => 'en',
    ], $creator, $users['owner']->email)->tenant;
    $context = app(TenantContext::class);
    $context->enterProvisioning($tenant);
    try {
        $institution = Institution::query()->firstOrFail();
        $campus = Campus::query()->create(['institution_id' => $institution->getKey(), 'code' => 'RACE', 'name' => 'Race Campus', 'status' => 'active', 'is_primary' => true]);
        $memberships = [];
        foreach (['owner' => 'tenant.owner', 'teacher' => 'teacher', 'reviewer' => 'academic.admin', 'reviewer_two' => 'academic.admin', 'student' => 'student', 'student_two' => 'student'] as $name => $roleKey) {
            $membership = TenantMembership::query()->create(['institution_id' => $institution->getKey(), 'user_id' => $users[$name]->getKey(), 'status' => 'active', 'activated_at' => now()]);
            $role = Role::query()->where('tenant_id', $tenant->getKey())->where('key', $roleKey)->firstOrFail();
            $membership->roles()->attach($role->getKey(), ['assigned_by' => $creator->getKey(), 'tenant_id' => $tenant->getKey()]);
            $memberships[$name] = (int) $membership->getKey();
        }
        $year = AcademicYear::query()->create(['institution_id' => $institution->getKey(), 'name' => 'Race Year', 'code' => 'RY'.substr(str_replace('-', '', $runId), 0, 8), 'starts_on' => today()->subDay(), 'ends_on' => today()->addMonths(2), 'timezone' => 'UTC', 'locale' => 'en', 'status' => 'active']);
        $period = AcademicPeriod::query()->create(['academic_year_id' => $year->getKey(), 'name' => 'Race Period', 'code' => 'RP'.substr(str_replace('-', '', $runId), 0, 8), 'period_type' => 'term', 'sequence' => 1, 'starts_on' => today()->subDay(), 'ends_on' => today()->addMonths(2), 'status' => 'active']);
        $course = AcademicCourse::query()->create(['institution_id' => $institution->getKey(), 'code' => 'RC'.substr(str_replace('-', '', $runId), 0, 8), 'title' => 'Race course', 'status' => 'active']);
        $policy = EnrollmentPolicy::query()->create(['institution_id' => $institution->getKey(), 'name' => 'Race policy', 'maximum_courses' => 8, 'allow_section_transfer' => true]);
        $offering = CourseOffering::query()->create(['institution_id' => $institution->getKey(), 'campus_id' => $campus->getKey(), 'academic_year_id' => $year->getKey(), 'academic_period_id' => $period->getKey(), 'academic_course_id' => $course->getKey(), 'policy_id' => $policy->getKey(), 'offering_code' => 'RO'.substr(str_replace('-', '', $runId), 0, 8), 'delivery_mode' => 'in_person', 'status' => 'open', 'created_by' => $users['owner']->getKey()]);
        $sections = [];
        foreach (['A', 'B'] as $code) {
            $sections[$code] = CourseSection::query()->create(['offering_id' => $offering->getKey(), 'campus_id' => $campus->getKey(), 'section_code' => $code, 'capacity' => 20, 'reserved_seats' => 0, 'status' => 'open']);
        }
        DB::table('course_section_instructors')->insert(['tenant_id' => $tenant->getKey(), 'section_id' => $sections['A']->getKey(), 'user_id' => $users['teacher']->getKey(), 'role' => 'instructor', 'created_at' => now(), 'updated_at' => now()]);
        $students = [];
        foreach (['student' => 'One', 'student_two' => 'Two'] as $key => $suffix) {
            $students[$key] = SisStudent::query()->create(['institution_id' => $institution->getKey(), 'home_campus_id' => $campus->getKey(), 'user_id' => $users[$key]->getKey(), 'student_number' => 'SR'.substr(str_replace('-', '', $runId), 0, 6).($suffix === 'One' ? '1' : '2'), 'registration_reference' => 'RF'.substr(str_replace('-', '', $runId), 0, 6).($suffix === 'One' ? '1' : '2'), 'legal_first_name' => 'Race', 'legal_last_name' => $suffix, 'admission_date' => today()->subYear(), 'status' => 'active', 'student_type' => 'standard']);
            CourseEnrollment::query()->create(['institution_id' => $institution->getKey(), 'student_id' => $students[$key]->getKey(), 'offering_id' => $offering->getKey(), 'section_id' => $sections['A']->getKey(), 'status' => 'confirmed', 'registration_source' => 'registrar', 'requested_at' => now(), 'confirmed_at' => now()]);
        }
        $attendance = app(AttendanceWorkflow::class);
        $context->set($tenant, TenantMembership::query()->withoutGlobalScope('tenant')->findOrFail($memberships['owner']));
        $attendancePolicy = $attendance->createPolicy(User::query()->findOrFail($users['owner']->getKey()), (int) $institution->getKey(), (int) $campus->getKey(), 'Race attendance policy', ['marking_opens_before_minutes' => 20000]);
        $statusIds = DB::table('attendance_status_definitions')->where('policy_version_id', $attendancePolicy->versions()->firstOrFail()->getKey())->pluck('id', 'code')->map(static fn ($id): int => (int) $id)->all();
        $timetable = AcademicTimetable::query()->create(['institution_id' => $institution->getKey(), 'academic_year_id' => $year->getKey(), 'academic_period_id' => $period->getKey(), 'campus_id' => $campus->getKey(), 'name' => 'Race schedule', 'version' => 1, 'status' => 'published', 'timezone' => 'UTC', 'independent_review_required' => false, 'created_by' => $users['owner']->getKey(), 'published_by' => $users['owner']->getKey(), 'published_at' => now()]);
        $meetingIds = $dates = [];
        for ($i = 0; $i <= 8; $i++) {
            $date = Carbon::today()->addDays($i + 2)->toDateString();
            $dates[$i] = $date;
            $meeting = AcademicTimetableMeeting::query()->create(['timetable_id' => $timetable->getKey(), 'section_id' => $sections['A']->getKey(), 'instructor_user_id' => $users['teacher']->getKey(), 'room_id' => null, 'starts_on' => $date, 'ends_on' => $date, 'weekdays' => [(int) Carbon::parse($date)->dayOfWeekIso], 'starts_at_local' => '09:00', 'duration_minutes' => 50, 'delivery_mode' => 'in_person', 'status' => 'scheduled', 'created_by' => $users['owner']->getKey()]);
            $meetingIds[$i] = (int) $meeting->getKey();
        }
        $userIds = array_map(static fn (User $user): int => (int) $user->getKey(), $users);
        $userIds['creator'] = (int) $creator->getKey();

        $enrollmentId = (int) DB::table('course_enrollments')->where('tenant_id', $tenant->getKey())->where('student_id', $students['student']->getKey())->value('id');

        return ['tenant_id' => (int) $tenant->getKey(), 'institution_id' => (int) $institution->getKey(), 'campus_id' => (int) $campus->getKey(), 'users' => $userIds, 'memberships' => $memberships, 'section_id' => (int) $sections['A']->getKey(), 'destination_section_id' => (int) $sections['B']->getKey(), 'student_id' => (int) $students['student']->getKey(), 'enrollment_id' => $enrollmentId, 'meeting_ids' => $meetingIds, 'dates' => $dates, 'status_ids' => $statusIds];
    } finally {
        $context->clear();
    }
}

function assertProbe(bool $condition, string $message): void
{
    if (! $condition) {
        throw new RuntimeException($message);
    }
}

/** @param array<string,mixed> $fixture */
function teacherContext(TenantContext $context, Tenant $tenant, array $fixture, string $role): void
{
    $context->set($tenant, TenantMembership::query()->withoutGlobalScope('tenant')->findOrFail($fixture['memberships'][$role]));
}

/** @param array<string,mixed> $fixture */
function studentContext(TenantContext $context, Tenant $tenant, array $fixture): void
{
    teacherContext($context, $tenant, $fixture, 'student');
}

/** @param array<string,mixed> $fixture */
function cleanupFixture(array $fixture): void
{
    $tenantId = (int) $fixture['tenant_id'];
    $creatorId = (int) DB::table('tenants')->where('id', $tenantId)->value('created_by');
    $memberIds = DB::table('tenant_memberships')->where('tenant_id', $tenantId)->pluck('user_id')->map(static fn ($id): int => (int) $id)->all();
    $inviteEmails = DB::table('tenant_invitations')->where('tenant_id', $tenantId)->pluck('email')->all();
    $invitedUserIds = DB::table('users')->whereIn('email', $inviteEmails)->pluck('id')->map(static fn ($id): int => (int) $id)->all();
    $userIds = array_values(array_unique([...$memberIds, ...$invitedUserIds, $creatorId, ...array_map('intval', $fixture['users'] ?? [])]));
    DB::transaction(function () use ($tenantId, $userIds): void {
        $registers = DB::table('attendance_registers')->where('tenant_id', $tenantId)->pluck('id');
        $entries = DB::table('attendance_entries')->whereIn('register_id', $registers)->pluck('id');
        DB::table('attendance_justification_evidence')->whereIn('justification_id', DB::table('attendance_justifications')->whereIn('entry_id', $entries)->select('id'))->delete();
        DB::table('attendance_justifications')->whereIn('entry_id', $entries)->delete();
        DB::table('attendance_correction_requests')->whereIn('entry_id', $entries)->delete();
        DB::table('attendance_entry_revisions')->whereIn('entry_id', $entries)->delete();
        DB::table('attendance_entries')->whereIn('register_id', $registers)->delete();
        DB::table('attendance_outbox_events')->where('tenant_id', $tenantId)->delete();
        DB::table('attendance_registers')->where('tenant_id', $tenantId)->delete();
        DB::table('attendance_status_definitions')->where('tenant_id', $tenantId)->delete();
        DB::table('attendance_policy_versions')->where('tenant_id', $tenantId)->delete();
        DB::table('attendance_policies')->where('tenant_id', $tenantId)->delete();
        DB::table('audit_events')->where('tenant_id', $tenantId)->delete();
        DB::table('course_section_transfers')->where('tenant_id', $tenantId)->delete();
        DB::table('course_enrollment_events')->where('tenant_id', $tenantId)->delete();
        DB::table('course_enrollments')->where('tenant_id', $tenantId)->delete();
        DB::table('course_section_instructors')->where('tenant_id', $tenantId)->delete();
        DB::table('academic_timetable_meetings')->where('tenant_id', $tenantId)->delete();
        DB::table('academic_timetables')->where('tenant_id', $tenantId)->delete();
        DB::table('course_sections')->where('tenant_id', $tenantId)->delete();
        DB::table('course_offerings')->where('tenant_id', $tenantId)->delete();
        DB::table('sis_students')->where('tenant_id', $tenantId)->delete();
        DB::table('academic_courses')->where('tenant_id', $tenantId)->delete();
        DB::table('academic_periods')->where('tenant_id', $tenantId)->delete();
        DB::table('academic_years')->where('tenant_id', $tenantId)->delete();
        DB::table('enrollment_policies')->where('tenant_id', $tenantId)->delete();
        DB::table('academic_configurations')->where('tenant_id', $tenantId)->delete();
        DB::table('admissions_settings')->where('tenant_id', $tenantId)->delete();
        DB::table('tenant_invitations')->where('tenant_id', $tenantId)->delete();
        DB::table('tenant_brandings')->where('tenant_id', $tenantId)->delete();
        DB::table('tenant_settings')->where('tenant_id', $tenantId)->delete();
        DB::table('campuses')->where('tenant_id', $tenantId)->delete();
        DB::table('membership_role')->where('tenant_id', $tenantId)->delete();
        DB::table('tenant_memberships')->where('tenant_id', $tenantId)->delete();
        $roleIds = DB::table('roles')->where('tenant_id', $tenantId)->pluck('id');
        DB::table('permission_role')->whereIn('role_id', $roleIds)->delete();
        DB::table('roles')->where('tenant_id', $tenantId)->delete();
        DB::table('institutions')->where('tenant_id', $tenantId)->delete();
        DB::table('tenants')->where('id', $tenantId)->delete();
        DB::table('users')->whereIn('id', $userIds)->delete();
    });
}

/** Remove only clearly identified run-scoped fixtures left by an interrupted probe attempt. */
function cleanupStalePhase10ProbeFixtures(): void
{
    $candidates = DB::table('tenants')->where('slug', 'like', 'phase10-race-%')->get(['id', 'slug', 'name']);
    foreach ($candidates as $candidate) {
        $slug = (string) $candidate->slug;
        if (! preg_match('/^phase10-race-([a-f0-9-]{36})$/', $slug, $matches)
            || (string) $candidate->name !== 'Phase 10 concurrency') {
            throw new RuntimeException('A Phase 10 probe slug is not a recognized run-scoped fixture; it was preserved.');
        }
        $institution = DB::table('institutions')->where('tenant_id', $candidate->id)->first(['official_name']);
        if ($institution === null || (string) $institution->official_name !== 'Phase 10 concurrency '.$matches[1]) {
            throw new RuntimeException('A Phase 10 probe candidate did not match the harness fixture identity; it was preserved.');
        }
        $expected = [
            'academic_timetables' => 1, 'academic_timetable_meetings' => 9,
            'course_offerings' => 1, 'course_sections' => 2,
            'attendance_policies' => 1, 'attendance_registers' => 9,
            'sis_students' => 2,
        ];
        foreach ($expected as $table => $count) {
            if ((int) DB::table($table)->where('tenant_id', $candidate->id)->count() !== $count) {
                throw new RuntimeException('A prior Phase 10 probe fixture inventory differed from the harness; it was preserved.');
            }
        }
        cleanupFixture(['tenant_id' => (int) $candidate->id]);
        if (DB::table('tenants')->where('id', $candidate->id)->exists()) {
            throw new RuntimeException('A recognized interrupted Phase 10 fixture was not fully removed.');
        }
    }
}

/** @param list<array<string,mixed>> $children */
function stopChildren(array $children): void
{
    foreach ($children as $entry) {
        if (isset($entry['process']) && is_resource($entry['process'])) {
            $status = proc_get_status($entry['process']);
            if ($status['running']) {
                proc_terminate($entry['process']);
                proc_close($entry['process']);
            }
        }
    }
}

/** @param list<string> $paths */
function removeCoordination(array $paths, string $framework): void
{
    foreach (array_unique($paths) as $path) {
        if (dirname($path) === $framework && is_file($path) && ! is_link($path)) {
            unlink($path);
        }
    }
}
