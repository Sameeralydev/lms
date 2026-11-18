<?php

declare(strict_types=1);

use Tests\Support\CentralTestingEnvironment;
use Tests\Support\Phase04MySqlGrantPolicy;
use Tests\Support\Phase15MySqlTestTarget as Target;
use Tests\Support\Phase15OwnershipManifestPolicy;
use Tests\Support\Phase15ProbeEnvironment;
use Tests\Support\Phase15ServerIdentityPolicy;

require dirname(__DIR__).'/vendor/autoload.php';

$root = dirname(__DIR__);
$cachedConfig = $root.DIRECTORY_SEPARATOR.'bootstrap'.DIRECTORY_SEPARATOR.'cache'.DIRECTORY_SEPARATOR.'config.php';
if (is_file($cachedConfig) || is_link($cachedConfig)) {
    fwrite(STDERR, "Cached Laravel configuration must be absent before Phase 15 database verification; no database was contacted.\n");
    exit(2);
}
$framework = $root.DIRECTORY_SEPARATOR.'storage'.DIRECTORY_SEPARATOR.'framework';
$manifestPath = $framework.DIRECTORY_SEPARATOR.Target::MANIFEST;
$intentPath = $framework.DIRECTORY_SEPARATOR.Target::INTENT;
$mode = $argv[1] ?? '';
if (! in_array($mode, ['--preflight', '--inspect-fixtures', '--run'], true)) {
    fwrite(STDERR, "Usage: php tests/run-phase15-mysql.php --preflight|--inspect-fixtures|--run\n");
    exit(2);
}
if ($mode === '--run' && ! is_file($root.DIRECTORY_SEPARATOR.'tests'.DIRECTORY_SEPARATOR.'Support'.DIRECTORY_SEPARATOR.'phase15-analytics-concurrency.php')) {
    fwrite(STDERR, "Phase 15 independent-session concurrency probes are incomplete; no database was contacted.\n");
    exit(2);
}
try {
    $environment = CentralTestingEnvironment::mysqlPhase($root, '15', Target::DATABASE, Target::ACCOUNT, Target::AUTHORIZATION, requireDisposable: true, allowAlternateLoopbackPort: true);
    $expectedServerUuid = $environment['TESTING_MYSQL_PHASE15_SERVER_UUID'] ?? '';
    Phase15ServerIdentityPolicy::assertExpected($expectedServerUuid, $expectedServerUuid);
    $expectedDataDirectory = $environment['TESTING_MYSQL_PHASE15_DATA_DIRECTORY'] ?? '';
    if ($expectedDataDirectory === '' || ! is_dir($expectedDataDirectory)) {
        throw new RuntimeException('A locally provisioned and owner-pinned Phase 15 MySQL data directory is required.');
    }
} catch (RuntimeException $exception) {
    fwrite(STDERR, $exception->getMessage()." No database was contacted.\n");
    exit(2);
}
$environment['TESTING_PROFILE'] = 'mysql';
$environment['TESTING_PHASE'] = '15';
$lockPath = $framework.DIRECTORY_SEPARATOR.Target::LOCK_FILE;
$lock = @fopen($lockPath, 'c');
if (! is_resource($lock) || ! flock($lock, LOCK_EX | LOCK_NB)) {
    fwrite(STDERR, "Another Phase 15 runner holds the local exclusive lock.\n");
    exit(2);
}
$pdo = null;
$serverLock = false;
try {
    $pdo = new PDO('mysql:host=127.0.0.1;port='.$environment['DB_PORT'].';dbname='.Target::DATABASE.';charset=utf8mb4', $environment['DB_USERNAME'], $environment['DB_PASSWORD'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false]);
    $identity = $pdo->query('SELECT DATABASE() AS db,CURRENT_USER() AS account,@@port AS port,@@version AS version,@@server_uuid AS uuid,@@datadir AS data_directory')->fetch();
    [$username, $host] = array_pad(explode('@', trim((string) $identity['account'], "`'\""), 2), 2, '');
    $host = trim($host, "`'\"");
    Phase04MySqlGrantPolicy::assertIdentity((string) $identity['db'], $username, $host, Target::DATABASE, Target::ACCOUNT);
    if ($host !== '127.0.0.1') {
        throw new RuntimeException('Phase 15 MySQL tests require the exact 127.0.0.1 account, not a host fallback.');
    }
    Phase15ServerIdentityPolicy::assertExpected((string) $identity['uuid'], $environment['TESTING_MYSQL_PHASE15_SERVER_UUID'] ?? '');
    Phase15ServerIdentityPolicy::assertDataDirectory((string) $identity['data_directory'], $expectedDataDirectory);
    $canonicalDataDirectory = realpath((string) $identity['data_directory']);
    if ($canonicalDataDirectory === false || (int) $identity['port'] !== (int) $environment['DB_PORT'] || ! str_starts_with((string) $identity['version'], '8.4.')) {
        throw new RuntimeException('Phase 15 integration tests require the dedicated local MySQL 8.4 instance.');
    }
    Phase04MySqlGrantPolicy::assertAllowed(array_map('strval', $pdo->query('SHOW GRANTS')->fetchAll(PDO::FETCH_COLUMN)), $username, $host, Target::DATABASE);
    $advisory = $pdo->prepare('SELECT GET_LOCK(:lock_name,0)');
    $advisory->execute(['lock_name' => Target::SERVER_LOCK]);
    if ((int) $advisory->fetchColumn() !== 1) {
        throw new RuntimeException('A competing Phase 15 runner holds the MySQL advisory lock.');
    }
    $serverLock = true;
    $before = Phase15OwnershipManifestPolicy::snapshot($pdo);
    if ($before !== Phase15OwnershipManifestPolicy::snapshot($pdo)) {
        throw new RuntimeException('Phase 15 schema changed during preflight.');
    }
    if (is_link($manifestPath) || is_link($intentPath)) {
        throw new RuntimeException('Phase 15 ownership evidence may not be a symlink.');
    }
    if (is_file($intentPath)) {
        throw new RuntimeException('Prior Phase 15 migration intent exists; preserve the database for read-only recovery review.');
    }
    $manifest = null;
    if (is_file($manifestPath)) {
        $manifest = json_decode((string) file_get_contents($manifestPath), true);
        Phase15OwnershipManifestPolicy::assertOwned(is_array($manifest) ? $manifest : null, (string) $identity['uuid'], $canonicalDataDirectory, $before['fingerprint'], $before['objects'], $before['migrations']);
    } elseif ($before['objects'] !== [] || $before['migrations'] !== []) {
        throw new RuntimeException('Unowned Phase 15 schema is nonempty; no changes were made.');
    }
    $migrations = expectedMigrations($root);
    $expectedNames = array_map(static fn (string $file): string => basename($file, '.php'), $migrations);
    $appliedNames = array_column($before['migrations'], 'migration');
    if ($manifest !== null && array_column((array) ($manifest['migrations'] ?? []), 'migration') !== $appliedNames) {
        throw new RuntimeException('Phase 15 manifest migration inventory differs from the current schema; no migration ran.');
    }
    if (array_slice($expectedNames, 0, count($appliedNames)) !== $appliedNames) {
        throw new RuntimeException('Current Phase 15 migrations are not an exact prefix of the reviewed migration inventory.');
    }
    $initial = $manifest === null;
    $pending = $initial ? $migrations : array_slice($migrations, count($appliedNames));
    if (! $initial && count($pending) > 1) {
        throw new RuntimeException('Phase 15 upgrades are limited to one reviewed additive migration per run.');
    }
    if ($mode === '--inspect-fixtures') {
        $fixtures = $pdo->query("SELECT id,created_at FROM tenants WHERE slug REGEXP '^phase15-concurrency-[0-9a-f]{32}$' ORDER BY id")->fetchAll();
        fwrite(STDOUT, 'Phase 15 read-only fixture inventory passed: database='.Target::DATABASE.' tenants='.count($fixtures).PHP_EOL);
        foreach ($fixtures as $fixture) {
            fwrite(STDOUT, 'owned_harness_tenant_id='.(int) $fixture['id'].' created_at='.$fixture['created_at'].PHP_EOL);
        }
        exit(0);
    }
    if ($mode === '--preflight') {
        fwrite(STDOUT, sprintf("Phase 15 read-only preflight passed: database=%s account=%s@%s MySQL=%s port=%d server_uuid=%s data_directory=%s objects=%d migrations=%d pending_reviewed_migrations=%d.\n", Target::DATABASE, $username, $host, $identity['version'], (int) $identity['port'], $identity['uuid'], $canonicalDataDirectory, count($before['objects']), count($before['migrations']), count($pending)));
        exit(0);
    }
    if ($pending !== []) {
        $migrationFiles = array_map(static fn (string $file): array => ['name' => basename($file, '.php'), 'sha256' => hash_file('sha256', $file)], $pending);
        $backupPath = null;
        if (! $initial) {
            $backupPath = $manifestPath.'.pre-migration-'.gmdate('Ymd\THis\Z').'-'.bin2hex(random_bytes(4)).'.json';
            writeExclusive($backupPath, (string) file_get_contents($manifestPath));
            if (! hash_equals(hash('sha256', (string) file_get_contents($manifestPath)), hash_file('sha256', $backupPath))) {
                throw new RuntimeException('Phase 15 pre-migration ownership backup verification failed.');
            }
        }
        $intent = ['database' => Target::DATABASE, 'account' => $username.'@'.$host, 'server_uuid' => $identity['uuid'], 'data_directory' => $canonicalDataDirectory, 'operation' => $initial ? 'initial_provisioning' : 'authorized_upgrade', 'initial_fingerprint' => $before['fingerprint'], 'previous_migrations' => $before['migrations'], 'previous_manifest_backup' => $backupPath === null ? null : basename($backupPath), 'migration_cutoff' => Target::MIGRATION_CUTOFF, 'migration_files' => $migrationFiles, 'authorization' => Target::AUTHORIZATION, 'created_at_utc' => gmdate(DATE_ATOM)];
        writeExclusive($intentPath, json_encode($intent, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
        installEnvironment($environment);
        foreach ($pending as $file) {
            $migrationName = basename($file, '.php');
            $approvedHash = null;
            foreach ($migrationFiles as $migrationFile) {
                if ($migrationFile['name'] === $migrationName) {
                    $approvedHash = $migrationFile['sha256'];
                    break;
                }
            }
            if (! is_string($approvedHash) || ! hash_equals($approvedHash, (string) hash_file('sha256', $file))) {
                throw new RuntimeException('A reviewed Phase 15 migration changed after intent was recorded; preserve the schema for read-only review.');
            }
            passthru(escapeshellarg(PHP_BINARY).' '.escapeshellarg($root.DIRECTORY_SEPARATOR.'artisan').' migrate --force --no-interaction --database=mysql --path='.escapeshellarg('database/migrations/'.basename($file)), $status);
            if ($status !== 0) {
                throw new RuntimeException('Authorized Phase 15 migration stopped; intent is preserved for read-only recovery review.');
            }
        }
        $after = Phase15OwnershipManifestPolicy::snapshot($pdo);
        if (array_column($after['migrations'], 'migration') !== $expectedNames) {
            throw new RuntimeException('Post-migration ledger differs from the reviewed Phase 15 inventory.');
        }
        $newManifest = json_encode(['database' => Target::DATABASE, 'account' => $username.'@'.$host, 'server_uuid' => $identity['uuid'], 'data_directory' => $canonicalDataDirectory, 'fingerprint' => $after['fingerprint'], 'objects' => $after['objects'], 'migrations' => $after['migrations'], 'authorization' => Target::AUTHORIZATION, 'attested_at_utc' => gmdate(DATE_ATOM)], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);
        if ($initial) {
            writeExclusive($manifestPath, $newManifest);
        } else {
            if (! is_file($backupPath) || is_link($backupPath)) {
                throw new RuntimeException('Phase 15 upgrade has no verified ownership backup; preserve schema for review.');
            }
            if (file_put_contents($manifestPath, $newManifest, LOCK_EX) !== strlen($newManifest)) {
                throw new RuntimeException('Phase 15 upgrade manifest could not be replaced after its verified backup.');
            }
            $updatedManifest = json_decode((string) file_get_contents($manifestPath), true);
            Phase15OwnershipManifestPolicy::assertOwned(is_array($updatedManifest) ? $updatedManifest : null, (string) $identity['uuid'], $canonicalDataDirectory, $after['fingerprint'], $after['objects'], $after['migrations']);
        }
        if (! rename($intentPath, $intentPath.'.completed-'.gmdate('Ymd\THis\Z').'-'.bin2hex(random_bytes(3)).'.json')) {
            throw new RuntimeException('Phase 15 migration and manifest were verified but intent could not be archived; preserve schema for review.');
        }
    } else {
        installEnvironment($environment);
    }
    putenv('PHASE15_MYSQL_PREFLIGHT_PASSED=true');
    $_ENV['PHASE15_MYSQL_PREFLIGHT_PASSED'] = $_SERVER['PHASE15_MYSQL_PREFLIGHT_PASSED'] = 'true';
    $childEnvironment = Phase15ProbeEnvironment::current();
    $process = proc_open([PHP_BINARY, $root.DIRECTORY_SEPARATOR.'artisan', 'test', '--filter=Phase15'], [0 => STDIN, 1 => STDOUT, 2 => STDERR], $pipes, $root, $childEnvironment, ['bypass_shell' => true]);
    if (! is_resource($process)) {
        throw new RuntimeException('Could not start guarded Phase 15 MySQL feature tests.');
    }
    $testStatus = proc_close($process);
    if ($testStatus !== 0) {
        exit($testStatus);
    }
    $probe = proc_open([PHP_BINARY, $root.DIRECTORY_SEPARATOR.'tests'.DIRECTORY_SEPARATOR.'Support'.DIRECTORY_SEPARATOR.'phase15-analytics-concurrency.php'], [0 => STDIN, 1 => STDOUT, 2 => STDERR], $probePipes, $root, $childEnvironment, ['bypass_shell' => true]);
    if (! is_resource($probe)) {
        throw new RuntimeException('Could not start guarded Phase 15 independent-session concurrency probes.');
    }
    $probeStatus = proc_close($probe);
    if ($probeStatus !== 0) {
        exit($probeStatus);
    }
    $after = Phase15OwnershipManifestPolicy::snapshot($pdo);
    $currentManifest = json_decode((string) file_get_contents($manifestPath), true);
    Phase15OwnershipManifestPolicy::assertOwned(is_array($currentManifest) ? $currentManifest : null, (string) $identity['uuid'], $canonicalDataDirectory, $after['fingerprint'], $after['objects'], $after['migrations']);
    fwrite(STDOUT, sprintf("Phase 15 guarded MySQL checks passed. database=%s account=%s@%s MySQL=%s objects=%d migrations=%d fingerprint=%s\n", Target::DATABASE, $username, $host, $identity['version'], count($after['objects']), count($after['migrations']), $after['fingerprint']));
} catch (Throwable $error) {
    fwrite(STDERR, 'Phase 15 guarded runner refused or failed: '.$error->getMessage().PHP_EOL);
    exit(2);
} finally {
    if ($serverLock && $pdo instanceof PDO) {
        try {
            $release = $pdo->prepare('SELECT RELEASE_LOCK(?)');
            $release->execute([Target::SERVER_LOCK]);
        } catch (Throwable) {
        }
    }
    flock($lock, LOCK_UN);
    fclose($lock);
}

/** @return list<string> */
function expectedMigrations(string $root): array
{
    $files = glob($root.DIRECTORY_SEPARATOR.'database'.DIRECTORY_SEPARATOR.'migrations'.DIRECTORY_SEPARATOR.'*.php') ?: [];
    $files = array_values(array_filter($files, static fn (string $file): bool => basename($file, '.php') <= Target::MIGRATION_CUTOFF));
    sort($files, SORT_STRING);
    if ($files === [] || basename(end($files), '.php') !== Target::MIGRATION_CUTOFF) {
        throw new RuntimeException('Reviewed Phase 15 migration cutoff is not present.');
    }

    return $files;
}

/** @param array<string,string> $environment */
function installEnvironment(array $environment): void
{
    CentralTestingEnvironment::install($environment);
}

/** Writes only when the path does not exist. */
function writeExclusive(string $path, string $contents): void
{
    $handle = @fopen($path, 'x');
    if (! is_resource($handle)) {
        throw new RuntimeException('Phase 15 ownership evidence already exists; no file was overwritten.');
    }
    try {
        if (fwrite($handle, $contents) !== strlen($contents) || ! fflush($handle)) {
            throw new RuntimeException('Could not persist Phase 15 migration evidence.');
        }
    } finally {
        fclose($handle);
    }
}
