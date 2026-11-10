<?php

declare(strict_types=1);

use App\Domain\Academic\Timetabling\TimetableWorkflow;
use App\Domain\Tenancy\TenantContext;
use App\Domain\Tenancy\TenantProvisioner;
use App\Models\AcademicCourse;
use App\Models\AcademicPeriod;
use App\Models\AcademicRoom;
use App\Models\AcademicTimetable;
use App\Models\AcademicTimetableMeeting;
use App\Models\AcademicYear;
use App\Models\Campus;
use App\Models\CourseSection;
use App\Models\Institution;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Connection;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\Support\Phase09MySqlTestTarget;
use Tests\Support\Phase09ProbeAuthorization;
use Tests\Support\Phase09ProbeEnvironment;
use Tests\Support\TestDatabaseGuard;

$base = dirname(__DIR__, 2);
require $base.'/vendor/autoload.php';
$app = require $base.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$environment = TestDatabaseGuard::currentEnvironment();
$connection = DB::connection();
try {
    TestDatabaseGuard::assertSafe($environment, allowPhase09Runner: true);
    if ($connection->getDriverName() !== 'mysql' || $connection->getDatabaseName() !== Phase09MySqlTestTarget::DATABASE) {
        throw new RuntimeException('The Phase 09 concurrency probe requires the exact guarded MySQL schema.');
    }
    Phase09ProbeAuthorization::assertAuthorized($connection->getPdo(), $environment);
} catch (Throwable) {
    fwrite(STDERR, "The guarded Phase 09 concurrency probe refused its database identity or runner authorization.\n");
    exit(2);
}

$framework = realpath(storage_path('framework'));
if ($framework === false) {
    fwrite(STDERR, "The Phase 09 concurrency probe has no private coordination directory.\n");
    exit(2);
}

