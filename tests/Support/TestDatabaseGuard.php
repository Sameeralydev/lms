<?php

namespace Tests\Support;

use RuntimeException;

/**
 * Restricts migration-based tests to explicitly isolated test databases.
 */
final class TestDatabaseGuard
{
    /** @param array<string, string> $environment */
    public static function assertSafe(
        array $environment,
        bool $allowPhase04E2ESqlite = false,
        bool $allowPhase05Runner = false,
        bool $allowPhase06Runner = false,
        bool $allowPhase06ReadOnlyPreflight = false,
        bool $allowPhase07Runner = false,
        bool $allowPhase08Runner = false,
        bool $allowPhase09Runner = false,
        bool $allowPhase10Runner = false,
        bool $allowPhase11Runner = false,
        bool $allowPhase12Runner = false,
        bool $allowPhase13Runner = false,
        bool $allowPhase14Runner = false,
    ): void {
        if (($environment['APP_ENV'] ?? '') !== 'testing') {
            throw new RuntimeException('Refusing to run tests unless APP_ENV is testing.');
        }

        if (($environment['DB_URL'] ?? '') !== '') {
            throw new RuntimeException('Refusing to run tests with DB_URL configured.');
        }

        $connection = strtolower($environment['DB_CONNECTION'] ?? '');
        $database = $environment['DB_DATABASE'] ?? '';

        if ($connection === 'sqlite' && $database === ':memory:') {
            return;
        }

        $e2eSqlitePath = dirname(__DIR__, 2).DIRECTORY_SEPARATOR.'storage'.DIRECTORY_SEPARATOR.'framework'.DIRECTORY_SEPARATOR.'phase04-e2e-test.sqlite';
        if (
            $allowPhase04E2ESqlite
            && $connection === 'sqlite'
            && $database === $e2eSqlitePath
            && ! is_link($database)
        ) {
            return;
        }
        $frameworkDirectory = realpath(dirname($e2eSqlitePath));
        if (
            $allowPhase04E2ESqlite
            && ($environment['E2E_TEST_MODE'] ?? '') === 'true'
            && $connection === 'sqlite'
            && $frameworkDirectory !== false
            && realpath(dirname($database)) === $frameworkDirectory
            && preg_match('/\\Aphase06-e2e-[0-9a-f-]{36}\\.sqlite\\z/iD', basename($database)) === 1
            && ! is_link($database)
        ) {
            return;
        }

        if ($connection === 'mysql' && $database === 'techmentors_lms_phase04_test') {
            $username = strtolower($environment['DB_USERNAME'] ?? '');
            $host = strtolower($environment['DB_HOST'] ?? '');
            $port = $environment['DB_PORT'] ?? '';

            if (
                $username === 'techmentors_phase04_test'
                && $host === '127.0.0.1'
                && $port === '3306'
                && ($environment['DB_SOCKET'] ?? '') === ''
            ) {
                return;
            }

            throw new RuntimeException('MySQL tests require the allowlisted local test account and endpoint.');
        }

        if ($connection === 'mysql' && $database === 'techmentors_lms_phase04_v2_test') {
            $username = strtolower($environment['DB_USERNAME'] ?? '');
            $host = strtolower($environment['DB_HOST'] ?? '');
            $port = $environment['DB_PORT'] ?? '';

            if (
                $username === 'techmentors_phase04_v2'
                && $host === '127.0.0.1'
                && $port === '3306'
                && ($environment['DB_SOCKET'] ?? '') === ''
            ) {
                return;
            }

            throw new RuntimeException('Phase 04 V2 MySQL tests require the allowlisted V2 test account and endpoint.');
        }

        if ($connection === 'mysql' && $database === 'techmentors_lms_phase05_test') {
            $username = strtolower($environment['DB_USERNAME'] ?? '');
            $host = strtolower($environment['DB_HOST'] ?? '');
            $port = $environment['DB_PORT'] ?? '';
            $runnerAuthorized = $allowPhase05Runner || ($environment['PHASE05_MYSQL_PREFLIGHT_PASSED'] ?? '') === 'true';

            if (
                $runnerAuthorized
                && $username === 'techmentors_phase05_test'
                && $host === '127.0.0.1'
                && $port === '3306'
                && ($environment['DB_SOCKET'] ?? '') === ''
            ) {
                return;
            }

            throw new RuntimeException('Phase 05 MySQL tests require the verified dedicated target through the guarded runner.');
        }

        if ($connection === 'mysql' && $database === Phase06MySqlTestTarget::DATABASE) {
            $username = strtolower($environment['DB_USERNAME'] ?? '');
            $host = strtolower($environment['DB_HOST'] ?? '');
            $port = $environment['DB_PORT'] ?? '';
            $runnerAuthorized = $allowPhase06Runner || ($environment['PHASE06_MYSQL_PREFLIGHT_PASSED'] ?? '') === 'true';
            $explicitlyDisposable = ($environment['PHASE06_TEST_SCHEMA_DISPOSABLE'] ?? '') === 'true';
            $readOnlyPreflightAuthorized = $allowPhase06ReadOnlyPreflight && $allowPhase06Runner;

            if ($runnerAuthorized && ($explicitlyDisposable || $readOnlyPreflightAuthorized) && $username === Phase06MySqlTestTarget::ACCOUNT && $host === '127.0.0.1' && $port === '3306' && ($environment['DB_SOCKET'] ?? '') === '') {
                return;
            }

            throw new RuntimeException('Phase 06 MySQL tests require the verified, explicitly disposable dedicated target through the guarded runner.');
        }

        if ($connection === 'mysql' && $database === Phase07MySqlTestTarget::DATABASE) {
            $username = strtolower($environment['DB_USERNAME'] ?? '');
            $host = strtolower($environment['DB_HOST'] ?? '');
            $port = $environment['DB_PORT'] ?? '';
            $runnerAuthorized = $allowPhase07Runner || ($environment['PHASE07_MYSQL_PREFLIGHT_PASSED'] ?? '') === 'true';
            $explicitlyDisposable = ($environment['PHASE07_TEST_SCHEMA_DISPOSABLE'] ?? '') === 'true';
            if ($runnerAuthorized && $explicitlyDisposable && $username === Phase07MySqlTestTarget::ACCOUNT && $host === '127.0.0.1' && $port === '3306' && ($environment['DB_SOCKET'] ?? '') === '') {
                return;
            }
            throw new RuntimeException('Phase 07 MySQL tests require the verified, explicitly disposable dedicated target through the guarded runner.');
        }

        if ($connection === 'mysql' && $database === Phase08MySqlTestTarget::DATABASE) {
            $username = strtolower($environment['DB_USERNAME'] ?? '');
            $host = strtolower($environment['DB_HOST'] ?? '');
            $port = $environment['DB_PORT'] ?? '';
            $runnerAuthorized = $allowPhase08Runner || ($environment['PHASE08_MYSQL_PREFLIGHT_PASSED'] ?? '') === 'true';
            $explicitlyDisposable = ($environment['PHASE08_TEST_SCHEMA_DISPOSABLE'] ?? '') === 'true';
            if ($runnerAuthorized && $explicitlyDisposable && $username === Phase08MySqlTestTarget::ACCOUNT && $host === '127.0.0.1' && $port === '3306' && ($environment['DB_SOCKET'] ?? '') === '') {
                return;
            }
            throw new RuntimeException('Phase 08 MySQL tests require the verified, explicitly disposable dedicated target through its guarded runner.');
        }

        if ($connection === 'mysql' && $database === Phase09MySqlTestTarget::DATABASE) {
            $username = strtolower($environment['DB_USERNAME'] ?? '');
            $host = strtolower($environment['DB_HOST'] ?? '');
            $port = $environment['DB_PORT'] ?? '';
            $authorized = $allowPhase09Runner || ($environment['PHASE09_MYSQL_PREFLIGHT_PASSED'] ?? '') === 'true';
            $disposable = ($environment['PHASE09_TEST_SCHEMA_DISPOSABLE'] ?? '') === 'true';
            if ($authorized && $disposable && $username === Phase09MySqlTestTarget::ACCOUNT && $host === '127.0.0.1' && $port === '3306' && ($environment['DB_SOCKET'] ?? '') === '') {
                return;
            }
            throw new RuntimeException('Phase 09 MySQL tests require the exact verified disposable target through its guarded runner.');
        }

        if ($connection === 'mysql' && $database === Phase10MySqlTestTarget::DATABASE) {
            $username = strtolower($environment['DB_USERNAME'] ?? '');
            $host = strtolower($environment['DB_HOST'] ?? '');
            $port = $environment['DB_PORT'] ?? '';
            $runnerAuthorized = $allowPhase10Runner || ($environment['PHASE10_MYSQL_PREFLIGHT_PASSED'] ?? '') === 'true';
            $disposable = ($environment['PHASE10_TEST_SCHEMA_DISPOSABLE'] ?? '') === 'true';
            if ($runnerAuthorized && $disposable && $username === Phase10MySqlTestTarget::ACCOUNT && $host === '127.0.0.1' && $port === '3306' && ($environment['DB_SOCKET'] ?? '') === '') {
                return;
            }
            throw new RuntimeException('Phase 10 MySQL tests require the exact verified disposable target through its guarded runner.');
        }

        if ($connection === 'mysql' && $database === Phase11MySqlTestTarget::DATABASE) {
            $username = strtolower($environment['DB_USERNAME'] ?? '');
            $host = strtolower($environment['DB_HOST'] ?? '');
            $port = $environment['DB_PORT'] ?? '';
            $runnerAuthorized = $allowPhase11Runner || ($environment['PHASE11_MYSQL_PREFLIGHT_PASSED'] ?? '') === 'true';
            $disposable = ($environment['PHASE11_TEST_SCHEMA_DISPOSABLE'] ?? '') === 'true';
            $authorization = ($environment['PHASE11_TEST_SCHEMA_AUTHORIZATION'] ?? '') === Phase11MySqlTestTarget::AUTHORIZATION;
            if ($runnerAuthorized && $disposable && $authorization && $username === Phase11MySqlTestTarget::ACCOUNT && $host === '127.0.0.1' && $port === '3306' && ($environment['DB_SOCKET'] ?? '') === '') {
                return;
            }
            throw new RuntimeException('Phase 11 MySQL tests require the exact verified disposable target through its guarded runner.');
        }

        if ($connection === 'mysql' && $database === Phase12MySqlTestTarget::DATABASE) {
            $username = strtolower($environment['DB_USERNAME'] ?? '');
            $host = strtolower($environment['DB_HOST'] ?? '');
            $port = $environment['DB_PORT'] ?? '';
            $runnerAuthorized = $allowPhase12Runner || ($environment['PHASE12_MYSQL_PREFLIGHT_PASSED'] ?? '') === 'true';
            $disposable = ($environment['PHASE12_TEST_SCHEMA_DISPOSABLE'] ?? '') === 'true';
            $authorization = ($environment['PHASE12_TEST_SCHEMA_AUTHORIZATION'] ?? '') === Phase12MySqlTestTarget::AUTHORIZATION;
            if ($runnerAuthorized && $disposable && $authorization && $username === Phase12MySqlTestTarget::ACCOUNT && $host === '127.0.0.1' && $port === '3306' && ($environment['DB_SOCKET'] ?? '') === '') {
                return;
            }
            throw new RuntimeException('Phase 12 MySQL tests require the exact verified disposable target through its guarded runner.');
        }

        if ($connection === 'mysql' && $database === Phase13MySqlTestTarget::DATABASE) {
            $username = strtolower($environment['DB_USERNAME'] ?? '');
            $host = strtolower($environment['DB_HOST'] ?? '');
            $port = $environment['DB_PORT'] ?? '';
            $runnerAuthorized = $allowPhase13Runner || ($environment['PHASE13_MYSQL_PREFLIGHT_PASSED'] ?? '') === 'true';
            $disposable = ($environment['PHASE13_TEST_SCHEMA_DISPOSABLE'] ?? '') === 'true';
            $authorization = ($environment['PHASE13_TEST_SCHEMA_AUTHORIZATION'] ?? '') === Phase13MySqlTestTarget::AUTHORIZATION;
            if ($runnerAuthorized && $disposable && $authorization && $username === Phase13MySqlTestTarget::ACCOUNT && $host === '127.0.0.1' && $port === '3306' && ($environment['DB_SOCKET'] ?? '') === '') {
                return;
            }
            throw new RuntimeException('Phase 13 MySQL tests require the exact verified disposable target through its guarded runner.');
        }

        if ($connection === 'mysql' && $database === Phase14MySqlTestTarget::DATABASE) {
            $username = strtolower($environment['DB_USERNAME'] ?? '');
            $host = strtolower($environment['DB_HOST'] ?? '');
            $port = $environment['DB_PORT'] ?? '';
            $runnerAuthorized = $allowPhase14Runner || ($environment['PHASE14_MYSQL_PREFLIGHT_PASSED'] ?? '') === 'true';
            $disposable = ($environment['PHASE14_TEST_SCHEMA_DISPOSABLE'] ?? '') === 'true';
            $authorization = ($environment['PHASE14_TEST_SCHEMA_AUTHORIZATION'] ?? '') === Phase14MySqlTestTarget::AUTHORIZATION;
            if ($runnerAuthorized && $disposable && $authorization && $username === Phase14MySqlTestTarget::ACCOUNT && $host === '127.0.0.1' && $port === '3306' && ($environment['DB_SOCKET'] ?? '') === '') {
                return;
            }
            throw new RuntimeException('Phase 14 MySQL tests require the exact verified disposable target through its guarded runner.');
        }

        throw new RuntimeException('Refusing unsafe test database configuration. Use SQLite :memory: or an explicitly allowlisted phase-specific disposable MySQL schema.');
    }

