<?php

use Dotenv\Dotenv;
use Tests\Support\Phase04MySqlTestDataPolicy;
use Tests\Support\Phase04MySqlTestPreflight;
use Tests\Support\Phase04MySqlTestRunLock;
use Tests\Support\Phase04MySqlTestTarget;
use Tests\Support\Phase04OwnershipManifestPolicy;
use Tests\Support\Phase04V2OwnershipResumePolicy;
use Tests\Support\Phase06MySqlTestTarget;
use Tests\Support\Phase07MySqlTestTarget;
use Tests\Support\TestDatabaseGuard;

require dirname(__DIR__).'/vendor/autoload.php';

$environment = [
    'APP_ENV' => 'testing',
    'APP_DEBUG' => 'false',
    'DB_CONNECTION' => 'sqlite',
    'DB_DATABASE' => ':memory:',
    'DB_URL' => '',
];
foreach ([
    'ADMISSIONS_DOCUMENT_SCANNER',
    'ADMISSIONS_CLAMAV_BINARY',
    'ADMISSIONS_CLAMAV_SIGNATURE_DIRECTORY',
    'ADMISSIONS_SIGTOOL_BINARY',
    'ADMISSIONS_CLAMAV_TIMEOUT',
    'ADMISSIONS_CLAMAV_TIMEZONE',
] as $scannerSetting) {
    $value = getenv($scannerSetting);
    if ($value !== false) {
        $environment[$scannerSetting] = (string) $value;
    }
}

$filter = null;
$phase07Baseline = false;
$useSqlite = false;
$usePhase04Mysql = false;
$usePhase04V2Mysql = false;
$usePhase05Mysql = false;
$usePhase06Mysql = false;
$usePhase07Mysql = false;
$resumePhase07Mysql = false;
$upgradePhase07Mysql = false;
$resumePhase04V2Mysql = false;
$preflightOnly = false;
$preflightPhase05 = false;
$preflightPhase06 = false;
$preflightPhase07 = false;
$preflightPhase07Upgrade = false;
$inspectPhase07 = false;
$preflightPhase06Upgrade = false;
$upgradePhase06Mysql = false;
$inspectPhase06 = false;
$reconcilePhase06 = false;
$preflightPhase05Upgrade = false;
$resumePhase05Mysql = false;
$upgradePhase05Mysql = false;
$inspectPhase04V2 = false;
$mysqlRunLock = null;
$isPhase05Target = false;
$isPhase06Target = false;
$isPhase07Target = false;

for ($index = 1; $index < count($argv); $index++) {
    $argument = $argv[$index];

    if ($argument === '--sqlite' && ! $useSqlite) {
        $useSqlite = true;

        continue;
    }

    if ($argument === '--mysql-phase04' && ! $usePhase04Mysql) {
        $usePhase04Mysql = true;

        continue;
    }

    if ($argument === '--mysql-phase04-v2' && ! $usePhase04V2Mysql) {
        $usePhase04V2Mysql = true;

        continue;
    }

    if ($argument === '--mysql-phase05' && ! $usePhase05Mysql) {
        $usePhase05Mysql = true;

        continue;
    }

    if ($argument === '--mysql-phase06' && ! $usePhase06Mysql) {
        $usePhase06Mysql = true;

        continue;
    }

    if ($argument === '--mysql-phase07' && ! $usePhase07Mysql) {
        $usePhase07Mysql = true;

        continue;
    }

    if ($argument === '--resume-phase07-mysql' && ! $resumePhase07Mysql) {
        $resumePhase07Mysql = true;

        continue;
    }

    if ($argument === '--preflight-phase07-upgrade' && ! $preflightPhase07Upgrade) {
        $preflightPhase07Upgrade = true;

        continue;
    }

    if ($argument === '--mysql-phase07-upgrade' && ! $upgradePhase07Mysql) {
        $upgradePhase07Mysql = true;

        continue;
    }

    if ($argument === '--preflight-phase05' && ! $preflightPhase05) {
        $preflightPhase05 = true;

        continue;
    }

    if ($argument === '--preflight-phase06' && ! $preflightPhase06) {
        $preflightPhase06 = true;

        continue;
    }

    if ($argument === '--preflight-phase07' && ! $preflightPhase07) {
        $preflightPhase07 = true;

        continue;
    }

    if ($argument === '--inspect-phase07' && ! $inspectPhase07) {
        $inspectPhase07 = true;

        continue;
    }

    if ($argument === '--preflight-phase06-upgrade' && ! $preflightPhase06Upgrade) {
        $preflightPhase06Upgrade = true;

        continue;
    }

    if ($argument === '--mysql-phase06-upgrade' && ! $upgradePhase06Mysql) {
        $upgradePhase06Mysql = true;

        continue;
    }

    if ($argument === '--inspect-phase06' && ! $inspectPhase06) {
        $inspectPhase06 = true;

        continue;
    }

    if ($argument === '--reconcile-phase06' && ! $reconcilePhase06) {
        $reconcilePhase06 = true;

        continue;
    }

    if ($argument === '--preflight-phase05-upgrade' && ! $preflightPhase05Upgrade) {
        $preflightPhase05Upgrade = true;

        continue;
    }

    if ($argument === '--mysql-phase05-resume' && ! $resumePhase05Mysql) {
        $resumePhase05Mysql = true;

        continue;
    }

    if ($argument === '--mysql-phase05-upgrade' && ! $upgradePhase05Mysql) {
        $upgradePhase05Mysql = true;

        continue;
    }

    if ($argument === '--mysql-phase04-v2-resume' && ! $resumePhase04V2Mysql) {
        $resumePhase04V2Mysql = true;

        continue;
    }

    if ($argument === '--preflight-phase04-v2' && ! $preflightOnly) {
        $preflightOnly = true;

        continue;
    }

    if ($argument === '--inspect-phase04-v2' && ! $inspectPhase04V2) {
        $inspectPhase04V2 = true;

        continue;
    }

    if (str_starts_with($argument, '--filter=') && $filter === null && trim(substr($argument, 9)) !== '') {
        $filter = substr($argument, 9);

        continue;
    }

    if ($argument === '--filter' && $filter === null && isset($argv[$index + 1]) && trim($argv[$index + 1]) !== '') {
        $filter = $argv[++$index];

        continue;
    }

    if ($argument === '--phase07-baseline' && ! $phase07Baseline) {
        $phase07Baseline = true;

        continue;
    }

    fwrite(STDERR, "Only guarded SQLite/MySQL modes, their documented preflight/resume modes, --phase07-baseline, and --filter PHPUnit options are supported by this runner.\n");
    exit(2);
}

