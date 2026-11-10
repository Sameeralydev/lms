<?php

use App\Domain\Academic\Curriculum\CurriculumWorkflow;
use App\Domain\Tenancy\TenantContext;
use App\Models\AcademicCurriculumNode;
use App\Models\AcademicCurriculumVersion;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Connection;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Tests\Support\Phase06MySqlTestTarget;
use Tests\Support\Phase06ProbeAuthorization;
use Tests\Support\Phase06ProbeEnvironment;
use Tests\Support\TestDatabaseGuard;

$basePath = dirname(__DIR__, 2);
require $basePath.'/vendor/autoload.php';
$app = require $basePath.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$environment = TestDatabaseGuard::currentEnvironment();
try {
    TestDatabaseGuard::assertSafe($environment);
    $connection = DB::connection();
    if ($connection->getDriverName() !== 'mysql'
        || $connection->getDatabaseName() !== Phase06MySqlTestTarget::DATABASE) {
        throw new RuntimeException('The curriculum concurrency probe requires the exact Phase 06 MySQL target.');
    }
    Phase06ProbeAuthorization::assertAuthorized($connection->getPdo(), $environment);
} catch (Throwable) {
    fwrite(STDERR, "The Phase 06 curriculum concurrency probe refused its database identity.\n");
    exit(2);
}

$frameworkRoot = realpath(storage_path('framework'));
if ($frameworkRoot === false) {
    fwrite(STDERR, "The Phase 06 curriculum concurrency probe has no private coordination directory.\n");
    exit(2);
}

$mode = $argv[1] ?? 'orchestrate';

