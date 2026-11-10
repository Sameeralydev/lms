<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use Tests\Support\Phase09MySqlTestTarget;
use Tests\Support\Phase09OwnershipManifestPolicy;
use Tests\Support\TestDatabaseGuard;

class Phase09GuardPolicyTest extends TestCase
{
    public function test_phase09_database_requires_disposable_runner_and_exact_local_target(): void
    {
        $environment = ['APP_ENV' => 'testing', 'DB_CONNECTION' => 'mysql', 'DB_HOST' => '127.0.0.1', 'DB_PORT' => '3306', 'DB_DATABASE' => Phase09MySqlTestTarget::DATABASE, 'DB_USERNAME' => Phase09MySqlTestTarget::ACCOUNT, 'DB_SOCKET' => '', 'PHASE09_TEST_SCHEMA_DISPOSABLE' => 'true'];
        TestDatabaseGuard::assertSafe($environment, allowPhase09Runner: true);
        TestDatabaseGuard::assertSafe([...$environment, 'PHASE09_MYSQL_PREFLIGHT_PASSED' => 'true']);
        foreach ([
            [...$environment, 'DB_DATABASE' => 'techmentors_lms_dev'],
            [...$environment, 'DB_USERNAME' => 'techmentors_app'],
            [...$environment, 'DB_HOST' => 'remote.example.invalid'],
            [...$environment, 'DB_PORT' => '3307'],
            [...$environment, 'DB_SOCKET' => 'mysql.sock'],
            [...$environment, 'PHASE09_TEST_SCHEMA_DISPOSABLE' => 'false'],
        ] as $unsafe) {
            try {
                TestDatabaseGuard::assertSafe($unsafe, allowPhase09Runner: true);
                $this->fail('Altered Phase 09 identity must be refused.');
            } catch (RuntimeException $exception) {
                $this->assertNotSame('', $exception->getMessage());
            }
        }
        try {
            TestDatabaseGuard::assertSafe($environment);
            $this->fail('Phase 09 DB must not be reachable outside guarded runner.');
        } catch (RuntimeException $exception) {
            $this->assertNotSame('', $exception->getMessage());
        }
    }

    public function test_manifest_identity_and_schema_fingerprint_are_checked(): void
    {
        $snapshot = ['objects' => ['academic_timetables', 'migrations'], 'migrations' => [['migration' => Phase09MySqlTestTarget::MIGRATION_CUTOFF, 'batch' => 1]], 'fingerprint' => str_repeat('a', 64)];
        $identity = ['uuid' => 'test-server-uuid'];
        $manifest = ['database' => Phase09MySqlTestTarget::DATABASE, 'account' => Phase09MySqlTestTarget::ACCOUNT.'@127.0.0.1', 'server_uuid' => $identity['uuid'], 'authorization' => Phase09MySqlTestTarget::AUTHORIZATION, ...$snapshot];
        Phase09OwnershipManifestPolicy::assertOwned($manifest, $identity['uuid'], $snapshot['fingerprint'], $snapshot['objects'], $snapshot['migrations']);
        foreach ([
            [...$manifest, 'database' => 'techmentors_lms_dev'],
            [...$manifest, 'server_uuid' => 'other-server'],
            [...$manifest, 'authorization' => 'tampered'],
            [...$manifest, 'fingerprint' => str_repeat('b', 64)],
            [...$manifest, 'objects' => ['unexpected_table']],
            [...$manifest, 'migrations' => []],
        ] as $tampered) {
            try {
                Phase09OwnershipManifestPolicy::assertOwned($tampered, $identity['uuid'], $snapshot['fingerprint'], $snapshot['objects'], $snapshot['migrations']);
                $this->fail('Tampered ownership must be rejected.');
            } catch (RuntimeException $exception) {
                $this->assertNotSame('', $exception->getMessage());
            }
        }
    }
}
