<?php

namespace Tests\Unit;

use Illuminate\Support\Str;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Tests\Support\TestDatabaseGuard;

class TestDatabaseGuardTest extends TestCase
{
    public function test_only_isolated_testing_database_configurations_are_allowed(): void
    {
        $cases = [
            'sqlite memory database' => [['APP_ENV' => 'testing', 'DB_CONNECTION' => 'sqlite', 'DB_DATABASE' => ':memory:'], true],
            'dedicated Phase 04 MySQL test account' => [['APP_ENV' => 'testing', 'DB_CONNECTION' => 'mysql', 'DB_HOST' => '127.0.0.1', 'DB_PORT' => '3306', 'DB_DATABASE' => 'techmentors_lms_phase04_test', 'DB_USERNAME' => 'techmentors_phase04_test'], true],
            'Phase 05 target is denied without a successful guarded preflight' => [['APP_ENV' => 'testing', 'DB_CONNECTION' => 'mysql', 'DB_HOST' => '127.0.0.1', 'DB_PORT' => '3306', 'DB_DATABASE' => 'techmentors_lms_phase05_test', 'DB_USERNAME' => 'techmentors_phase05_test'], false],
            'previous populated Phase 01-03 test schema' => [['APP_ENV' => 'testing', 'DB_CONNECTION' => 'mysql', 'DB_HOST' => '127.0.0.1', 'DB_PORT' => '3306', 'DB_DATABASE' => 'techmentors_lms_test', 'DB_USERNAME' => 'techmentors_test'], false],
            'previous CI schema' => [['APP_ENV' => 'testing', 'DB_CONNECTION' => 'mysql', 'DB_HOST' => '127.0.0.1', 'DB_PORT' => '3306', 'DB_DATABASE' => 'techmentors_lms_test', 'DB_USERNAME' => 'phase01_ci_user'], false],
            'development database' => [['APP_ENV' => 'testing', 'DB_CONNECTION' => 'mysql', 'DB_DATABASE' => 'techmentors_lms_dev', 'DB_USERNAME' => 'techmentors_app'], false],
            'unrelated development database' => [['APP_ENV' => 'testing', 'DB_CONNECTION' => 'mysql', 'DB_HOST' => '127.0.0.1', 'DB_PORT' => '3306', 'DB_DATABASE' => 'alamgirian_dev', 'DB_USERNAME' => 'techmentors_test'], false],
            'unrelated testing database' => [['APP_ENV' => 'testing', 'DB_CONNECTION' => 'mysql', 'DB_HOST' => '127.0.0.1', 'DB_PORT' => '3306', 'DB_DATABASE' => 'alamgirian_test', 'DB_USERNAME' => 'techmentors_test'], false],
            'production database' => [['APP_ENV' => 'testing', 'DB_CONNECTION' => 'mysql', 'DB_HOST' => '127.0.0.1', 'DB_PORT' => '3306', 'DB_DATABASE' => 'production', 'DB_USERNAME' => 'techmentors_test'], false],
            'root Phase 04 test account' => [['APP_ENV' => 'testing', 'DB_CONNECTION' => 'mysql', 'DB_HOST' => '127.0.0.1', 'DB_PORT' => '3306', 'DB_DATABASE' => 'techmentors_lms_phase04_test', 'DB_USERNAME' => 'root'], false],
            'development account on Phase 04 test schema' => [['APP_ENV' => 'testing', 'DB_CONNECTION' => 'mysql', 'DB_DATABASE' => 'techmentors_lms_phase04_test', 'DB_USERNAME' => 'techmentors_app'], false],
            'unlisted MySQL account' => [['APP_ENV' => 'testing', 'DB_CONNECTION' => 'mysql', 'DB_HOST' => '127.0.0.1', 'DB_PORT' => '3306', 'DB_DATABASE' => 'techmentors_lms_phase04_test', 'DB_USERNAME' => 'mysql_admin'], false],
            'remote MySQL host' => [['APP_ENV' => 'testing', 'DB_CONNECTION' => 'mysql', 'DB_HOST' => 'db.example.invalid', 'DB_PORT' => '3306', 'DB_DATABASE' => 'techmentors_lms_phase04_test', 'DB_USERNAME' => 'techmentors_phase04_test'], false],
            'unexpected MySQL port' => [['APP_ENV' => 'testing', 'DB_CONNECTION' => 'mysql', 'DB_HOST' => '127.0.0.1', 'DB_PORT' => '3307', 'DB_DATABASE' => 'techmentors_lms_phase04_test', 'DB_USERNAME' => 'techmentors_phase04_test'], false],
            'database URL override' => [['APP_ENV' => 'testing', 'DB_CONNECTION' => 'sqlite', 'DB_DATABASE' => ':memory:', 'DB_URL' => 'mysql://target-override'], false],
            'file-backed SQLite' => [['APP_ENV' => 'testing', 'DB_CONNECTION' => 'sqlite', 'DB_DATABASE' => 'database.sqlite'], false],
            'non-test application environment' => [['APP_ENV' => 'local', 'DB_CONNECTION' => 'sqlite', 'DB_DATABASE' => ':memory:'], false],
        ];

        foreach ($cases as $name => [$environment, $expected]) {
            try {
                TestDatabaseGuard::assertSafe($environment);
                $actual = true;
            } catch (RuntimeException) {
                $actual = false;
            }

            $this->assertSame($expected, $actual, $name);
        }
    }

