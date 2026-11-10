<?php

namespace Tests\Support;

use RuntimeException;

/** One forensic, fingerprint-pinned recovery of the known interrupted Phase 08 first migration. */
final class Phase08MigrationRecoveryPolicy
{
    public const MIGRATION = '2026_10_30_000000_expand_course_enrollment_lifecycle';

    public const INITIAL_FINGERPRINT = '601cb6535db75ad646df9aa9f9e60d74d3e6c5a3dd8ab2d21dffad6ef0a01ea6';

    public const PARTIAL_FINGERPRINT = 'c96b4fe4314ffcd81d49f1a48c9f8a66d2ba81c89503d7a6b39d56f48890c7c9';

    public const PARTIAL_OBJECT_COUNT = 144;

    public const PARTIAL_MIGRATION_COUNT = 43;

    public const RETRY_FINGERPRINT = 'a8362ed961a0acedfda234c83ee1b9eb1015263e7a18a4585938124017a176e4';

    public const RETRY_OBJECT_COUNT = 145;

    public const FIRST_RECOVERY_MIGRATION_SHA256 = '6ce1dfe710da07d8507b4f321900a4e5cc047ea8ed081c3639ff4a2941df144e';

    public const RESUME_MIGRATION_SHA256 = 'be6632a46be6916cd115d03d893cbb6602a5fbf6421d5ff81aea91c48cf97504';

    /** @var list<string> */
    public const TABLES_CREATED_BY_MIGRATION = [
        'enrollment_policy_academic_levels',
        'course_prerequisite_rules',
        'course_prerequisite_exceptions',
        'course_enrollment_events',
        'course_section_transfers',
        'course_enrollment_batches',
        'course_enrollment_batch_rows',
    ];

    /** @param array<string,mixed> $intent
     * @param  array{database:string,account:string,server_uuid:string}  $identity
     */
    public static function assertIntent(array $intent, array $identity): void
    {
        if (($intent['database'] ?? null) !== Phase08MySqlTestTarget::DATABASE
            || ($intent['account'] ?? null) !== Phase08MySqlTestTarget::ACCOUNT.'@127.0.0.1'
            || ($intent['server_uuid'] ?? null) !== $identity['server_uuid']
            || $intent['server_uuid'] === ''
            || $identity['database'] !== Phase08MySqlTestTarget::DATABASE
            || $identity['account'] !== Phase08MySqlTestTarget::ACCOUNT.'@127.0.0.1'
            || ($intent['initial_fingerprint'] ?? null) !== self::INITIAL_FINGERPRINT
            || ($intent['authorization'] ?? null) !== Phase08MySqlTestTarget::AUTHORIZATION
            || ! is_string($intent['created_at_utc'] ?? null)
            || strtotime($intent['created_at_utc']) === false) {
            throw new RuntimeException('The Phase 08 migration intent does not prove the authorized initial target identity.');
        }
    }

