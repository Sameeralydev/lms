<?php

namespace Tests\Support;

use RuntimeException;

/** Allows only deterministic foundation permission/bootstrap rows created by local migrations. */
final class Phase04MySqlTestDataPolicy
{
    /** @var list<string> */
    private const PERMISSION_KEYS = [
        'academic.audit.view', 'academic.calendar.manage', 'academic.catalog.manage',
        'academic.classes.manage', 'academic.credit.manage', 'academic.documents.manage',
        'academic.outcomes.manage', 'academic.periods.manage', 'academic.policy.approve',
        'academic.programs.manage', 'academic.quality.manage', 'academic.regional.manage',
        'academic.settings.manage', 'academic.structure.manage', 'academic.view',
        'admissions.applications.review', 'admissions.applications.view',
        'admissions.assessments.manage', 'admissions.audit.view', 'admissions.decisions.approve',
        'admissions.decisions.recommend', 'admissions.documents.review',
        'admissions.eligibility.manage', 'admissions.fees.manage', 'admissions.forms.manage',
        'admissions.handoff.manage', 'admissions.inquiries.manage', 'admissions.intakes.manage',
        'admissions.merit.manage', 'admissions.offers.manage', 'admissions.settings.manage',
        'admissions.view', 'admissions.waitlist.manage', 'admissions.guardians.verify',
        'sis.view', 'sis.students.create', 'sis.students.update', 'sis.students.status',
        'sis.placement.manage', 'sis.guardians.manage', 'sis.guardians.verify', 'sis.family.manage',
        'sis.custom-fields.manage', 'sis.custom-fields.sensitive', 'sis.documents.view',
        'sis.documents.manage', 'sis.import', 'sis.export', 'sis.audit.view', 'sis.settings.manage',
        'sis.transfers.manage', 'sis.transfers.approve',
        'sis.identity.reconcile.request', 'sis.identity.reconcile.approve',
        'sis.promotion.approve', 'sis.promotion.holds.manage', 'sis.promotion.correct',
        'sis.lifecycle.approve',
        'academic.curriculum.view', 'academic.curriculum.manage', 'academic.curriculum.review',
        'academic.curriculum.publish', 'academic.curriculum.templates.share', 'academic.curriculum.audit.view',
    ];

    /** @var list<string> */
    private const PHASE07_PERMISSION_KEYS = [
        'learning.delivery.view', 'learning.delivery.manage', 'learning.delivery.teach',
        'learning.delivery.review', 'learning.delivery.publish', 'learning.delivery.learn',
    ];

    /** @var list<string> */
    private const PHASE07_RELEASE_EXCEPTION_PERMISSION_KEYS = ['learning.delivery.release-exceptions.approve'];

    /** @param list<array{migration:string,batch:int}> $applied
     * @param  list<string>  $expected
     */
    public static function assertCompleteMigrationInventory(array $applied, array $expected): void
    {
        $appliedNames = array_column($applied, 'migration');
        $expectedNames = $expected;
        sort($appliedNames);
        sort($expectedNames);

        if (count($appliedNames) !== count(array_unique($appliedNames)) || $appliedNames !== $expectedNames) {
            $missing = array_values(array_diff($expectedNames, $appliedNames));
            $unknown = array_values(array_diff($appliedNames, $expectedNames));
            $details = [];
            if ($missing !== []) {
                $details[] = 'missing: '.implode(', ', $missing);
            }
            if ($unknown !== []) {
                $details[] = 'unknown: '.implode(', ', $unknown);
            }
            if (count($appliedNames) !== count(array_unique($appliedNames))) {
                $details[] = 'duplicate migration records';
            }

            throw new RuntimeException('The Phase 04 schema does not have the complete local migration inventory ('.implode('; ', $details).').');
        }
    }

    /** @param list<array{migration:string,batch:int}> $applied */
    public static function assertSingleFreshMigrationBatch(array $applied): void
    {
        if ($applied === [] || count(array_unique(array_column($applied, 'batch'))) !== 1 || (int) $applied[0]['batch'] !== 1) {
            throw new RuntimeException('The authorized fresh migration state must contain one complete batch numbered one.');
        }
    }

    /** @param list<array{migration:string,batch:int}> $applied
     * @param  list<string>  $expected
     */
    public static function assertAuthorizedMigrationPrefix(array $applied, array $expected): void
    {
        $names = array_column($applied, 'migration');
        if (
            $names === []
            || count($names) !== count(array_unique($names))
            || array_slice($expected, 0, count($names)) !== $names
            || count($names) >= count($expected)
            || count(array_unique(array_column($applied, 'batch'))) !== 1
            || (int) $applied[0]['batch'] !== 1
        ) {
            throw new RuntimeException('The Phase 04 V2 schema is not an exact interrupted prefix of the authorized migration run.');
        }
    }