if (($useSqlite ? 1 : 0) + ($usePhase04Mysql ? 1 : 0) + ($usePhase04V2Mysql ? 1 : 0) + ($usePhase05Mysql ? 1 : 0) + ($usePhase06Mysql ? 1 : 0) + ($usePhase07Mysql ? 1 : 0) + ($resumePhase07Mysql ? 1 : 0) + ($upgradePhase07Mysql ? 1 : 0) + ($upgradePhase06Mysql ? 1 : 0) + ($resumePhase04V2Mysql ? 1 : 0) + ($preflightOnly ? 1 : 0) + ($preflightPhase05 ? 1 : 0) + ($preflightPhase06 ? 1 : 0) + ($preflightPhase07 ? 1 : 0) + ($preflightPhase07Upgrade ? 1 : 0) + ($inspectPhase07 ? 1 : 0) + ($preflightPhase06Upgrade ? 1 : 0) + ($inspectPhase06 ? 1 : 0) + ($reconcilePhase06 ? 1 : 0) + ($preflightPhase05Upgrade ? 1 : 0) + ($resumePhase05Mysql ? 1 : 0) + ($upgradePhase05Mysql ? 1 : 0) + ($inspectPhase04V2 ? 1 : 0) > 1) {
    fwrite(STDERR, "Choose exactly one database mode for the guarded runner.\n");
    exit(2);
}

if ($phase07Baseline && (! $usePhase07Mysql || $filter !== null)) {
    fwrite(STDERR, "The Phase 07 baseline option requires the unfiltered guarded --mysql-phase07 suite.\n");
    exit(2);
}

if (($preflightOnly || $preflightPhase05 || $preflightPhase06 || $preflightPhase07 || $preflightPhase07Upgrade || $inspectPhase07 || $preflightPhase06Upgrade || $inspectPhase06 || $reconcilePhase06 || $preflightPhase05Upgrade || $inspectPhase04V2) && $filter !== null) {
    fwrite(STDERR, "A PHPUnit filter cannot be combined with read-only preflight mode.\n");
    exit(2);
}

