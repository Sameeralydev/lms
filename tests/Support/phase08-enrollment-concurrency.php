<?php

declare(strict_types=1);

use App\Domain\Enrollment\CourseEnrollmentWorkflow;
use App\Domain\Tenancy\TenantContext;
use App\Models\CourseEnrollment;
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
use Tests\Support\Phase08MySqlTestTarget;
use Tests\Support\Phase08ProbeAuthorization;
use Tests\Support\Phase08ProbeEnvironment;
use Tests\Support\TestDatabaseGuard;

$basePath = dirname(__DIR__, 2);
require $basePath.'/vendor/autoload.php';
$app = require $basePath.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$environment = TestDatabaseGuard::currentEnvironment();
$connection = DB::connection();
try {
    TestDatabaseGuard::assertSafe($environment);
    if ($connection->getDriverName() !== 'mysql' || $connection->getDatabaseName() !== Phase08MySqlTestTarget::DATABASE) {
        throw new RuntimeException('The Phase 08 concurrency probe requires its exact guarded MySQL schema.');
    }
    Phase08ProbeAuthorization::assertAuthorized($connection->getPdo(), $environment);
} catch (Throwable) {
    fwrite(STDERR, "The guarded Phase 08 concurrency probe refused its database identity.\n");
    exit(2);
}

$framework = realpath(storage_path('framework'));
if ($framework === false) {
    fwrite(STDERR, "The Phase 08 concurrency probe has no private coordination directory.\n");
    exit(2);
}

