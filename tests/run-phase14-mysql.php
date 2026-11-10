<?php

declare(strict_types=1);

use Dotenv\Dotenv;
use Tests\Support\Phase04MySqlGrantPolicy;
use Tests\Support\Phase14MySqlTestTarget as Target;
use Tests\Support\Phase14OwnershipManifestPolicy;
use Tests\Support\Phase14ProbeEnvironment;

require dirname(__DIR__).'/vendor/autoload.php';

$root = dirname(__DIR__);
$framework = $root.DIRECTORY_SEPARATOR.'storage'.DIRECTORY_SEPARATOR.'framework';
$environmentPath = $root.DIRECTORY_SEPARATOR.Target::CONFIGURATION;
$manifestPath = $framework.DIRECTORY_SEPARATOR.Target::MANIFEST;
$intentPath = $framework.DIRECTORY_SEPARATOR.Target::INTENT;
$mode = $argv[1] ?? '';
if (! in_array($mode, ['--preflight', '--run'], true)) {
    fwrite(STDERR, "Usage: php tests/run-phase14-mysql.php --preflight|--run\n");
    exit(2);
}
if ($mode === '--run' && ! is_file($root.DIRECTORY_SEPARATOR.'tests'.DIRECTORY_SEPARATOR.'Support'.DIRECTORY_SEPARATOR.'phase14-grade-concurrency.php')) {
    fwrite(STDERR, "Phase 14 independent-session concurrency probes are incomplete; no database was contacted.\n");
    exit(2);
}
if (! is_file($environmentPath) || is_link($environmentPath)) {
    fwrite(STDERR, 'Missing regular private configuration '.Target::CONFIGURATION."; no database was contacted.\n");
    exit(2);
}
$ignoreCheck = proc_open(['git', 'check-ignore', '--quiet', '--', Target::CONFIGURATION], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $root);
if (! is_resource($ignoreCheck)) {
    fwrite(STDERR, "Could not verify private environment ignore status.\n");
    exit(2);
}
foreach ($pipes as $pipe) {
    fclose($pipe);
}
if (proc_close($ignoreCheck) !== 0) {
    fwrite(STDERR, 'Phase 14 private environment must be Git-ignored.'.PHP_EOL);
    exit(2);
}
try {
    $environment = Dotenv::parse((string) file_get_contents($environmentPath));
} catch (Throwable) {
    fwrite(STDERR, "Phase 14 private environment could not be parsed.\n");
    exit(2);
}
$required = [
    'APP_ENV' => 'testing', 'DB_CONNECTION' => 'mysql', 'DB_HOST' => '127.0.0.1', 'DB_PORT' => '3306',
    'DB_DATABASE' => Target::DATABASE, 'DB_USERNAME' => Target::ACCOUNT,
    'PHASE14_TEST_SCHEMA_DISPOSABLE' => 'true', 'PHASE14_TEST_SCHEMA_AUTHORIZATION' => Target::AUTHORIZATION,
];
foreach ($required as $key => $value) {
    if (($environment[$key] ?? '') !== $value) {
        fwrite(STDERR, "Phase 14 target/authorization check failed for {$key}; no database changes were made.\n");
        exit(2);
    }
}
if (trim((string) ($environment['DB_PASSWORD'] ?? '')) === '' || trim((string) ($environment['DB_PASSWORD'] ?? '')) === 'replace-locally' || ($environment['DB_URL'] ?? '') !== '') {
    fwrite(STDERR, "Phase 14 database credentials are missing or DB_URL is set; no database changes were made.\n");
    exit(2);
}
$lockPath = $framework.DIRECTORY_SEPARATOR.Target::LOCK_FILE;
$lock = @fopen($lockPath, 'c');
if (! is_resource($lock) || ! flock($lock, LOCK_EX | LOCK_NB)) {
    fwrite(STDERR, "Another Phase 14 runner holds the local exclusive lock.\n");
    exit(2);
}
$pdo = null;
$serverLock = false;
try {
    $pdo = new PDO('mysql:host=127.0.0.1;port=3306;dbname='.Target::DATABASE.';charset=utf8mb4', $environment['DB_USERNAME'], $environment['DB_PASSWORD'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false]);
    $identity = $pdo->query('SELECT DATABASE() AS db,CURRENT_USER() AS account,@@port AS port,@@version AS version,@@server_uuid AS uuid')->fetch();
    [$username, $host] = array_pad(explode('@', trim((string) $identity['account'], "`'\""), 2), 2, '');
    $host = trim($host, "`'\"");
    Phase04MySqlGrantPolicy::assertIdentity((string) $identity['db'], $username, $host, Target::DATABASE, Target::ACCOUNT);
    if ($host !== '127.0.0.1') {
        throw new RuntimeException('Phase 14 MySQL tests require the exact 127.0.0.1 account, not a host fallback.');
    }
    if ((int) $identity['port'] !== 3306 || ! str_starts_with((string) $identity['version'], '8.4.')) {
        throw new RuntimeException('Phase 14 integration tests require the dedicated local MySQL 8.4 instance.');
    }
    Phase04MySqlGrantPolicy::assertAllowed(array_map('strval', $pdo->query('SHOW GRANTS')->fetchAll(PDO::FETCH_COLUMN)), $username, $host, Target::DATABASE);
    $advisory = $pdo->prepare('SELECT GET_LOCK(:lock_name,0)');
    $advisory->execute(['lock_name' => Target::SERVER_LOCK]);
    if ((int) $advisory->fetchColumn() !== 1) {
        throw new RuntimeException('A competing Phase 14 runner holds the MySQL advisory lock.');
    }
    $serverLock = true;
    $before = Phase14OwnershipManifestPolicy::snapshot($pdo);
    if ($before !== Phase14OwnershipManifestPolicy::snapshot($pdo)) {
        throw new RuntimeException('Phase 14 schema changed during preflight.');
    }
    if (is_link($manifestPath) || is_link($intentPath)) {
        throw new RuntimeException('Phase 14 ownership evidence may not be a symlink.');
    }
    if (is_file($intentPath)) {
        throw new RuntimeException('Prior Phase 14 migration intent exists; preserve the database for read-only recovery review.');
    }
    $manifest = null;
    if (is_file($manifestPath)) {
        $manifest = json_decode((string) file_get_contents($manifestPath), true);
        Phase14OwnershipManifestPolicy::assertOwned(is_array($manifest) ? $manifest : null, (string) $identity['uuid'], $before['fingerprint'], $before['objects'], $before['migrations']);
    } elseif ($before['objects'] !== [] || $before['migrations'] !== []) {
        throw new RuntimeException('Unowned Phase 14 schema is nonempty; no changes were made.');
    }
    $migrations = expectedMigrations($root);
    $expectedNames = array_map(static fn (string $file): string => basename($file, '.php'), $migrations);
    $appliedNames = array_column($before['migrations'], 'migration');
    if ($manifest !== null && array_column((array) ($manifest['migrations'] ?? []), 'migration') !== $appliedNames) {
        throw new RuntimeException('Phase 14 manifest migration inventory differs from the current schema; no migration ran.');
    }
    if (array_slice($expectedNames, 0, count($appliedNames)) !== $appliedNames) {
        throw new RuntimeException('Current Phase 14 migrations are not an exact prefix of the reviewed migration inventory.');
    }
    $initial = $manifest === null;
    $pending = $initial ? $migrations : array_slice($migrations, count($appliedNames));
    if (! $initial && count($pending) > 1) {
        throw new RuntimeException('Phase 14 upgrades are limited to one reviewed additive migration per run.');
    }
    if ($mode === '--preflight') {
        fwrite(STDOUT, sprintf("Phase 14 read-only preflight passed: database=%s account=%s@%s MySQL=%s objects=%d migrations=%d pending_reviewed_migrations=%d.\n", Target::DATABASE, $username, $host, $identity['version'], count($before['objects']), count($before['migrations']), count($pending)));
        exit(0);
    }
    if ($pending !== []) {
        $migrationFiles = array_map(static fn (string $file): array => ['name' => basename($file, '.php'), 'sha256' => hash_file('sha256', $file)], $pending);
        $backupPath = null;
        if (! $initial) {
            $backupPath = $manifestPath.'.pre-migration-'.gmdate('Ymd\THis\Z').'-'.bin2hex(random_bytes(4)).'.json';
            writeExclusive($backupPath, (string) file_get_contents($manifestPath));
            if (! hash_equals(hash('sha256', (string) file_get_contents($manifestPath)), hash_file('sha256', $backupPath))) {
                throw new RuntimeException('Phase 14 pre-migration ownership backup verification failed.');
            }
        }
        $intent = ['database' => Target::DATABASE, 'account' => $username.'@'.$host, 'server_uuid' => $identity['uuid'], 'operation' => $initial ? 'initial_provisioning' : 'authorized_upgrade', 'initial_fingerprint' => $before['fingerprint'], 'previous_migrations' => $before['migrations'], 'previous_manifest_backup' => $backupPath === null ? null : basename($backupPath), 'migration_cutoff' => Target::MIGRATION_CUTOFF, 'migration_files' => $migrationFiles, 'authorization' => Target::AUTHORIZATION, 'created_at_utc' => gmdate(DATE_ATOM)];
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
                throw new RuntimeException('A reviewed Phase 14 migration changed after intent was recorded; preserve the schema for read-only review.');
            }
            passthru(escapeshellarg(PHP_BINARY).' '.escapeshellarg($root.DIRECTORY_SEPARATOR.'artisan').' migrate --force --no-interaction --database=mysql --path='.escapeshellarg('database/migrations/'.basename($file)), $status);
            if ($status !== 0) {
                throw new RuntimeException('Authorized Phase 14 migration stopped; intent is preserved for read-only recovery review.');
            }
        }
        $after = Phase14OwnershipManifestPolicy::snapshot($pdo);
        if (array_column($after['migrations'], 'migration') !== $expectedNames) {
            throw new RuntimeException('Post-migration ledger differs from the reviewed Phase 14 inventory.');
        }
        $newManifest = json_encode(['database' => Target::DATABASE, 'account' => $username.'@'.$host, 'server_uuid' => $identity['uuid'], 'fingerprint' => $after['fingerprint'], 'objects' => $after['objects'], 'migrations' => $after['migrations'], 'authorization' => Target::AUTHORIZATION, 'attested_at_utc' => gmdate(DATE_ATOM)], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);
        if ($initial) {
            writeExclusive($manifestPath, $newManifest);
        } else {
            if (! is_file($backupPath) || is_link($backupPath)) {
                throw new RuntimeException('Phase 14 upgrade has no verified ownership backup; preserve schema for review.');
            }
            if (file_put_contents($manifestPath, $newManifest, LOCK_EX) !== strlen($newManifest)) {
                throw new RuntimeException('Phase 14 upgrade manifest could not be replaced after its verified backup.');
            }
            $updatedManifest = json_decode((string) file_get_contents($manifestPath), true);
            Phase14OwnershipManifestPolicy::assertOwned(is_array($updatedManifest) ? $updatedManifest : null, (string) $identity['uuid'], $after['fingerprint'], $after['objects'], $after['migrations']);
        }
        if (! rename($intentPath, $intentPath.'.completed-'.gmdate('Ymd\THis\Z').'-'.bin2hex(random_bytes(3)).'.json')) {
            throw new RuntimeException('Phase 14 migration and manifest were verified but intent could not be archived; preserve schema for review.');
        }
    } else {
        installEnvironment($environment);
    }
    putenv('PHASE14_MYSQL_PREFLIGHT_PASSED=true');
    $_ENV['PHASE14_MYSQL_PREFLIGHT_PASSED'] = $_SERVER['PHASE14_MYSQL_PREFLIGHT_PASSED'] = 'true';
    $childEnvironment = Phase14ProbeEnvironment::current();
    $process = proc_open([PHP_BINARY, $root.DIRECTORY_SEPARATOR.'artisan', 'test', '--filter=Phase14'], [0 => STDIN, 1 => STDOUT, 2 => STDERR], $pipes, $root, $childEnvironment, ['bypass_shell' => true]);
    if (! is_resource($process)) {
        throw new RuntimeException('Could not start guarded Phase 14 MySQL feature tests.');
    }
    $testStatus = proc_close($process);
    if ($testStatus !== 0) {
        exit($testStatus);
    }
    $probe = proc_open([PHP_BINARY, $root.DIRECTORY_SEPARATOR.'tests'.DIRECTORY_SEPARATOR.'Support'.DIRECTORY_SEPARATOR.'phase14-grade-concurrency.php'], [0 => STDIN, 1 => STDOUT, 2 => STDERR], $probePipes, $root, $childEnvironment, ['bypass_shell' => true]);
    if (! is_resource($probe)) {
        throw new RuntimeException('Could not start guarded Phase 14 independent-session concurrency probes.');
    }
    $probeStatus = proc_close($probe);
    if ($probeStatus !== 0) {
        exit($probeStatus);
    }
    $after = Phase14OwnershipManifestPolicy::snapshot($pdo);
    $currentManifest = json_decode((string) file_get_contents($manifestPath), true);
    Phase14OwnershipManifestPolicy::assertOwned(is_array($currentManifest) ? $currentManifest : null, (string) $identity['uuid'], $after['fingerprint'], $after['objects'], $after['migrations']);
    fwrite(STDOUT, sprintf("Phase 14 guarded MySQL checks passed. database=%s account=%s@%s MySQL=%s objects=%d migrations=%d fingerprint=%s\n", Target::DATABASE, $username, $host, $identity['version'], count($after['objects']), count($after['migrations']), $after['fingerprint']));
} catch (Throwable $error) {
    fwrite(STDERR, 'Phase 14 guarded runner refused or failed: '.$error->getMessage().PHP_EOL);
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
        throw new RuntimeException('Reviewed Phase 14 migration cutoff is not present.');
    }

    return $files;
}

/** @param array<string,string> $environment */
function installEnvironment(array $environment): void
{
    foreach ($environment as $key => $value) {
        putenv($key.'='.$value);
        $_ENV[$key] = $_SERVER[$key] = $value;
    }
    putenv('DB_URL=');
    $_ENV['DB_URL'] = $_SERVER['DB_URL'] = '';
}

/** Writes only when the path does not exist. */
function writeExclusive(string $path, string $contents): void
{
    $handle = @fopen($path, 'x');
    if (! is_resource($handle)) {
        throw new RuntimeException('Phase 14 ownership evidence already exists; no file was overwritten.');
    }
    try {
        if (fwrite($handle, $contents) !== strlen($contents) || ! fflush($handle)) {
            throw new RuntimeException('Could not persist Phase 14 migration evidence.');
        }
    } finally {
        fclose($handle);
    }
}