if ($mode === 'worker') {
    $operation = $argv[2] ?? '';
    $tenantId = (int) ($argv[3] ?? 0);
    $versionId = (int) ($argv[4] ?? 0);
    $nodeId = (int) ($argv[5] ?? 0);
    $actorId = (int) ($argv[6] ?? 0);
    $expectedVersion = (int) ($argv[7] ?? 0);
    $expectedNode = (int) ($argv[8] ?? 0);
    $title = $argv[9] ?? '';
    $runId = $argv[10] ?? '';
    $label = $argv[11] ?? '';
    $names = [$argv[12] ?? '', $argv[13] ?? '', $argv[14] ?? ''];

    if (! in_array($operation, ['edit', 'approve', 'publish'], true)
        || ! preg_match('/^[a-f0-9-]{36}$/', $runId)
        || ! in_array($label, ['a', 'b'], true)
        || $tenantId < 1 || $versionId < 1 || $actorId < 1
        || ($operation === 'edit' && ($nodeId < 1 || $expectedVersion < 1 || $expectedNode < 1 || $title === ''))
        || ($operation !== 'edit' && $nodeId !== 0)) {
        fwrite(STDERR, "The Phase 06 curriculum worker rejected invalid operation metadata.\n");
        exit(2);
    }

    $prefix = 'phase06-curriculum-'.$runId.'-'.$operation.'-'.$label;
    [$readyName, $goName, $resultName] = $names;
    if ($readyName !== $prefix.'.ready' || $goName !== 'phase06-curriculum-'.$runId.'-'.$operation.'.go'
        || $resultName !== $prefix.'.result') {
        fwrite(STDERR, "The Phase 06 curriculum worker rejected an unsafe coordination name.\n");
        exit(2);
    }
    $paths = array_map(static fn (string $name): string => $frameworkRoot.DIRECTORY_SEPARATOR.$name, $names);
    foreach ($paths as $path) {
        if (dirname($path) !== $frameworkRoot || is_link($path)) {
            fwrite(STDERR, "The Phase 06 curriculum worker rejected an unsafe coordination path.\n");
            exit(2);
        }
    }
    [$readyPath, $goPath, $resultPath] = $paths;
    if (file_exists($readyPath) || file_exists($resultPath)
        || file_put_contents($readyPath, 'ready', LOCK_EX) !== 5) {
        fwrite(STDERR, "The Phase 06 curriculum worker could not establish its private barrier.\n");
        exit(2);
    }

    $deadline = microtime(true) + 20;
    while (! is_file($goPath) && microtime(true) < $deadline) {
        usleep(20000);
    }
    if (! is_file($goPath) || is_link($goPath)) {
        fwrite(STDERR, "The Phase 06 curriculum concurrency barrier timed out.\n");
        exit(2);
    }

    $workerStage = 'load_tenant_context';
    $context = app(TenantContext::class);
    try {
        $workerStage = 'load_fixture_identity';
        $tenant = Tenant::query()->findOrFail($tenantId);
        $actor = User::query()->findOrFail($actorId);
        $context->enterProvisioning($tenant);
        $workflow = app(CurriculumWorkflow::class);
        $version = AcademicCurriculumVersion::query()->withoutGlobalScope('tenant')->where('tenant_id', $tenantId)->findOrFail($versionId);

        $workerStage = $operation;
        match ($operation) {
            'edit' => $workflow->updateNode(
                AcademicCurriculumNode::query()->withoutGlobalScope('tenant')->where('tenant_id', $tenantId)->findOrFail($nodeId),
                ['version_lock' => $expectedVersion, 'node_lock' => $expectedNode, 'title' => $title],
                $actor,
            ),
            'approve' => $workflow->decide($version, 'approve', 'Concurrent Phase 06 approval verification.', $actor),
            'publish' => $workflow->publish($version, $actor),
        };
        $outcome = 'success';
    } catch (ConflictHttpException) {
        $outcome = 'conflict';
    } catch (Throwable $exception) {
        $errorCode = $exception instanceof QueryException
            ? ($exception->errorInfo[0] ?? $exception->getCode())
            : $exception->getCode();
        $safeCode = preg_match('/^[A-Za-z0-9_-]{1,16}$/', (string) $errorCode) === 1 ? (string) $errorCode : 'unknown';
        file_put_contents($resultPath, json_encode([
            'outcome' => 'failed',
            'stage' => $workerStage,
            'error_type' => class_basename($exception),
            'error_code' => $safeCode,
        ], JSON_THROW_ON_ERROR), LOCK_EX);
        fwrite(STDERR, "A Phase 06 curriculum worker failed safely during {$workerStage} (".class_basename($exception).", code {$safeCode}).\n");
        exit(1);
    } finally {
        $context->clear();
    }

    file_put_contents($resultPath, json_encode(['outcome' => $outcome], JSON_THROW_ON_ERROR), LOCK_EX);
    exit(0);
}

if ($mode !== 'orchestrate') {
    fwrite(STDERR, "Unknown Phase 06 curriculum concurrency mode.\n");
    exit(2);
}

/** @var Connection $connection */
$connection = DB::connection();
$runId = strtolower((string) Str::uuid());
$timestamp = now()->format('Y-m-d H:i:s');
$userIds = [];
$created = [];
$processes = [];
$coordinationPaths = [];
$summary = null;
$failure = null;
$failureStage = null;
$failureType = null;
$failureCode = null;

