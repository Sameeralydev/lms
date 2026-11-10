<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use Tests\Support\Phase10MySqlTestTarget;
use Tests\Support\Phase10OwnershipManifestPolicy;
use Tests\Support\TestDatabaseGuard;

class Phase10GuardPolicyTest extends TestCase
{
    public function test_phase10_tests_require_guarded_disposable_exact_loopback_target(): void
    {
        $environment = [
            'APP_ENV' => 'testing', 'DB_CONNECTION' => 'mysql', 'DB_HOST' => '127.0.0.1', 'DB_PORT' => '3306',
            'DB_DATABASE' => Phase10MySqlTestTarget::DATABASE, 'DB_USERNAME' => Phase10MySqlTestTarget::ACCOUNT,
            'DB_SOCKET' => '', 'DB_URL' => '', 'PHASE10_TEST_SCHEMA_DISPOSABLE' => 'true',
        ];
        TestDatabaseGuard::assertSafe([...$environment, 'PHASE10_MYSQL_PREFLIGHT_PASSED' => 'true']);
        TestDatabaseGuard::assertSafe($environment, allowPhase10Runner: true);

        foreach ([
            [...$environment, 'DB_DATABASE' => 'techmentors_lms_dev'],
            [...$environment, 'DB_DATABASE' => 'techmentors_lms_phase09_test'],
            [...$environment, 'DB_USERNAME' => 'techmentors_app'],
            [...$environment, 'DB_HOST' => 'localhost'],
            [...$environment, 'DB_PORT' => '3307'],
            [...$environment, 'DB_SOCKET' => 'mysql.sock'],
            [...$environment, 'DB_URL' => 'mysql://override'],
            [...$environment, 'PHASE10_TEST_SCHEMA_DISPOSABLE' => 'false'],
        ] as $unsafe) {
            $this->assertRejected(function () use ($unsafe): void {
                TestDatabaseGuard::assertSafe($unsafe, allowPhase10Runner: true);
            });
        }
        $this->expectException(RuntimeException::class);
        TestDatabaseGuard::assertSafe($environment);
    }

    public function test_phase10_ownership_manifest_requires_exact_target_fingerprint_and_inventory(): void
    {
        $objects = ['attendance_entries', 'migrations'];
        $migrations = [['migration' => 'one', 'batch' => 1]];
        $manifest = [
            'database' => Phase10MySqlTestTarget::DATABASE,
            'account' => Phase10MySqlTestTarget::ACCOUNT.'@127.0.0.1',
            'server_uuid' => 'server-uuid', 'fingerprint' => 'fingerprint',
            'objects' => $objects, 'migrations' => $migrations,
            'authorization' => Phase10MySqlTestTarget::AUTHORIZATION,
        ];
        Phase10OwnershipManifestPolicy::assertOwned($manifest, 'server-uuid', 'fingerprint', $objects, $migrations);
        foreach ([
            [...$manifest, 'database' => 'techmentors_lms_dev'],
            [...$manifest, 'account' => 'root@localhost'],
            [...$manifest, 'server_uuid' => 'other-server'],
            [...$manifest, 'fingerprint' => 'tampered'],
            [...$manifest, 'objects' => ['unexpected_table']],
            [...$manifest, 'migrations' => []],
            [...$manifest, 'authorization' => ''],
        ] as $tampered) {
            $this->assertRejected(function () use ($tampered, $objects, $migrations): void {
                Phase10OwnershipManifestPolicy::assertOwned($tampered, 'server-uuid', 'fingerprint', $objects, $migrations);
            });
        }
    }

    public function test_phase10_forward_migration_transition_only_allows_the_reviewed_schema_delta(): void
    {
        $before = [
            'objects' => ['attendance_justifications', 'migrations'],
            'definitions' => ['attendance_justifications' => 'old-parent', 'migrations' => 'stable-migrations'],
            'migrations' => [['migration' => 'one', 'batch' => 1]], 'fingerprint' => 'before',
        ];
        $after = [
            'objects' => ['attendance_justification_evidence', 'attendance_justifications', 'migrations'],
            'definitions' => ['attendance_justification_evidence' => 'new-table', 'attendance_justifications' => 'parent-with-composite-key', 'migrations' => 'stable-migrations'],
            'migrations' => [['migration' => 'one', 'batch' => 1], ['migration' => 'two', 'batch' => 2]], 'fingerprint' => 'after',
        ];
        Phase10OwnershipManifestPolicy::assertForwardMigrationTransition($before, $after, ['one', 'two'], ['attendance_justification_evidence'], ['attendance_justifications']);

        $unexpectedObject = $after;
        $unexpectedObject['objects'][] = 'unowned_table';
        $this->assertRejected(fn () => Phase10OwnershipManifestPolicy::assertForwardMigrationTransition($before, $unexpectedObject, ['one', 'two'], ['attendance_justification_evidence'], ['attendance_justifications']));

        $unexpectedDefinition = $after;
        $unexpectedDefinition['definitions']['migrations'] = 'changed-migration-ledger';
        $this->assertRejected(fn () => Phase10OwnershipManifestPolicy::assertForwardMigrationTransition($before, $unexpectedDefinition, ['one', 'two'], ['attendance_justification_evidence'], ['attendance_justifications']));

        $gap = $after;
        $gap['migrations'] = [['migration' => 'one', 'batch' => 1], ['migration' => 'unexpected', 'batch' => 2]];
        $this->assertRejected(fn () => Phase10OwnershipManifestPolicy::assertForwardMigrationTransition($before, $gap, ['one', 'two'], ['attendance_justification_evidence'], ['attendance_justifications']));
    }

    /** @param callable():void $operation */
    private function assertRejected(callable $operation): RuntimeException
    {
        try {
            $operation();
        } catch (RuntimeException $exception) {
            $this->assertNotSame('', $exception->getMessage());

            return $exception;
        }

        self::fail('The unsafe Phase 10 state must be rejected.');
    }
}
