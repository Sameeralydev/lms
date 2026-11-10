<?php

declare(strict_types=1);

use Dotenv\Dotenv;
use Tests\Support\Phase04MySqlGrantPolicy;
use Tests\Support\Phase11MySqlTestTarget as Target;
use Tests\Support\Phase11OwnershipManifestPolicy;
use Tests\Support\Phase11ProbeEnvironment;

require dirname(__DIR__).'/vendor/autoload.php';

$root = dirname(__DIR__);
$framework = $root.DIRECTORY_SEPARATOR.'storage'.DIRECTORY_SEPARATOR.'framework';
$environmentPath = $root.DIRECTORY_SEPARATOR.Target::CONFIGURATION;
$manifestPath = $framework.DIRECTORY_SEPARATOR.Target::MANIFEST;
$intentPath = $framework.DIRECTORY_SEPARATOR.Target::INTENT;
$mode = $argv[1] ?? '';
if (! in_array($mode, ['--preflight', '--run', '--cleanup-fixture'], true)) {
    fwrite(STDERR, "Usage: php tests/run-phase11-mysql.php --preflight|--run|--cleanup-fixture <run-uuid>\n");
    exit(2);
}
if ($mode === '--cleanup-fixture' && ! preg_match('/\A[a-f0-9]{8}-(?:[a-f0-9]{4}-){3}[a-f0-9]{12}\z/iD', (string) ($argv[2] ?? ''))) {
    fwrite(STDERR, "Phase 11 fixture cleanup requires one exact run UUID.\n");
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
    fwrite(STDERR, "Phase 11 private environment file must be Git-ignored.\n");
    exit(2);
}
try {
    $environment = Dotenv::parse((string) file_get_contents($environmentPath));
} catch (Throwable) {
    fwrite(STDERR, "Phase 11 private environment file could not be parsed.\n");
    exit(2);
}
$required = [
    'APP_ENV' => 'testing', 'DB_CONNECTION' => 'mysql', 'DB_HOST' => '127.0.0.1', 'DB_PORT' => '3306',
    'DB_DATABASE' => Target::DATABASE, 'DB_USERNAME' => Target::ACCOUNT,
    'PHASE11_TEST_SCHEMA_DISPOSABLE' => 'true', 'PHASE11_TEST_SCHEMA_AUTHORIZATION' => Target::AUTHORIZATION,
];
foreach ($required as $key => $value) {
    if (($environment[$key] ?? '') !== $value) {
        fwrite(STDERR, "Phase 11 target/authorization check failed for {$key}; no database changes were made.\n");
        exit(2);
    }
}
if (trim((string) ($environment['DB_PASSWORD'] ?? '')) === ''
    || trim((string) ($environment['DB_PASSWORD'] ?? '')) === 'replace-locally'
    || ($environment['DB_URL'] ?? '') !== '') {
    fwrite(STDERR, "Phase 11 database credentials are missing or DB_URL is set; no database changes were made.\n");
    exit(2);
}
$lockPath = $framework.DIRECTORY_SEPARATOR.Target::LOCK_FILE;
$lock = @fopen($lockPath, 'c');
if (! is_resource($lock) || ! flock($lock, LOCK_EX | LOCK_NB)) {
    fwrite(STDERR, "Another Phase 11 runner holds the local exclusive lock.\n");
    exit(2);
}
$pdo = null;
$serverLock = false;
try {
    $pdo = new PDO('mysql:host=127.0.0.1;port=3306;dbname='.Target::DATABASE.';charset=utf8mb4', $environment['DB_USERNAME'], $environment['DB_PASSWORD'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false]);
    $identity = $pdo->query('SELECT DATABASE() AS db,CURRENT_USER() AS account,@@port AS port,@@version AS version,@@server_uuid AS uuid')->fetch();
    [$username, $host] = array_pad(explode('@', (string) $identity['account'], 2), 2, '');
    $host = trim($host, "`'\"");
    Phase04MySqlGrantPolicy::assertIdentity((string) $identity['db'], $username, $host, Target::DATABASE, Target::ACCOUNT);
    if ((int) $identity['port'] !== 3306 || ! str_starts_with((string) $identity['version'], '8.4.')) {
        throw new RuntimeException('Phase 11 requires the dedicated local MySQL 8.4 instance.');
    }
    Phase04MySqlGrantPolicy::assertAllowed(array_map('strval', $pdo->query('SHOW GRANTS')->fetchAll(PDO::FETCH_COLUMN)), $username, $host, Target::DATABASE);
    $advisory = $pdo->prepare('SELECT GET_LOCK(:lock_name,0)');
    $advisory->execute(['lock_name' => Target::SERVER_LOCK]);
    if ((int) $advisory->fetchColumn() !== 1) {
        throw new RuntimeException('A competing Phase 11 process holds the MySQL advisory lock.');
    }
    $serverLock = true;
    $before = Phase11OwnershipManifestPolicy::snapshot($pdo);
    $stable = Phase11OwnershipManifestPolicy::snapshot($pdo);
    if ($before !== $stable) {
        throw new RuntimeException('Phase 11 schema changed during preflight; no database changes were made.');
    }
    if (is_link($manifestPath) || is_link($intentPath)) {
        throw new RuntimeException('Phase 11 ownership evidence may not be a symlink.');
    }
    if (is_file($intentPath)) {
        throw new RuntimeException('A prior Phase 11 migration intent exists; preserve the schema for read-only recovery review.');
    }
    $manifest = null;
    if (is_file($manifestPath)) {
        $manifest = json_decode((string) file_get_contents($manifestPath), true);
        Phase11OwnershipManifestPolicy::assertOwned(is_array($manifest) ? $manifest : null, (string) $identity['uuid'], $stable['fingerprint'], $stable['objects'], $stable['migrations']);
    } elseif ($stable['objects'] !== [] || $stable['migrations'] !== []) {
        throw new RuntimeException('Unowned Phase 11 schema is nonempty; no changes were made.');
    }
    if ($mode === '--preflight') {
        fwrite(STDOUT, sprintf("Phase 11 read-only preflight passed: database=%s account=%s@%s MySQL=%s objects=%d migrations=%d; no schema/data/manifest changes.\n", Target::DATABASE, $username, $host, $identity['version'], count($stable['objects']), count($stable['migrations'])));
        exit(0);
    }
    $migrations = expectedMigrations($root);
    $expectedMigrationNames = array_map(static fn (string $file): string => basename($file, '.php'), $migrations);
    if ($mode === '--cleanup-fixture') {
        if ($manifest === null || array_column($stable['migrations'], 'migration') !== $expectedMigrationNames) {
            throw new RuntimeException('Fixture cleanup requires a complete currently owned Phase 11 schema.');
        }
        installEnvironment($environment);
        putenv('PHASE11_MYSQL_PREFLIGHT_PASSED=true');
        $_ENV['PHASE11_MYSQL_PREFLIGHT_PASSED'] = $_SERVER['PHASE11_MYSQL_PREFLIGHT_PASSED'] = 'true';
        $command = [PHP_BINARY, $root.DIRECTORY_SEPARATOR.'tests'.DIRECTORY_SEPARATOR.'Support'.DIRECTORY_SEPARATOR.'phase11-assignment-concurrency.php', 'cleanup_fixture', strtolower((string) $argv[2])];
        $process = proc_open($command, [0 => STDIN, 1 => STDOUT, 2 => STDERR], $pipes, $root, null, ['bypass_shell' => true]);
        if (! is_resource($process)) {
            throw new RuntimeException('Could not start guarded Phase 11 fixture cleanup.');
        }
        $cleanupStatus = proc_close($process);
        if ($cleanupStatus !== 0) {
            throw new RuntimeException('Guarded Phase 11 fixture cleanup failed with exit code '.$cleanupStatus.'.');
        }
        $postflight = Phase11OwnershipManifestPolicy::snapshot($pdo);
        $manifest = json_decode((string) file_get_contents($manifestPath), true);
        Phase11OwnershipManifestPolicy::assertOwned(is_array($manifest) ? $manifest : null, (string) $identity['uuid'], $postflight['fingerprint'], $postflight['objects'], $postflight['migrations']);
        if ($postflight['fingerprint'] !== $stable['fingerprint']) {
            throw new RuntimeException('Fixture cleanup unexpectedly changed the owned schema fingerprint.');
        }
        fwrite(STDOUT, sprintf("Guarded Phase 11 fixture cleanup passed; schema fingerprint unchanged: %s\n", $postflight['fingerprint']));
        exit(0);
    }
    $isInitialProvisioning = ! is_file($manifestPath);
    $pendingMigrations = $migrations;
    $isForwardTransition = false;
    if (! $isInitialProvisioning) {
        $applied = array_column($stable['migrations'], 'migration');
        if ($applied !== $expectedMigrationNames) {
            if ($applied === [] || count($applied) >= count($expectedMigrationNames)
                || array_slice($expectedMigrationNames, 0, count($applied)) !== $applied) {
                throw new RuntimeException('Owned Phase 11 schema does not match an authorized migration prefix.');
            }
            $isForwardTransition = true;
            $pendingMigrations = array_slice($migrations, count($applied));
        }
    }
    if ($isInitialProvisioning || $isForwardTransition) {
        $intent = [
            'database' => Target::DATABASE, 'account' => $username.'@'.$host, 'server_uuid' => $identity['uuid'],
            'operation' => $isInitialProvisioning ? 'initial_provisioning' : 'forward_migration',
            'initial_fingerprint' => $stable['fingerprint'], 'previous_manifest_sha256' => $isForwardTransition ? hash_file('sha256', $manifestPath) : null,
            'migration_cutoff' => Target::MIGRATION_CUTOFF,
            'migration_files' => array_map(static fn (string $file): array => ['name' => basename($file, '.php'), 'sha256' => hash_file('sha256', $file)], $pendingMigrations),
            'authorization' => Target::AUTHORIZATION, 'created_at_utc' => gmdate(DATE_ATOM),
        ];
        writeExclusive($intentPath, json_encode($intent, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
        installEnvironment($environment);
        foreach ($pendingMigrations as $file) {
            passthru(escapeshellarg(PHP_BINARY).' '.escapeshellarg($root.DIRECTORY_SEPARATOR.'artisan').' migrate --force --no-interaction --database=mysql --path='.escapeshellarg('database/migrations/'.basename($file)), $status);
            if ($status !== 0) {
                throw new RuntimeException('Authorized Phase 11 migration stopped; intent is preserved for read-only recovery review.');
            }
        }
        $after = Phase11OwnershipManifestPolicy::snapshot($pdo);
        if (array_column($after['migrations'], 'migration') !== $expectedMigrationNames) {
            throw new RuntimeException('Post-migration ledger differs from the reviewed Phase 11 migration inventory.');
        }
        $newManifest = json_encode([
            'database' => Target::DATABASE, 'account' => $username.'@'.$host, 'server_uuid' => $identity['uuid'],
            'fingerprint' => $after['fingerprint'], 'objects' => $after['objects'], 'migrations' => $after['migrations'],
            'authorization' => Target::AUTHORIZATION, 'attested_at_utc' => gmdate(DATE_ATOM),
        ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);
        if ($isForwardTransition) {
            Phase11OwnershipManifestPolicy::assertForwardMigrationTransition($stable, $after, $expectedMigrationNames, Target::FORWARD_MIGRATION_NEW_OBJECTS, Target::FORWARD_MIGRATION_MODIFIED_OBJECTS);
            $backupPath = $manifestPath.'.previous-'.gmdate('Ymd-His').'-'.bin2hex(random_bytes(3));
            writeExclusive($backupPath, (string) file_get_contents($manifestPath));
            $temporaryManifest = $manifestPath.'.next-'.bin2hex(random_bytes(8));
            writeExclusive($temporaryManifest, $newManifest);
            if (! rename($temporaryManifest, $manifestPath)) {
                @unlink($temporaryManifest);
                throw new RuntimeException('Verified Phase 11 ownership manifest could not be atomically advanced.');
            }
        } else {
            writeExclusive($manifestPath, $newManifest);
        }
        if (! unlink($intentPath)) {
            throw new RuntimeException('Migrations succeeded but intent could not be closed; preserve schema for review.');
        }
    } else {
        $applied = array_column($stable['migrations'], 'migration');
        if ($applied !== array_map(static fn (string $file): string => basename($file, '.php'), $migrations)) {
            throw new RuntimeException('Owned Phase 11 schema does not match the reviewed migration inventory.');
        }
        installEnvironment($environment);
    }
    putenv('PHASE11_MYSQL_PREFLIGHT_PASSED=true');
    $_ENV['PHASE11_MYSQL_PREFLIGHT_PASSED'] = $_SERVER['PHASE11_MYSQL_PREFLIGHT_PASSED'] = 'true';
    $command = [PHP_BINARY, $root.DIRECTORY_SEPARATOR.'artisan', 'test', 'tests/Feature/AssignmentManagementTest.php'];
    $process = proc_open($command, [0 => STDIN, 1 => STDOUT, 2 => STDERR], $pipes, $root);
    if (! is_resource($process)) {
        throw new RuntimeException('Could not start Phase 11 MySQL integration tests.');
    }
    $testStatus = proc_close($process);
    if ($testStatus !== 0) {
        exit($testStatus);
    }
    $probeCommand = [PHP_BINARY, $root.DIRECTORY_SEPARATOR.'tests'.DIRECTORY_SEPARATOR.'Support'.DIRECTORY_SEPARATOR.'phase11-assignment-concurrency.php'];
    $probeProcess = proc_open($probeCommand, [0 => STDIN, 1 => STDOUT, 2 => STDERR], $probePipes, $root, Phase11ProbeEnvironment::current(), ['bypass_shell' => true]);
    if (! is_resource($probeProcess)) {
        throw new RuntimeException('Could not start guarded Phase 11 independent-session concurrency probes.');
    }
    $probeStatus = proc_close($probeProcess);
    if ($probeStatus !== 0) {
        throw new RuntimeException('Guarded Phase 11 independent-session concurrency probes failed with exit code '.$probeStatus.'.');
    }
    $postflight = Phase11OwnershipManifestPolicy::snapshot($pdo);
    $manifest = json_decode((string) file_get_contents($manifestPath), true);
    Phase11OwnershipManifestPolicy::assertOwned(is_array($manifest) ? $manifest : null, (string) $identity['uuid'], $postflight['fingerprint'], $postflight['objects'], $postflight['migrations']);
    fwrite(STDOUT, sprintf("Phase 11 guarded MySQL tests passed. database=%s account=%s@%s MySQL=%s objects=%d migrations=%d fingerprint=%s\n", Target::DATABASE, $username, $host, $identity['version'], count($postflight['objects']), count($postflight['migrations']), $postflight['fingerprint']));
} catch (Throwable $error) {
    fwrite(STDERR, 'Phase 11 guarded runner refused or failed: '.$error->getMessage().PHP_EOL);
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
        throw new RuntimeException('Reviewed Phase 11 migration cutoff is not present in the repository.');
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

function writeExclusive(string $path, string $contents): void
{
    $handle = @fopen($path, 'x');
    if (! is_resource($handle)) {
        throw new RuntimeException('Phase 11 evidence already exists; no file was overwritten.');
    }
    try {
        if (fwrite($handle, $contents) !== strlen($contents) || ! fflush($handle)) {
            throw new RuntimeException('Could not persist Phase 11 migration evidence.');
        }
    } finally {
        fclose($handle);
    }
}
