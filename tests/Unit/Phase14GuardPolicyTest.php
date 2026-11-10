<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use Tests\Support\Phase14MySqlTestTarget;
use Tests\Support\TestDatabaseGuard;

final class Phase14GuardPolicyTest extends TestCase
{
    public function test_phase14_target_is_accepted_only_after_runner_or_preflight_and_explicit_authorization(): void
    {
        $environment = $this->environment();
        TestDatabaseGuard::assertSafe($environment, allowPhase14Runner: true);
        TestDatabaseGuard::assertSafe([...$environment, 'PHASE14_MYSQL_PREFLIGHT_PASSED' => 'true']);
        self::assertSame(Phase14MySqlTestTarget::DATABASE, $environment['DB_DATABASE']);
    }

    public function test_phase14_guard_rejects_wrong_schema_credentials_or_missing_owner_authorization(): void
    {
        $environment = $this->environment();
        $rejected = 0;
        foreach ([
            [...$environment, 'DB_DATABASE' => 'techmentors_lms_dev'],
            [...$environment, 'DB_USERNAME' => 'root'],
            [...$environment, 'DB_HOST' => 'localhost'],
            [...$environment, 'PHASE14_TEST_SCHEMA_AUTHORIZATION' => ''],
            [...$environment, 'PHASE14_TEST_SCHEMA_DISPOSABLE' => 'false'],
            [...$environment, 'DB_SOCKET' => 'mysql.sock'],
        ] as $unsafe) {
            try {
                TestDatabaseGuard::assertSafe($unsafe, allowPhase14Runner: true);
            } catch (RuntimeException $exception) {
                self::assertNotSame('', $exception->getMessage());
                $rejected++;
            }
        }
        self::assertSame(6, $rejected, 'Every unsafe Phase 14 target must be rejected.');
    }

    public function test_phase14_environment_is_included_only_by_name_and_not_printed(): void
    {
        self::assertSame('techmentors_lms_phase14_test', Phase14MySqlTestTarget::DATABASE);
        self::assertSame('techmentors_phase14_test', Phase14MySqlTestTarget::ACCOUNT);
        self::assertSame('.env.phase14.testing', Phase14MySqlTestTarget::CONFIGURATION);
        self::assertSame('Explicit owner authorization for disposable Phase 14 test schema', Phase14MySqlTestTarget::AUTHORIZATION);
    }

    /** @return array<string,string> */
    private function environment(): array
    {
        return [
            'APP_ENV' => 'testing',
            'DB_URL' => '',
            'DB_CONNECTION' => 'mysql',
            'DB_DATABASE' => Phase14MySqlTestTarget::DATABASE,
            'DB_USERNAME' => Phase14MySqlTestTarget::ACCOUNT,
            'DB_PASSWORD' => 'unit-test-only',
            'DB_HOST' => '127.0.0.1',
            'DB_PORT' => '3306',
            'DB_SOCKET' => '',
            'PHASE14_TEST_SCHEMA_DISPOSABLE' => 'true',
            'PHASE14_TEST_SCHEMA_AUTHORIZATION' => Phase14MySqlTestTarget::AUTHORIZATION,
        ];
    }
}