    public function test_phase05_mysql_target_requires_guarded_runner_authorization_and_exact_identity(): void
    {
        $environment = [
            'APP_ENV' => 'testing', 'DB_CONNECTION' => 'mysql', 'DB_HOST' => '127.0.0.1',
            'DB_PORT' => '3306', 'DB_DATABASE' => 'techmentors_lms_phase05_test',
            'DB_USERNAME' => 'techmentors_phase05_test', 'DB_SOCKET' => '',
        ];

        TestDatabaseGuard::assertSafe($environment, allowPhase05Runner: true);
        TestDatabaseGuard::assertSafe([...$environment, 'PHASE05_MYSQL_PREFLIGHT_PASSED' => 'true']);

        foreach ([
            [...$environment, 'DB_DATABASE' => 'techmentors_lms_dev'],
            [...$environment, 'DB_USERNAME' => 'techmentors_app'],
            [...$environment, 'DB_HOST' => 'db.example.invalid'],
            [...$environment, 'DB_PORT' => '3307'],
            [...$environment, 'DB_SOCKET' => 'mysql.sock'],
        ] as $unsafe) {
            $this->assertRejected($unsafe);
        }
    }

    public function test_phase06_mysql_target_requires_runner_authorization_explicit_disposable_flag_and_exact_identity(): void
    {
        $environment = [
            'APP_ENV' => 'testing', 'DB_CONNECTION' => 'mysql', 'DB_HOST' => '127.0.0.1',
            'DB_PORT' => '3306', 'DB_DATABASE' => 'techmentors_lms_phase06_test',
            'DB_USERNAME' => 'techmentors_phase06_test', 'DB_SOCKET' => '',
            'PHASE06_TEST_SCHEMA_DISPOSABLE' => 'true',
        ];
        TestDatabaseGuard::assertSafe($environment, allowPhase06Runner: true);
        TestDatabaseGuard::assertSafe([...$environment, 'PHASE06_MYSQL_PREFLIGHT_PASSED' => 'true']);

        foreach ([
            [...$environment, 'DB_DATABASE' => 'techmentors_lms_dev'],
            [...$environment, 'DB_USERNAME' => 'techmentors_app'],
            [...$environment, 'DB_HOST' => 'db.example.invalid'],
            [...$environment, 'DB_PORT' => '3307'],
            [...$environment, 'DB_SOCKET' => 'mysql.sock'],
            [...$environment, 'PHASE06_TEST_SCHEMA_DISPOSABLE' => 'false'],
        ] as $unsafe) {
            $this->assertRejected($unsafe);
        }
        $this->assertRejected($environment);
    }