$mode = $argv[1] ?? 'orchestrate';
if ($mode === 'worker') {
    $operation = $argv[2] ?? '';
    $tenantId = (int) ($argv[3] ?? 0);
    $userId = (int) ($argv[4] ?? 0);
    $sectionId = (int) ($argv[5] ?? 0);
    $enrollmentId = (int) ($argv[6] ?? 0);
    $destinationId = (int) ($argv[7] ?? 0);
    $idempotencyKey = $argv[8] ?? '';
    $decision = $argv[9] ?? '';
    $reason = $argv[10] ?? '';
    $runId = $argv[11] ?? '';
    $label = $argv[12] ?? '';
    $readyName = $argv[13] ?? '';
    $goName = $argv[14] ?? '';
    $resultName = $argv[15] ?? '';
    if (! in_array($operation, ['register', 'drop', 'transfer', 'decide'], true)
        || ! preg_match('/^[a-f0-9-]{36}$/', $runId)
        || ! in_array($label, ['a', 'b'], true)
        || $tenantId < 1 || $userId < 1 || $sectionId < 1
        || ($operation !== 'register' && $enrollmentId < 1)
        || ($operation === 'transfer' && $destinationId < 1)
        || ($operation === 'register' && $idempotencyKey === '')
        || ($operation === 'decide' && ! in_array($decision, ['approve', 'reject'], true))) {
        fwrite(STDERR, "The Phase 08 concurrency worker rejected invalid operation metadata.\n");
        exit(2);
    }
    $prefix = 'phase08-enrollment-'.$runId.'-'.$operation.'-'.$label;
    if ($readyName !== $prefix.'.ready' || $resultName !== $prefix.'.result'
        || $goName !== 'phase08-enrollment-'.$runId.'-'.$operation.'.go') {
        fwrite(STDERR, "The Phase 08 concurrency worker rejected an unsafe barrier name.\n");
        exit(2);
    }
    $paths = array_map(static fn (string $name): string => $framework.DIRECTORY_SEPARATOR.$name, [$readyName, $goName, $resultName]);
    foreach ($paths as $path) {
        if (dirname($path) !== $framework || is_link($path)) {
            fwrite(STDERR, "The Phase 08 concurrency worker rejected an unsafe coordination path.\n");
            exit(2);
        }
    }
    [$readyPath, $goPath, $resultPath] = $paths;
    if (file_exists($readyPath) || file_exists($resultPath) || file_put_contents($readyPath, 'ready', LOCK_EX) !== 5) {
        fwrite(STDERR, "The Phase 08 concurrency worker could not register at the barrier.\n");
        exit(2);
    }
    $connectionId = (int) DB::selectOne('SELECT CONNECTION_ID() AS id')->id;
    $deadline = microtime(true) + 20;
    while (! is_file($goPath) && microtime(true) < $deadline) {
        usleep(20000);
    }
    if (! is_file($goPath) || is_link($goPath)) {
        fwrite(STDERR, "The Phase 08 worker barrier timed out.\n");
        exit(2);
    }

    $context = app(TenantContext::class);
    try {
        $tenant = Tenant::withoutGlobalScope('tenant')->findOrFail($tenantId);
        $membership = TenantMembership::withoutGlobalScope('tenant')->where('tenant_id', $tenantId)->where('user_id', $userId)->where('status', 'active')->firstOrFail();
        $actor = User::query()->findOrFail($userId);
        $context->set($tenant, $membership);
        $workflow = app(CourseEnrollmentWorkflow::class);
        $value = match ($operation) {
            'register' => $workflow->register($sectionId, $idempotencyKey, $actor),
            'drop' => $workflow->drop(CourseEnrollment::query()->where('tenant_id', $tenantId)->findOrFail($enrollmentId), $actor),
            'transfer' => $workflow->requestSectionTransfer($enrollmentId, $destinationId, 'Concurrent Phase 08 transfer request.', $actor),
            'decide' => $workflow->decide($enrollmentId, $decision, 'Concurrent Phase 08 approval.', $actor),
        };
        file_put_contents($resultPath, json_encode([
            'outcome' => 'success', 'connection_id' => $connectionId,
            'record_id' => $value instanceof CourseEnrollment ? (int) $value->getKey() : (int) $value,
            'status' => $value instanceof CourseEnrollment ? (string) $value->status : null,
        ], JSON_THROW_ON_ERROR), LOCK_EX);
        exit(0);
    } catch (ValidationException|AuthorizationException $exception) {
        file_put_contents($resultPath, json_encode(['outcome' => 'conflict', 'connection_id' => $connectionId], JSON_THROW_ON_ERROR), LOCK_EX);
        exit(0);
    } catch (Throwable $exception) {
        $code = $exception instanceof QueryException ? (string) ($exception->errorInfo[0] ?? 'sql') : class_basename($exception);
        $location = null;
        foreach ($exception->getTrace() as $frame) {
            $file = (string) ($frame['file'] ?? '');
            if (str_starts_with($file, $basePath.DIRECTORY_SEPARATOR.'app'.DIRECTORY_SEPARATOR)) {
                $location = basename($file).':'.(int) ($frame['line'] ?? 0);
                break;
            }
        }
        file_put_contents($resultPath, json_encode(['outcome' => 'failed', 'connection_id' => $connectionId, 'error_code' => preg_match('/^[A-Za-z0-9_-]{1,32}$/', $code) ? $code : 'unknown', 'error_location' => $location], JSON_THROW_ON_ERROR), LOCK_EX);
        fwrite(STDERR, 'A Phase 08 concurrency worker failed ('.class_basename($exception).").\n");
        exit(1);
    } finally {
        $context->clear();
    }
}

if ($mode !== 'orchestrate') {
    fwrite(STDERR, "Unknown Phase 08 concurrency probe mode.\n");
    exit(2);
}

/** @var Connection $connection */
$connection = DB::connection();
$runId = strtolower((string) Str::uuid());
$now = now()->format('Y-m-d H:i:s');
$created = [];
$users = [];
$processes = [];
$paths = [];
$summary = [];
$failure = null;