/** @param array{0:int,1:int,2:int,3:int,4:int,5:int} $fixture @param list<array{actor:int,title:string}> $workers @return list<string> */
$race = static function (string $operation, array $fixture, array $workers = []) use ($basePath, $frameworkRoot, $runId, &$processes, &$coordinationPaths): array {
    $goName = 'phase06-curriculum-'.$runId.'-'.$operation.'.go';
    $goPath = $frameworkRoot.DIRECTORY_SEPARATOR.$goName;
    $coordinationPaths[] = $goPath;
    $workerEntries = [];

    foreach (['a', 'b'] as $label) {
        $readyName = 'phase06-curriculum-'.$runId.'-'.$operation.'-'.$label.'.ready';
        $resultName = 'phase06-curriculum-'.$runId.'-'.$operation.'-'.$label.'.result';
        $readyPath = $frameworkRoot.DIRECTORY_SEPARATOR.$readyName;
        $resultPath = $frameworkRoot.DIRECTORY_SEPARATOR.$resultName;
        $coordinationPaths[] = $readyPath;
        $coordinationPaths[] = $resultPath;
        if (file_exists($readyPath) || file_exists($resultPath) || file_exists($goPath)
            || is_link($readyPath) || is_link($resultPath) || is_link($goPath)) {
            throw new RuntimeException('A Phase 06 curriculum coordination path already exists.');
        }
        $worker = $workers[$label] ?? ['actor' => $fixture[3], 'title' => 'Concurrent edit '.$label];
        $nodeId = $operation === 'edit' ? $fixture[2] : 0;
        $versionLock = $operation === 'edit' ? $fixture[4] : 0;
        $nodeLock = $operation === 'edit' ? $fixture[5] : 0;
        $command = [
            PHP_BINARY, __FILE__, 'worker', $operation, (string) $fixture[0], (string) $fixture[1], (string) $nodeId,
            (string) $worker['actor'], (string) $versionLock, (string) $nodeLock, $worker['title'], $runId, $label,
            $readyName, $goName, $resultName,
        ];
        $proc = proc_open(
            $command,
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $basePath,
            Phase06ProbeEnvironment::current(),
            ['bypass_shell' => true],
        );
        if (! is_resource($proc)) {
            throw new RuntimeException('A guarded Phase 06 curriculum worker could not start.');
        }
        fclose($pipes[0]);
        $entry = ['process' => $proc, 'stdout' => $pipes[1], 'stderr' => $pipes[2], 'result' => $resultPath, 'ready' => $readyPath];
        $processes[] = $entry;
        $workerEntries[] = $entry;
    }

    $deadline = microtime(true) + 20;
    while ((! is_file($workerEntries[0]['ready']) || ! is_file($workerEntries[1]['ready'])) && microtime(true) < $deadline) {
        usleep(20000);
    }
    if (! is_file($workerEntries[0]['ready']) || ! is_file($workerEntries[1]['ready'])) {
        throw new RuntimeException('Both Phase 06 curriculum workers did not reach their concurrency barrier.');
    }
    if (file_put_contents($goPath, 'go', LOCK_EX) !== 2) {
        throw new RuntimeException('The Phase 06 curriculum concurrency barrier could not be released.');
    }

    $outcomes = [];
    foreach ($workerEntries as $entry) {
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
            throw new RuntimeException('A Phase 06 curriculum worker exceeded its time limit.');
        }
        $stdout = stream_get_contents($entry['stdout']);
        $stderr = stream_get_contents($entry['stderr']);
        fclose($entry['stdout']);
        fclose($entry['stderr']);
        $closeCode = proc_close($entry['process']);
        if ($exitCode < 0) {
            $exitCode = $closeCode;
        }
        $result = is_file($entry['result']) && ! is_link($entry['result'])
            ? json_decode((string) file_get_contents($entry['result']), true)
            : null;
        if ($exitCode !== 0 || ! is_array($result) || ! in_array($result['outcome'] ?? null, ['success', 'conflict'], true)) {
            $stage = is_array($result) && is_string($result['stage'] ?? null) ? $result['stage'] : 'worker_start_or_coordination';
            $errorType = is_array($result) && is_string($result['error_type'] ?? null) ? $result['error_type'] : 'WorkerFailure';
            $errorCode = is_array($result) && is_string($result['error_code'] ?? null) ? $result['error_code'] : 'unknown';
            throw new RuntimeException("A Phase 06 curriculum worker failed at {$stage} ({$errorType}, code {$errorCode}).");
        }
        $outcomes[] = $result['outcome'];
    }

    if (count(array_filter($outcomes, static fn (string $outcome): bool => $outcome === 'success')) !== 1
        || count(array_filter($outcomes, static fn (string $outcome): bool => $outcome === 'conflict')) !== 1) {
        throw new RuntimeException('The Phase 06 curriculum race did not serialize to one success and one conflict.');
    }

    return $outcomes;
};

