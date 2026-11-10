<?php

declare(strict_types=1);

use Dotenv\Dotenv;
use Tests\Support\Phase04MySqlGrantPolicy;
use Tests\Support\Phase09MySqlTestTarget as Target;
use Tests\Support\Phase09OwnershipManifestPolicy;

require dirname(__DIR__).'/vendor/autoload.php';

$root = dirname(__DIR__);
$framework = $root.DIRECTORY_SEPARATOR.'storage'.DIRECTORY_SEPARATOR.'framework';
$envPath = $root.DIRECTORY_SEPARATOR.Target::CONFIGURATION;
$manifestPath = $framework.DIRECTORY_SEPARATOR.Target::MANIFEST;
$intentPath = $framework.DIRECTORY_SEPARATOR.Target::INTENT;
$mode = $argv[1] ?? '';
if (! in_array($mode, ['--preflight', '--run'], true)) {
    fwrite(STDERR, "Usage: php tests/run-phase09-mysql.php --preflight|--run\n");
    exit(2);
}
if (! is_file($envPath) || is_link($envPath)) {
    fwrite(STDERR, 'Missing regular private configuration '.Target::CONFIGURATION."; no database was contacted.\n");
    exit(2);
}
$check = proc_open(['git', 'check-ignore', '--quiet', '--', Target::CONFIGURATION], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $root);
if (! is_resource($check)) {
    fwrite(STDERR, "Could not verify private environment ignore status.\n");
    exit(2);
}
foreach ($pipes as $pipe) {
    fclose($pipe);
}
if (proc_close($check) !== 0) {
    fwrite(STDERR, "Phase 09 private environment file must be Git-ignored.\n");
    exit(2);
}
try {
    $env = Dotenv::parse((string) file_get_contents($envPath));
} catch (Throwable) {
    fwrite(STDERR, "Phase 09 environment file could not be parsed.\n");
    exit(2);
}
$required = ['APP_ENV' => 'testing', 'DB_CONNECTION' => 'mysql', 'DB_HOST' => '127.0.0.1', 'DB_PORT' => '3306', 'DB_DATABASE' => Target::DATABASE, 'DB_USERNAME' => Target::ACCOUNT, 'PHASE09_TEST_SCHEMA_DISPOSABLE' => 'true', 'PHASE09_TEST_SCHEMA_AUTHORIZATION' => Target::AUTHORIZATION];
foreach ($required as $key => $value) {
    if (($env[$key] ?? null) !== $value) {
        fwrite(STDERR, "Phase 09 target or authorization check failed for {$key}.\n");
        exit(2);
    }
}
if (trim((string) ($env['DB_PASSWORD'] ?? '')) === '' || ($env['DB_URL'] ?? '') !== '') {
    fwrite(STDERR, "Phase 09 dedicated database credentials are missing or DB_URL is set.\n");
    exit(2);
}
$lockPath = $framework.DIRECTORY_SEPARATOR.Target::LOCK_FILE;
$lockHandle = @fopen($lockPath, 'c');
if (! is_resource($lockHandle) || ! flock($lockHandle, LOCK_EX | LOCK_NB)) {
    fwrite(STDERR, "Another Phase 09 runner holds the local exclusive lock.\n");
    exit(2);
}
$pdo = null;
$serverLock = false;
try {
    $pdo = new PDO('mysql:host=127.0.0.1;port=3306;dbname='.Target::DATABASE.';charset=utf8mb4', $env['DB_USERNAME'], $env['DB_PASSWORD'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false]);
    $identity = $pdo->query('SELECT DATABASE() AS db,CURRENT_USER() AS account,@@hostname AS hostname,@@port AS port,@@version AS version,@@server_uuid AS uuid')->fetch();
    [$username, $host] = array_pad(explode('@', (string) $identity['account'], 2), 2, '');
    $host = trim($host, "`'\"");
    Phase04MySqlGrantPolicy::assertIdentity((string) $identity['db'], $username, $host, Target::DATABASE, Target::ACCOUNT);
    if ((int) $identity['port'] !== 3306 || ! str_starts_with((string) $identity['version'], '8.4.')) {
        throw new RuntimeException('Phase 09 requires the dedicated local MySQL 8.4 instance.');
    }
    Phase04MySqlGrantPolicy::assertAllowed(array_map('strval', $pdo->query('SHOW GRANTS')->fetchAll(PDO::FETCH_COLUMN)), $username, $host, Target::DATABASE);
    $advisory = $pdo->prepare('SELECT GET_LOCK(:name,0)');
    $advisory->execute(['name' => Target::SERVER_LOCK]);
    if ((int) $advisory->fetchColumn() !== 1) {
        throw new RuntimeException('A competing Phase 09 process holds the MySQL advisory lock.');
    }
    $serverLock = true;
    $first = Phase09OwnershipManifestPolicy::snapshot($pdo);
    $second = Phase09OwnershipManifestPolicy::snapshot($pdo);
    if ($first !== $second) {
        throw new RuntimeException('Phase 09 schema changed during read-only preflight; no database changes were made.');
    }
    if (is_link($manifestPath) || is_link($intentPath)) {
        throw new RuntimeException('Phase 09 ownership evidence may not be a symlink.');
    }
    if ($mode === '--preflight') {
        if (is_file($intentPath)) {
            throw new RuntimeException('A prior migration intent exists; preserve the target for read-only recovery review.');
        }
        if (is_file($manifestPath)) {
            Phase09OwnershipManifestPolicy::assertOwned(json_decode((string) file_get_contents($manifestPath), true), (string) $identity['uuid'], $first['fingerprint'], $first['objects'], $first['migrations']);
        } elseif ($first['objects'] !== [] || $first['migrations'] !== []) {
            throw new RuntimeException('Unowned Phase 09 schema is nonempty; no changes were made.');
        }
        fwrite(STDOUT, sprintf("Phase 09 read-only preflight passed: database=%s account=%s@%s MySQL=%s objects=%d migrations=%d; no schema/data/manifest changes.\n", Target::DATABASE, $username, $host, $identity['version'], count($first['objects']), count($first['migrations'])));
        exit(0);
    }
    if (is_file($intentPath)) {
        throw new RuntimeException('A prior migration intent exists; no migrations were started. Preserve the database for read-only recovery review.');
    }
    if (is_file($manifestPath)) {
        Phase09OwnershipManifestPolicy::assertOwned(json_decode((string) file_get_contents($manifestPath), true), (string) $identity['uuid'], $first['fingerprint'], $first['objects'], $first['migrations']);
    } else {
        if ($first['objects'] !== [] || $first['migrations'] !== []) {
            throw new RuntimeException('Unowned Phase 09 schema is nonempty; no changes were made.');
        }
        $migrations = expectedMigrations($root);
        writeExclusive($intentPath, json_encode(['database' => Target::DATABASE, 'account' => $username.'@'.$host, 'server_uuid' => $identity['uuid'], 'initial_fingerprint' => $first['fingerprint'], 'migration_cutoff' => Target::MIGRATION_CUTOFF, 'migration_files' => array_map(fn ($file) => ['name' => basename($file, '.php'), 'sha256' => hash_file('sha256', $file)], $migrations), 'authorization' => Target::AUTHORIZATION, 'created_at_utc' => gmdate(DATE_ATOM)], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
        installEnvironment($env);
        passthru(escapeshellarg(PHP_BINARY).' '.escapeshellarg($root.DIRECTORY_SEPARATOR.'artisan').' config:clear --ansi', $status);
        if ($status !== 0) {
            exit($status);
        }
        foreach ($migrations as $file) {
            $relative = 'database/migrations/'.basename($file);
            passthru(escapeshellarg(PHP_BINARY).' '.escapeshellarg($root.DIRECTORY_SEPARATOR.'artisan').' migrate --force --no-interaction --database=mysql --path='.escapeshellarg($relative), $status);
            if ($status !== 0) {
                throw new RuntimeException('Authorized migration execution stopped; migration intent is preserved for read-only recovery.');
            }
        }
        $after = Phase09OwnershipManifestPolicy::snapshot($pdo);
        $applied = array_column($after['migrations'], 'migration');
        $expected = array_map(fn ($file) => basename($file, '.php'), $migrations);
        if ($applied !== $expected) {
            throw new RuntimeException('Post-migration ledger differs from the reviewed Phase 09 migration inventory.');
        }
        writeExclusive($manifestPath, json_encode(['database' => Target::DATABASE, 'account' => $username.'@'.$host, 'server_uuid' => $identity['uuid'], 'fingerprint' => $after['fingerprint'], 'objects' => $after['objects'], 'migrations' => $after['migrations'], 'authorization' => Target::AUTHORIZATION, 'attested_at_utc' => gmdate(DATE_ATOM)], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
        if (! unlink($intentPath)) {
            throw new RuntimeException('Phase 09 migration succeeded, but the migration intent could not be closed; preserve the schema for review.');
        }
    }
    installEnvironment($env);
    putenv('PHASE09_MYSQL_PREFLIGHT_PASSED=true');
    $_ENV['PHASE09_MYSQL_PREFLIGHT_PASSED'] = $_SERVER['PHASE09_MYSQL_PREFLIGHT_PASSED'] = 'true';
    $command = [PHP_BINARY, $root.DIRECTORY_SEPARATOR.'artisan', 'test', 'tests/Feature/AcademicTimetableTest.php', 'tests/Unit/LocalScheduleTimeTest.php'];
    $process = proc_open($command, [0 => STDIN, 1 => STDOUT, 2 => STDERR], $pipes, $root);
    if (! is_resource($process)) {
        throw new RuntimeException('Could not start the Phase 09 MySQL integration suite.');
    }
    $testStatus = proc_close($process);
    if ($testStatus !== 0) {
        exit($testStatus);
    }
    $probe = proc_open([PHP_BINARY, $root.DIRECTORY_SEPARATOR.'tests'.DIRECTORY_SEPARATOR.'Support'.DIRECTORY_SEPARATOR.'phase09-timetable-concurrency.php'], [0 => STDIN, 1 => STDOUT, 2 => STDERR], $pipes, $root);
    if (! is_resource($probe)) {
        throw new RuntimeException('Could not start the guarded Phase 09 independent-session concurrency probes.');
    }
    $probeStatus = proc_close($probe);
    if ($probeStatus !== 0) {
        exit($probeStatus);
    }
    $final = Phase09OwnershipManifestPolicy::snapshot($pdo);
    $manifest = json_decode((string) file_get_contents($manifestPath), true);
    Phase09OwnershipManifestPolicy::assertOwned($manifest, (string) $identity['uuid'], $final['fingerprint'], $final['objects'], $final['migrations']);
    fwrite(STDOUT, sprintf("Phase 09 guarded MySQL integration passed. Database=%s account=%s@%s MySQL=%s objects=%d migrations=%d fingerprint=%s\n", Target::DATABASE, $username, $host, $identity['version'], count($final['objects']), count($final['migrations']), $final['fingerprint']));
} catch (Throwable $error) {
    fwrite(STDERR, 'Phase 09 guarded runner refused or failed: '.$error->getMessage().PHP_EOL);
    exit(2);
} finally {
    if ($serverLock && $pdo instanceof PDO) {
        try {
            $pdo->query("SELECT RELEASE_LOCK('".Target::SERVER_LOCK."')");
        } catch (Throwable) {
        }
    }
    flock($lockHandle, LOCK_UN);
    fclose($lockHandle);
}

/** @return list<string> */
function expectedMigrations(string $root): array
{
    $files = glob($root.DIRECTORY_SEPARATOR.'database'.DIRECTORY_SEPARATOR.'migrations'.DIRECTORY_SEPARATOR.'*.php') ?: [];
    $files = array_values(array_filter($files, static fn (string $file): bool => basename($file, '.php') <= Target::MIGRATION_CUTOFF));
    sort($files, SORT_STRING);
    if ($files === [] || basename(end($files), '.php') !== Target::MIGRATION_CUTOFF) {
        throw new RuntimeException('The Phase 09 reviewed migration cutoff is absent from the local migration inventory.');
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
    putenv('PHASE09_TEST_SCHEMA_DISPOSABLE=true');
    $_ENV['PHASE09_TEST_SCHEMA_DISPOSABLE'] = $_SERVER['PHASE09_TEST_SCHEMA_DISPOSABLE'] = 'true';
}

function writeExclusive(string $path, string $contents): void
{
    $handle = @fopen($path, 'x');
    if (! is_resource($handle)) {
        throw new RuntimeException('Phase 09 ownership evidence already exists; no file was overwritten.');
    }
    try {
        if (fwrite($handle, $contents) !== strlen($contents) || ! fflush($handle)) {
            throw new RuntimeException('Could not persist Phase 09 ownership evidence.');
        }
    } finally {
        fclose($handle);
    }
}
