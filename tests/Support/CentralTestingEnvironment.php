<?php

namespace Tests\Support;

use Dotenv\Dotenv;
use RuntimeException;

/** Loads the one private testing environment and applies an explicit profile. */
final class CentralTestingEnvironment
{
    public const FILE = '.env.testing';

    public static function assertNoCachedConfiguration(string $projectRoot): void
    {
        $cachedConfiguration = $projectRoot.DIRECTORY_SEPARATOR.'bootstrap'.DIRECTORY_SEPARATOR.'cache'.DIRECTORY_SEPARATOR.'config.php';
        if (is_file($cachedConfiguration) || is_link($cachedConfiguration)) {
            throw new RuntimeException('Laravel configuration cache must be absent before tests so the selected testing profile is authoritative.');
        }
    }

    /**
     * @param  array<string, string>|null  $file
     * @param  array<string, string>|null  $process
     * @return array<string, string>
     */
    public static function sqlite(string $projectRoot, ?array $file = null, ?array $process = null): array
    {
        $environment = array_replace($file ?? self::read($projectRoot), $process ?? self::processEnvironment());

        return array_replace($environment, [
            'APP_ENV' => 'testing',
            'APP_DEBUG' => 'false',
            'DB_CONNECTION' => 'sqlite',
            'DB_DATABASE' => ':memory:',
            'DB_URL' => '',
            'DB_HOST' => '',
            'DB_PORT' => '',
            'DB_USERNAME' => '',
            'DB_PASSWORD' => '',
            'DB_SOCKET' => '',
            'CACHE_STORE' => 'array',
            'QUEUE_CONNECTION' => 'sync',
            'SESSION_DRIVER' => 'array',
        ]);
    }

    /** @param array<string, string>|null $file
     * @param  array<string, string>|null  $process
     * @return array<string, string>
     */
    public static function mysqlPhase(
        string $projectRoot,
        string $phase,
        string $database,
        string $account,
        ?string $authorization = null,
        ?array $file = null,
        ?array $process = null,
        bool $requireDisposable = false,
        bool $allowAlternateLoopbackPort = false,
    ): array {
        $phase = strtoupper(preg_replace('/[^A-Z0-9]/', '', $phase) ?? '');
        if ($phase === '') {
            throw new RuntimeException('A known phase identifier is required for the MySQL test profile.');
        }

        $environment = array_replace($file ?? self::read($projectRoot), $process ?? self::processEnvironment());
        $prefix = 'TESTING_MYSQL_PHASE'.$phase.'_';
        $username = trim($environment[$prefix.'USERNAME'] ?? '');
        $password = trim($environment[$prefix.'PASSWORD'] ?? '');
        $disposable = $environment[$prefix.'DISPOSABLE'] ?? '';
        $approval = $environment[$prefix.'AUTHORIZATION'] ?? '';
        $host = $environment['TESTING_MYSQL_HOST'] ?? '127.0.0.1';
        $port = $allowAlternateLoopbackPort && $phase === '15'
            ? ($environment['TESTING_MYSQL_PHASE15_PORT'] ?? '')
            : ($environment['TESTING_MYSQL_PORT'] ?? '3306');

        if ($username !== $account || $password === ''
            || ($requireDisposable && $disposable !== 'true')
            || ($authorization !== null && $approval !== $authorization)) {
            throw new RuntimeException('Central MySQL profile is missing the exact phase account, password, disposable authorization, or owner authorization.');
        }
        $validPort = filter_var($port, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1024, 'max_range' => 65535]]) !== false;
        if ($host !== '127.0.0.1' || ! $validPort || (! $allowAlternateLoopbackPort && $port !== '3306')) {
            throw new RuntimeException('Central MySQL profiles are restricted to the reviewed local MySQL endpoint.');
        }

        foreach (['APP_KEY', 'APP_URL', 'APP_LOCALE', 'APP_TIMEZONE', 'ADMISSIONS_DOCUMENT_SCANNER', 'ADMISSIONS_CLAMAV_BINARY', 'ADMISSIONS_CLAMAV_SIGNATURE_DIRECTORY', 'ADMISSIONS_SIGTOOL_BINARY', 'ADMISSIONS_CLAMAV_MAX_SIGNATURE_AGE_HOURS', 'ADMISSIONS_CLAMAV_TIMEZONE', 'ADMISSIONS_CLAMAV_TIMEOUT'] as $setting) {
            if (isset($environment[$prefix.$setting])) {
                $environment[$setting] = $environment[$prefix.$setting];
            }
        }

        return array_replace($environment, [
            'APP_ENV' => 'testing',
            'APP_DEBUG' => 'false',
            'DB_CONNECTION' => 'mysql',
            'DB_HOST' => '127.0.0.1',
            'DB_PORT' => (string) $port,
            'DB_DATABASE' => $database,
            'DB_USERNAME' => $account,
            'DB_PASSWORD' => $password,
            'DB_SOCKET' => '',
            'DB_URL' => '',
            'PHASE'.$phase.'_TEST_SCHEMA_DISPOSABLE' => $disposable === 'true' ? 'true' : '',
            'PHASE'.$phase.'_TEST_SCHEMA_AUTHORIZATION' => $approval,
            ...($phase === '15' ? ['TESTING_MYSQL_PHASE15_PORT' => (string) $port] : []),
        ]);
    }

    /** @return array<string, string> */
    public static function read(string $projectRoot): array
    {
        $path = $projectRoot.DIRECTORY_SEPARATOR.self::FILE;
        if (! is_file($path) || is_link($path) || ! self::isIgnored($projectRoot)) {
            throw new RuntimeException('A regular Git-ignored .env.testing file is required for automated testing.');
        }

        try {
            return Dotenv::parse((string) file_get_contents($path));
        } catch (\Throwable) {
            throw new RuntimeException('The centralized testing environment could not be parsed.');
        }
    }

    /** @param array<string, string> $environment */
    public static function install(array $environment): void
    {
        foreach ($environment as $key => $value) {
            putenv($key.'='.$value);
            $_ENV[$key] = $value;
            $_SERVER[$key] = $value;
        }
    }

    /** @return array<string, string> */
    private static function processEnvironment(): array
    {
        $environment = [];
        foreach ($_ENV as $key => $value) {
            if (is_string($key) && is_string($value)) {
                $environment[$key] = $value;
            }
        }
        foreach (getenv() ?: [] as $key => $value) {
            $environment[$key] = $value;
        }

        return $environment;
    }

    private static function isIgnored(string $projectRoot): bool
    {
        $process = proc_open(
            ['git', 'check-ignore', '--quiet', '--', self::FILE],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $projectRoot,
        );
        if (! is_resource($process)) {
            return false;
        }
        foreach ($pipes as $pipe) {
            fclose($pipe);
        }

        return proc_close($process) === 0;
    }
}
