<?php

declare(strict_types=1);

use Dotenv\Dotenv;

require dirname(__DIR__).'/vendor/autoload.php';

$root = dirname(__DIR__);
$centralPath = $root.DIRECTORY_SEPARATOR.'.env.testing';
$mode = $argv[1] ?? '--check';
if (! in_array($mode, ['--check', '--apply'], true)) {
    fwrite(STDERR, "Usage: php tests/centralize-testing-environments.php --check|--apply\n");
    exit(2);
}
if (! is_file($centralPath) || is_link($centralPath)) {
    fwrite(STDERR, "Central .env.testing must be a regular file.\n");
    exit(2);
}
$ignored = proc_open(['git', 'check-ignore', '--quiet', '--', '.env.testing'], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $root);
if (! is_resource($ignored)) {
    fwrite(STDERR, "Could not verify that .env.testing is ignored.\n");
    exit(2);
}
foreach ($pipes as $pipe) {
    fclose($pipe);
}
if (proc_close($ignored) !== 0) {
    fwrite(STDERR, ".env.testing must remain Git-ignored.\n");
    exit(2);
}

$targets = [
    '01' => ['techmentors_lms_test', 'techmentors_test', '.env.testing', false, false],
    '04' => ['techmentors_lms_phase04_test', 'techmentors_phase04_test', '.env.phase04.testing', false, false],
    '04V2' => ['techmentors_lms_phase04_v2_test', 'techmentors_phase04_v2', '.env.phase04.v2.testing', false, false],
    '05' => ['techmentors_lms_phase05_test', 'techmentors_phase05_test', '.env.phase05.testing', false, false],
    '06' => ['techmentors_lms_phase06_test', 'techmentors_phase06_test', '.env.phase06.testing', false, true],
    '07' => ['techmentors_lms_phase07_test', 'techmentors_phase07_test', '.env.phase07.testing', false, true],
    '08' => ['techmentors_lms_phase08_test', 'techmentors_phase08_test', '.env.phase08.testing', true, true],
    '09' => ['techmentors_lms_phase09_test', 'techmentors_phase09_test', '.env.phase09.testing', true, true],
    '10' => ['techmentors_lms_phase10_test', 'techmentors_phase10_test', '.env.phase10.testing', true, true],
    '11' => ['techmentors_lms_phase11_test', 'techmentors_phase11_test', '.env.phase11.testing', true, true],
    '12' => ['techmentors_lms_phase12_test', 'techmentors_phase12_test', '.env.phase12.testing', true, true],
    '13' => ['techmentors_lms_phase13_test', 'techmentors_phase13_test', '.env.phase13.testing', true, true],
    '14' => ['techmentors_lms_phase14_test', 'techmentors_phase14_test', '.env.phase14.testing', true, true],
    '15' => ['techmentors_lms_phase15_test', 'techmentors_phase15_test', '.env.phase15.testing', true, true],
];