$mode = $argv[1] ?? 'orchestrate';
if ($mode === 'worker') {
    $runId = $argv[2] ?? '';
    $label = $argv[3] ?? '';
    $payload = json_decode(base64_decode($argv[4] ?? '', true) ?: '', true);
    if (! preg_match('/^[a-f0-9-]{36}$/', $runId) || ! in_array($label, ['a', 'b'], true) || ! is_array($payload)) {
        fwrite(STDERR, "Phase 09 worker rejected invalid coordination metadata.\n");
        exit(2);
    }
    $prefix = 'phase09-timetable-'.$runId.'-'.(string) ($payload['operation'] ?? 'invalid');
    if (! preg_match('/^phase09-timetable-[a-f0-9-]{36}-(create_room|create_instructor|edit|request_reschedule|request_substitution|publish|revise)$/', $prefix)) {
        fwrite(STDERR, "Phase 09 worker rejected an unsupported operation.\n");
        exit(2);
    }
    $readyPath = $framework.DIRECTORY_SEPARATOR.$prefix.'-'.$label.'.ready';
    $goPath = $framework.DIRECTORY_SEPARATOR.$prefix.'.go';
    $resultPath = $framework.DIRECTORY_SEPARATOR.$prefix.'-'.$label.'.result';
    foreach ([$readyPath, $goPath, $resultPath] as $path) {
        if (dirname($path) !== $framework || is_link($path)) {
            fwrite(STDERR, "Phase 09 worker rejected an unsafe coordination path.\n");
            exit(2);
        }
    }
    if (file_exists($readyPath) || file_exists($resultPath) || file_put_contents($readyPath, (string) DB::selectOne('SELECT CONNECTION_ID() AS id')->id, LOCK_EX) === false) {
        fwrite(STDERR, "Phase 09 worker could not register at the barrier.\n");
        exit(2);
    }
    DB::statement('SET SESSION innodb_lock_wait_timeout = 12');
    $deadline = microtime(true) + 30;
    while (! is_file($goPath) && microtime(true) < $deadline) {
        usleep(10_000);
    }
    if (! is_file($goPath) || is_link($goPath)) {
        fwrite(STDERR, "Phase 09 worker barrier timed out.\n");
        exit(2);
    }
    $stage = 'load_fixture';
    $workerState = [];
    try {
        $tenant = Tenant::query()->findOrFail((int) $payload['tenant_id']);
        $membership = TenantMembership::query()->withoutGlobalScope('tenant')->where('tenant_id', $tenant->getKey())->findOrFail((int) $payload['membership_id']);
        $actor = User::query()->findOrFail((int) $payload['actor_id']);
        app(TenantContext::class)->set($tenant, $membership);
        $workflow = app(TimetableWorkflow::class);
        $operation = (string) $payload['operation'];
        $targetMeeting = in_array($operation, ['edit', 'request_reschedule', 'request_substitution'], true)
            ? AcademicTimetableMeeting::query()->findOrFail((int) $payload['meeting_id'])
            : null;
        if ($targetMeeting) {
            $targetMeeting->loadMissing('timetable');
            $workerState = ['meeting_status' => $targetMeeting->status, 'timetable_status' => $targetMeeting->timetable->status];
        }
        $stage = $operation;
        $result = match ($operation) {
            'create_room', 'create_instructor' => $workflow->createMeeting(AcademicTimetable::query()->findOrFail((int) $payload['timetable_id']), $payload['meeting'], $actor),
            'edit' => $workflow->updateMeeting($targetMeeting, $payload['meeting'], $actor),
            'request_reschedule', 'request_substitution' => $workflow->requestChange($targetMeeting, $payload['change'], $actor),
            'publish' => $workflow->publish(AcademicTimetable::query()->findOrFail((int) $payload['timetable_id']), $actor),
            'revise' => $workflow->revise(AcademicTimetable::query()->findOrFail((int) $payload['timetable_id']), $actor),
        };
        file_put_contents($resultPath, json_encode(['outcome' => 'success', 'connection_id' => (int) DB::selectOne('SELECT CONNECTION_ID() AS id')->id, 'id' => (int) $result->getKey()], JSON_THROW_ON_ERROR), LOCK_EX);
        exit(0);
    } catch (ValidationException|AuthorizationException $exception) {
        if ($exception instanceof ValidationException) {
            $errors = $exception->errors();
            $field = (string) (array_key_first($errors) ?: 'validation');
            $conflict = $field.':'.substr((string) ($errors[$field][0] ?? ''), 0, 140);
        } else {
            $conflict = 'authorization';
        }
        file_put_contents($resultPath, json_encode(['outcome' => 'conflict', 'connection_id' => (int) DB::selectOne('SELECT CONNECTION_ID() AS id')->id, 'conflict' => $conflict, 'state' => $workerState], JSON_THROW_ON_ERROR), LOCK_EX);
        exit(0);
    } catch (Throwable $exception) {
        $code = $exception instanceof QueryException ? (string) ($exception->errorInfo[0] ?? 'sql') : class_basename($exception);
        file_put_contents($resultPath, json_encode(['outcome' => 'failed', 'connection_id' => (int) DB::selectOne('SELECT CONNECTION_ID() AS id')->id, 'stage' => $stage, 'error_code' => preg_match('/^[A-Za-z0-9_-]{1,32}$/', $code) ? $code : 'unknown'], JSON_THROW_ON_ERROR), LOCK_EX);
        fwrite(STDERR, "A Phase 09 worker failed at {$stage} (".class_basename($exception).").\n");
        exit(1);
    } finally {
        app(TenantContext::class)->clear();
    }
}
if ($mode !== 'orchestrate') {
    fwrite(STDERR, "Unknown Phase 09 probe mode.\n");
    exit(2);
}

$runId = strtolower((string) Str::uuid());
$slug = 'phase09-race-'.$runId;
$actorIds = [];
$tenantId = 0;
$fixtureIds = ['users' => [], 'timetables' => [], 'meetings' => [], 'sections' => [], 'rooms' => []];
$children = [];
$coordination = [];
$summaries = [];
$failure = null;