    /** @param list<array{migration:string,batch:int}> $applied
     * @param  array<string,string>  $migrationFiles
     * @return list<string>
     */
    public static function expectedCreatedTables(array $applied, array $migrationFiles): array
    {
        $tables = ['migrations'];
        foreach (array_column($applied, 'migration') as $migration) {
            $path = $migrationFiles[$migration] ?? null;
            if (! is_string($path) || ! is_file($path)) {
                throw new RuntimeException('The Phase 04 V2 migration inventory references a missing local migration file.');
            }

            $source = file_get_contents($path);
            if ($source === false) {
                throw new RuntimeException('The Phase 04 V2 migration source could not be read for schema ownership validation.');
            }

            preg_match_all('/Schema::create\s*\(\s*[\'\"]([A-Za-z0-9_]+)[\'\"]/', $source, $matches);
            foreach ($matches[1] as $table) {
                $tables[] = (string) $table;
            }
        }

        $tables = array_values(array_unique($tables));
        sort($tables);

        return $tables;
    }

    /** @param list<array{name:string,type:string}> $actualObjects
     * @param  list<array{migration:string,batch:int}>  $applied
     * @param  array<string,string>  $migrationFiles
     */
    public static function assertExpectedTableInventory(array $actualObjects, array $applied, array $migrationFiles): void
    {
        $actual = [];
        foreach ($actualObjects as $object) {
            if ($object['type'] !== 'BASE TABLE') {
                throw new RuntimeException('The Phase 04 V2 schema contains a view or non-table object not created by its migrations.');
            }
            $actual[] = $object['name'];
        }
        sort($actual);
        $expected = self::expectedCreatedTables($applied, $migrationFiles);

        if ($actual !== $expected) {
            $missing = array_values(array_diff($expected, $actual));
            $unexpected = array_values(array_diff($actual, $expected));
            $details = [];
            if ($missing !== []) {
                $details[] = 'missing: '.implode(', ', $missing);
            }
            if ($unexpected !== []) {
                $details[] = 'unexpected: '.implode(', ', $unexpected);
            }

            throw new RuntimeException('The Phase 04 V2 schema objects do not match the applied local migration prefix ('.implode('; ', $details).').');
        }
    }

    /** @param list<string> $tables
     * @param  array<string,int>  $rowCounts
     * @param  list<array{key:string,scope:string}>  $permissionRows
     * @param  list<array{id:int,completed:int,completed_at:?string}>  $bootstrapRows
     * @param  list<array{migration:string,batch:int}>  $applied
     */
    public static function assertAuthorizedMigrationPrefixBaseline(
        array $tables,
        array $rowCounts,
        array $permissionRows,
        array $bootstrapRows,
        array $applied,
    ): void {
        if (! in_array('migrations', $tables, true) || ! in_array('permissions', $tables, true) || ! in_array('platform_bootstrap_states', $tables, true)) {
            throw new RuntimeException('The interrupted Phase 04 V2 migration prefix is missing migration-owned foundation tables.');
        }

        foreach ($tables as $table) {
            if (in_array($table, ['migrations', 'permissions', 'platform_bootstrap_states'], true)) {
                continue;
            }
            if (($rowCounts[$table] ?? 0) !== 0) {
                throw new RuntimeException('The interrupted Phase 04 V2 schema contains non-migration fixture rows.');
            }
        }

        if (($rowCounts['migrations'] ?? 0) !== count($applied)) {
            throw new RuntimeException('The Phase 04 V2 migration ledger row count does not match its migration inventory.');
        }

        $expectedKeys = [];
        $appliedNames = array_column($applied, 'migration');
        if (in_array('2026_10_22_000006_backfill_academic_permissions_for_existing_tenants', $appliedNames, true)) {
            $expectedKeys = array_merge($expectedKeys, array_slice(self::PERMISSION_KEYS, 0, 15));
        }
        if (in_array('2026_10_22_000008_backfill_admissions_permissions', $appliedNames, true)) {
            $expectedKeys = array_merge($expectedKeys, array_slice(self::PERMISSION_KEYS, 15, 18));
        }
        if (in_array('2026_10_22_000010_create_admission_guardian_authorizations', $appliedNames, true)) {
            $expectedKeys[] = 'admissions.guardians.verify';
        }
        if (in_array('2026_10_23_000001_enable_sis_for_existing_tenants', $appliedNames, true)) {
            $expectedKeys = array_merge($expectedKeys, [
                'sis.view', 'sis.students.create', 'sis.students.update', 'sis.students.status',
                'sis.placement.manage', 'sis.guardians.manage', 'sis.guardians.verify', 'sis.family.manage',
                'sis.custom-fields.manage', 'sis.custom-fields.sensitive', 'sis.documents.view',
                'sis.documents.manage', 'sis.import', 'sis.export', 'sis.audit.view', 'sis.settings.manage',
            ]);
        }
        if (in_array('2026_10_23_000004_create_sis_student_transfer_cases', $appliedNames, true)) {
            $expectedKeys = array_merge($expectedKeys, ['sis.transfers.manage', 'sis.transfers.approve']);
        }
        if (in_array('2026_10_24_000000_create_sis_identity_reconciliation_cases', $appliedNames, true)) {
            $expectedKeys = array_merge($expectedKeys, ['sis.identity.reconcile.request', 'sis.identity.reconcile.approve']);
        }
        if (in_array('2026_10_24_000001_create_sis_student_promotion_holds', $appliedNames, true)) {
            $expectedKeys = array_merge($expectedKeys, ['sis.promotion.approve', 'sis.promotion.holds.manage', 'sis.promotion.correct']);
        }
        if (in_array('2026_10_24_000003_complete_sis_operations_workflows', $appliedNames, true)) {
            $expectedKeys[] = 'sis.lifecycle.approve';
        }

        $expectedPermissionRows = array_map(static fn (string $key): array => ['key' => $key, 'scope' => 'tenant'], $expectedKeys);
        $actualPermissionRows = array_map(static fn (array $row): array => ['key' => (string) $row['key'], 'scope' => (string) $row['scope']], $permissionRows);
        usort($expectedPermissionRows, static fn (array $left, array $right): int => $left['key'] <=> $right['key']);
        usort($actualPermissionRows, static fn (array $left, array $right): int => $left['key'] <=> $right['key']);
        if (($rowCounts['permissions'] ?? 0) !== count($expectedKeys) || $actualPermissionRows !== $expectedPermissionRows) {
            throw new RuntimeException('The interrupted Phase 04 V2 permission rows do not match the exact applied migration prefix.');
        }

        if ($bootstrapRows !== [['id' => 1, 'completed' => 0, 'completed_at' => null]] || ($rowCounts['platform_bootstrap_states'] ?? 0) !== 1) {
            throw new RuntimeException('The interrupted Phase 04 V2 bootstrap row is not the expected empty migration baseline.');
        }
    }