if ($usePhase04Mysql || $usePhase04V2Mysql || $usePhase05Mysql || $usePhase06Mysql || $usePhase07Mysql || $resumePhase07Mysql || $upgradePhase07Mysql || $upgradePhase06Mysql || $resumePhase04V2Mysql || $preflightOnly || $preflightPhase05 || $preflightPhase06 || $preflightPhase07 || $preflightPhase07Upgrade || $inspectPhase07 || $preflightPhase06Upgrade || $inspectPhase06 || $reconcilePhase06 || $preflightPhase05Upgrade || $resumePhase05Mysql || $upgradePhase05Mysql || $inspectPhase04V2) {
    $isV2Target = $usePhase04V2Mysql || $resumePhase04V2Mysql || $preflightOnly || $inspectPhase04V2;
    $isPhase05Target = $usePhase05Mysql || $preflightPhase05 || $preflightPhase05Upgrade || $resumePhase05Mysql || $upgradePhase05Mysql;
    $isPhase06Target = $usePhase06Mysql || $upgradePhase06Mysql || $preflightPhase06 || $preflightPhase06Upgrade || $inspectPhase06 || $reconcilePhase06;
    $isPhase07Target = $usePhase07Mysql || $resumePhase07Mysql || $upgradePhase07Mysql || $preflightPhase07 || $preflightPhase07Upgrade || $inspectPhase07;
    $target = $isPhase07Target
        ? Phase07MySqlTestTarget::create()
        : ($isPhase06Target
        ? Phase06MySqlTestTarget::create()
        : ($isPhase05Target ? Phase04MySqlTestTarget::phase05() : ($isV2Target ? Phase04MySqlTestTarget::phase04V2() : Phase04MySqlTestTarget::phase04())));

    try {
        $lockPath = dirname(__DIR__).DIRECTORY_SEPARATOR.'storage'.DIRECTORY_SEPARATOR.'framework'.DIRECTORY_SEPARATOR.$target->fileLockFilename;
        $mysqlRunLock = Phase04MySqlTestRunLock::acquireAtPath($lockPath);
    } catch (RuntimeException $exception) {
        fwrite(STDERR, 'Guarded MySQL runner lock failed: '.$exception->getMessage().PHP_EOL);
        exit(2);
    }

    $configurationFilename = $isPhase07Target
        ? Phase07MySqlTestTarget::CONFIGURATION
        : ($isPhase06Target
        ? Phase06MySqlTestTarget::CONFIGURATION
        : ($isPhase05Target ? '.env.phase05.testing' : ($isV2Target ? '.env.phase04.v2.testing' : '.env.phase04.testing')));
    $configurationPath = dirname(__DIR__).DIRECTORY_SEPARATOR.$configurationFilename;
    if (! is_file($configurationPath) || is_link($configurationPath) || ! isEnvironmentFileIgnored($configurationFilename)) {
        fwrite(STDERR, 'Guarded MySQL tests require a regular, Git-ignored '.$configurationFilename." file.\n");
        exit(2);
    }

    try {
        $mysqlEnvironment = Dotenv::parse((string) file_get_contents($configurationPath));
    } catch (Throwable) {
        fwrite(STDERR, "The guarded MySQL test configuration could not be parsed.\n");
        exit(2);
    }

    $environment = array_replace($environment, $mysqlEnvironment);
    $environment['APP_ENV'] = 'testing';
    $environment['APP_DEBUG'] = 'false';

    if (trim($environment['DB_PASSWORD'] ?? '') === '') {
        fwrite(STDERR, "The dedicated MySQL test account password is missing from the ignored configuration.\n");
        exit(2);
    }
    if (($usePhase06Mysql || $upgradePhase06Mysql || $reconcilePhase06) && ($environment['PHASE06_TEST_SCHEMA_DISPOSABLE'] ?? '') !== 'true') {
        fwrite(STDERR, "The Phase 06 target must be explicitly marked disposable in its ignored private configuration.\n");
        exit(2);
    }
    if (($usePhase07Mysql || $resumePhase07Mysql || $upgradePhase07Mysql) && ($environment['PHASE07_TEST_SCHEMA_DISPOSABLE'] ?? '') !== 'true') {
        fwrite(STDERR, "The Phase 07 target must be explicitly marked disposable in its ignored private configuration.\n");
        exit(2);
    }
}

try {
    TestDatabaseGuard::assertSafe(
        $environment,
        allowPhase05Runner: $isPhase05Target,
        allowPhase06Runner: $isPhase06Target,
        allowPhase06ReadOnlyPreflight: $preflightPhase06 || $inspectPhase06,
        allowPhase07Runner: $isPhase07Target,
    );
    $mysqlPreflight = ($usePhase04Mysql || $usePhase04V2Mysql || $usePhase05Mysql || $usePhase06Mysql || $usePhase07Mysql || $resumePhase07Mysql || $upgradePhase07Mysql || $upgradePhase06Mysql || $resumePhase04V2Mysql || $preflightOnly || $preflightPhase05 || $preflightPhase06 || $preflightPhase07 || $preflightPhase07Upgrade || $inspectPhase07 || $preflightPhase06Upgrade || $inspectPhase06 || $reconcilePhase06 || $preflightPhase05Upgrade || $resumePhase05Mysql || $upgradePhase05Mysql)
        ? new Phase04MySqlTestPreflight($environment, $target, $preflightPhase06 || $inspectPhase06)
        : null;
    if ($inspectPhase06 || $inspectPhase07) {
        $inspection = $mysqlPreflight->inspectCurrentStateReadOnly();
        $snapshot = $inspection['snapshot'];
    } elseif ($inspectPhase04V2) {
        $mysqlPreflight = new Phase04MySqlTestPreflight($environment, $target);
        $inspection = $mysqlPreflight->inspectCurrentStateReadOnly();
        $snapshot = $inspection['snapshot'];
    } else {
        $snapshot = match (true) {
            $preflightPhase05Upgrade => $mysqlPreflight?->assertAuthorizedPhase05MigrationUpgrade(),
            $preflightPhase06Upgrade, $upgradePhase06Mysql => $mysqlPreflight?->assertAuthorizedPhase06MigrationUpgrade(),
            $preflightPhase07Upgrade, $upgradePhase07Mysql => $mysqlPreflight?->assertAuthorizedPhase07MigrationUpgrade(),
            $resumePhase05Mysql => $mysqlPreflight?->reconcileAuthorizedPhase05MigrationTransition(),
            $upgradePhase05Mysql => $mysqlPreflight?->assertAuthorizedPhase05MigrationUpgrade(),
            $reconcilePhase06 => $mysqlPreflight?->reconcileAuthorizedPhase06MigrationTransition(),
            default => $mysqlPreflight?->assertOwnedSchemaSafe(
                shouldCreateOwnershipManifest($preflightOnly, $preflightPhase05, $preflightPhase06, $preflightPhase07, $inspectPhase07, $usePhase07Mysql, $resumePhase07Mysql),
                allowsVerifiedPartialTransition($resumePhase04V2Mysql, $resumePhase07Mysql),
            ),
        };
    }
} catch (RuntimeException $exception) {
    fwrite(STDERR, 'Test database safety check failed: '.$exception->getMessage().PHP_EOL);
    exit(2);
}

