<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use Tests\Support\Phase15MySqlTestTarget;
use Tests\Support\Phase15ServerIdentityPolicy;
use Tests\Support\TestDatabaseGuard;

final class Phase15GuardPolicyTest extends TestCase
{
    public function test_phase15_database_is_allowed_only_after_the_guard_and_owner_authorization(): void
    {
        $environment = $this->environment();
        TestDatabaseGuard::assertSafe($environment, allowPhase15Runner: true);
        TestDatabaseGuard::assertSafe([...$environment, 'PHASE15_MYSQL_PREFLIGHT_PASSED' => 'true']);
        self::assertSame(Phase15MySqlTestTarget::DATABASE, $environment['DB_DATABASE']);
    }

    public function test_phase15_database_guard_refuses_substituted_targets_or_missing_authorization(): void
    {
        $environment = $this->environment();
        $rejected = 0;
        foreach ([
            [...$environment, 'DB_DATABASE' => 'techmentors_lms_phase14_test'],
            [...$environment, 'DB_USERNAME' => 'root'],
            [...$environment, 'DB_HOST' => 'localhost'],
            [...$environment, 'PHASE15_TEST_SCHEMA_AUTHORIZATION' => ''],
            [...$environment, 'PHASE15_TEST_SCHEMA_DISPOSABLE' => 'false'],
            [...$environment, 'DB_SOCKET' => 'mysql.sock'],
        ] as $unsafe) {
            try {
                TestDatabaseGuard::assertSafe($unsafe, allowPhase15Runner: true);
                self::fail('Unsafe Phase 15 test database configuration was accepted.');
            } catch (RuntimeException) {
                $rejected++;
            }
        }
        self::assertSame(6, $rejected);
    }

    public function test_phase15_server_identity_requires_a_well_formed_exact_owner_pinned_uuid(): void
    {
        $uuid = 'b38c6a99-8200-4b05-aab2-156cfc8ff102';
        Phase15ServerIdentityPolicy::assertExpected(strtoupper($uuid), $uuid);

        foreach ([['another-server', $uuid], [$uuid, ''], [$uuid, 'not-a-server-uuid']] as [$actual, $expected]) {
            $rejected = false;
            try {
                Phase15ServerIdentityPolicy::assertExpected($actual, $expected);
            } catch (RuntimeException) {
                $rejected = true;
            }

            self::assertTrue($rejected, 'A wrong or absent Phase 15 server UUID was accepted.');
        }
    }

    public function test_phase15_server_data_directory_must_match_the_owner_pinned_instance(): void
    {
        $directory = sys_get_temp_dir().DIRECTORY_SEPARATOR.'phase15-owned-data-'.bin2hex(random_bytes(6));
        mkdir($directory, 0700);

        try {
            Phase15ServerIdentityPolicy::assertDataDirectory($directory, $directory.DIRECTORY_SEPARATOR.'.');

            $this->expectException(RuntimeException::class);
            Phase15ServerIdentityPolicy::assertDataDirectory($directory, $directory.'-other');
        } finally {
            rmdir($directory);
        }
    }

    public function test_current_test_environment_preserves_the_owner_pinned_phase15_port(): void
    {
        $previous = $_SERVER['TESTING_MYSQL_PHASE15_PORT'] ?? null;
        $_SERVER['TESTING_MYSQL_PHASE15_PORT'] = '54870';

        try {
            self::assertSame('54870', TestDatabaseGuard::currentEnvironment()['TESTING_MYSQL_PHASE15_PORT']);
        } finally {
            if (is_string($previous)) {
                $_SERVER['TESTING_MYSQL_PHASE15_PORT'] = $previous;
            } else {
                unset($_SERVER['TESTING_MYSQL_PHASE15_PORT']);
            }
        }
    }

    public function test_current_test_environment_preserves_the_owner_pinned_phase15_server_identity(): void
    {
        $names = ['TESTING_MYSQL_PHASE15_SERVER_UUID', 'TESTING_MYSQL_PHASE15_DATA_DIRECTORY'];
        $previous = [];
        $expected = [
            'TESTING_MYSQL_PHASE15_SERVER_UUID' => 'afc44433-e24a-11f1-a2f0-f4c911fab391',
            'TESTING_MYSQL_PHASE15_DATA_DIRECTORY' => sys_get_temp_dir(),
        ];

        foreach ($names as $name) {
            $previous[$name] = $_SERVER[$name] ?? null;
            $_SERVER[$name] = $expected[$name];
        }

        try {
            $environment = TestDatabaseGuard::currentEnvironment();
            foreach ($expected as $name => $value) {
                self::assertSame($value, $environment[$name]);
            }
        } finally {
            foreach ($names as $name) {
                if (is_string($previous[$name])) {
                    $_SERVER[$name] = $previous[$name];
                } else {
                    unset($_SERVER[$name]);
                }
            }
        }
    }

    /** @return array<string,string> */
    private function environment(): array
    {
        return [
            'APP_ENV' => 'testing', 'DB_URL' => '', 'DB_CONNECTION' => 'mysql',
            'DB_DATABASE' => Phase15MySqlTestTarget::DATABASE, 'DB_USERNAME' => Phase15MySqlTestTarget::ACCOUNT,
            'DB_PASSWORD' => 'unit-test-only', 'DB_HOST' => '127.0.0.1', 'DB_PORT' => '3306', 'DB_SOCKET' => '',
            'TESTING_MYSQL_PHASE15_PORT' => '3306',
            'PHASE15_MYSQL_PREFLIGHT_PASSED' => 'true', 'PHASE15_TEST_SCHEMA_DISPOSABLE' => 'true',
            'PHASE15_TEST_SCHEMA_AUTHORIZATION' => Phase15MySqlTestTarget::AUTHORIZATION,
        ];
    }
}