    /** @param list<string> $objects
     * @param  list<array{migration:string,batch:int}>  $applied
     * @param  list<string>  $local
     * @param  array<string,int>  $nonemptyTables
     * @param  list<string>  $permissionKeys
     * @param  list<string>  $knownPermissionKeys
     * @param  list<array{key:string,description:string,scope:string}>  $permissionRows
     * @param  array<string,string>  $knownPermissionDescriptions
     * @param  array<string,string>  $knownPermissionScopes
     * @param  array<string,list<string>>  $presentColumns
     * @param  list<array{columns:list<string>,table:string,referenced_columns:list<string>}>  $presentForeignKeys
     * @param  list<string>  $presentTables
     * @param  list<array{id:int,completed:int,completed_at:?string}>  $bootstrapRows
     */
    public static function assertInterruptedState(
        string $fingerprint,
        array $objects,
        array $applied,
        array $local,
        array $nonemptyTables,
        array $permissionKeys,
        array $knownPermissionKeys,
        array $permissionRows,
        array $knownPermissionDescriptions,
        array $knownPermissionScopes,
        array $presentColumns,
        array $presentForeignKeys,
        array $presentTables,
        array $bootstrapRows,
    ): void {
        $appliedNames = array_column($applied, 'migration');
        $batches = array_values(array_unique(array_column($applied, 'batch')));
        sort($batches);
        ksort($nonemptyTables);

        $migrationsValid = count($applied) === self::PARTIAL_MIGRATION_COUNT
            && count($local) === self::PARTIAL_MIGRATION_COUNT + 1
            && array_slice($local, 0, self::PARTIAL_MIGRATION_COUNT) === $appliedNames
            && end($local) === self::MIGRATION
            && count($appliedNames) === count(array_unique($appliedNames))
            && $batches === [1];
        $nonemptyTablesValid = $nonemptyTables === ['migrations' => self::PARTIAL_MIGRATION_COUNT, 'permissions' => 76, 'platform_bootstrap_states' => 1];
        $permissionKeysValid = array_diff($permissionKeys, $knownPermissionKeys) === [];
        $bootstrapRowsValid = $bootstrapRows === [['id' => 1, 'completed' => 0, 'completed_at' => null]];

        if (! hash_equals(self::PARTIAL_FINGERPRINT, $fingerprint) || count($objects) !== self::PARTIAL_OBJECT_COUNT) {
            throw new RuntimeException('Phase 08 schema fingerprint or object inventory differs from the verified interrupted state.');
        }
        if (! $migrationsValid) {
            throw new RuntimeException('Phase 08 applied and local migration inventories differ from the verified interrupted prefix.');
        }
        if (! self::sameSet($presentTables, [self::TABLES_CREATED_BY_MIGRATION[0]])) {
            throw new RuntimeException('Phase 08 interrupted migration created an unexpected subset of tables.');
        }
        if (! $nonemptyTablesValid) {
            throw new RuntimeException('Phase 08 nonempty table inventory differs from the verified migration-generated baseline.');
        }
        if (! $permissionKeysValid) {
            throw new RuntimeException('Phase 08 permission key inventory differs from the local migration catalog.');
        }
        if (! $bootstrapRowsValid) {
            throw new RuntimeException('Phase 08 platform bootstrap state differs from the initial migration-generated row.');
        }

        foreach ($permissionRows as $permission) {
            if (($knownPermissionDescriptions[$permission['key']] ?? null) !== $permission['description']
                || ($knownPermissionScopes[$permission['key']] ?? null) !== $permission['scope']) {
                throw new RuntimeException('Phase 08 interrupted migration permission metadata differs from the local catalog.');
            }
        }

        $requiredColumns = [
            'enrollment_policies' => ['require_prerequisites', 'require_withdrawal_approval', 'allow_section_transfer', 'allow_late_registration', 'waitlist_ordering'],
            'course_offerings' => ['late_registration_closes_at', 'add_deadline_at', 'drop_deadline_at', 'withdrawal_deadline_at', 'institution_timezone', 'cancellation_reason', 'archived_at'],
            'course_enrollments' => ['credits_snapshot', 'withdrawal_reason', 'withdrawn_effective_at', 'archived_at'],
        ];
        foreach ($requiredColumns as $table => $columns) {
            if (array_diff($columns, $presentColumns[$table] ?? []) !== []) {
                throw new RuntimeException('The interrupted Phase 08 migration has an unexpected column transition.');
            }
        }

        $hasTenantForeignKey = false;
        $hasPolicyForeignKey = false;
        $hasUnfinishedAcademicLevelForeignKey = false;
        foreach ($presentForeignKeys as $foreignKey) {
            if ($foreignKey['columns'] === ['tenant_id'] && $foreignKey['table'] === 'tenants' && $foreignKey['referenced_columns'] === ['id']) {
                $hasTenantForeignKey = true;
            }
            if ($foreignKey['columns'] === ['tenant_id', 'policy_id'] && $foreignKey['table'] === 'enrollment_policies' && $foreignKey['referenced_columns'] === ['tenant_id', 'id']) {
                $hasPolicyForeignKey = true;
            }
            if ($foreignKey['columns'] === ['tenant_id', 'academic_level_id']) {
                $hasUnfinishedAcademicLevelForeignKey = true;
            }
        }
        if (! $hasTenantForeignKey || ! $hasPolicyForeignKey || $hasUnfinishedAcademicLevelForeignKey) {
            throw new RuntimeException('The existing policy-level table is not the exact known partial foreign-key state.');
        }
    }

    /** @param list<string> $objects
     * @param  list<array{migration:string,batch:int}>  $migrations
     */
    public static function assertInitialEmpty(array $objects, array $migrations): void
    {
        if ($objects !== [] || $migrations !== []) {
            throw new RuntimeException('Initial Phase 08 migration intent requires an empty schema and migration ledger.');
        }
    }

    /** @param array{database:string,account:string,server_uuid:string} $identity
     * @return array{database:string,account:string,server_uuid:string,initial_fingerprint:string,authorization:string,created_at_utc:string}
     */
    public static function createIntent(array $identity, string $fingerprint): array
    {
        if ($identity['database'] !== Phase08MySqlTestTarget::DATABASE
            || $identity['account'] !== Phase08MySqlTestTarget::ACCOUNT.'@127.0.0.1'
            || ! hash_equals(self::INITIAL_FINGERPRINT, $fingerprint)) {
            throw new RuntimeException('Cannot create Phase 08 migration intent for an unverified initial schema.');
        }

        return [
            'database' => $identity['database'], 'account' => $identity['account'],
            'server_uuid' => $identity['server_uuid'], 'initial_fingerprint' => $fingerprint,
            'authorization' => Phase08MySqlTestTarget::AUTHORIZATION, 'created_at_utc' => gmdate(DATE_ATOM),
        ];
    }

    public static function assertLocksAcquired(bool $fileLock, bool $mysqlLock): void
    {
        if (! $fileLock || ! $mysqlLock) {
            throw new RuntimeException('Phase 08 migration recovery requires both exclusive local and MySQL advisory locks.');
        }
    }