/** @param array<string,mixed> $left @param array<string,mixed> $right @return array{array<string,mixed>,array<string,mixed>} */
$race = static function (array $left, array $right) use ($base, $framework, $runId, &$children, &$coordination): array {
    $operation = (string) ($left['operation'] ?? '');
    if ($operation !== ($right['operation'] ?? null)) {
        throw new RuntimeException('Phase 09 race workers must execute the same operation.');
    }
    $prefix = 'phase09-timetable-'.$runId.'-'.$operation;
    $goPath = $framework.DIRECTORY_SEPARATOR.$prefix.'.go';
    $entries = [];
    $coordination[] = $goPath;
    foreach (['a' => $left, 'b' => $right] as $label => $payload) {
        $ready = $framework.DIRECTORY_SEPARATOR.$prefix.'-'.$label.'.ready';
        $result = $framework.DIRECTORY_SEPARATOR.$prefix.'-'.$label.'.result';
        foreach ([$ready, $result, $goPath] as $path) {
            if (file_exists($path) || is_link($path)) {
                throw new RuntimeException('A Phase 09 coordination path already exists.');
            }
        }
        $coordination[] = $ready;
        $coordination[] = $result;
        $command = [PHP_BINARY, __FILE__, 'worker', $runId, $label, base64_encode(json_encode($payload, JSON_THROW_ON_ERROR))];
        $process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $base, Phase09ProbeEnvironment::current(), ['bypass_shell' => true]);
        if (! is_resource($process)) {
            throw new RuntimeException('Could not start a Phase 09 independent MySQL worker.');
        }
        fclose($pipes[0]);
        $entry = ['process' => $process, 'stdout' => $pipes[1], 'stderr' => $pipes[2], 'ready' => $ready, 'result' => $result];
        $entries[] = $entry;
        $children[] = $entry;
    }
    $deadline = microtime(true) + 30;
    while ((! is_file($entries[0]['ready']) || ! is_file($entries[1]['ready'])) && microtime(true) < $deadline) {
        usleep(10_000);
    }
    if (! is_file($entries[0]['ready']) || ! is_file($entries[1]['ready'])) {
        throw new RuntimeException("Both {$operation} MySQL sessions did not reach the synchronization barrier.");
    }
    $connectionA = (int) file_get_contents($entries[0]['ready']);
    $connectionB = (int) file_get_contents($entries[1]['ready']);
    if ($connectionA < 1 || $connectionB < 1 || $connectionA === $connectionB) {
        throw new RuntimeException("The {$operation} workers did not establish independent MySQL sessions.");
    }
    if (file_put_contents($goPath, 'go', LOCK_EX) !== 2) {
        throw new RuntimeException("Could not release the {$operation} barrier.");
    }
    $outcomes = [];
    foreach ($entries as $entry) {
        $deadline = microtime(true) + 45;
        do {
            $status = proc_get_status($entry['process']);
            if (! $status['running']) {
                break;
            }
            usleep(10_000);
        } while (microtime(true) < $deadline);
        if ($status['running']) {
            proc_terminate($entry['process']);
            throw new RuntimeException("The {$operation} worker exceeded its bounded execution time.");
        }
        $stderr = stream_get_contents($entry['stderr']);
        stream_get_contents($entry['stdout']);
        fclose($entry['stdout']);
        fclose($entry['stderr']);
        $exit = proc_close($entry['process']);
        $result = is_file($entry['result']) && ! is_link($entry['result']) ? json_decode((string) file_get_contents($entry['result']), true) : null;
        if ($exit !== 0 || ! is_array($result) || ! in_array($result['outcome'] ?? null, ['success', 'conflict'], true)) {
            throw new RuntimeException("The {$operation} worker failed: ".(is_array($result) ? (string) ($result['stage'] ?? 'unknown').'/'.(string) ($result['error_code'] ?? 'unknown') : trim((string) $stderr)));
        }
        $outcomes[] = $result;
    }
    if (($outcomes[0]['connection_id'] ?? null) === ($outcomes[1]['connection_id'] ?? null)) {
        throw new RuntimeException("The {$operation} race did not use independent MySQL sessions.");
    }

    return [$outcomes[0], $outcomes[1]];
};

