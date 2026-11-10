<?php

namespace Tests\Support;

use RuntimeException;

/** Verifies a complete migration-only Phase 06 schema before interrupted-run re-attestation. */
final class Phase06OwnershipReconciliationPolicy
{
    /**
     * @param  array<string,mixed>  $storedManifest
     * @param  array<string,mixed>  $snapshot
     * @param  list<string>  $localMigrationNames
     * @param  array<string,string>  $localMigrationFiles
     * @param  array<string,int>  $rowCounts
     * @param  list<array{key:string,scope:string}>  $permissionRows
     * @param  list<array{id:int,completed:int,completed_at:?string}>  $bootstrapRows
     */
    public static function assertAuthorizedInterruptedRun(
        array $storedManifest,
        array $snapshot,
        string $authorization,
        array $localMigrationNames,
        array $localMigrationFiles,
        array $rowCounts,
        array $permissionRows,
        array $bootstrapRows,
    ): void {
        if (! Phase04V2OwnershipResumePolicy::isOriginalEmptyManifest($storedManifest, $snapshot, $authorization)) {
            throw new RuntimeException('Phase 06 recovery requires the original empty-schema ownership manifest and matching server identity.');
        }

        if (($snapshot['database'] ?? null) !== Phase06MySqlTestTarget::DATABASE
            || ($snapshot['username'] ?? null) !== Phase06MySqlTestTarget::ACCOUNT
            || ($snapshot['host'] ?? null) !== '127.0.0.1'
            || ! str_starts_with((string) ($snapshot['server_version'] ?? ''), '8.4.')) {
            throw new RuntimeException('Phase 06 recovery requires the exact dedicated test database, account, endpoint, and MySQL version.');
        }

        Phase04MySqlTestDataPolicy::assertCompleteMigrationInventory($snapshot['migrations'] ?? [], $localMigrationNames);
        Phase04MySqlTestDataPolicy::assertSingleFreshMigrationBatch($snapshot['migrations']);
        Phase04MySqlTestDataPolicy::assertExpectedTableInventory(
            $snapshot['object_inventory']['tables_and_views'] ?? [],
            $snapshot['migrations'],
            $localMigrationFiles,
        );
        $objectNames = array_map(static fn (array $object): string => (string) $object['name'], $snapshot['object_inventory']['tables_and_views']);
        if ($objectNames !== ($snapshot['tables'] ?? []) || ($rowCounts['migrations'] ?? 0) !== count($snapshot['migrations'])) {
            throw new RuntimeException('Phase 06 recovery found a mismatch between the migration ledger, table inventory, and row counts.');
        }

        if (($snapshot['object_inventory']['auxiliary'] ?? null) !== []) {
            throw new RuntimeException('Phase 06 recovery rejects schema objects outside the local migration inventory.');
        }

        Phase04MySqlTestDataPolicy::assertMigrationOwnedBaseline(
            $snapshot['tables'],
            $rowCounts,
            $permissionRows,
            $bootstrapRows,
        );
    }
}
