<?php

namespace Tests\Support;

use RuntimeException;

/** One reviewed additive release-exception migration against an owned Phase 07 schema. */
final class Phase07MigrationUpgradePolicy
{
    public const MIGRATION = '2026_10_29_000000_create_course_delivery_release_exceptions';

    /** @var list<string> */
    public const ADDED_TABLES = [
        'course_delivery_release_exceptions',
    ];

    /** @param list<array{migration:string,batch:int}> $applied
     * @param  list<string>  $local
     */
    public static function assertExactlyOnePending(array $applied, array $local): string
    {
        $names = array_column($applied, 'migration');
        $batches = array_map(static fn (array $row): int => (int) $row['batch'], $applied);
        $batchSet = array_values(array_unique($batches));
        sort($batchSet);
        if ($local === [] || $local[array_key_last($local)] !== self::MIGRATION
            || count($names) !== count($local) - 1 || array_slice($local, 0, count($names)) !== $names
            || count($names) !== count(array_unique($names)) || $batches === []
            || min($batches) !== 1 || $batchSet !== range(1, max($batchSet))
            || self::hasDecreasingBatch($batches)) {
            throw new RuntimeException('A Phase 07 guarded upgrade requires the exact owned migration prefix and one reviewed trailing release-exception migration.');
        }

        return self::MIGRATION;
    }

    /** @param list<array{migration:string,batch:int}> $before
     * @param  list<array{migration:string,batch:int}>  $after
     * @param  list<string>  $local
     */
    public static function assertCompletedExactlyOne(array $before, array $after, array $local): void
    {
        self::assertExactlyOnePending($before, $local);
        if (array_column($after, 'migration') !== $local || array_slice($after, 0, count($before)) !== $before
            || count($after) !== count($before) + 1
            || (int) ($after[count($before)]['batch'] ?? 0) !== max(array_column($before, 'batch')) + 1) {
            throw new RuntimeException('The Phase 07 migration ledger did not record exactly the authorized release-exception migration.');
        }
    }

    /** @param list<string> $beforeTables
     * @param  list<string>  $afterTables
     */
    public static function assertObjectTransition(array $beforeTables, array $afterTables): void
    {
        $added = array_values(array_diff($afterTables, $beforeTables));
        $removed = array_values(array_diff($beforeTables, $afterTables));
        sort($added);
        sort($removed);
        $expectedAdded = self::ADDED_TABLES;
        sort($expectedAdded);
        if ($added !== $expectedAdded || $removed !== []) {
            throw new RuntimeException('The Phase 07 release-exception migration changed the table inventory outside its reviewed contract.');
        }
    }

    /** @param list<string> $changed */
    public static function assertChangedTables(array $changed): void
    {
        sort($changed);
        // The migration adds the permission and owner/admin role grants as rows;
        // this list tracks schema definitions, not row contents.
        $expected = [];
        sort($expected);
        if ($changed !== $expected) {
            throw new RuntimeException('The Phase 07 release-exception migration changed existing table definitions outside its reviewed schema contract: '.implode(', ', $changed).'.');
        }
    }

    /** @param list<int> $batches */
    private static function hasDecreasingBatch(array $batches): bool
    {
        $last = 0;
        foreach ($batches as $batch) {
            if ($batch < $last) {
                return true;
            }
            $last = $batch;
        }

        return false;
    }
}