if ($preflightOnly) {
    fwrite(STDOUT, sprintf(
        "Phase 04 V2 read-only preflight passed: database=%s account=%s server=%s/%s:%d uuid=%s objects=%d migrations=%d; no ownership manifest or database schema/data was changed.\n",
        $snapshot['database'],
        $snapshot['username'].'@'.$snapshot['host'],
        $snapshot['server_version'],
        $snapshot['server_identity']['hostname'],
        $snapshot['server_identity']['port'],
        $snapshot['server_identity']['uuid'],
        count($snapshot['object_inventory']['tables_and_views']),
        count($snapshot['migrations']),
    ));
    exit(0);
}

if ($preflightPhase05) {
    fwrite(STDOUT, sprintf(
        "Phase 05 read-only preflight passed: database=%s account=%s@%s server=%s objects=%d migrations=%d; no ownership manifest or schema/data changed.\n",
        $snapshot['database'],
        $snapshot['username'],
        $snapshot['host'],
        $snapshot['server_version'],
        count($snapshot['object_inventory']['tables_and_views']),
        count($snapshot['migrations']),
    ));
    exit(0);
}

if ($preflightPhase06 || $preflightPhase06Upgrade) {
    $upgradeName = $snapshot['phase06_upgrade_migration'] ?? null;
    fwrite(STDOUT, sprintf(
        "Phase 06 %s preflight passed: database=%s account=%s@%s server=%s objects=%d migrations=%d%s; no ownership manifest or schema/data changed.\n",
        $preflightPhase06Upgrade ? 'single-migration upgrade' : 'read-only',
        $snapshot['database'],
        $snapshot['username'],
        $snapshot['host'],
        $snapshot['server_version'],
        $snapshot['phase06_upgrade_current_object_count'] ?? count($snapshot['object_inventory']['tables_and_views'] ?? []),
        $snapshot['phase06_upgrade_current_migration_count'] ?? count($snapshot['migrations']),
        $preflightPhase06Upgrade
            ? ((($snapshot['phase06_upgrade_already_applied'] ?? false) === true) ? '; verified completed migration pending audited ownership attestation='.$upgradeName : '; authorized pending migration='.$upgradeName)
            : '',
    ));
    exit(0);
}

if ($preflightPhase07Upgrade) {
    fwrite(STDOUT, sprintf(
        "Phase 07 single-migration upgrade preflight passed: database=%s account=%s@%s server=%s objects=%d migrations=%d pending=%s; read-only, no schema or ownership change.\n",
        $snapshot['database'], $snapshot['username'], $snapshot['host'], $snapshot['server_version'],
        count($snapshot['object_inventory']['tables_and_views'] ?? []), count($snapshot['migrations'] ?? []),
        $snapshot['phase07_upgrade_migration'] ?? 'unknown',
    ));
    exit(0);
}

if ($preflightPhase07) {
    fwrite(STDOUT, sprintf(
        "Phase 07 read-only preflight passed: database=%s account=%s@%s server=%s/%s:%d objects=%d migrations=%d; no ownership manifest or database schema/data was changed.\n",
        $snapshot['database'], $snapshot['username'], $snapshot['host'], $snapshot['server_version'],
        $snapshot['server_identity']['hostname'], $snapshot['server_identity']['port'],
        count($snapshot['object_inventory']['tables_and_views']), count($snapshot['migrations']),
    ));
    exit(0);
}

