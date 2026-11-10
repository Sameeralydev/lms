<?php

namespace Tests\Support;

use RuntimeException;

/** Restricts recovery evidence to the original batch plus reviewed Phase 07 migrations. */
final class Phase07MigrationBatchPolicy
{
    private const PROGRESS_MIGRATION = '2026_10_27_000001_create_course_delivery_progress';

    private const MEMBERSHIP_KEY_MIGRATION = '2026_10_27_000002_add_tenant_safe_progress_membership_foreign_key';

    /** @param list<array{migration:string,batch:int}> $applied
     * @param  list<string>  $expected
     */
    public static function assertAuthorizedPrefix(array $applied, array $expected): void
    {
        $names = array_column($applied, 'migration');
        if ($names === []
            || count($names) >= count($expected)
            || count($names) !== count(array_unique($names))
            || array_slice($expected, 0, count($names)) !== $names) {
            throw new RuntimeException('The Phase 07 schema is not an exact prefix of the reviewed migration inventory.');
        }

        self::assertBatchLayout($applied, $expected, false);
    }

    /** @param list<array{migration:string,batch:int}> $applied
     * @param  list<string>  $expected
     */
    public static function assertRecoveredCompletion(array $applied, array $expected): void
    {
        $names = array_column($applied, 'migration');
        if ($names !== $expected || count($names) !== count(array_unique($names))) {
            throw new RuntimeException('The recovered Phase 07 database does not have the complete local migration inventory.');
        }

        self::assertBatchLayout($applied, $expected, true);
    }

    /** @param list<array{migration:string,batch:int}> $before
     * @param  list<array{migration:string,batch:int}>  $after
     * @param  list<string>  $expected
     */
    public static function assertAuthorizedTransition(array $before, array $after, array $expected): void
    {
        self::assertRecoveredCompletion($after, $expected);
        if (array_slice($after, 0, count($before)) !== $before) {
            throw new RuntimeException('The Phase 07 recovered run changed previously recorded migration ownership or batches.');
        }
    }

    /** @param list<array{migration:string,batch:int}> $rows
     * @param  list<string>  $expected
     */
    private static function assertBatchLayout(array $rows, array $expected, bool $complete): void
    {
        $progressPosition = array_search(self::PROGRESS_MIGRATION, $expected, true);
        if (! is_int($progressPosition)
            || ($expected[$progressPosition + 1] ?? null) !== self::MEMBERSHIP_KEY_MIGRATION) {
            throw new RuntimeException('The reviewed Phase 07 migration transition is not present in the local inventory.');
        }

        foreach ($rows as $index => $row) {
            $expectedBatch = $index < $progressPosition ? 1 : 2;
            if ($index === $progressPosition + 1 && $complete && (int) $row['batch'] === 3) {
                continue;
            }
            if ((int) $row['batch'] !== $expectedBatch) {
                throw new RuntimeException('The Phase 07 migration batches do not match the single authorized recovery transition.');
            }
        }

        if ($complete && count($rows) !== count($expected)) {
            throw new RuntimeException('The recovered Phase 07 migration batch inventory is incomplete.');
        }
        if (! $complete && count($rows) > $progressPosition + 1) {
            throw new RuntimeException('The partial Phase 07 migration prefix includes an unapproved later migration.');
        }
    }
}