try {
    $centralRaw = (string) file_get_contents($centralPath);
    $central = Dotenv::parse($centralRaw);
    $updates = [];
    $ready = [];
    $missing = [];
    foreach ($targets as $phase => [$database, $account, $filename, $requiresAuthorization, $requiresDisposable]) {
        $path = $root.DIRECTORY_SEPARATOR.$filename;
        if (! is_file($path) || is_link($path)) {
            $missing[] = $phase;

            continue;
        }
        if ($phase !== '01') {
            $sourceIgnored = proc_open(['git', 'check-ignore', '--quiet', '--', $filename], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $sourcePipes, $root);
            if (! is_resource($sourceIgnored)) {
                throw new RuntimeException('Could not verify an existing private phase file ignore status.');
            }
            foreach ($sourcePipes as $pipe) {
                fclose($pipe);
            }
            if (proc_close($sourceIgnored) !== 0) {
                throw new RuntimeException('An existing private phase environment is not Git-ignored.');
            }
        }
        try {
            $sourceRaw = $phase === '01' ? $centralRaw : (string) file_get_contents($path);
            $source = Dotenv::parse($sourceRaw);
        } catch (Throwable) {
            throw new RuntimeException('An existing test environment could not be parsed.');
        }
        if (($source['APP_ENV'] ?? '') !== 'testing'
            || ($source['DB_CONNECTION'] ?? '') !== 'mysql'
            || ($source['DB_HOST'] ?? '') !== '127.0.0.1'
            || ($source['DB_PORT'] ?? '') !== '3306'
            || ($source['DB_DATABASE'] ?? '') !== $database
            || ($source['DB_USERNAME'] ?? '') !== $account
            || trim((string) ($source['DB_PASSWORD'] ?? '')) === ''
            || ($source['DB_URL'] ?? '') !== '') {
            throw new RuntimeException('An existing test profile does not match its reviewed target identity. No files were changed.');
        }
        $prefix = 'TESTING_MYSQL_PHASE'.$phase.'_';
        $keys = [
            'USERNAME' => 'DB_USERNAME', 'PASSWORD' => 'DB_PASSWORD',
            'DISPOSABLE' => 'PHASE'.$phase.'_TEST_SCHEMA_DISPOSABLE',
            'AUTHORIZATION' => 'PHASE'.$phase.'_TEST_SCHEMA_AUTHORIZATION',
        ];
        foreach (['APP_KEY', 'APP_URL', 'APP_LOCALE', 'APP_TIMEZONE', 'ADMISSIONS_DOCUMENT_SCANNER', 'ADMISSIONS_CLAMAV_BINARY', 'ADMISSIONS_CLAMAV_SIGNATURE_DIRECTORY', 'ADMISSIONS_SIGTOOL_BINARY', 'ADMISSIONS_CLAMAV_MAX_SIGNATURE_AGE_HOURS', 'ADMISSIONS_CLAMAV_TIMEZONE', 'ADMISSIONS_CLAMAV_TIMEOUT'] as $setting) {
            if (isset($source[$setting])) {
                $keys[$setting] = $setting;
            }
        }
        foreach ($keys as $suffix => $sourceKey) {
            if (! isset($source[$sourceKey]) || ($suffix === 'AUTHORIZATION' && ! $requiresAuthorization && $source[$sourceKey] === '')) {
                continue;
            }
            $key = $prefix.$suffix;
            $rawValue = rawValue($sourceRaw, $sourceKey);
            if (array_key_exists($key, $central)) {
                if ($central[$key] !== $source[$sourceKey]) {
                    throw new RuntimeException('Central profile conflicts with an existing private phase profile. No files were changed.');
                }

                continue;
            }
            $updates[$key] = $rawValue;
        }
        if (($requiresDisposable && ($source['PHASE'.$phase.'_TEST_SCHEMA_DISPOSABLE'] ?? '') !== 'true')
            || ($requiresAuthorization && ($source['PHASE'.$phase.'_TEST_SCHEMA_AUTHORIZATION'] ?? '') === '')) {
            throw new RuntimeException('A required disposable/authorization declaration is absent from an existing phase profile.');
        }
        $ready[] = $phase;
    }

    $updates['TESTING_PROFILE'] = 'sqlite';
    $updates['TESTING_MYSQL_HOST'] = '127.0.0.1';
    $updates['TESTING_MYSQL_PORT'] = '3306';
    $updatedRaw = $centralRaw;
    foreach ([
        'DB_CONNECTION' => 'sqlite', 'DB_DATABASE' => ':memory:', 'DB_HOST' => '', 'DB_PORT' => '',
        'DB_USERNAME' => '', 'DB_PASSWORD' => '', 'DB_SOCKET' => '', 'DB_URL' => '',
    ] as $key => $value) {
        $updatedRaw = replaceEnvLine($updatedRaw, $key, $value);
    }
    foreach ($updates as $key => $value) {
        if (! array_key_exists($key, $central)) {
            $updatedRaw = rtrim($updatedRaw)."\n{$key}={$value}\n";
        }
    }
    fwrite(STDOUT, 'Centralized profile preflight: migrated phase profiles='.implode(',', $ready).'; missing source profiles='.implode(',', $missing).'; secrets omitted. Mode='.$mode.PHP_EOL);
    if ($mode === '--apply' && $updatedRaw !== $centralRaw) {
        if (file_put_contents($centralPath, $updatedRaw, LOCK_EX) !== strlen($updatedRaw)) {
            file_put_contents($centralPath, $centralRaw, LOCK_EX);
            throw new RuntimeException('Could not completely write centralized .env.testing; previous file contents were restored.');
        }
        try {
            Dotenv::parse((string) file_get_contents($centralPath));
        } catch (Throwable) {
            file_put_contents($centralPath, $centralRaw, LOCK_EX);
            throw new RuntimeException('Central .env.testing failed post-write parsing; previous contents were restored.');
        }
        fwrite(STDOUT, "Central .env.testing was updated and parsed successfully; legacy source files were preserved.\n");
    }
} catch (RuntimeException $exception) {
    fwrite(STDERR, $exception->getMessage().PHP_EOL);
    exit(2);
}

/** @return non-empty-string */
function rawValue(string $contents, string $key): string
{
    if (preg_match('/^\s*'.preg_quote($key, '/').'=(.*)$/m', $contents, $match) !== 1 || trim($match[1]) === '') {
        throw new RuntimeException('An existing test profile credential cannot be copied safely.');
    }

    return trim($match[1]);
}

function replaceEnvLine(string $contents, string $key, string $value): string
{
    $line = $key.'='.$value;
    $pattern = '/^\s*'.preg_quote($key, '/').'=.*$/m';

    return preg_match($pattern, $contents) === 1
        ? (string) preg_replace($pattern, $line, $contents, 1)
        : rtrim($contents)."\n{$line}\n";
}
