<?php

namespace Tests\Support;

use RuntimeException;

/** Validates the only partial DDL state produced by the known V2 migration failure. */
final class Phase04V2MigrationResumePolicy
{
    /** @param list<array{name:string,type:string}> $actualObjects
     * @param  list<array{migration:string,batch:int}>  $applied
     * @param  array<string,string>  $migrationFiles
     * @param  array<string,mixed>  $guardianSchema
     */
    public static function assertKnownGuardianMigrationPrefix(
        array $actualObjects,
        array $applied,
        array $migrationFiles,
        array $guardianSchema,
    ): void {
        $lastApplied = $applied === [] ? '' : (string) $applied[array_key_last($applied)]['migration'];
        if ($lastApplied !== '2026_10_22_000009_add_admissions_document_quarantine') {
            throw new RuntimeException('The partial guardian table does not follow the known V2 migration failure point.');
        }

        $expectedTables = Phase04MySqlTestDataPolicy::expectedCreatedTables($applied, $migrationFiles);
        $expectedTables[] = 'admission_guardian_authorizations';
        $expectedTables = array_values(array_unique($expectedTables));
        sort($expectedTables);

        $actualTables = [];
        foreach ($actualObjects as $object) {
            if ($object['type'] !== 'BASE TABLE') {
                throw new RuntimeException('A partial V2 migration state contains a view or non-table object.');
            }
            $actualTables[] = $object['name'];
        }
        sort($actualTables);
        if ($actualTables !== $expectedTables) {
            throw new RuntimeException('A partial V2 migration state contains missing or unexpected schema objects.');
        }

        self::assertPartialGuardianSchema($guardianSchema);
    }

    /** @param array<string,mixed> $schema */
    private static function assertPartialGuardianSchema(array $schema): void
    {
        if (($schema['rows'] ?? null) !== 0) {
            throw new RuntimeException('The partially created guardian table contains rows and cannot be resumed automatically.');
        }

        $expectedColumns = [
            ['id', 'bigint unsigned', 'NO', null, 'auto_increment'],
            ['tenant_id', 'bigint unsigned', 'NO', null, ''],
            ['application_id', 'bigint unsigned', 'NO', null, ''],
            ['applicant_user_id', 'bigint unsigned', 'NO', null, ''],
            ['guardian_user_id', 'bigint unsigned', 'YES', null, ''],
            ['guardian_name', 'varchar(180)', 'NO', null, ''],
            ['guardian_email', 'varchar(254)', 'NO', null, ''],
            ['relationship', 'varchar(48)', 'NO', null, ''],
            ['token_hash', 'char(64)', 'YES', null, ''],
            ['status', 'varchar(32)', 'NO', 'invited', ''],
            ['expires_at', 'datetime', 'YES', null, ''],
            ['email_verified_at', 'datetime', 'YES', null, ''],
            ['relationship_verified_by', 'bigint unsigned', 'YES', null, ''],
            ['relationship_verified_at', 'datetime', 'YES', null, ''],
            ['verification_method', 'varchar(32)', 'YES', null, ''],
            ['revoked_by', 'bigint unsigned', 'YES', null, ''],
            ['revoked_at', 'datetime', 'YES', null, ''],
            ['created_at', 'timestamp', 'YES', null, ''],
            ['updated_at', 'timestamp', 'YES', null, ''],
        ];
        $actualColumns = array_map(static fn (array $row): array => [
            (string) $row['COLUMN_NAME'],
            (string) $row['COLUMN_TYPE'],
            (string) $row['IS_NULLABLE'],
            $row['COLUMN_DEFAULT'] === null ? null : (string) $row['COLUMN_DEFAULT'],
            (string) $row['EXTRA'],
        ], $schema['columns'] ?? []);
        if ($actualColumns !== $expectedColumns) {
            throw new RuntimeException('The partial guardian table columns do not match the checked-in migration prefix.');
        }

        $expectedIndexes = [
            ['admission_guardian_authorizations_applicant_user_id_foreign', '1', 'applicant_user_id'],
            ['admission_guardian_authorizations_guardian_user_id_foreign', '1', 'guardian_user_id'],
            ['admission_guardian_authorizations_tenant_id_foreign', '1', 'tenant_id'],
            ['PRIMARY', '0', 'id'],
        ];
        $actualIndexes = array_map(static fn (array $row): array => [
            (string) $row['INDEX_NAME'],
            (string) $row['NON_UNIQUE'],
            (string) $row['columns_list'],
        ], $schema['indexes'] ?? []);
        if ($actualIndexes !== $expectedIndexes) {
            throw new RuntimeException('The partial guardian table indexes differ from the known failed migration prefix.');
        }

        $expectedForeignKeys = [
            ['admission_guardian_authorizations_applicant_user_id_foreign', 'applicant_user_id', 'users', 'id'],
            ['admission_guardian_authorizations_guardian_user_id_foreign', 'guardian_user_id', 'users', 'id'],
            ['admission_guardian_authorizations_tenant_id_foreign', 'tenant_id', 'tenants', 'id'],
        ];
        $actualForeignKeys = array_map(static fn (array $row): array => [
            (string) $row['CONSTRAINT_NAME'],
            (string) $row['columns_list'],
            (string) $row['REFERENCED_TABLE_NAME'],
            (string) $row['referenced_columns'],
        ], $schema['foreign_keys'] ?? []);
        if ($actualForeignKeys !== $expectedForeignKeys) {
            throw new RuntimeException('The partial guardian table foreign keys differ from the known failed migration prefix.');
        }
    }
}