    /** @param list<string> $tables
     * @param  array<string, int>  $rowCounts
     * @param  list<array{key:string,scope:string}>  $permissionRows
     * @param  list<array{id:int,completed:int,completed_at:?string}>  $bootstrapRows
     * @param  list<array{migration:string,batch:int}>  $applied
     */
    public static function assertMigrationOwnedBaseline(array $tables, array $rowCounts, array $permissionRows, array $bootstrapRows, array $applied = []): void
    {
        if (! in_array('permissions', $tables, true) || ! in_array('platform_bootstrap_states', $tables, true)) {
            throw new RuntimeException('The Phase 04 schema is missing a required migration-owned baseline table.');
        }

        foreach ($tables as $table) {
            if (in_array($table, ['migrations', 'permissions', 'platform_bootstrap_states'], true)) {
                continue;
            }

            if (($rowCounts[$table] ?? 0) !== 0) {
                throw new RuntimeException('The Phase 04 test schema contains persistent non-migration rows; no database changes were made.');
            }
        }

        $permissionKeys = self::PERMISSION_KEYS;
        if (in_array('2026_10_27_000000_create_course_delivery_foundation', array_column($applied, 'migration'), true)) {
            $permissionKeys = [...$permissionKeys, ...self::PHASE07_PERMISSION_KEYS];
        }
        if (in_array(Phase07MigrationUpgradePolicy::MIGRATION, array_column($applied, 'migration'), true)) {
            $permissionKeys = [...$permissionKeys, ...self::PHASE07_RELEASE_EXCEPTION_PERMISSION_KEYS];
        }
        $expectedPermissionRows = array_map(static fn (string $key): array => ['key' => $key, 'scope' => 'tenant'], $permissionKeys);
        $actualPermissionRows = array_map(static fn (array $row): array => [
            'key' => (string) $row['key'],
            'scope' => (string) $row['scope'],
        ], $permissionRows);
        usort($expectedPermissionRows, static fn (array $left, array $right): int => $left['key'] <=> $right['key']);
        usort($actualPermissionRows, static fn (array $left, array $right): int => $left['key'] <=> $right['key']);

        if (($rowCounts['permissions'] ?? 0) !== count($permissionKeys) || $actualPermissionRows !== $expectedPermissionRows) {
            throw new RuntimeException('The Phase 04 permission rows do not match the exact migration-owned baseline.');
        }

        $expectedBootstrapRows = [['id' => 1, 'completed' => 0, 'completed_at' => null]];
        if ($bootstrapRows !== $expectedBootstrapRows || ($rowCounts['platform_bootstrap_states'] ?? 0) !== 1) {
            throw new RuntimeException('The Phase 04 platform bootstrap row does not match the exact migration-owned baseline.');
        }
    }

    /** @return list<string> */
    public static function migrationOwnedPermissionKeys(): array
    {
        return self::PERMISSION_KEYS;
    }
}