try {
    $failureStage = 'fixture_creation';
    $connection->transaction(function () use ($connection, $runId, $timestamp, &$userIds, &$created, $environment): void {
        if ($connection->getDriverName() !== 'mysql'
            || $connection->getDatabaseName() !== Phase06MySqlTestTarget::DATABASE) {
            throw new RuntimeException('The Phase 06 curriculum fixture refused an unapproved database.');
        }
        Phase06ProbeAuthorization::assertAuthorized($connection->getPdo(), $environment);

        foreach (['author', 'reviewer', 'publisher-a', 'publisher-b'] as $label) {
            $userIds[$label] = (int) $connection->table('users')->insertGetId([
                'name' => 'Phase 06 curriculum '.$label,
                'email' => 'phase06-curriculum-'.$runId.'-'.$label.'@example.test',
                'email_verified_at' => $timestamp,
                'password' => password_hash(Str::random(48), PASSWORD_DEFAULT),
                'created_at' => $timestamp,
                'updated_at' => $timestamp,
            ]);
        }
        $tenantId = (int) $connection->table('tenants')->insertGetId([
            'public_id' => (string) Str::uuid(), 'name' => 'Phase 06 curriculum concurrency fixture',
            'slug' => 'phase06-curriculum-'.$runId, 'status' => 'active', 'created_by' => $userIds['author'],
            'created_at' => $timestamp, 'updated_at' => $timestamp,
        ]);
        $created['tenant_id'] = $tenantId;
        $institutionId = (int) $connection->table('institutions')->insertGetId([
            'tenant_id' => $tenantId, 'official_name' => 'Phase 06 concurrency institution',
            'display_name' => 'Phase 06 concurrency fixture', 'type' => 'academy', 'timezone' => 'UTC',
            'locale' => 'en', 'status' => 'active', 'created_at' => $timestamp, 'updated_at' => $timestamp,
        ]);
        $roleId = (int) $connection->table('roles')->insertGetId([
            'tenant_id' => $tenantId, 'key' => 'academic.admin', 'name' => 'Phase 06 concurrency administrator',
            'scope' => 'tenant', 'description' => 'Temporary least-privilege race-test role.',
            'is_system' => false, 'created_at' => $timestamp, 'updated_at' => $timestamp,
        ]);
        $permissionIds = $connection->table('permissions')
            ->whereIn('key', ['academic.curriculum.manage', 'academic.curriculum.review', 'academic.curriculum.publish'])
            ->pluck('id')->map(static fn ($id): int => (int) $id)->all();
        if (count($permissionIds) !== 3) {
            throw new RuntimeException('The authorized Phase 06 curriculum permissions are incomplete.');
        }
        $connection->table('permission_role')->insert(array_map(static fn (int $permissionId): array => [
            'role_id' => $roleId, 'permission_id' => $permissionId, 'created_at' => $timestamp, 'updated_at' => $timestamp,
        ], $permissionIds));
        foreach ($userIds as $userId) {
            $membershipId = (int) $connection->table('tenant_memberships')->insertGetId([
                'tenant_id' => $tenantId, 'institution_id' => $institutionId, 'user_id' => $userId,
                'status' => 'active', 'activated_at' => $timestamp, 'created_at' => $timestamp, 'updated_at' => $timestamp,
            ]);
            $connection->table('membership_role')->insert([
                'membership_id' => $membershipId, 'role_id' => $roleId, 'assigned_by' => $userIds['author'],
                'tenant_id' => $tenantId, 'created_at' => $timestamp, 'updated_at' => $timestamp,
            ]);
        }
        $levelId = (int) $connection->table('academic_levels')->insertGetId([
            'tenant_id' => $tenantId, 'institution_id' => $institutionId, 'name' => 'Concurrency level',
            'code' => 'PH06-'.$runId, 'category' => 'custom', 'status' => 'active',
            'created_at' => $timestamp, 'updated_at' => $timestamp,
        ]);
        $programId = (int) $connection->table('academic_programs')->insertGetId([
            'tenant_id' => $tenantId, 'institution_id' => $institutionId, 'academic_level_id' => $levelId,
            'title' => 'Concurrency program', 'code' => 'PH06-'.$runId, 'credit_bearing' => false,
            'delivery_mode' => 'on_campus', 'status' => 'active', 'created_by' => $userIds['author'],
            'created_at' => $timestamp, 'updated_at' => $timestamp,
        ]);
        $curriculumId = (int) $connection->table('academic_curricula')->insertGetId([
            'tenant_id' => $tenantId, 'institution_id' => $institutionId, 'academic_program_id' => $programId,
            'code' => 'PH06-'.$runId, 'title' => 'Concurrency curriculum', 'status' => 'draft',
            'sharing_status' => 'private', 'current_version_number' => 1, 'created_by' => $userIds['author'],
            'created_at' => $timestamp, 'updated_at' => $timestamp,
        ]);
        $versionId = (int) $connection->table('academic_curriculum_versions')->insertGetId([
            'tenant_id' => $tenantId, 'curriculum_id' => $curriculumId, 'version_number' => 1,
            'status' => 'draft', 'title' => 'Concurrency draft', 'lock_version' => 1,
            'created_at' => $timestamp, 'updated_at' => $timestamp,
        ]);
        $nodeId = (int) $connection->table('academic_curriculum_nodes')->insertGetId([
            'tenant_id' => $tenantId, 'institution_id' => $institutionId, 'curriculum_version_id' => $versionId,
            'node_type' => 'unit', 'code' => 'PH06-'.$runId, 'title' => 'Original unit', 'sort_order' => 10,
            'is_required' => true, 'workload_unit' => 'minutes', 'lock_version' => 1,
            'created_at' => $timestamp, 'updated_at' => $timestamp,
        ]);
        $created['institution_id'] = $institutionId;
        $created['level_id'] = $levelId;
        $created['program_id'] = $programId;
        $created['curriculum_id'] = $curriculumId;
        $created['version_id'] = $versionId;
        $created['node_id'] = $nodeId;
    });

    $tenantId = (int) $created['tenant_id'];
    $versionId = (int) $created['version_id'];
    $nodeId = (int) $created['node_id'];
    $fixture = [$tenantId, $versionId, $nodeId, $userIds['author'], 1, 1];
    $failureStage = 'concurrent_edit';
    $editOutcomes = $race('edit', $fixture, [
        'a' => ['actor' => $userIds['author'], 'title' => 'Concurrent curriculum edit A'],
        'b' => ['actor' => $userIds['author'], 'title' => 'Concurrent curriculum edit B'],
    ]);
    $edited = $connection->table('academic_curriculum_versions')->where('id', $versionId)->first();
    $editedNode = $connection->table('academic_curriculum_nodes')->where('id', $nodeId)->first();
    if ($edited === null || (int) $edited->lock_version !== 2 || $editedNode === null || (int) $editedNode->lock_version !== 2
        || ! in_array((string) $editedNode->title, ['Concurrent curriculum edit A', 'Concurrent curriculum edit B'], true)) {
        throw new RuntimeException('The Phase 06 edit race left an inconsistent version or node lock.');
    }

    $failureStage = 'approval_fixture';
    $connection->transaction(function () use ($connection, $created, $userIds, $timestamp): void {
        $tenantId = (int) $created['tenant_id'];
        $versionId = (int) $created['version_id'];
        $connection->table('academic_curriculum_versions')->where('tenant_id', $tenantId)->where('id', $versionId)->update([
            'status' => 'in_review', 'reviewer_user_id' => $userIds['reviewer'], 'submitted_by' => $userIds['author'],
            'submitted_at' => $timestamp, 'updated_at' => $timestamp,
        ]);
        $connection->table('academic_curriculum_reviews')->insert([
            'tenant_id' => $tenantId, 'curriculum_version_id' => $versionId, 'reviewer_user_id' => $userIds['reviewer'],
            'decision' => 'assigned', 'assigned_at' => $timestamp, 'created_at' => $timestamp, 'updated_at' => $timestamp,
        ]);
    });

    $approveFixture = [$tenantId, $versionId, 0, $userIds['reviewer'], 0, 0];
    $failureStage = 'concurrent_approval';
    $approvalOutcomes = $race('approve', $approveFixture);
    $approved = $connection->table('academic_curriculum_versions')->where('id', $versionId)->first();
    $approvedReviews = (int) $connection->table('academic_curriculum_reviews')->where('curriculum_version_id', $versionId)->where('decision', 'approve')->count();
    if ($approved === null || $approved->status !== 'approved' || $approvedReviews !== 1) {
        throw new RuntimeException('The Phase 06 approval race did not preserve one approved decision.');
    }

    $publishFixture = [$tenantId, $versionId, 0, $userIds['publisher-a'], 0, 0];
    $failureStage = 'concurrent_publication';
    $publishOutcomes = $race('publish', $publishFixture, [
        'a' => ['actor' => $userIds['publisher-a'], 'title' => 'unused'],
        'b' => ['actor' => $userIds['publisher-b'], 'title' => 'unused'],
    ]);
    $published = $connection->table('academic_curriculum_versions')->where('id', $versionId)->first();
    $publishedCurriculum = $connection->table('academic_curricula')->where('id', (int) $created['curriculum_id'])->first();
    $publishAudits = (int) $connection->table('audit_events')->where('tenant_id', $tenantId)->where('event_type', 'academic.curriculum.published')->count();
    if ($published === null || $published->status !== 'published'
        || ! in_array((int) $published->published_by, [$userIds['publisher-a'], $userIds['publisher-b']], true)
        || $publishedCurriculum === null || $publishedCurriculum->status !== 'published'
        || (int) $publishedCurriculum->current_version_number !== 1 || $publishAudits !== 1) {
        throw new RuntimeException('The Phase 06 publication race did not preserve one published snapshot and audit event.');
    }

    $summary = [
        'edit' => ['success' => count(array_filter($editOutcomes, static fn (string $outcome): bool => $outcome === 'success')), 'conflict' => count(array_filter($editOutcomes, static fn (string $outcome): bool => $outcome === 'conflict'))],
        'approval' => ['success' => count(array_filter($approvalOutcomes, static fn (string $outcome): bool => $outcome === 'success')), 'conflict' => count(array_filter($approvalOutcomes, static fn (string $outcome): bool => $outcome === 'conflict')), 'approved_review_rows' => $approvedReviews],
        'publish' => ['success' => count(array_filter($publishOutcomes, static fn (string $outcome): bool => $outcome === 'success')), 'conflict' => count(array_filter($publishOutcomes, static fn (string $outcome): bool => $outcome === 'conflict')), 'publish_audit_rows' => $publishAudits],
    ];
} catch (Throwable $exception) {
    $failure = true;
    $failureType = class_basename($exception);
    $errorCode = $exception instanceof QueryException
        ? ($exception->errorInfo[0] ?? $exception->getCode())
        : $exception->getCode();
    $failureCode = preg_match('/^[A-Za-z0-9_-]{1,16}$/', (string) $errorCode) === 1 ? (string) $errorCode : 'unknown';
    if (str_contains($exception->getMessage(), 'worker failed at ')) {
        preg_match('/worker failed at ([a-z_]+) \(([^,]+), code ([^)]+)\)/', $exception->getMessage(), $matches);
        $failureStage = $matches[1] ?? $failureStage;
        $failureType = $matches[2] ?? $failureType;
        $failureCode = $matches[3] ?? $failureCode;
    }
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
    if (isset($created['tenant_id'])) {
        try {
            $connection->transaction(function () use ($connection, $created, $userIds): void {
                $tenantId = (int) $created['tenant_id'];
                $connection->table('audit_events')->where('tenant_id', $tenantId)->delete();
                foreach ([
                    'academic_curriculum_reviews', 'academic_curriculum_node_outcomes', 'academic_curriculum_node_competencies',
                    'academic_curriculum_prerequisites', 'academic_curriculum_nodes', 'academic_curriculum_completion_rules',
                    'academic_curriculum_versions', 'academic_curricula', 'academic_programs', 'academic_levels',
                ] as $table) {
                    $connection->table($table)->where('tenant_id', $tenantId)->delete();
                }
                $connection->table('membership_role')->where('tenant_id', $tenantId)->delete();
                $connection->table('tenant_memberships')->where('tenant_id', $tenantId)->delete();
                $connection->table('roles')->where('tenant_id', $tenantId)->delete();
                $connection->table('institutions')->where('tenant_id', $tenantId)->delete();
                $connection->table('tenants')->where('id', $tenantId)->delete();
                $connection->table('users')->whereIn('id', array_map('intval', $userIds))->delete();
                foreach ([
                    'academic_curriculum_reviews', 'academic_curriculum_node_outcomes', 'academic_curriculum_node_competencies',
                    'academic_curriculum_prerequisites', 'academic_curriculum_nodes', 'academic_curriculum_completion_rules',
                    'academic_curriculum_versions', 'academic_curricula', 'academic_programs', 'academic_levels',
                    'institutions', 'audit_events',
                ] as $table) {
                    if ($connection->table($table)->where('tenant_id', $tenantId)->exists()) {
                        throw new RuntimeException('A Phase 06 curriculum fixture row remains after cleanup.');
                    }
                }
                if ($connection->table('tenant_memberships')->where('tenant_id', $tenantId)->exists()
                    || $connection->table('membership_role')->where('tenant_id', $tenantId)->exists()
                    || $connection->table('roles')->where('tenant_id', $tenantId)->exists()) {
                    throw new RuntimeException('A Phase 06 curriculum authorization fixture row remains after cleanup.');
                }
                if ($connection->table('tenants')->where('id', $tenantId)->exists()
                    || $connection->table('users')->whereIn('id', array_map('intval', $userIds))->exists()) {
                    throw new RuntimeException('A Phase 06 curriculum fixture identity remains after cleanup.');
                }
            });
        } catch (Throwable $exception) {
            $failureStage = $failure ? $failureStage : 'fixture_cleanup';
            $failure = true;
            $failureType ??= class_basename($exception);
            $errorCode = $exception instanceof QueryException
                ? ($exception->errorInfo[0] ?? $exception->getCode())
                : $exception->getCode();
            $failureCode ??= preg_match('/^[A-Za-z0-9_-]{1,16}$/', (string) $errorCode) === 1 ? (string) $errorCode : 'unknown';
        }
    }
    foreach ($coordinationPaths as $path) {
        if (is_file($path) && ! is_link($path)) {
            unlink($path);
        }
    }
}

if ($failure) {
    $safeStage = $failureStage;
    $safeType = $failureType ?? 'UnknownFailure';
    $safeCode = $failureCode ?? 'unknown';
    fwrite(STDERR, "The guarded Phase 06 curriculum concurrency verification failed safely at {$safeStage} ({$safeType}, code {$safeCode}); inspect the dedicated disposable schema read-only before any retry.\n");
    exit(1);
}

fwrite(STDOUT, json_encode($summary, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR).PHP_EOL);
exit(0);