if ($inspectPhase07) {
    $nonempty = array_filter($inspection['row_counts'], static fn (int $count): bool => $count > 0);
    $probePdo = new PDO(
        'mysql:host='.$environment['DB_HOST'].';port='.$environment['DB_PORT'].';dbname='.$environment['DB_DATABASE'].';charset=utf8mb4',
        $environment['DB_USERNAME'], $environment['DB_PASSWORD'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC],
    );
    $probeQuery = $probePdo->prepare('SELECT id, slug FROM tenants WHERE slug LIKE :prefix ORDER BY id');
    $probeQuery->execute(['prefix' => 'phase07-learning-%']);
    $probeTenants = $probeQuery->fetchAll();
    $probeFixtures = array_map(static function (array $tenant) use ($probePdo): array {
        $counts = [];
        foreach ([
            'institutions', 'roles', 'tenant_memberships', 'membership_role', 'academic_courses', 'academic_course_versions',
            'course_delivery_workspaces', 'course_delivery_assignments', 'course_delivery_modules', 'course_delivery_lessons',
            'course_delivery_blocks', 'course_delivery_lesson_revisions', 'course_delivery_progress', 'course_delivery_activity_responses',
            'course_delivery_templates', 'course_delivery_template_versions', 'course_delivery_copy_operations',
            'course_delivery_live_sessions', 'audit_events',
        ] as $table) {
            $query = $probePdo->prepare('SELECT COUNT(*) FROM `'.$table.'` WHERE tenant_id = :tenant');
            $query->execute(['tenant' => $tenant['id']]);
            $counts[$table] = (int) $query->fetchColumn();
        }

        return ['tenant_id' => (int) $tenant['id'], 'slug' => (string) $tenant['slug'], 'scoped_row_counts' => $counts];
    }, $probeTenants);
    fwrite(STDOUT, 'Phase 07 read-only inspection: '.json_encode([
        'database' => $snapshot['database'],
        'account' => $snapshot['username'].'@'.$snapshot['host'],
        'server_version' => $snapshot['server_version'],
        'server_identity' => $snapshot['server_identity'],
        'schema_fingerprint' => $snapshot['schema_fingerprint'],
        'objects' => $snapshot['object_inventory']['tables_and_views'],
        'auxiliary_objects' => $snapshot['object_inventory']['auxiliary'],
        'migrations' => $snapshot['migrations'],
        'nonempty_table_row_counts' => $nonempty,
        'phase07_probe_fixture_inventory' => $probeFixtures,
        'course_delivery_progress_schema' => $inspection['phase07_progress_schema'],
        'target_sessions' => $inspection['sessions'],
        'read_only' => true,
    ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR).PHP_EOL);
    exit(0);
}

if ($reconcilePhase06) {
    fwrite(STDOUT, sprintf(
        "Phase 06 interrupted-run ownership reconciliation passed: database=%s account=%s@%s server=%s objects=%d migrations=%d; original manifest preserved as %s; evidence=%s; no MySQL schema or data was changed.\n",
        $snapshot['database'],
        $snapshot['username'],
        $snapshot['host'],
        $snapshot['server_version'],
        count($snapshot['object_inventory']['tables_and_views'] ?? []),
        count($snapshot['migrations']),
        $snapshot['previous_manifest_file'],
        $snapshot['reconciliation_evidence_file'],
    ));
    exit(0);
}

if ($inspectPhase06) {
    $manifestPath = dirname(__DIR__).DIRECTORY_SEPARATOR.'storage'.DIRECTORY_SEPARATOR.'framework'.DIRECTORY_SEPARATOR.$target->manifestFilename;
    $storedManifest = null;
    $manifestState = 'missing';
    $manifestDifferences = [];
    if (is_link($manifestPath)) {
        $manifestState = 'symlink-rejected';
    } elseif (is_file($manifestPath)) {
        $decoded = json_decode((string) file_get_contents($manifestPath), true);
        if (is_array($decoded)) {
            $storedManifest = $decoded;
            $manifestState = 'valid-json';
            $manifestDifferences = Phase04OwnershipManifestPolicy::differences($storedManifest, $snapshot);
        } else {
            $manifestState = 'invalid-json';
        }
    }
    $localMigrationFiles = [];
    foreach (glob(dirname(__DIR__).DIRECTORY_SEPARATOR.'database'.DIRECTORY_SEPARATOR.'migrations'.DIRECTORY_SEPARATOR.'*.php') ?: [] as $migrationPath) {
        $localMigrationFiles[basename($migrationPath, '.php')] = $migrationPath;
    }
    ksort($localMigrationFiles);
    $validate = static function (callable $check): array {
        try {
            $check();

            return ['passed' => true, 'reason' => null];
        } catch (RuntimeException $exception) {
            return ['passed' => false, 'reason' => $exception->getMessage()];
        }
    };
    $validation = [
        'complete_local_migration_inventory' => $validate(static fn () => Phase04MySqlTestDataPolicy::assertCompleteMigrationInventory($snapshot['migrations'], array_keys($localMigrationFiles))),
        'single_fresh_migration_batch' => $validate(static fn () => Phase04MySqlTestDataPolicy::assertSingleFreshMigrationBatch($snapshot['migrations'])),
        'migration_owned_table_inventory' => $validate(static fn () => Phase04MySqlTestDataPolicy::assertExpectedTableInventory($snapshot['object_inventory']['tables_and_views'], $snapshot['migrations'], $localMigrationFiles)),
        'migration_owned_baseline_rows' => $validate(static fn () => Phase04MySqlTestDataPolicy::assertMigrationOwnedBaseline($snapshot['tables'], $inspection['row_counts'], $inspection['permission_rows'], $inspection['bootstrap_rows'])),
        'original_empty_ownership_manifest' => ['passed' => $storedManifest !== null && Phase04V2OwnershipResumePolicy::isOriginalEmptyManifest($storedManifest, $snapshot, Phase06MySqlTestTarget::AUTHORIZATION), 'reason' => null],
    ];
    $interruptedTransitionProven = array_reduce($validation, static fn (bool $valid, array $result): bool => $valid && $result['passed'], true);
    $nonemptyTables = array_filter($inspection['row_counts'], static fn (int $count): bool => $count > 0);
    $sessions = $inspection['sessions'];
    fwrite(STDOUT, 'Phase 06 read-only inspection: '.json_encode([
        'database' => $snapshot['database'],
        'account' => $snapshot['username'].'@'.$snapshot['host'],
        'server_version' => $snapshot['server_version'],
        'server_identity' => $snapshot['server_identity'],
        'schema_fingerprint' => $snapshot['schema_fingerprint'],
        'objects' => $snapshot['object_inventory']['tables_and_views'],
        'auxiliary_objects' => $snapshot['object_inventory']['auxiliary'],
        'migrations' => $snapshot['migrations'],
        'row_counts' => $inspection['row_counts'],
        'nonempty_tables' => $nonemptyTables,
        'permission_row_count' => count($inspection['permission_keys']),
        'permission_keys' => $inspection['permission_keys'],
        'bootstrap_rows' => $inspection['bootstrap_rows'],
        'guardian_schema' => $inspection['guardian_schema'],
        'manifest' => ['state' => $manifestState, 'fields_differing' => $manifestDifferences],
        'migration_ownership_validation' => $validation,
        'authorized_interrupted_transition_proven' => $interruptedTransitionProven,
        'target_sessions' => $sessions,
        'read_only' => true,
    ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR).PHP_EOL);
    exit(0);
}

if ($preflightPhase05Upgrade) {
    fwrite(STDOUT, sprintf(
        "Phase 05 migration-upgrade preflight passed: exact owned schema, account and grants verified; one trailing local migration is authorized; database=%s objects=%d migrations=%d. No database or ownership state was changed.\n",
        $snapshot['database'], count($snapshot['object_inventory']['tables_and_views']), count($snapshot['migrations']),
    ));
    exit(0);
}

if ($inspectPhase04V2) {
    $nonemptyTables = array_filter($inspection['row_counts'], static fn (int $count): bool => $count > 0);
    $nonemptySummary = [];
    foreach ($nonemptyTables as $table => $count) {
        $nonemptySummary[] = $table.'='.$count;
    }
    $migrations = array_map(static fn (array $row): string => $row['migration'], $snapshot['migrations']);
    $tableNames = implode(', ', $snapshot['tables']);
    $guardianSchema = json_encode($inspection['guardian_schema'], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    fwrite(STDOUT, sprintf(
        "Phase 04 V2 read-only inspection: database=%s account=%s server=%s/%s:%d uuid=%s tables_or_views=%d {%s} migrations=%d [%s] nonempty_rows=[%s] auxiliary_objects=%d guardian_schema=%s; no ownership or database state was changed.\n",
        $snapshot['database'],
        $snapshot['username'].'@'.$snapshot['host'],
        $snapshot['server_version'],
        $snapshot['server_identity']['hostname'],
        $snapshot['server_identity']['port'],
        $snapshot['server_identity']['uuid'],
        count($snapshot['object_inventory']['tables_and_views']),
        $tableNames,
        count($migrations),
        implode(', ', $migrations),
        implode(', ', $nonemptySummary),
        count($snapshot['object_inventory']['auxiliary']),
        $guardianSchema,
    ));
    exit(0);
}

if ($resumePhase04V2Mysql && $mysqlPreflight?->isResumingAuthorizedPartialTransition()) {
    fwrite(STDOUT, sprintf(
        "Phase 04 V2 verified interrupted migration prefix for safe resume: %d schema objects, %d migrations; no ownership manifest was changed before the guarded test transition.\n",
        count($snapshot['object_inventory']['tables_and_views']),
        count($snapshot['migrations']),
    ));
}

if ($resumePhase05Mysql && is_array($snapshot)) {
    fwrite(STDOUT, sprintf(
        "Phase 05 ownership reconciliation passed: exact dedicated identity and grants; %d local migrations, %d migration-owned tables, stable schema, and no persistent test fixtures. Previous empty manifest was preserved as local audit evidence.\n",
        count($snapshot['migrations']),
        count($snapshot['object_inventory']['tables_and_views'] ?? []),
    ));
}

if ($usePhase05Mysql || $resumePhase05Mysql || $upgradePhase05Mysql) {
    $environment['PHASE05_MYSQL_PREFLIGHT_PASSED'] = 'true';
}
if ($usePhase06Mysql || $upgradePhase06Mysql) {
    $environment['PHASE06_MYSQL_PREFLIGHT_PASSED'] = 'true';
}
if ($usePhase07Mysql || $resumePhase07Mysql || $upgradePhase07Mysql) {
    $environment['PHASE07_MYSQL_PREFLIGHT_PASSED'] = 'true';
}

foreach ($environment as $name => $value) {
    putenv($name.'='.$value);
    $_ENV[$name] = $value;
    $_SERVER[$name] = $value;
}

putenv('DB_URL=');
$_ENV['DB_URL'] = '';
$_SERVER['DB_URL'] = '';

$artisan = escapeshellarg(dirname(__DIR__).DIRECTORY_SEPARATOR.'artisan');
$php = escapeshellarg(PHP_BINARY);
$phase06MigrationIntent = null;
$phase07MigrationIntent = null;
if ($upgradePhase06Mysql) {
    try {
        $phase06MigrationIntent = $mysqlPreflight?->prepareAuthorizedPhase06MigrationUpgrade($snapshot);
    } catch (RuntimeException $exception) {
        fwrite(STDERR, 'Phase 06 migration transition intent failed: '.$exception->getMessage().PHP_EOL);
        exit(2);
    }
}
if ($upgradePhase07Mysql) {
    try {
        $phase07MigrationIntent = $mysqlPreflight?->prepareAuthorizedPhase07MigrationUpgrade($snapshot);
    } catch (RuntimeException $exception) {
        fwrite(STDERR, 'Phase 07 migration transition intent failed: '.$exception->getMessage().PHP_EOL);
        exit(2);
    }
}
passthru($php.' '.$artisan.' config:clear --ansi', $clearStatus);

if ($clearStatus !== 0) {
    exit($clearStatus);
}

if ($usePhase07Mysql && ($snapshot['tables'] ?? []) === []) {
    $migrationCommand = $php.' '.$artisan.' migrate --force --no-interaction --database=mysql';
    fwrite(STDOUT, "Applying additive Phase 07 migrations to the freshly verified empty dedicated schema.\n");
    passthru($migrationCommand, $migrationStatus);
    if ($migrationStatus !== 0) {
        fwrite(STDERR, "Phase 07 migration command failed; tests were not started and no ownership manifest was attested. Preserve the schema for read-only inspection.\n");
        exit($migrationStatus);
    }
    try {
        $snapshot = $mysqlPreflight?->completeAuthorizedPhase07InitialMigration($snapshot) ?? $snapshot;
    } catch (RuntimeException $exception) {
        fwrite(STDERR, 'Phase 07 migration ownership postflight failed: '.$exception->getMessage()." Preserve the schema for read-only inspection; no tests were started.\n");
        exit(2);
    }
}

if ($resumePhase07Mysql) {
    $migrationCommand = $php.' '.$artisan.' migrate --force --no-interaction --database=mysql';
    fwrite(STDOUT, "Resuming the specifically verified interrupted Phase 07 migration prefix on its dedicated test schema.\n");
    passthru($migrationCommand, $migrationStatus);
    if ($migrationStatus !== 0) {
        fwrite(STDERR, "Phase 07 migration resume failed; tests were not started and no ownership manifest was attested. Preserve the schema for read-only inspection.\n");
        exit($migrationStatus);
    }
    try {
        $snapshot = $mysqlPreflight?->completeAuthorizedPhase07InterruptedMigration($snapshot) ?? $snapshot;
    } catch (RuntimeException $exception) {
        fwrite(STDERR, 'Phase 07 migration recovery postflight failed: '.$exception->getMessage()." Preserve the schema for read-only inspection; no tests were started.\n");
        exit(2);
    }
}

if ($upgradePhase07Mysql) {
    $migrationName = (string) ($snapshot['phase07_upgrade_migration'] ?? '');
    $migrationPath = 'database/migrations/'.$migrationName.'.php';
    if (($snapshot['phase07_upgrade_already_applied'] ?? false) !== true) {
        $migrationCommand = $php.' '.$artisan.' migrate --force --no-interaction --database=mysql --path='.escapeshellarg($migrationPath);
        fwrite(STDOUT, 'Applying one preflight-authorized Phase 07 migration: '.$migrationName.PHP_EOL);
        passthru($migrationCommand, $migrationStatus);
        if ($migrationStatus !== 0) {
            fwrite(STDERR, "The authorized Phase 07 migration failed. Preserve the schema and transition intent for read-only inspection; tests were not started.\n");
            exit($migrationStatus);
        }
    } else {
        fwrite(STDOUT, 'Verifying the interrupted, intent-backed Phase 07 migration before tests.'.PHP_EOL);
    }
    try {
        $transition = $mysqlPreflight?->completeAuthorizedPhase07MigrationUpgrade($snapshot, (string) $phase07MigrationIntent);
        fwrite(STDOUT, sprintf(
            "Phase 07 migration ownership transition verified: database=%s migration=%s objects=%d migrations=%d previous_manifest=%s evidence=%s.\n",
            $transition['database'], $migrationName, count($transition['object_inventory']['tables_and_views']),
            count($transition['migrations']), $transition['phase07_previous_manifest_file'], $transition['phase07_upgrade_evidence_file'],
        ));
    } catch (RuntimeException $exception) {
        fwrite(STDERR, 'Phase 07 migration ownership postflight failed: '.$exception->getMessage()." Preserve the schema for read-only inspection; no tests were started.\n");
        exit(2);
    }
}

if ($upgradePhase06Mysql) {
    $migrationName = (string) ($snapshot['phase06_upgrade_migration'] ?? '');
    $migrationPath = 'database/migrations/'.$migrationName.'.php';
    if (($snapshot['phase06_upgrade_already_applied'] ?? false) !== true) {
        $migrationCommand = $php.' '.$artisan.' migrate --force --no-interaction --database=mysql --path='.escapeshellarg($migrationPath);
        fwrite(STDOUT, 'Applying one preflight-authorized Phase 06 migration: '.$migrationName.PHP_EOL);
        passthru($migrationCommand, $migrationStatus);
        if ($migrationStatus !== 0) {
            fwrite(STDERR, "Phase 06 migration command failed; no tests were started and the ownership manifest was not advanced. Preserve the schema for read-only inspection.\n");
            exit($migrationStatus);
        }
    } else {
        fwrite(STDOUT, 'The authorized Phase 06 migration is already applied and verified from its recorded transition intent; skipping migration execution.'.PHP_EOL);
    }
    try {
        $transition = $mysqlPreflight?->completeAuthorizedPhase06MigrationUpgrade($snapshot, (string) $phase06MigrationIntent);
        fwrite(STDOUT, sprintf(
            "Phase 06 migration ownership transition verified: database=%s migration=%s objects=%d migrations=%d previous_manifest=%s evidence=%s.\n",
            $transition['database'],
            $migrationName,
            count($transition['object_inventory']['tables_and_views']),
            count($transition['migrations']),
            $transition['phase06_previous_manifest_file'],
            $transition['phase06_upgrade_evidence_file'],
        ));
    } catch (RuntimeException $exception) {
        fwrite(STDERR, 'Phase 06 migration ownership postflight failed: '.$exception->getMessage()." Preserve the schema for read-only inspection; no tests were started.\n");
        exit(2);
    }
}

$testCommand = $php.' '.$artisan.' test'.($phase07Baseline ? ' --exclude-group=phase08' : '').($filter !== null ? ' --filter='.escapeshellarg($filter) : '');
passthru($testCommand, $testStatus);

if ($mysqlPreflight !== null && $testStatus === 0 && $usePhase07Mysql
    && ($phase07Baseline || $filter === null || $filter === 'CourseDeliveryTest')) {
    $probe = escapeshellarg(__DIR__.DIRECTORY_SEPARATOR.'Support'.DIRECTORY_SEPARATOR.'phase07-learning-concurrency.php');
    fwrite(STDOUT, "Starting the five deterministic two-worker Phase 07 MySQL concurrency probes under the existing exclusive guarded run.\n");
    passthru($php.' '.$probe.' orchestrate', $raceStatus);
    if ($raceStatus !== 0) {
        fwrite(STDERR, "Phase 07 concurrency probes failed; ownership evidence was not advanced.\n");
        exit($raceStatus);
    }
}

if ($mysqlPreflight !== null && $testStatus === 0) {
    try {
        $mysqlPreflight->refreshOwnershipManifest();
    } catch (RuntimeException $exception) {
        fwrite(STDERR, 'Guarded MySQL ownership postflight failed: '.$exception->getMessage().PHP_EOL);
        exit(2);
    }
} elseif ($mysqlPreflight !== null) {
    fwrite(STDERR, "Guarded MySQL tests failed; the ownership manifest was not advanced. Any subsequent run must pass complete schema and fixture-safety preflight.\n");
}

exit($testStatus);

/** Confirm private DB configuration is excluded from Git without displaying its contents. */
function isEnvironmentFileIgnored(string $filename): bool
{
    $process = proc_open(
        ['git', 'check-ignore', '--quiet', '--', $filename],
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes,
        dirname(__DIR__),
    );

    if (! is_resource($process)) {
        return false;
    }

    fclose($pipes[0]);
    stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    stream_get_contents($pipes[2]);
    fclose($pipes[2]);

    return proc_close($process) === 0;
}

function allowsVerifiedPartialTransition(bool $phase04V2Resume, bool $phase07Resume): bool
{
    return $phase04V2Resume || $phase07Resume;
}

function shouldCreateOwnershipManifest(
    bool $preflightOnly,
    bool $preflightPhase05,
    bool $preflightPhase06,
    bool $preflightPhase07,
    bool $inspectPhase07,
    bool $usePhase07Mysql,
    bool $resumePhase07Mysql,
): bool {
    return ! ($preflightOnly || $preflightPhase05 || $preflightPhase06 || $preflightPhase07 || $inspectPhase07 || $usePhase07Mysql || $resumePhase07Mysql);
}