try {
    Phase09ProbeAuthorization::assertAuthorized($connection->getPdo(), $environment);
    $staleProbeFixtures = cleanupPriorProbeFixtures($connection);
    if ($staleProbeFixtures > 0) {
        fwrite(STDOUT, 'Removed '.$staleProbeFixtures.' previously attested Phase 09 concurrency probe fixture(s) left by interrupted cleanup. '."\n");
    }
    $creator = User::factory()->create();
    $users = [];
    foreach (['owner', 'reviewer', 'teacher_a', 'teacher_b'] as $name) {
        $users[$name] = User::factory()->create();
        $actorIds[] = (int) $users[$name]->getKey();
    }
    $actorIds[] = (int) $creator->getKey();
    $provisioned = app(TenantProvisioner::class)->provision([
        'slug' => $slug, 'official_name' => 'Phase 09 concurrency probe '.$runId, 'display_name' => 'Phase 09 concurrency probe',
        'code' => '', 'type' => 'academy', 'contact_email' => '', 'contact_phone' => '', 'website' => '', 'country' => '',
        'region' => '', 'city' => '', 'address' => '', 'timezone' => 'UTC', 'locale' => 'en',
    ], $creator, $users['owner']->email);
    $tenant = $provisioned->tenant;
    $tenantId = (int) $tenant->getKey();
    $context = app(TenantContext::class);
    $context->enterProvisioning($tenant);
    try {
        $institution = Institution::query()->firstOrFail();
        $campus = Campus::query()->create(['institution_id' => $institution->getKey(), 'code' => 'RACE', 'name' => 'Race Campus', 'status' => 'active', 'is_primary' => true]);
        $memberships = [];
        foreach ([['owner', 'tenant.owner'], ['reviewer', 'academic.admin'], ['teacher_a', 'teacher'], ['teacher_b', 'teacher']] as [$name, $roleKey]) {
            $membership = TenantMembership::query()->create(['institution_id' => $institution->getKey(), 'user_id' => $users[$name]->getKey(), 'status' => 'active', 'activated_at' => now()]);
            $role = Role::query()->where('tenant_id', $tenantId)->where('key', $roleKey)->firstOrFail();
            $membership->roles()->attach($role->getKey(), ['assigned_by' => $creator->getKey(), 'tenant_id' => $tenantId]);
            $memberships[$name] = (int) $membership->getKey();
        }
        $dates = [now()->addDays(14), now()->addDays(15), now()->addDays(16), now()->addDays(17), now()->addDays(18), now()->addDays(19)];
        $year = AcademicYear::query()->create(['institution_id' => $institution->getKey(), 'name' => 'Race Year '.$runId, 'code' => 'RY'.substr(str_replace('-', '', $runId), 0, 8), 'starts_on' => now()->subDay()->toDateString(), 'ends_on' => now()->addMonths(2)->toDateString(), 'timezone' => 'UTC', 'locale' => 'en', 'status' => 'active']);
        $period = AcademicPeriod::query()->create(['academic_year_id' => $year->getKey(), 'name' => 'Race Period '.$runId, 'code' => 'RP'.substr(str_replace('-', '', $runId), 0, 8), 'period_type' => 'term', 'sequence' => 1, 'starts_on' => now()->subDay()->toDateString(), 'ends_on' => now()->addMonths(2)->toDateString(), 'status' => 'active']);
        $course = AcademicCourse::query()->create(['institution_id' => $institution->getKey(), 'code' => 'RC'.substr(str_replace('-', '', $runId), 0, 8), 'title' => 'Race course', 'status' => 'active']);
        $policyId = (int) DB::table('enrollment_policies')->insertGetId(['tenant_id' => $tenantId, 'institution_id' => $institution->getKey(), 'name' => 'Race policy '.$runId, 'maximum_courses' => 8, 'created_by' => $users['owner']->getKey(), 'created_at' => now(), 'updated_at' => now()]);
        $offeringId = (int) DB::table('course_offerings')->insertGetId(['tenant_id' => $tenantId, 'institution_id' => $institution->getKey(), 'academic_year_id' => $year->getKey(), 'academic_period_id' => $period->getKey(), 'academic_course_id' => $course->getKey(), 'policy_id' => $policyId, 'offering_code' => 'RO'.substr(str_replace('-', '', $runId), 0, 8), 'delivery_mode' => 'in_person', 'status' => 'open', 'created_by' => $users['owner']->getKey(), 'created_at' => now(), 'updated_at' => now()]);
        $sections = [];
        foreach (['A', 'B', 'C', 'D', 'E', 'F'] as $code) {
            $section = CourseSection::query()->create(['offering_id' => $offeringId, 'campus_id' => $campus->getKey(), 'section_code' => $code, 'capacity' => 20, 'reserved_seats' => 0, 'status' => 'open']);
            $sections[$code] = $section;
            $fixtureIds['sections'][] = (int) $section->getKey();
            foreach (['teacher_a', 'teacher_b'] as $teacherKey) {
                DB::table('course_section_instructors')->insert(['tenant_id' => $tenantId, 'section_id' => $section->getKey(), 'user_id' => $users[$teacherKey]->getKey(), 'role' => 'instructor', 'created_at' => now(), 'updated_at' => now()]);
            }
        }
        $rooms = [];
        foreach (['A', 'B', 'C'] as $code) {
            $room = AcademicRoom::query()->create(['institution_id' => $institution->getKey(), 'campus_id' => $campus->getKey(), 'code' => 'RR'.$code.substr(str_replace('-', '', $runId), 0, 5), 'name' => 'Race room '.$code, 'room_type' => 'classroom', 'capacity' => 40, 'status' => 'active']);
            $rooms[$code] = $room;
            $fixtureIds['rooms'][] = (int) $room->getKey();
        }
        $newTimetable = static function (int $version, string $status, bool $reviewRequired, ?AcademicPeriod $forPeriod = null) use ($institution, $year, $period, $campus, $users, &$fixtureIds): AcademicTimetable {
            $selectedPeriod = $forPeriod ?? $period;
            $row = AcademicTimetable::query()->create(['institution_id' => $institution->getKey(), 'academic_year_id' => $year->getKey(), 'academic_period_id' => $selectedPeriod->getKey(), 'campus_id' => $campus->getKey(), 'name' => 'Race timetable v'.$version, 'version' => $version, 'status' => $status, 'timezone' => 'UTC', 'independent_review_required' => $reviewRequired, 'created_by' => $users['owner']->getKey(), 'submitted_by' => $status !== 'draft' ? $users['owner']->getKey() : null, 'reviewed_by' => in_array($status, ['approved', 'published'], true) ? $users['reviewer']->getKey() : null, 'published_by' => $status === 'published' ? $users['reviewer']->getKey() : null, 'submitted_at' => $status !== 'draft' ? now() : null, 'reviewed_at' => in_array($status, ['approved', 'published'], true) ? now() : null, 'published_at' => $status === 'published' ? now() : null]);
            $fixtureIds['timetables'][] = (int) $row->getKey();

            return $row;
        };
        $draft = $newTimetable(1, 'draft', false);
        $publish = $newTimetable(2, 'approved', false);
        $changePeriod = AcademicPeriod::query()->create(['academic_year_id' => $year->getKey(), 'name' => 'Change Race Period '.$runId, 'code' => 'CR'.substr(str_replace('-', '', $runId), 0, 8), 'period_type' => 'term', 'sequence' => 2, 'starts_on' => now()->subDay()->toDateString(), 'ends_on' => now()->addMonths(2)->toDateString(), 'status' => 'active']);
        $source = $newTimetable(1, 'published', false, $changePeriod);
        $makeMeeting = static function (AcademicTimetable $timetable, string $sectionCode, User $teacher, AcademicRoom $room, string $date, string $time = '09:00') use ($users, $sections, &$fixtureIds): AcademicTimetableMeeting {
            $meeting = AcademicTimetableMeeting::query()->create(['timetable_id' => $timetable->getKey(), 'section_id' => $sections[$sectionCode]->getKey(), 'instructor_user_id' => $teacher->getKey(), 'room_id' => $room->getKey(), 'starts_on' => $date, 'ends_on' => $date, 'weekdays' => [(int) now()->parse($date)->dayOfWeekIso], 'starts_at_local' => $time, 'duration_minutes' => 50, 'delivery_mode' => 'in_person', 'status' => 'scheduled', 'created_by' => $users['owner']->getKey()]);
            $fixtureIds['meetings'][] = (int) $meeting->getKey();

            return $meeting;
        };
        $roomMeetings = [];
        foreach (['A', 'B'] as $code) {
            $roomMeetings[$code] = ['section_id' => (int) $sections[$code]->getKey(), 'instructor_user_id' => (int) $users[$code === 'A' ? 'teacher_a' : 'teacher_b']->getKey(), 'room_id' => (int) $rooms['A']->getKey(), 'starts_on' => $dates[0]->toDateString(), 'ends_on' => $dates[0]->toDateString(), 'weekdays' => [(int) $dates[0]->dayOfWeekIso], 'starts_at_local' => '09:00', 'duration_minutes' => 50, 'delivery_mode' => 'in_person'];
        }
        $instructorMeetings = [];
        foreach (['C', 'D'] as $i => $code) {
            $instructorMeetings[$code] = ['section_id' => (int) $sections[$code]->getKey(), 'instructor_user_id' => (int) $users['teacher_a']->getKey(), 'room_id' => (int) $rooms[$i === 0 ? 'B' : 'C']->getKey(), 'starts_on' => $dates[1]->toDateString(), 'ends_on' => $dates[1]->toDateString(), 'weekdays' => [(int) $dates[1]->dayOfWeekIso], 'starts_at_local' => '10:00', 'duration_minutes' => 50, 'delivery_mode' => 'in_person'];
        }
        $series1 = $makeMeeting($draft, 'E', $users['teacher_a'], $rooms['B'], $dates[2]->toDateString(), '11:00');
        $series2 = $makeMeeting($draft, 'F', $users['teacher_b'], $rooms['C'], $dates[3]->toDateString(), '12:00');
        $publicationMeeting = $makeMeeting($publish, 'A', $users['teacher_a'], $rooms['A'], $dates[4]->toDateString());
        $publish->update(['status' => 'approved']);
        $sourceMeeting = $makeMeeting($source, 'A', $users['teacher_a'], $rooms['A'], $dates[5]->toDateString());
        $tenant->refresh();
    } finally {
        $context->clear();
    }

    $payload = static fn (string $operation, string $actor, array $extra = []): array => ['operation' => $operation, 'tenant_id' => $tenantId, 'membership_id' => $memberships[$actor], 'actor_id' => (int) $users[$actor]->getKey(), ...$extra];
    $roomRace = $race($payload('create_room', 'owner', ['timetable_id' => $draft->getKey(), 'meeting' => $roomMeetings['A']]), $payload('create_room', 'owner', ['timetable_id' => $draft->getKey(), 'meeting' => $roomMeetings['B']]));
    if (count(array_filter($roomRace, fn ($item) => $item['outcome'] === 'success')) !== 1 || DB::table('academic_timetable_meetings')->where('timetable_id', $draft->getKey())->where('room_id', $rooms['A']->getKey())->where('starts_on', $dates[0]->toDateString())->count() !== 1) {
        throw new RuntimeException('Concurrent room allocation did not preserve the single-booking invariant.');
    }
    $summaries[] = 'room allocation: one commit, one conflict';

    $instructorRace = $race($payload('create_instructor', 'owner', ['timetable_id' => $draft->getKey(), 'meeting' => $instructorMeetings['C']]), $payload('create_instructor', 'owner', ['timetable_id' => $draft->getKey(), 'meeting' => $instructorMeetings['D']]));
    if (count(array_filter($instructorRace, fn ($item) => $item['outcome'] === 'success')) !== 1 || DB::table('academic_timetable_meetings')->where('timetable_id', $draft->getKey())->where('instructor_user_id', $users['teacher_a']->getKey())->where('starts_on', $dates[1]->toDateString())->count() !== 1) {
        throw new RuntimeException('Concurrent instructor allocation did not preserve the single-booking invariant.');
    }
    $summaries[] = 'instructor allocation: one commit, one conflict';

    $seriesMeeting = ['section_id' => (int) $sections['E']->getKey(), 'instructor_user_id' => (int) $users['teacher_a']->getKey(), 'room_id' => (int) $rooms['A']->getKey(), 'starts_on' => $dates[2]->toDateString(), 'ends_on' => $dates[2]->toDateString(), 'weekdays' => [(int) $dates[2]->dayOfWeekIso], 'starts_at_local' => '14:00', 'duration_minutes' => 50, 'delivery_mode' => 'in_person'];
    $editRace = $race($payload('edit', 'owner', ['meeting_id' => $series1->getKey(), 'meeting' => $seriesMeeting]), $payload('edit', 'owner', ['meeting_id' => $series2->getKey(), 'meeting' => $seriesMeeting]));
    if (count(array_filter($editRace, fn ($item) => $item['outcome'] === 'success')) !== 1 || DB::table('academic_timetable_meetings')->where('timetable_id', $draft->getKey())->where('room_id', $rooms['A']->getKey())->where('starts_on', $dates[0]->toDateString())->count() !== 1) {
        throw new RuntimeException('Concurrent recurring-series edits did not preserve conflict safety.');
    }
    $summaries[] = 'recurring-series conflict: one commit, one conflict';

    $publishRace = $race($payload('publish', 'reviewer', ['timetable_id' => $publish->getKey()]), $payload('publish', 'owner', ['timetable_id' => $publish->getKey()]));
    if (count(array_filter($publishRace, fn ($item) => $item['outcome'] === 'success')) !== 1 || DB::table('academic_timetable_snapshots')->where('timetable_id', $publish->getKey())->where('version', 2)->count() !== 1 || DB::table('audit_events')->where('tenant_id', $tenantId)->where('event_type', 'academic.timetable.published')->count() !== 1) {
        throw new RuntimeException('Competing publication produced an inconsistent status, snapshot or audit trail.');
    }
    $summaries[] = 'competing publication: one immutable snapshot and audit';

    $sourceState = DB::table('academic_timetable_meetings as m')->join('academic_timetables as t', 't.id', '=', 'm.timetable_id')->where('m.id', $sourceMeeting->getKey())->select(['m.status as meeting_status', 't.status as timetable_status'])->first();
    if (! $sourceState || $sourceState->meeting_status !== 'scheduled' || $sourceState->timetable_status !== 'published') {
        throw new RuntimeException('Reschedule race fixture state mismatch: '.json_encode($sourceState).'.');
    }
    $targetDate = $dates[4]->copy()->addDays(2)->toDateString();
    $changeBase = ['meeting_id' => $sourceMeeting->getKey(), 'change' => ['occurrence_date' => $dates[5]->toDateString(), 'replacement_date' => $targetDate, 'change_type' => 'reschedule', 'starts_at_local' => '13:00', 'reason' => 'Concurrent Phase 09 reschedule probe.']];
    $rescheduleRace = $race($payload('request_reschedule', 'owner', $changeBase), $payload('request_reschedule', 'owner', $changeBase));
    $rescheduleRows = DB::table('academic_timetable_changes')->where('meeting_id', $sourceMeeting->getKey())->where('occurrence_date', $dates[5]->toDateString())->where('change_type', 'reschedule')->whereIn('status', ['pending', 'approved'])->count();
    $rescheduleAudits = DB::table('audit_events')->where('tenant_id', $tenantId)->where('event_type', 'academic.timetable.change_requested')->count();
    if (count(array_filter($rescheduleRace, fn ($item) => $item['outcome'] === 'success')) !== 1 || $rescheduleRows !== 1 || $rescheduleAudits !== 1) {
        throw new RuntimeException('Concurrent occurrence reschedule invariant mismatch: outcomes='.json_encode(array_column($rescheduleRace, 'outcome')).', conflicts='.json_encode(array_column($rescheduleRace, 'conflict')).', states='.json_encode(array_column($rescheduleRace, 'state')).', changes='.$rescheduleRows.', audit='.$rescheduleAudits.'.');
    }
    $summaries[] = 'occurrence reschedule: one active change and audit';

    $substitutionBase = ['meeting_id' => $sourceMeeting->getKey(), 'change' => ['occurrence_date' => $dates[5]->toDateString(), 'replacement_date' => $dates[5]->toDateString(), 'change_type' => 'substitution', 'starts_at_local' => '09:00', 'reason' => 'Concurrent Phase 09 substitution probe.']];
    $subA = $race($payload('request_substitution', 'owner', ['meeting_id' => $sourceMeeting->getKey(), 'change' => [...$substitutionBase['change'], 'instructor_user_id' => (int) $users['teacher_a']->getKey()]]), $payload('request_substitution', 'owner', ['meeting_id' => $sourceMeeting->getKey(), 'change' => [...$substitutionBase['change'], 'instructor_user_id' => (int) $users['teacher_b']->getKey()]]));
    if (count(array_filter($subA, fn ($item) => $item['outcome'] === 'success')) !== 1 || DB::table('academic_timetable_changes')->where('meeting_id', $sourceMeeting->getKey())->where('occurrence_date', $dates[5]->toDateString())->where('change_type', 'substitution')->whereIn('status', ['pending', 'approved'])->count() !== 1) {
        throw new RuntimeException('Concurrent substitute assignment produced duplicate active change requests.');
    }
    $summaries[] = 'substitution assignment: one active change';

    $versionRace = $race($payload('revise', 'owner', ['timetable_id' => $source->getKey()]), $payload('revise', 'owner', ['timetable_id' => $source->getKey()]));
    if (count(array_filter($versionRace, fn ($item) => $item['outcome'] === 'success')) !== 2 || $versionRace[0]['id'] !== $versionRace[1]['id'] || DB::table('academic_timetables')->where('tenant_id', $tenantId)->where('supersedes_timetable_id', $source->getKey())->count() !== 1 || DB::table('audit_events')->where('tenant_id', $tenantId)->where('event_type', 'academic.timetable.revision_created')->count() !== 1) {
        throw new RuntimeException('Concurrent timetable version changes created a duplicate revision or audit event.');
    }
    $summaries[] = 'version change: idempotent single revision and audit';
    fwrite(STDOUT, 'Phase 09 independent-session MySQL concurrency probes passed (7): '.implode('; ', $summaries).".\n");
} catch (Throwable $exception) {
    $failure = $exception;
} finally {
    foreach ($children as $child) {
        if (is_resource($child['process'])) {
            $status = proc_get_status($child['process']);
            if ($status['running']) {
                proc_terminate($child['process']);
                proc_close($child['process']);
            }
        }
    }
    foreach ($coordination as $path) {
        if (dirname($path) === $framework && ! is_link($path) && is_file($path)) {
            unlink($path);
        }
    }
    if ($tenantId > 0) {
        try {
            cleanupTenantFixture($connection, $tenantId, $actorIds, $slug);
        } catch (Throwable $cleanupError) {
            $failure ??= new RuntimeException('Phase 09 race fixture cleanup failed for '.$slug.' ('.class_basename($cleanupError).'): '.$cleanupError->getMessage(), 0, $cleanupError);
        }
    }
}
if ($failure instanceof Throwable) {
    fwrite(STDERR, 'Phase 09 concurrency verification failed: '.class_basename($failure).': '.$failure->getMessage().PHP_EOL);
    exit(1);
}

