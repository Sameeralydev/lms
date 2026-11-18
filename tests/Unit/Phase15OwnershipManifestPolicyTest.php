<?php

namespace Tests\Unit;

use RuntimeException;
use Tests\Support\Phase15MySqlTestTarget;
use Tests\Support\Phase15OwnershipManifestPolicy;
use Tests\TestCase;

final class Phase15OwnershipManifestPolicyTest extends TestCase
{
    public function test_verified_manifest_must_match_exact_database_account_server_schema_and_migrations(): void
    {
        $objects = ['migrations', 'tenants'];
        $migrations = [['migration' => '2026_11_11_000000_backfill_academic_analytics_permissions', 'batch' => 1]];
        $manifest = [
            'database' => Phase15MySqlTestTarget::DATABASE,
            'account' => Phase15MySqlTestTarget::ACCOUNT.'@127.0.0.1',
            'server_uuid' => 'authorized-server-id',
            'data_directory' => 'C:/isolated/phase15/data',
            'authorization' => Phase15MySqlTestTarget::AUTHORIZATION,
            'fingerprint' => 'expected-fingerprint',
            'objects' => $objects,
            'migrations' => $migrations,
        ];

        Phase15OwnershipManifestPolicy::assertOwned($manifest, 'authorized-server-id', 'C:/isolated/phase15/data', 'expected-fingerprint', $objects, $migrations);

        foreach ([
            [...$manifest, 'database' => 'techmentors_lms_dev'],
            [...$manifest, 'account' => 'root@localhost'],
            [...$manifest, 'server_uuid' => 'other-server'],
            [...$manifest, 'data_directory' => 'C:/xampp/mysql/data'],
            [...$manifest, 'fingerprint' => 'tampered'],
            [...$manifest, 'objects' => ['migrations', 'unexpected_table']],
            [...$manifest, 'migrations' => []],
        ] as $tampered) {
            try {
                Phase15OwnershipManifestPolicy::assertOwned($tampered, 'authorized-server-id', 'C:/isolated/phase15/data', 'expected-fingerprint', $objects, $migrations);
                self::fail('Tampered Phase 15 ownership metadata must be rejected.');
            } catch (RuntimeException $exception) {
                self::assertSame('Phase 15 schema differs from its local ownership manifest.', $exception->getMessage());
            }
        }
    }
}