/** @param array<string,mixed> $fixture @return list<array<string,mixed>> */
$race = static function (string $operation, array $fixture, string $runId) use ($basePath, $framework, &$processes, &$paths): array {
    $barrierId = (string) Str::uuid();
    $goName = 'phase08-enrollment-'.$barrierId.'-'.$operation.'.go';
    $goPath = $framework.DIRECTORY_SEPARATOR.$goName;
    $paths[] = $goPath;
    $entries = [];
    foreach (['a', 'b'] as $workerIndex => $label) {
        $readyName = 'phase08-enrollment-'.$barrierId.'-'.$operation.'-'.$label.'.ready';
        $resultName = 'phase08-enrollment-'.$barrierId.'-'.$operation.'-'.$label.'.result';
        $readyPath = $framework.DIRECTORY_SEPARATOR.$readyName;
        $resultPath = $framework.DIRECTORY_SEPARATOR.$resultName;
        $paths[] = $readyPath;
        $paths[] = $resultPath;
        if (file_exists($readyPath) || file_exists($resultPath) || file_exists($goPath) || is_link($readyPath) || is_link($resultPath) || is_link($goPath)) {
            throw new RuntimeException('A Phase 08 concurrency coordination path already exists.');
        }
        $workers = $fixture['workers'] ?? [$fixture['user_id'], $fixture['user_id']];
        $command = [PHP_BINARY, __FILE__, 'worker', $operation, (string) $fixture['tenant_id'], (string) $workers[$label === 'a' ? 0 : 1],
            (string) $fixture['section_id'], (string) ($fixture['enrollment_id'] ?? 0), (string) ($fixture['destination_id'] ?? 0),
            (string) ($fixture['idempotency_keys'][$workerIndex] ?? $fixture['idempotency_key'] ?? ''), (string) ($fixture['decision'] ?? ''), 'Deterministic independent-session probe.',
            $barrierId, $label, $readyName, $goName, $resultName];
        $proc = proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $basePath, Phase08ProbeEnvironment::current(), ['bypass_shell' => true]);
        if (! is_resource($proc)) {
            throw new RuntimeException('Could not start an isolated Phase 08 MySQL worker.');
        }
        fclose($pipes[0]);
        $entry = ['process' => $proc, 'stdout' => $pipes[1], 'stderr' => $pipes[2], 'ready' => $readyPath, 'result' => $resultPath];
        $processes[] = $entry;
        $entries[] = $entry;
    }
    $deadline = microtime(true) + 20;
    while ((! is_file($entries[0]['ready']) || ! is_file($entries[1]['ready'])) && microtime(true) < $deadline) {
        usleep(20000);
    }
    if (! is_file($entries[0]['ready']) || ! is_file($entries[1]['ready'])) {
        throw new RuntimeException('Both independent Phase 08 MySQL sessions did not reach the barrier.');
    }
    if (file_put_contents($goPath, 'go', LOCK_EX) !== 2) {
        throw new RuntimeException('Could not release the Phase 08 concurrency barrier.');
    }
    $results = [];
    foreach ($entries as $entry) {
        $deadline = microtime(true) + 30;
        $exitCode = null;
        do {
            $status = proc_get_status($entry['process']);
            if (! $status['running']) {
                $exitCode = (int) $status['exitcode'];
                break;
            }
            usleep(20000);
        } while (microtime(true) < $deadline);
        if ($exitCode === null) {
            proc_terminate($entry['process']);
            throw new RuntimeException('A Phase 08 worker exceeded its bounded execution time.');
        }
        stream_get_contents($entry['stdout']);
        $stderr = stream_get_contents($entry['stderr']);
        fclose($entry['stdout']);
        fclose($entry['stderr']);
        $closeCode = proc_close($entry['process']);
        if ($exitCode < 0) {
            $exitCode = $closeCode;
        }
        $result = is_file($entry['result']) && ! is_link($entry['result']) ? json_decode((string) file_get_contents($entry['result']), true) : null;
        if ($exitCode !== 0 || ! is_array($result) || ! in_array($result['outcome'] ?? null, ['success', 'conflict'], true)) {
            throw new RuntimeException('Phase 08 '.$operation.' concurrency worker failed: '.(is_array($result) ? (string) ($result['error_code'] ?? 'unexpected outcome').' at '.(string) ($result['error_location'] ?? 'unknown location') : trim((string) $stderr)));
        }
        $results[] = $result;
    }
    if (($results[0]['connection_id'] ?? null) === ($results[1]['connection_id'] ?? null)) {
        throw new RuntimeException('The Phase 08 race did not use two independent MySQL sessions.');
    }

    return $results;
};

