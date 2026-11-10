<?php

declare(strict_types=1);

use App\Domain\Identity\PermissionCatalog;
use Dotenv\Dotenv;
use Tests\Support\Phase04MySqlGrantPolicy;
use Tests\Support\Phase08MigrationRecoveryPolicy;
use Tests\Support\Phase08MySqlTestTarget;
use Tests\Support\Phase08MySqlTestTarget as Target;

require dirname(__DIR__).'/vendor/autoload.php';

$root = dirname(__DIR__);
$framework = $root.DIRECTORY_SEPARATOR.'storage'.DIRECTORY_SEPARATOR.'framework';
$envFile = $root.DIRECTORY_SEPARATOR.Target::CONFIGURATION;
$manifestPath = $framework.DIRECTORY_SEPARATOR.Target::MANIFEST;
$intentPath = $framework.DIRECTORY_SEPARATOR.'.phase08-mysql-migration-intent.json';
$lockPath = $framework.DIRECTORY_SEPARATOR.Target::LOCK_FILE;
$mode = $argv[1] ?? '';
if (! in_array($mode, ['--preflight', '--run', '--recover', '--resume-recovery'], true)) {
    fwrite(STDERR, "Usage: php tests/run-phase08-mysql.php --preflight|--run|--recover|--resume-recovery\n");
    exit(2);
}
if (! is_file($envFile) || is_link($envFile)) {
    fwrite(STDERR, 'Missing regular ignored configuration '.$envFile.".\n");
    exit(2);
}
$ignored = proc_open(['git', 'check-ignore', '--quiet', '--', Target::CONFIGURATION], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $root);
if (! is_resource($ignored)) {
    fwrite(STDERR, "Could not verify private environment ignore status.\n");
    exit(2);
}
fclose($pipes[0]);
fclose($pipes[1]);
fclose($pipes[2]);
if (proc_close($ignored) !== 0) {
    fwrite(STDERR, "Phase 08 private environment file is not Git-ignored.\n");
    exit(2);
}
try {
    $env = Dotenv::parse((string) file_get_contents($envFile));
} catch (Throwable) {
    fwrite(STDERR, "Phase 08 environment file could not be parsed.\n");
    exit(2);
}
$required = [
    'DB_CONNECTION' => 'mysql', 'DB_HOST' => '127.0.0.1', 'DB_PORT' => '3306',
    'DB_DATABASE' => Target::DATABASE, 'DB_USERNAME' => Target::ACCOUNT,
    'APP_ENV' => 'testing', 'PHASE08_TEST_SCHEMA_DISPOSABLE' => 'true',
    'PHASE08_TEST_SCHEMA_AUTHORIZATION' => Target::AUTHORIZATION,
];
foreach ($required as $key => $value) {
    if (($env[$key] ?? null) !== $value) {
        fwrite(STDERR, "Phase 08 configuration identity/authorization check failed for {$key}.\n");
        exit(2);
    }
}
if (trim($env['DB_PASSWORD'] ?? '') === '' || ($env['DB_URL'] ?? '') !== '') {
    fwrite(STDERR, "Phase 08 database credentials are missing or DB_URL is set.\n");
    exit(2);
}
$handle = @fopen($lockPath, 'c');
if (! is_resource($handle) || ! flock($handle, LOCK_EX | LOCK_NB)) {
    fwrite(STDERR, "Another Phase 08 MySQL run holds the exclusive local lock.\n");
    exit(2);
}
$pdo = null;
$mysqlLock = false;
try {
    $pdo = new PDO('mysql:host=127.0.0.1;port=3306;dbname='.Target::DATABASE.';charset=utf8mb4', $env['DB_USERNAME'], $env['DB_PASSWORD'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false]);
    $identity = $pdo->query('SELECT DATABASE() AS db, CURRENT_USER() AS account, @@hostname AS hostname, @@port AS port, @@version AS version, @@server_uuid AS uuid')->fetch();
    [$username, $host] = array_pad(explode('@', (string) $identity['account'], 2), 2, '');
    Phase04MySqlGrantPolicy::assertIdentity((string) $identity['db'], $username, trim($host, "`'\""), Target::DATABASE, Target::ACCOUNT);
    if ((int) $identity['port'] !== 3306 || ! str_starts_with((string) $identity['version'], '8.4.')) {
        throw new RuntimeException('Phase 08 target must be the dedicated local MySQL 8.4 server.');
    }
    Phase04MySqlGrantPolicy::assertAllowed(array_map('strval', $pdo->query('SHOW GRANTS')->fetchAll(PDO::FETCH_COLUMN)), $username, trim($host, "`'\""), Target::DATABASE);
    $lock = $pdo->prepare('SELECT GET_LOCK(:name, 0)');
    $lock->execute(['name' => Target::SERVER_LOCK]);
    if ((int) $lock->fetchColumn() !== 1) {
        throw new RuntimeException('A competing Phase 08 process holds the MySQL advisory lock.');
    }
    $mysqlLock = true;
    Phase08MigrationRecoveryPolicy::assertLocksAcquired(is_resource($handle), $mysqlLock);
    $snapshot = snapshot($pdo, (string) $identity['uuid']);
    $stable = snapshot($pdo, (string) $identity['uuid']);
    if (! hash_equals($snapshot['fingerprint'], $stable['fingerprint'])) {
        throw new RuntimeException('Phase 08 schema changed during preflight; no migration was started.');
    }
    $snapshot = $stable;
    if (is_link($manifestPath)) {
        throw new RuntimeException('Phase 08 ownership manifest may not be a symlink.');
    }
    if ($mode === '--preflight') {
        if (is_file($manifestPath)) {
            $manifest = json_decode((string) file_get_contents($manifestPath), true);
            if (! is_array($manifest)) {
                throw new RuntimeException('Phase 08 ownership manifest is malformed.');
            }
            Phase08MigrationRecoveryPolicy::assertOwnedManifest($manifest, Target::DATABASE, (string) $identity['uuid'], $snapshot['fingerprint']);
            if (isset($manifest['data_counts']) && $manifest['data_counts'] !== tableRowCounts($pdo, $snapshot['objects'])) {
                throw new RuntimeException('Phase 08 data state differs from its ownership manifest.');
            }
        }
        if (! is_file($manifestPath) && ($snapshot['objects'] !== [] || $snapshot['migrations'] !== [])) {
            throw new RuntimeException('Unowned Phase 08 schema is nonempty; no changes were made.');
        }
        fwrite(STDOUT, sprintf("Phase 08 read-only preflight passed: database=%s account=%s@%s MySQL=%s objects=%d migrations=%d; no schema/data/manifest changes.\n", Target::DATABASE, $username, trim($host, "`'\""), $identity['version'], count($snapshot['objects']), count($snapshot['migrations'])));
        exit(0);
    }
    $localMigrations = array_map(static fn (string $path): string => basename($path, '.php'), glob($root.DIRECTORY_SEPARATOR.'database'.DIRECTORY_SEPARATOR.'migrations'.DIRECTORY_SEPARATOR.'*.php') ?: []);
    sort($localMigrations);
    $identityContract = ['database' => (string) $identity['db'], 'account' => $username.'@'.trim($host, "`'\""), 'server_uuid' => (string) $identity['uuid']];
    $recovering = $mode === '--recover';
    $resumingRecovery = $mode === '--resume-recovery';
    $recovering = $recovering || $resumingRecovery;
    $recoveryReceiptPath = $framework.DIRECTORY_SEPARATOR.Target::RECOVERY_RECEIPT;
    $retryReceiptPath = $framework.DIRECTORY_SEPARATOR.Target::RETRY_RECEIPT;
    $preRecovery = null;
    if ($resumingRecovery) {
        if (! is_file($intentPath) || is_link($intentPath) || ! is_file($recoveryReceiptPath) || is_link($recoveryReceiptPath) || is_file($manifestPath) || is_link($retryReceiptPath) || file_exists($retryReceiptPath)) {
            throw new RuntimeException('Phase 08 retry requires the preserved intent and first recovery receipt, no ownership manifest, and no prior retry receipt.');
        }
        $intent = json_decode((string) file_get_contents($intentPath), true);
        $receipt = json_decode((string) file_get_contents($recoveryReceiptPath), true);
        if (! is_array($intent) || ! is_array($receipt)) {
            throw new RuntimeException('Phase 08 retry evidence is malformed; retry was refused.');
        }
        Phase08MigrationRecoveryPolicy::assertIntent($intent, $identityContract);
        $nonempty = nonemptyTableCounts($pdo, $snapshot['objects']);
        $presentTables = array_values(array_intersect($snapshot['objects'], Phase08MigrationRecoveryPolicy::TABLES_CREATED_BY_MIGRATION));
        $migrationHash = hash_file('sha256', $root.DIRECTORY_SEPARATOR.'database'.DIRECTORY_SEPARATOR.'migrations'.DIRECTORY_SEPARATOR.Phase08MigrationRecoveryPolicy::MIGRATION.'.php');
        Phase08MigrationRecoveryPolicy::assertInterruptedRetry(
            $receipt, $identityContract, hash_file('sha256', $intentPath), $snapshot['fingerprint'], $snapshot['objects'],
            $snapshot['migrations'], $presentTables, $nonempty, $localMigrations, $migrationHash,
        );
        $preRecovery = $snapshot;
        $retryReceipt = [
            'database' => Target::DATABASE, 'account' => $identityContract['account'], 'server_uuid' => $identity['uuid'],
            'first_recovery_receipt_sha256' => hash_file('sha256', $recoveryReceiptPath),
            'intent_sha256' => hash_file('sha256', $intentPath), 'retry_fingerprint' => $snapshot['fingerprint'],
            'migrations_before' => $snapshot['migrations'], 'objects_before' => $snapshot['objects'],
            'nonempty_tables_before' => $nonempty, 'migration_sha256' => $migrationHash,
            'retry_started_at_utc' => gmdate(DATE_ATOM), 'authorization' => Target::AUTHORIZATION,
        ];
        writeExclusive($retryReceiptPath, json_encode($retryReceipt, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
    } elseif ($mode === '--recover') {
        if (! is_file($intentPath) || is_link($intentPath) || is_file($manifestPath) || is_link($recoveryReceiptPath) || file_exists($recoveryReceiptPath) || file_exists($retryReceiptPath)) {
            throw new RuntimeException('Phase 08 recovery requires the existing regular intent, no ownership manifest, and no prior recovery receipt.');
        }
        $intent = json_decode((string) file_get_contents($intentPath), true);
        if (! is_array($intent)) {
            throw new RuntimeException('Phase 08 migration intent is malformed; recovery was refused.');
        }
        Phase08MigrationRecoveryPolicy::assertIntent($intent, $identityContract);
        $nonempty = nonemptyTableCounts($pdo, $snapshot['objects']);
        $permissionRows = in_array('permissions', $snapshot['objects'], true)
            ? array_map(static fn (array $row): array => ['key' => (string) $row['key'], 'description' => (string) $row['description'], 'scope' => (string) $row['scope']], $pdo->query('SELECT `key`,description,scope FROM permissions ORDER BY `key`')->fetchAll())
            : [];
        $permissions = array_column($permissionRows, 'key');
        $tenantPermissions = PermissionCatalog::tenantPermissions();
        $platformPermissions = PermissionCatalog::platformPermissions();
        $knownPermissionDescriptions = $tenantPermissions + $platformPermissions;
        $knownPermissionScopes = array_fill_keys(array_keys($tenantPermissions), 'tenant') + array_fill_keys(array_keys($platformPermissions), 'platform');
        $columns = columnInventory($pdo, ['enrollment_policies', 'course_offerings', 'course_enrollments']);
        $foreignKeys = foreignKeyInventory($pdo, 'enrollment_policy_academic_levels');
        $presentTables = array_values(array_intersect($snapshot['objects'], Phase08MigrationRecoveryPolicy::TABLES_CREATED_BY_MIGRATION));
        $bootstrapRow = in_array('platform_bootstrap_states', $snapshot['objects'], true)
            ? array_map(static fn (array $row): array => ['id' => (int) $row['id'], 'completed' => (int) $row['completed'], 'completed_at' => $row['completed_at'] === null ? null : (string) $row['completed_at']], $pdo->query('SELECT id,completed,completed_at FROM platform_bootstrap_states ORDER BY id')->fetchAll())
            : [];
        Phase08MigrationRecoveryPolicy::assertInterruptedState(
            $snapshot['fingerprint'], $snapshot['objects'], $snapshot['migrations'], $localMigrations,
            $nonempty, $permissions, array_keys($knownPermissionDescriptions), $permissionRows, $knownPermissionDescriptions, $knownPermissionScopes, $columns,
            $foreignKeys, $presentTables, $bootstrapRow,
        );
        $preRecovery = $snapshot;
        $receipt = [
            'database' => Target::DATABASE, 'account' => $identityContract['account'], 'server_uuid' => $identity['uuid'],
            'intent_sha256' => hash_file('sha256', $intentPath), 'initial_fingerprint' => $intent['initial_fingerprint'],
            'partial_fingerprint' => $snapshot['fingerprint'], 'migrations_before' => $snapshot['migrations'],
            'objects_before' => $snapshot['objects'], 'nonempty_tables_before' => $nonempty,
            'recovered_migration' => Phase08MigrationRecoveryPolicy::MIGRATION,
            'migration_sha256' => hash_file('sha256', $root.DIRECTORY_SEPARATOR.'database'.DIRECTORY_SEPARATOR.'migrations'.DIRECTORY_SEPARATOR.Phase08MigrationRecoveryPolicy::MIGRATION.'.php'),
            'recovery_started_at_utc' => gmdate(DATE_ATOM), 'authorization' => Target::AUTHORIZATION,
        ];
        writeExclusive($recoveryReceiptPath, json_encode($receipt, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
    } elseif (file_exists($intentPath)) {
        throw new RuntimeException('Prior migration intent exists; use the explicit guarded recovery mode after forensic review.');
    } elseif (! is_file($manifestPath)) {
        Phase08MigrationRecoveryPolicy::assertInitialEmpty($snapshot['objects'], $snapshot['migrations']);
        $intent = Phase08MigrationRecoveryPolicy::createIntent($identityContract, $snapshot['fingerprint']);
        writeExclusive($intentPath, json_encode($intent, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
    } else {
        $manifest = json_decode((string) file_get_contents($manifestPath), true);
        if (! is_array($manifest)) {
            throw new RuntimeException('Phase 08 ownership manifest is malformed.');
        }
        Phase08MigrationRecoveryPolicy::assertOwnedManifest($manifest, Target::DATABASE, (string) $identity['uuid'], $snapshot['fingerprint']);
        if (isset($manifest['data_counts']) && $manifest['data_counts'] !== tableRowCounts($pdo, $snapshot['objects'])) {
            throw new RuntimeException('Phase 08 data state differs from its ownership manifest.');
        }
    }
    foreach ($env as $key => $value) {
        putenv($key.'='.$value);
        $_ENV[$key] = $value;
        $_SERVER[$key] = $value;
    }
    putenv('DB_URL=');
    $_ENV['DB_URL'] = '';
    $_SERVER['DB_URL'] = '';
    putenv('PHASE08_MYSQL_PREFLIGHT_PASSED=true');
    $_ENV['PHASE08_MYSQL_PREFLIGHT_PASSED'] = 'true';
    $_SERVER['PHASE08_MYSQL_PREFLIGHT_PASSED'] = 'true';
    passthru(escapeshellarg(PHP_BINARY).' '.escapeshellarg($root.DIRECTORY_SEPARATOR.'artisan').' config:clear --ansi', $clearStatus);
    if ($clearStatus !== 0) {
        exit($clearStatus);
    }
    if (! is_file($manifestPath)) {
        passthru(escapeshellarg(PHP_BINARY).' '.escapeshellarg($root.DIRECTORY_SEPARATOR.'artisan').' migrate --force --no-interaction --database=mysql', $migrationStatus);
        if ($migrationStatus !== 0) {
            fwrite(STDERR, "Migration failed; intent retained and database must be reviewed read-only.\n");
            exit($migrationStatus);
        }
        $after = snapshot($pdo, (string) $identity['uuid']);
        if ($recovering) {
            Phase08MigrationRecoveryPolicy::assertPostflight($preRecovery['migrations'], $after['migrations'], $localMigrations, $preRecovery['objects'], $after['objects']);
            if (nonemptyTableCounts($pdo, $after['objects']) !== ['migrations' => count($localMigrations), 'permissions' => 76, 'platform_bootstrap_states' => 1]) {
                throw new RuntimeException('Phase 08 recovery produced unexpected non-migration data.');
            }
        } elseif (array_column($after['migrations'], 'migration') !== $localMigrations) {
            throw new RuntimeException('Applied migration inventory differs from this checkout; ownership was not attested.');
        }
        $dataCounts = tableRowCounts($pdo, $after['objects']);
        $manifest = ['database' => Target::DATABASE, 'account' => $identityContract['account'], 'server_uuid' => $identity['uuid'], 'server_version' => $identity['version'], 'objects' => $after['objects'], 'migrations' => $after['migrations'], 'fingerprint' => $after['fingerprint'], 'data_counts' => $dataCounts, 'attested_at_utc' => gmdate(DATE_ATOM), 'authorization' => Target::AUTHORIZATION];
        writeExclusive($manifestPath, json_encode($manifest, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
        $archivedIntentPath = $framework.DIRECTORY_SEPARATOR.($recovering ? '.phase08-mysql-migration-intent.recovered.json' : '.phase08-mysql-migration-intent.completed.json');
        if (is_link($archivedIntentPath) || file_exists($archivedIntentPath) || ! rename($intentPath, $archivedIntentPath)) {
            throw new RuntimeException('Verified migration completed, but the intent could not be preserved in its immutable archive path.');
        }
    }
    $appliedMigrations = array_column(snapshot($pdo, (string) $identity['uuid'])['migrations'], 'migration');
    sort($localMigrations);
    sort($appliedMigrations);
    if ($appliedMigrations !== $localMigrations) {
        throw new RuntimeException('The Phase 08 target migration inventory does not match this checkout; tests were not started.');
    }
    passthru(escapeshellarg(PHP_BINARY).' '.escapeshellarg($root.DIRECTORY_SEPARATOR.'artisan').' test --group=phase08', $testStatus);
    if ($testStatus === 0) {
        passthru(escapeshellarg(PHP_BINARY).' '.escapeshellarg($root.DIRECTORY_SEPARATOR.'tests'.DIRECTORY_SEPARATOR.'Support'.DIRECTORY_SEPARATOR.'phase08-enrollment-concurrency.php'), $probeStatus);
        if ($probeStatus !== 0) {
            $testStatus = $probeStatus;
        }
    }
    $final = snapshot($pdo, (string) $identity['uuid']);
    $manifest = json_decode((string) file_get_contents($manifestPath), true);
    if (! is_array($manifest)) {
        throw new RuntimeException('Phase 08 ownership manifest became unreadable after tests.');
    }
    Phase08MigrationRecoveryPolicy::assertOwnedManifest($manifest, Target::DATABASE, (string) $identity['uuid'], $final['fingerprint']);
    if (($manifest['data_counts'] ?? null) !== tableRowCounts($pdo, $final['objects'])) {
        throw new RuntimeException('Post-test data differs from the Phase 08 ownership baseline.');
    }
    exit($testStatus);
} catch (Throwable $error) {
    fwrite(STDERR, 'Phase 08 guarded test failed: '.$error->getMessage()."\n");
    exit(2);
} finally {
    if ($mysqlLock && $pdo instanceof PDO) {
        try {
            $release = $pdo->prepare('SELECT RELEASE_LOCK(:name)');
            $release->execute(['name' => Target::SERVER_LOCK]);
        } catch (Throwable) {
        }
    }
    if (is_resource($handle)) {
        flock($handle, LOCK_UN);
        fclose($handle);
    }
}

/** @return array{objects:list<string>,migrations:list<array{migration:string,batch:int}>,fingerprint:string} */
function snapshot(PDO $pdo, string $serverUuid): array
{
    $schema = Phase08MySqlTestTarget::DATABASE;
    $objectsQuery = $pdo->prepare('SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = :schema ORDER BY TABLE_NAME');
    $objectsQuery->execute(['schema' => $schema]);
    $objects = array_map('strval', $objectsQuery->fetchAll(PDO::FETCH_COLUMN));
    $migrations = [];
    if (in_array('migrations', $objects, true)) {
        $migrations = array_map(static fn (array $row): array => ['migration' => (string) $row['migration'], 'batch' => (int) $row['batch']], $pdo->query('SELECT migration,batch FROM migrations ORDER BY migration')->fetchAll());
    }
    $catalog = [];
    foreach ([
        'COLUMNS' => 'TABLE_NAME,COLUMN_NAME,ORDINAL_POSITION,COLUMN_TYPE,IS_NULLABLE,COLUMN_DEFAULT,EXTRA',
        'STATISTICS' => 'TABLE_NAME,INDEX_NAME,NON_UNIQUE,SEQ_IN_INDEX,COLUMN_NAME',
        'TABLE_CONSTRAINTS' => 'TABLE_NAME,CONSTRAINT_NAME,CONSTRAINT_TYPE',
        'KEY_COLUMN_USAGE' => 'TABLE_NAME,CONSTRAINT_NAME,COLUMN_NAME,REFERENCED_TABLE_NAME,REFERENCED_COLUMN_NAME',
    ] as $table => $columns) {
        $q = $pdo->prepare("SELECT {$columns} FROM information_schema.{$table} WHERE TABLE_SCHEMA=:schema ORDER BY 1,2,3");
        $q->execute(['schema' => $schema]);
        $catalog[$table] = $q->fetchAll();
    }

    return ['objects' => $objects, 'migrations' => $migrations, 'fingerprint' => hash('sha256', json_encode([$serverUuid, $objects, $migrations, $catalog], JSON_THROW_ON_ERROR))];
}

/** @param list<string> $objects
 * @return array<string,int>
 */
function tableRowCounts(PDO $pdo, array $objects): array
{
    $counts = [];
    foreach ($objects as $table) {
        $quoted = '`'.str_replace('`', '``', $table).'`';
        $counts[$table] = (int) $pdo->query('SELECT COUNT(*) FROM '.$quoted)->fetchColumn();
    }
    ksort($counts);

    return $counts;
}

/** @param list<string> $objects
 * @return array<string,int>
 */
function nonemptyTableCounts(PDO $pdo, array $objects): array
{
    return array_filter(tableRowCounts($pdo, $objects), static fn (int $count): bool => $count > 0);
}

/** @param list<string> $tables
 * @return array<string,list<string>>
 */
function columnInventory(PDO $pdo, array $tables): array
{
    $placeholders = implode(',', array_fill(0, count($tables), '?'));
    $query = $pdo->prepare('SELECT TABLE_NAME,COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN ('.$placeholders.') ORDER BY TABLE_NAME,ORDINAL_POSITION');
    $query->execute($tables);
    $inventory = array_fill_keys($tables, []);
    foreach ($query->fetchAll() as $row) {
        $inventory[(string) $row['TABLE_NAME']][] = (string) $row['COLUMN_NAME'];
    }

    return $inventory;
}

/** @return list<array{columns:list<string>,table:string,referenced_columns:list<string>}> */
function foreignKeyInventory(PDO $pdo, string $table): array
{
    $query = $pdo->prepare('SELECT CONSTRAINT_NAME,COLUMN_NAME,REFERENCED_TABLE_NAME,REFERENCED_COLUMN_NAME,ORDINAL_POSITION FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND REFERENCED_TABLE_NAME IS NOT NULL ORDER BY CONSTRAINT_NAME,ORDINAL_POSITION');
    $query->execute([$table]);
    $foreignKeys = [];
    foreach ($query->fetchAll() as $row) {
        $name = (string) $row['CONSTRAINT_NAME'];
        $foreignKeys[$name] ??= ['columns' => [], 'table' => (string) $row['REFERENCED_TABLE_NAME'], 'referenced_columns' => []];
        $foreignKeys[$name]['columns'][] = (string) $row['COLUMN_NAME'];
        $foreignKeys[$name]['referenced_columns'][] = (string) $row['REFERENCED_COLUMN_NAME'];
    }

    return array_values($foreignKeys);
}

function writeExclusive(string $path, string $contents): void
{
    $handle = @fopen($path, 'x');
    if (! is_resource($handle)) {
        throw new RuntimeException('Ownership evidence already exists or cannot be created safely.');
    }
    try {
        if (fwrite($handle, $contents.PHP_EOL) === false || ! fflush($handle)) {
            throw new RuntimeException('Could not persist ownership evidence.');
        }
    } finally {
        fclose($handle);
    }
}