/**
 * Delete only a run-identified tenant fixture, child tables before referenced parents.
 *
 * @param  list<int>  $userIds
 */
function cleanupTenantFixture(Connection $connection, int $tenantId, array $userIds, string $expectedSlug): void
{
    $tenant = DB::table('tenants')->where('id', $tenantId)->where('slug', $expectedSlug)->first();
    if (! $tenant) {
        throw new RuntimeException('Phase 09 cleanup refused a tenant whose run-specific slug did not match.');
    }
    $connection->table('academic_timetables')->where('tenant_id', $tenantId)->update(['supersedes_timetable_id' => null]);
    $connection->table('academic_credit_policies')->where('tenant_id', $tenantId)->update(['academic_program_id' => null]);
    $connection->table('academic_programs')->where('tenant_id', $tenantId)->update(['credit_policy_id' => null]);
    $connection->table('academic_units')->where('tenant_id', $tenantId)->update(['parent_unit_id' => null]);
    $connection->table('academic_levels')->where('tenant_id', $tenantId)->update(['parent_level_id' => null]);
    $schema = Phase09MySqlTestTarget::DATABASE;
    $tables = $connection->select('SELECT DISTINCT c.TABLE_NAME AS table_name FROM information_schema.COLUMNS c WHERE c.TABLE_SCHEMA = ? AND c.COLUMN_NAME = ?', [$schema, 'tenant_id']);
    $remaining = array_fill_keys(array_map(static fn ($row): string => (string) $row->table_name, $tables), true);
    unset($remaining['tenants']);
    while ($remaining !== []) {
        $deletedAny = false;
        foreach (array_keys($remaining) as $table) {
            $children = $connection->select('SELECT DISTINCT TABLE_NAME AS table_name,CONSTRAINT_NAME AS constraint_name FROM information_schema.KEY_COLUMN_USAGE WHERE CONSTRAINT_SCHEMA = ? AND REFERENCED_TABLE_NAME = ? AND TABLE_NAME <> ?', [$schema, $table, $table]);
            $breakableEdges = [
                'academic_credit_policies' => ['acad_credit_program_fk'],
                'academic_programs' => ['acad_program_credit_fk'],
                'academic_units' => ['acad_unit_parent_fk'],
                'academic_levels' => ['acad_level_parent_fk'],
            ];
            $blocked = false;
            foreach ($children as $child) {
                $childTable = (string) $child->table_name;
                $constraint = (string) $child->constraint_name;
                if (isset($remaining[$childTable]) && ! in_array($constraint, $breakableEdges[$childTable] ?? [], true)) {
                    $blocked = true;
                    break;
                }
            }
            if ($blocked) {
                continue;
            }
            $connection->table($table)->where('tenant_id', $tenantId)->delete();
            unset($remaining[$table]);
            $deletedAny = true;
        }
        if (! $deletedAny) {
            throw new RuntimeException('Phase 09 fixture cleanup found a cyclic tenant-table reference ('.implode(',', array_keys($remaining)).'); no forced constraint bypass was attempted.');
        }
    }
    DB::table('tenants')->where('id', $tenantId)->where('slug', $expectedSlug)->delete();
    if ($userIds !== []) {
        DB::table('users')->whereIn('id', array_values(array_unique($userIds)))->delete();
    }
}