try {
    Phase08ProbeAuthorization::assertAuthorized($connection->getPdo(), $environment);
    $connection->transaction(function () use ($connection, $runId, $now, &$users, &$created): void {
        foreach (['registrar', 'learner-a', 'learner-b', 'learner-c', 'learner-d'] as $name) {
            $users[$name] = (int) $connection->table('users')->insertGetId([
                'name' => 'Phase 08 concurrency '.$name,
                'email' => 'phase08-race-'.$runId.'-'.$name.'@example.test',
                'email_verified_at' => $now,
                'password' => password_hash(Str::random(48), PASSWORD_DEFAULT),
                'created_at' => $now, 'updated_at' => $now,
            ]);
        }
        $tenantId = (int) $connection->table('tenants')->insertGetId([
            'public_id' => (string) Str::uuid(), 'name' => 'Phase 08 race '.$runId,
            'slug' => 'phase08-race-'.$runId, 'status' => 'active', 'created_by' => $users['registrar'],
            'created_at' => $now, 'updated_at' => $now,
        ]);
        $institutionId = (int) $connection->table('institutions')->insertGetId([
            'tenant_id' => $tenantId, 'official_name' => 'Phase 08 race '.$runId, 'display_name' => 'Phase 08 race',
            'type' => 'academy', 'timezone' => 'UTC', 'locale' => 'en', 'status' => 'active', 'created_at' => $now, 'updated_at' => $now,
        ]);
        $roleIds = [];
        foreach (['student' => ['enrollment.student.register'], 'registrar' => ['enrollment.registrar.manage']] as $roleKey => $permissionKeys) {
            $roleIds[$roleKey] = (int) $connection->table('roles')->insertGetId([
                'tenant_id' => $tenantId, 'key' => 'phase08.race.'.$roleKey, 'name' => 'Phase 08 race '.$roleKey,
                'scope' => 'tenant', 'description' => 'Temporary concurrency probe role.', 'is_system' => false,
                'created_at' => $now, 'updated_at' => $now,
            ]);
            $permissionIds = $connection->table('permissions')->whereIn('key', $permissionKeys)->pluck('id')->all();
            if (count($permissionIds) !== count($permissionKeys)) {
                throw new RuntimeException('The authorized Phase 08 permission catalog is incomplete.');
            }
            foreach ($permissionIds as $permissionId) {
                $connection->table('permission_role')->insert(['role_id' => $roleIds[$roleKey], 'permission_id' => $permissionId, 'created_at' => $now, 'updated_at' => $now]);
            }
        }
        $created['tenant_id'] = $tenantId;
        $created['institution_id'] = $institutionId;
        $created['role_ids'] = $roleIds;
        $created['user_ids'] = array_values($users);
        foreach ($users as $name => $userId) {
            $membershipId = (int) $connection->table('tenant_memberships')->insertGetId([
                'tenant_id' => $tenantId, 'institution_id' => $institutionId, 'user_id' => $userId,
                'status' => 'active', 'activated_at' => $now, 'created_at' => $now, 'updated_at' => $now,
            ]);
            $connection->table('membership_role')->insert([
                'tenant_id' => $tenantId, 'membership_id' => $membershipId,
                'role_id' => $name === 'registrar' ? $roleIds['registrar'] : $roleIds['student'],
                'assigned_by' => $users['registrar'], 'created_at' => $now, 'updated_at' => $now,
            ]);
        }
        $yearId = (int) $connection->table('academic_years')->insertGetId([
            'tenant_id' => $tenantId, 'institution_id' => $institutionId, 'name' => 'Race year', 'code' => 'RACE-'.$runId,
            'starts_on' => today()->subMonth()->toDateString(), 'ends_on' => today()->addMonths(8)->toDateString(), 'timezone' => 'UTC', 'locale' => 'en', 'status' => 'active',
            'created_at' => $now, 'updated_at' => $now,
        ]);
        $periodId = (int) $connection->table('academic_periods')->insertGetId([
            'tenant_id' => $tenantId, 'academic_year_id' => $yearId, 'name' => 'Race term', 'code' => 'TERM-'.$runId,
            'period_type' => 'term', 'sequence' => 1, 'starts_on' => today()->subMonth()->toDateString(), 'ends_on' => today()->addMonths(5)->toDateString(), 'status' => 'active',
            'created_at' => $now, 'updated_at' => $now,
        ]);
        $policyId = (int) $connection->table('enrollment_policies')->insertGetId([
            'tenant_id' => $tenantId, 'institution_id' => $institutionId, 'name' => 'Race policy', 'maximum_courses' => 8,
            'requires_approval' => false, 'waitlist_enabled' => true, 'waitlist_offer_hours' => 48, 'status' => 'active',
            'created_by' => $users['registrar'], 'created_at' => $now, 'updated_at' => $now,
        ]);
        $approvalPolicyId = (int) $connection->table('enrollment_policies')->insertGetId([
            'tenant_id' => $tenantId, 'institution_id' => $institutionId, 'name' => 'Approval race policy', 'maximum_courses' => 8,
            'requires_approval' => true, 'waitlist_enabled' => true, 'waitlist_offer_hours' => 48, 'status' => 'active',
            'created_by' => $users['registrar'], 'created_at' => $now, 'updated_at' => $now,
        ]);
        $offerings = [];
        foreach (['capacity', 'duplicate', 'promotion', 'transfer', 'transition'] as $name) {
            $courseId = (int) $connection->table('academic_courses')->insertGetId([
                'tenant_id' => $tenantId, 'institution_id' => $institutionId, 'code' => 'R'.strtoupper(substr(hash('sha256', $name), 0, 8)).'-'.strtoupper(substr(str_replace('-', '', $runId), 0, 16)),
                'title' => 'Concurrent '.$name, 'status' => 'active', 'created_at' => $now, 'updated_at' => $now,
            ]);
            $offeringId = (int) $connection->table('course_offerings')->insertGetId([
                'tenant_id' => $tenantId, 'institution_id' => $institutionId, 'academic_year_id' => $yearId, 'academic_period_id' => $periodId,
                'academic_course_id' => $courseId, 'policy_id' => $name === 'transition' ? $approvalPolicyId : $policyId,
                'offering_code' => 'R'.strtoupper(substr(hash('sha256', $name), 0, 8)).'-'.strtoupper(substr(str_replace('-', '', $runId), 0, 16)), 'status' => 'open', 'created_by' => $users['registrar'], 'created_at' => $now, 'updated_at' => $now,
            ]);
            $offerings[$name] = ['course_id' => $courseId, 'offering_id' => $offeringId];
        }
        $sections = [];
        foreach (['capacity', 'duplicate', 'promotion', 'transfer-source', 'transfer-destination', 'transition'] as $name) {
            $offeringName = str_starts_with($name, 'transfer-') ? 'transfer' : $name;
            $sections[$name] = (int) $connection->table('course_sections')->insertGetId([
                'tenant_id' => $tenantId, 'offering_id' => $offerings[$offeringName]['offering_id'], 'section_code' => 'S'.strtoupper(substr(hash('sha256', $name), 0, 8)).'-'.strtoupper(substr(str_replace('-', '', $runId), 0, 16)),
                'capacity' => $name === 'transfer-destination' ? 2 : 1, 'reserved_seats' => 0, 'status' => 'open', 'created_at' => $now, 'updated_at' => $now,
            ]);
        }
        foreach (['learner-a', 'learner-b', 'learner-c', 'learner-d'] as $index => $studentName) {
            $connection->table('sis_students')->insert([
                'tenant_id' => $tenantId, 'institution_id' => $institutionId, 'user_id' => $users[$studentName],
                'student_number' => 'RACE-'.strtoupper(substr(str_replace('-', '', $runId), 0, 16)).'-'.$index, 'registration_reference' => 'RACE-REF-'.$runId.'-'.$index,
                'legal_first_name' => 'Race', 'legal_last_name' => $studentName, 'admission_date' => today()->subYear()->toDateString(),
                'status' => 'active', 'student_type' => 'standard', 'created_at' => $now, 'updated_at' => $now,
            ]);
        }
        $created['sections'] = $sections;
        $created['offerings'] = $offerings;
        $created['period_id'] = $periodId;
    });

    $tenantId = (int) $created['tenant_id'];
    $sections = $created['sections'];
    $enrollmentIds = [];

    $capacity = $race('register', ['tenant_id' => $tenantId, 'section_id' => $sections['capacity'], 'workers' => [$users['learner-a'], $users['learner-b']], 'idempotency_keys' => ['capacity-a-'.$runId, 'capacity-b-'.$runId]], $runId);
    $capacityCounts = $connection->table('course_enrollments')->where('tenant_id', $tenantId)->where('section_id', $sections['capacity'])->selectRaw("SUM(status IN ('confirmed','offer_pending')) AS occupied, SUM(status='confirmed') AS confirmed, SUM(status='waitlisted') AS waitlisted")->first();
    if ((int) $capacityCounts->occupied !== 1 || (int) $capacityCounts->confirmed !== 1 || (int) $capacityCounts->waitlisted !== 1) {
        throw new RuntimeException('Concurrent registration exceeded capacity or lost its waitlist transition.');
    }

    $duplicateKey = 'duplicate-'.$runId;
    $duplicate = $race('register', ['tenant_id' => $tenantId, 'section_id' => $sections['duplicate'], 'workers' => [$users['learner-c'], $users['learner-c']], 'idempotency_key' => $duplicateKey], $runId);
    $duplicateIds = array_unique(array_column($duplicate, 'record_id'));
    if (count($duplicateIds) !== 1 || (int) $connection->table('course_enrollments')->where('tenant_id', $tenantId)->where('idempotency_key', $duplicateKey)->count() !== 1) {
        throw new RuntimeException('Concurrent duplicate registration did not remain idempotent.');
    }

    $context = app(TenantContext::class);
    $tenant = Tenant::withoutGlobalScope('tenant')->findOrFail($tenantId);
    try {
        $workflow = app(CourseEnrollmentWorkflow::class);
        $context->set($tenant, TenantMembership::withoutGlobalScope('tenant')->where('tenant_id', $tenantId)->where('user_id', $users['learner-c'])->firstOrFail());
        $confirmed = $workflow->register($sections['promotion'], 'promotion-owner-'.$runId, User::query()->findOrFail($users['learner-c']));
        $context->set($tenant, TenantMembership::withoutGlobalScope('tenant')->where('tenant_id', $tenantId)->where('user_id', $users['learner-d'])->firstOrFail());
        $waitlisted = $workflow->register($sections['promotion'], 'promotion-waiter-'.$runId, User::query()->findOrFail($users['learner-d']));
        if ($confirmed->status !== 'confirmed' || $waitlisted->status !== 'waitlisted') {
            throw new RuntimeException('Waitlist-promotion race fixture did not reach its expected initial state.');
        }
        $enrollmentIds['promotion'] = (int) $confirmed->getKey();
        $context->set($tenant, TenantMembership::withoutGlobalScope('tenant')->where('tenant_id', $tenantId)->where('user_id', $users['learner-a'])->firstOrFail());
        $enrollmentIds['transfer'] = (int) $workflow->register($sections['transfer-source'], 'transfer-owner-'.$runId, User::query()->findOrFail($users['learner-a']))->getKey();
        $context->set($tenant, TenantMembership::withoutGlobalScope('tenant')->where('tenant_id', $tenantId)->where('user_id', $users['learner-b'])->firstOrFail());
        $enrollmentIds['transition'] = (int) $workflow->register($sections['transition'], 'transition-owner-'.$runId, User::query()->findOrFail($users['learner-b']))->getKey();
    } finally {
        $context->clear();
    }
    $promotion = $race('drop', ['tenant_id' => $tenantId, 'section_id' => $sections['promotion'], 'workers' => [$users['learner-c'], $users['learner-c']], 'enrollment_id' => $enrollmentIds['promotion']], $runId);
    $offered = (int) $connection->table('course_waitlist_entries')->where('tenant_id', $tenantId)->where('section_id', $sections['promotion'])->where('status', 'offered')->count();
    $offerEvents = (int) $connection->table('course_enrollment_events')->where('tenant_id', $tenantId)->where('event_type', 'waitlist_offer_issued')->count();
    $outboxOffers = (int) $connection->table('admission_outbox_events')->where('tenant_id', $tenantId)->where('event_type', 'enrollment.waitlist.offer_created')->count();
    if ($offered !== 1 || $offerEvents !== 1 || $outboxOffers !== 1) {
        throw new RuntimeException('Concurrent waitlist promotion produced duplicate or missing transition/audit/outbox records.');
    }

    $destinationId = (int) $sections['transfer-destination'];
    $transfers = $race('transfer', ['tenant_id' => $tenantId, 'section_id' => $sections['transfer-source'], 'workers' => [$users['learner-a'], $users['learner-a']], 'enrollment_id' => $enrollmentIds['transfer'], 'destination_id' => $destinationId], $runId);
    $pendingTransfers = (int) $connection->table('course_section_transfers')->where('tenant_id', $tenantId)->where('enrollment_id', $enrollmentIds['transfer'])->where('status', 'pending')->count();
    if ($pendingTransfers !== 1 || count(array_filter($transfers, static fn (array $result): bool => $result['outcome'] === 'success')) !== 1) {
        throw new RuntimeException('Concurrent section transfers were not serialized to one pending request.');
    }

    $transition = $race('decide', ['tenant_id' => $tenantId, 'section_id' => $sections['transition'], 'workers' => [$users['registrar'], $users['registrar']], 'enrollment_id' => $enrollmentIds['transition'], 'decision' => 'approve'], $runId);
    $finalEnrollment = $connection->table('course_enrollments')->where('tenant_id', $tenantId)->where('id', $enrollmentIds['transition'])->first();
    $approvalEvents = (int) $connection->table('course_enrollment_events')->where('tenant_id', $tenantId)->where('enrollment_id', $enrollmentIds['transition'])->where('event_type', 'registrar_decision')->count();
    if ($finalEnrollment === null || $finalEnrollment->status !== 'confirmed' || $approvalEvents !== 1 || count(array_filter($transition, static fn (array $result): bool => $result['outcome'] === 'success')) !== 1) {
        throw new RuntimeException('Concurrent enrollment decisions did not preserve one confirmed transition and event.');
    }
    $auditEvents = (int) $connection->table('audit_events')->where('tenant_id', $tenantId)->where('event_type', 'enrollment.registration.approved')->count();
    if ($auditEvents !== 1) {
        throw new RuntimeException('Concurrent enrollment decisions did not preserve exactly one audit event.');
    }
    $summary = [
        'sessions' => 10,
        'capacity' => ['confirmed' => (int) $capacityCounts->confirmed, 'waitlisted' => (int) $capacityCounts->waitlisted, 'occupied' => (int) $capacityCounts->occupied],
        'duplicate_registration' => ['enrollment_ids' => count($duplicateIds), 'rows' => 1],
        'waitlist_promotion' => ['offered_entries' => $offered, 'offer_events' => $offerEvents, 'outbox_events' => $outboxOffers],
        'section_transfer' => ['pending_requests' => $pendingTransfers, 'successful_workers' => count(array_filter($transfers, static fn (array $result): bool => $result['outcome'] === 'success'))],
        'enrollment_transition' => ['status' => (string) $finalEnrollment->status, 'transition_events' => $approvalEvents, 'audit_events' => $auditEvents],
        'connection_ids_distinct' => true,
    ];
} catch (Throwable $exception) {
    $failure = $exception;
} finally {
    foreach ($processes as $entry) {
        if (is_resource($entry['process'])) {
            $status = proc_get_status($entry['process']);
            if ($status['running']) {
                proc_terminate($entry['process']);
                proc_close($entry['process']);
            }
        }
    }
    foreach (array_unique($paths) as $path) {
        if (dirname($path) === $framework && is_file($path) && ! is_link($path)) {
            unlink($path);
        }
    }
    if (isset($created['tenant_id'])) {
        try {
            $connection->transaction(function () use ($connection, $created, $users): void {
                $tenantId = (int) $created['tenant_id'];
                foreach (['admission_outbox_events', 'audit_events', 'course_enrollment_events', 'course_section_transfers', 'course_waitlist_entries', 'course_enrollments', 'sis_students'] as $table) {
                    $connection->table($table)->where('tenant_id', $tenantId)->delete();
                }
                foreach (['course_sections', 'course_offerings', 'enrollment_policies', 'academic_courses', 'academic_periods', 'academic_years'] as $table) {
                    $connection->table($table)->where('tenant_id', $tenantId)->delete();
                }
                $connection->table('membership_role')->where('tenant_id', $tenantId)->delete();
                $connection->table('tenant_memberships')->where('tenant_id', $tenantId)->delete();
                $connection->table('permission_role')->whereIn('role_id', array_values($created['role_ids']))->delete();
                $connection->table('roles')->where('tenant_id', $tenantId)->delete();
                $connection->table('institutions')->where('tenant_id', $tenantId)->delete();
                $connection->table('tenants')->where('id', $tenantId)->delete();
                $connection->table('users')->whereIn('id', array_values($users))->delete();
            });
        } catch (Throwable) {
            $failure ??= new RuntimeException('Phase 08 concurrency fixture cleanup did not complete; inspect only the run-scoped tenant slug before cleanup.');
        }
    }
}

if ($failure !== null) {
    fwrite(STDERR, 'Phase 08 concurrency verification failed: '.class_basename($failure).': '.$failure->getMessage()."\n");
    exit(1);
}

fwrite(STDOUT, 'Phase 08 independent-session concurrency probes passed: '.json_encode($summary, JSON_THROW_ON_ERROR)."\n");