    public function test_phase06_read_only_preflight_does_not_require_destructive_disposable_authorization(): void
    {
        $environment = [
            'APP_ENV' => 'testing', 'DB_CONNECTION' => 'mysql', 'DB_HOST' => '127.0.0.1',
            'DB_PORT' => '3306', 'DB_DATABASE' => 'techmentors_lms_phase06_test',
            'DB_USERNAME' => 'techmentors_phase06_test', 'DB_SOCKET' => '',
        ];

        TestDatabaseGuard::assertSafe(
            $environment,
            allowPhase06Runner: true,
            allowPhase06ReadOnlyPreflight: true,
        );

        foreach ([
            [...$environment, 'DB_DATABASE' => 'techmentors_lms_dev'],
            [...$environment, 'DB_USERNAME' => 'techmentors_app'],
            [...$environment, 'DB_HOST' => 'db.example.invalid'],
            [...$environment, 'DB_PORT' => '3307'],
            [...$environment, 'DB_SOCKET' => 'mysql.sock'],
        ] as $unsafe) {
            try {
                TestDatabaseGuard::assertSafe(
                    $unsafe,
                    allowPhase06Runner: true,
                    allowPhase06ReadOnlyPreflight: true,
                );
                $this->fail('Read-only preflight must remain bound to the exact Phase 06 loopback target.');
            } catch (RuntimeException) {
                continue;
            }
        }

        $this->assertRejected($environment);
    }

    public function test_persistent_e2e_sqlite_requires_explicit_browser_test_allowance(): void
    {
        $environment = [
            'APP_ENV' => 'testing',
            'DB_CONNECTION' => 'sqlite',
            'DB_DATABASE' => dirname(__DIR__, 2).DIRECTORY_SEPARATOR.'storage'.DIRECTORY_SEPARATOR.'framework'.DIRECTORY_SEPARATOR.'phase04-e2e-test.sqlite',
            'DB_URL' => '',
        ];

        try {
            TestDatabaseGuard::assertSafe($environment);
            $this->fail('The general migration test runner must reject persistent SQLite databases.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Refusing unsafe test database configuration. Use SQLite :memory: or an explicitly allowlisted phase-specific disposable MySQL schema.', $exception->getMessage());
        }

        TestDatabaseGuard::assertSafe($environment, allowPhase04E2ESqlite: true);

        $uniqueBrowserDatabase = dirname(__DIR__, 2).DIRECTORY_SEPARATOR.'storage'.DIRECTORY_SEPARATOR.'framework'.DIRECTORY_SEPARATOR.'phase06-e2e-'.strtolower((string) Str::uuid()).'.sqlite';
        $uniqueEnvironment = [...$environment, 'DB_DATABASE' => $uniqueBrowserDatabase, 'E2E_TEST_MODE' => 'true'];
        try {
            TestDatabaseGuard::assertSafe($uniqueEnvironment, allowPhase04E2ESqlite: true);
        } catch (RuntimeException $exception) {
            self::fail('A uniquely named explicitly guarded browser-test database should be allowed: '.$exception->getMessage());
        }

        $unapprovedUnique = [...$uniqueEnvironment, 'E2E_TEST_MODE' => 'false'];
        $this->assertRejected($unapprovedUnique);
    }

    /** @param array<string, string> $environment */
    private function assertRejected(array $environment): void
    {
        try {
            TestDatabaseGuard::assertSafe($environment, allowPhase05Runner: true);
        } catch (RuntimeException $exception) {
            $this->assertNotSame('', $exception->getMessage());

            return;
        }

        $this->fail('An altered Phase 05 MySQL target must be rejected.');
    }
}
