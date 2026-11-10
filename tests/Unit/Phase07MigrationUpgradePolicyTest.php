<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use Tests\Support\Phase07MigrationUpgradePolicy;
use Tests\Support\Phase07MySqlTestTarget;

final class Phase07MigrationUpgradePolicyTest extends TestCase
{
    private const LOCAL = ['foundation', '2026_10_27_000000_create_course_delivery_foundation', '2026_10_27_000001_create_course_delivery_progress', '2026_10_27_000002_add_tenant_safe_progress_membership_foreign_key', '2026_10_27_000003_create_course_delivery_activity_responses', '2026_10_28_000000_create_course_delivery_templates', Phase07MigrationUpgradePolicy::MIGRATION];

    private const APPLIED = [
        ['migration' => 'foundation', 'batch' => 1],
        ['migration' => '2026_10_27_000000_create_course_delivery_foundation', 'batch' => 1],
        ['migration' => '2026_10_27_000001_create_course_delivery_progress', 'batch' => 2],
        ['migration' => '2026_10_27_000002_add_tenant_safe_progress_membership_foreign_key', 'batch' => 3],
        ['migration' => '2026_10_27_000003_create_course_delivery_activity_responses', 'batch' => 4],
        ['migration' => '2026_10_28_000000_create_course_delivery_templates', 'batch' => 5],
    ];

    public function test_exact_phase07_prefix_authorizes_only_the_release_exception_migration(): void
    {
        self::assertSame(Phase07MigrationUpgradePolicy::MIGRATION, Phase07MigrationUpgradePolicy::assertExactlyOnePending(self::APPLIED, self::LOCAL));
        Phase07MigrationUpgradePolicy::assertCompletedExactlyOne(self::APPLIED, [...self::APPLIED, ['migration' => Phase07MigrationUpgradePolicy::MIGRATION, 'batch' => 6]], self::LOCAL);
    }

    public function test_wrong_or_multiple_pending_migrations_are_rejected(): void
    {
        try {
            Phase07MigrationUpgradePolicy::assertExactlyOnePending(self::APPLIED, [...self::LOCAL, 'unexpected_migration']);
            self::fail('An additional trailing migration must not be authorized.');
        } catch (RuntimeException $exception) {
            self::assertStringContainsString('exact owned migration prefix', $exception->getMessage());
        }
    }

    public function test_phase07_schema_inventory_excludes_later_phase_migrations_without_weakening_its_cutoff(): void
    {
        self::assertTrue(Phase07MySqlTestTarget::includesMigration(Phase07MigrationUpgradePolicy::MIGRATION));
        self::assertFalse(Phase07MySqlTestTarget::includesMigration('2026_10_29_000001_create_course_enrollment_foundation'));
        self::assertFalse(Phase07MySqlTestTarget::includesMigration('2026_10_29_000002_backfill_course_enrollment_permissions'));
        self::assertFalse(Phase07MySqlTestTarget::includesMigration('2026_10_30_000000_unreviewed_migration'));
    }

    public function test_changed_existing_migration_history_is_rejected(): void
    {
        $tampered = self::APPLIED;
        $tampered[2]['migration'] = 'unauthorized_migration';
        $this->expectException(RuntimeException::class);
        Phase07MigrationUpgradePolicy::assertExactlyOnePending($tampered, self::LOCAL);
    }

    public function test_only_reviewed_exception_table_and_permission_rows_can_be_added(): void
    {
        $before = ['course_delivery_blocks', 'course_delivery_progress'];
        $after = [...$before, ...Phase07MigrationUpgradePolicy::ADDED_TABLES];
        $changed = [];
        self::assertEqualsCanonicalizing(Phase07MigrationUpgradePolicy::ADDED_TABLES, array_values(array_diff($after, $before)));
        self::assertSame([], array_values(array_diff($before, $after)));
        Phase07MigrationUpgradePolicy::assertObjectTransition($before, $after);
        Phase07MigrationUpgradePolicy::assertChangedTables($changed);
        try {
            Phase07MigrationUpgradePolicy::assertChangedTables(['permissions']);
            self::fail('An existing schema definition change must not be authorized.');
        } catch (RuntimeException) {
            // Expected rejection for unrelated data mutation.
        }
    }

    public function test_unexpected_table_or_existing_table_changes_are_rejected(): void
    {
        try {
            Phase07MigrationUpgradePolicy::assertObjectTransition(['course_delivery_blocks'], ['course_delivery_blocks', 'unexpected']);
            self::fail('Unexpected table addition must be refused.');
        } catch (RuntimeException) {
            // Expected rejection for an unauthorized table addition.
        }
        $this->expectException(RuntimeException::class);
        Phase07MigrationUpgradePolicy::assertChangedTables(['course_delivery_progress', 'users']);
    }
}