    /** @param array<string,mixed> $receipt
     * @param  array{database:string,account:string,server_uuid:string}  $identity
     * @param  list<string>  $objects
     * @param  list<array{migration:string,batch:int}>  $migrations
     * @param  list<string>  $presentTables
     * @param  array<string,int>  $nonemptyTables
     * @param  list<string>  $localMigrations
     */
    public static function assertInterruptedRetry(
        array $receipt,
        array $identity,
        string $intentHash,
        string $fingerprint,
        array $objects,
        array $migrations,
        array $presentTables,
        array $nonemptyTables,
        array $localMigrations,
        string $currentMigrationHash,
    ): void {
        ksort($nonemptyTables);
        if (($receipt['database'] ?? null) !== Phase08MySqlTestTarget::DATABASE
            || ($receipt['account'] ?? null) !== Phase08MySqlTestTarget::ACCOUNT.'@127.0.0.1'
            || ($receipt['server_uuid'] ?? null) !== $identity['server_uuid']
            || $identity['database'] !== Phase08MySqlTestTarget::DATABASE
            || $identity['account'] !== Phase08MySqlTestTarget::ACCOUNT.'@127.0.0.1'
            || ! hash_equals((string) ($receipt['intent_sha256'] ?? ''), $intentHash)
            || ($receipt['initial_fingerprint'] ?? null) !== self::INITIAL_FINGERPRINT
            || ($receipt['partial_fingerprint'] ?? null) !== self::PARTIAL_FINGERPRINT
            || ($receipt['recovered_migration'] ?? null) !== self::MIGRATION
            || ($receipt['migration_sha256'] ?? null) !== self::FIRST_RECOVERY_MIGRATION_SHA256
            || ($receipt['authorization'] ?? null) !== Phase08MySqlTestTarget::AUTHORIZATION
            || ! is_string($receipt['recovery_started_at_utc'] ?? null)
            || strtotime($receipt['recovery_started_at_utc']) === false
            || ! hash_equals(self::RETRY_FINGERPRINT, $fingerprint)
            || count($objects) !== self::RETRY_OBJECT_COUNT
            || count($migrations) !== self::PARTIAL_MIGRATION_COUNT
            || ($receipt['migrations_before'] ?? null) !== $migrations
            || count($localMigrations) !== self::PARTIAL_MIGRATION_COUNT + 1
            || array_slice($localMigrations, 0, self::PARTIAL_MIGRATION_COUNT) !== array_column($migrations, 'migration')
            || end($localMigrations) !== self::MIGRATION
            || ! self::sameSet($presentTables, ['enrollment_policy_academic_levels', 'course_prerequisite_rules'])
            || $nonemptyTables !== ['migrations' => self::PARTIAL_MIGRATION_COUNT, 'permissions' => 76, 'platform_bootstrap_states' => 1]
            || ($receipt['nonempty_tables_before'] ?? null) !== ['migrations' => self::PARTIAL_MIGRATION_COUNT, 'permissions' => 76, 'platform_bootstrap_states' => 1]
            || ! hash_equals(self::RESUME_MIGRATION_SHA256, $currentMigrationHash)) {
            throw new RuntimeException('Phase 08 schema does not match the exact authorized interrupted migration retry state.');
        }
    }

    /** @param array<string,mixed> $manifest */
    public static function assertOwnedManifest(array $manifest, string $database, string $serverUuid, string $fingerprint): void
    {
        if (($manifest['database'] ?? null) !== $database
            || ($manifest['server_uuid'] ?? null) !== $serverUuid
            || ($manifest['authorization'] ?? null) !== Phase08MySqlTestTarget::AUTHORIZATION
            || ! is_string($manifest['fingerprint'] ?? null)
            || ! hash_equals($fingerprint, $manifest['fingerprint'])) {
            throw new RuntimeException('Phase 08 ownership manifest does not match the exact verified database state.');
        }
    }

    /** @param list<array{migration:string,batch:int}> $before
     * @param  list<array{migration:string,batch:int}>  $after
     * @param  list<string>  $beforeObjects
     * @param  list<string>  $afterObjects
     * @param  list<string>  $local
     */
    public static function assertPostflight(array $before, array $after, array $local, array $beforeObjects, array $afterObjects): void
    {
        $added = array_values(array_diff($afterObjects, $beforeObjects));
        sort($added);
        $expectedAdded = array_values(array_diff(self::TABLES_CREATED_BY_MIGRATION, $beforeObjects));
        sort($expectedAdded);
        if (array_column($after, 'migration') !== $local
            || array_slice($after, 0, count($before)) !== $before
            || count($after) !== count($before) + 1
            || (int) ($after[count($before)]['batch'] ?? 0) !== max(array_column($before, 'batch')) + 1
            || $added !== $expectedAdded) {
            throw new RuntimeException('Phase 08 recovery did not complete exactly its reviewed migration transition.');
        }
    }

    /** @param list<string> $left
     * @param  list<string>  $right
     */
    private static function sameSet(array $left, array $right): bool
    {
        sort($left);
        sort($right);

        return $left === $right;
    }
}