/** Remove only fixtures whose unique UUID slug, institution label, and provisioning audit prove this probe created them. */
function cleanupPriorProbeFixtures(Connection $connection): int
{
    $fixtures = DB::table('tenants')->where('slug', 'like', 'phase09-race-%')->orderBy('id')->get(['id', 'slug', 'name', 'created_by']);
    $removed = 0;
    foreach ($fixtures as $fixture) {
        $uuid = substr((string) $fixture->slug, strlen('phase09-race-'));
        $expectedSlug = 'phase09-race-'.$uuid;
        $institutionEvidence = DB::table('institutions')->where('tenant_id', $fixture->id)->where('official_name', 'Phase 09 concurrency probe '.$uuid)->exists();
        if (! preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-4[a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/', $uuid)
            || $fixture->name !== 'Phase 09 concurrency probe' || ! $institutionEvidence) {
            throw new RuntimeException('A phase09-race tenant did not match its unique tenant/institution ownership markers (slug='.$expectedSlug.', institution='.($institutionEvidence ? 'yes' : 'no').'); no stale fixture was deleted.');
        }
        $users = DB::table('tenant_memberships')->where('tenant_id', $fixture->id)->pluck('user_id')->map(static fn ($id): int => (int) $id)->all();
        $users[] = (int) $fixture->created_by;
        cleanupTenantFixture($connection, (int) $fixture->id, array_values(array_unique($users)), $expectedSlug);
        $removed++;
    }

    return $removed;
}