    /** @return array<string, string> */
    public static function currentEnvironment(): array
    {
        $environment = [];

        foreach ([
            'APP_ENV',
            'E2E_TEST_MODE',
            'DB_CONNECTION',
            'DB_DATABASE',
            'DB_USERNAME',
            'DB_PASSWORD',
            'DB_HOST',
            'DB_PORT',
            'DB_SOCKET',
            'DB_CHARSET',
            'DB_COLLATION',
            'DB_URL',
            'PHASE05_MYSQL_PREFLIGHT_PASSED',
            'PHASE06_MYSQL_PREFLIGHT_PASSED',
            'PHASE06_TEST_SCHEMA_DISPOSABLE',
            'PHASE07_MYSQL_PREFLIGHT_PASSED',
            'PHASE07_TEST_SCHEMA_DISPOSABLE',
            'PHASE08_MYSQL_PREFLIGHT_PASSED',
            'PHASE08_TEST_SCHEMA_DISPOSABLE',
            'PHASE08_TEST_SCHEMA_AUTHORIZATION',
            'PHASE09_MYSQL_PREFLIGHT_PASSED',
            'PHASE09_TEST_SCHEMA_DISPOSABLE',
            'PHASE09_TEST_SCHEMA_AUTHORIZATION',
            'PHASE10_MYSQL_PREFLIGHT_PASSED',
            'PHASE10_TEST_SCHEMA_DISPOSABLE',
            'PHASE10_TEST_SCHEMA_AUTHORIZATION',
            'PHASE11_MYSQL_PREFLIGHT_PASSED',
            'PHASE11_TEST_SCHEMA_DISPOSABLE',
            'PHASE11_TEST_SCHEMA_AUTHORIZATION',
            'PHASE12_MYSQL_PREFLIGHT_PASSED',
            'PHASE12_TEST_SCHEMA_DISPOSABLE',
            'PHASE12_TEST_SCHEMA_AUTHORIZATION',
            'PHASE13_MYSQL_PREFLIGHT_PASSED',
            'PHASE13_TEST_SCHEMA_DISPOSABLE',
            'PHASE13_TEST_SCHEMA_AUTHORIZATION',
            'PHASE14_MYSQL_PREFLIGHT_PASSED',
            'PHASE14_TEST_SCHEMA_DISPOSABLE',
            'PHASE14_TEST_SCHEMA_AUTHORIZATION',
            'ADMISSIONS_DOCUMENT_SCANNER',
            'ADMISSIONS_CLAMAV_BINARY',
            'ADMISSIONS_CLAMAV_SIGNATURE_DIRECTORY',
            'ADMISSIONS_SIGTOOL_BINARY',
            'ADMISSIONS_CLAMAV_TIMEOUT',
            'ADMISSIONS_CLAMAV_TIMEZONE',
        ] as $name) {
            $value = $_SERVER[$name] ?? $_ENV[$name] ?? getenv($name);

            if ($value !== false) {
                $environment[$name] = (string) $value;
            }
        }

        return $environment;
    }
}
