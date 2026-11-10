<?php

namespace App\Domain\Learning;

use Illuminate\Support\Facades\DB;
use RuntimeException;

/** Validates the one known MySQL DDL interruption before a guarded resume. */
final class Phase07ProgressMigrationRecoveryPolicy
{
    /** @return array{exists:bool,columns:list<array{name:string,type:string,nullable:string,default:mixed}>,indexes:list<array{name:string,columns:list<string>,unique:bool}>,foreign_keys:list<array{name:string,column:string,referenced_table:string,referenced_column:string}>,membership_indexes:list<array{name:string,columns:list<string>,unique:bool}>} */
    public static function inspect(): array
    {
        $schema = DB::connection('mysql')->getDatabaseName();
        $columns = DB::connection('mysql')->select(
            'SELECT COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? ORDER BY ORDINAL_POSITION',
            [$schema, 'course_delivery_progress'],
        );
        if ($columns === []) {
            return ['exists' => false, 'columns' => [], 'indexes' => [], 'foreign_keys' => [], 'membership_indexes' => self::membershipIndexes()];
        }

        $indexes = DB::connection('mysql')->select(
            'SELECT INDEX_NAME, COLUMN_NAME, NON_UNIQUE, SEQ_IN_INDEX FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? ORDER BY INDEX_NAME, SEQ_IN_INDEX',
            [$schema, 'course_delivery_progress'],
        );
        $indexesByName = [];
        foreach ($indexes as $index) {
            $name = (string) $index->INDEX_NAME;
            $indexesByName[$name] ??= ['name' => $name, 'columns' => [], 'unique' => (int) $index->NON_UNIQUE === 0];
            $indexesByName[$name]['columns'][] = (string) $index->COLUMN_NAME;
        }

        $foreignKeys = DB::connection('mysql')->select(
            'SELECT CONSTRAINT_NAME, COLUMN_NAME, REFERENCED_TABLE_NAME, REFERENCED_COLUMN_NAME FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND REFERENCED_TABLE_NAME IS NOT NULL ORDER BY CONSTRAINT_NAME, ORDINAL_POSITION',
            [$schema, 'course_delivery_progress'],
        );

        return [
            'exists' => true,
            'columns' => array_map(static fn (object $column): array => [
                'name' => (string) $column->COLUMN_NAME,
                'type' => strtolower((string) $column->COLUMN_TYPE),
                'nullable' => (string) $column->IS_NULLABLE,
                'default' => $column->COLUMN_DEFAULT,
            ], $columns),
            'indexes' => array_values($indexesByName),
            'foreign_keys' => array_map(static fn (object $key): array => [
                'name' => (string) $key->CONSTRAINT_NAME,
                'column' => (string) $key->COLUMN_NAME,
                'referenced_table' => (string) $key->REFERENCED_TABLE_NAME,
                'referenced_column' => (string) $key->REFERENCED_COLUMN_NAME,
            ], $foreignKeys),
            'membership_indexes' => self::membershipIndexes(),
        ];
    }

    /** @return list<array{name:string,columns:list<string>,unique:bool}> */
    private static function membershipIndexes(): array
    {
        $indexes = DB::connection('mysql')->select(
            'SELECT INDEX_NAME, COLUMN_NAME, NON_UNIQUE, SEQ_IN_INDEX FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? ORDER BY INDEX_NAME, SEQ_IN_INDEX',
            [DB::connection('mysql')->getDatabaseName(), 'tenant_memberships'],
        );
        $byName = [];
        foreach ($indexes as $index) {
            $name = (string) $index->INDEX_NAME;
            $byName[$name] ??= ['name' => $name, 'columns' => [], 'unique' => (int) $index->NON_UNIQUE === 0];
            $byName[$name]['columns'][] = (string) $index->COLUMN_NAME;
        }

        return array_values($byName);
    }

    /**
     * @param  array{exists:bool,columns:list<array{name:string,type:string,nullable:string,default:mixed}>,indexes:list<array{name:string,columns:list<string>,unique:bool}>,foreign_keys:list<array{name:string,column:string,referenced_table:string,referenced_column:string}>}  $schema
     * @param  list<array{name:string,columns:list<string>,unique:bool}>  $membershipIndexes
     */
    public static function assertRecoverable(array $schema, int $rowCount, array $membershipIndexes = []): void
    {
        $expectedColumns = [
            ['name' => 'id', 'type' => 'bigint unsigned', 'nullable' => 'NO', 'default' => null],
            ['name' => 'tenant_id', 'type' => 'bigint unsigned', 'nullable' => 'NO', 'default' => null],
            ['name' => 'workspace_id', 'type' => 'bigint unsigned', 'nullable' => 'NO', 'default' => null],
            ['name' => 'lesson_id', 'type' => 'bigint unsigned', 'nullable' => 'NO', 'default' => null],
            ['name' => 'membership_id', 'type' => 'bigint unsigned', 'nullable' => 'NO', 'default' => null],
            ['name' => 'revision_version', 'type' => 'int unsigned', 'nullable' => 'NO', 'default' => null],
            ['name' => 'status', 'type' => 'varchar(16)', 'nullable' => 'NO', 'default' => 'started'],
            ['name' => 'resume_seconds', 'type' => 'int unsigned', 'nullable' => 'NO', 'default' => '0'],
            ['name' => 'started_at', 'type' => 'timestamp', 'nullable' => 'NO', 'default' => null],
            ['name' => 'last_activity_at', 'type' => 'timestamp', 'nullable' => 'NO', 'default' => null],
            ['name' => 'completed_at', 'type' => 'timestamp', 'nullable' => 'YES', 'default' => null],
            ['name' => 'created_at', 'type' => 'timestamp', 'nullable' => 'YES', 'default' => null],
            ['name' => 'updated_at', 'type' => 'timestamp', 'nullable' => 'YES', 'default' => null],
        ];
        $expectedIndexes = [
            ['name' => 'PRIMARY', 'columns' => ['id'], 'unique' => true],
            ['name' => 'delivery_progress_member_uq', 'columns' => ['tenant_id', 'workspace_id', 'lesson_id', 'membership_id'], 'unique' => true],
            ['name' => 'delivery_progress_course_idx', 'columns' => ['tenant_id', 'workspace_id', 'membership_id', 'status'], 'unique' => false],
        ];
        $expectedForeignKeys = [
            ['name' => 'course_delivery_progress_tenant_id_foreign', 'column' => 'tenant_id', 'referenced_table' => 'tenants', 'referenced_column' => 'id'],
            ['name' => 'delivery_progress_lesson_fk', 'column' => 'tenant_id', 'referenced_table' => 'course_delivery_lessons', 'referenced_column' => 'tenant_id'],
            ['name' => 'delivery_progress_lesson_fk', 'column' => 'workspace_id', 'referenced_table' => 'course_delivery_lessons', 'referenced_column' => 'workspace_id'],
            ['name' => 'delivery_progress_lesson_fk', 'column' => 'lesson_id', 'referenced_table' => 'course_delivery_lessons', 'referenced_column' => 'id'],
            ['name' => 'delivery_progress_workspace_fk', 'column' => 'tenant_id', 'referenced_table' => 'course_delivery_workspaces', 'referenced_column' => 'tenant_id'],
            ['name' => 'delivery_progress_workspace_fk', 'column' => 'workspace_id', 'referenced_table' => 'course_delivery_workspaces', 'referenced_column' => 'id'],
        ];

        $columns = array_map(static fn (array $column): array => [
            'name' => (string) $column['name'],
            'type' => strtolower((string) $column['type']),
            'nullable' => (string) $column['nullable'],
            'default' => $column['default'] === null ? null : (string) $column['default'],
        ], $schema['columns']);
        $expectedColumns = array_map(static fn (array $column): array => [
            ...$column,
            'default' => $column['default'] === null ? null : (string) $column['default'],
        ], $expectedColumns);
        $indexes = $schema['indexes'];
        $foreignKeys = $schema['foreign_keys'];
        usort($indexes, static fn (array $left, array $right): int => $left['name'] <=> $right['name']);
        usort($expectedIndexes, static fn (array $left, array $right): int => $left['name'] <=> $right['name']);
        usort($foreignKeys, self::compareForeignKeys(...));
        usort($expectedForeignKeys, self::compareForeignKeys(...));

        $mismatches = [];
        if (! $schema['exists']) {
            $mismatches[] = 'table missing';
        }
        if ($columns !== $expectedColumns) {
            $mismatches[] = 'columns';
        }
        if ($indexes !== $expectedIndexes) {
            $mismatches[] = 'indexes';
        }
        if ($foreignKeys !== $expectedForeignKeys) {
            $mismatches[] = 'foreign keys';
        }
        if ($rowCount !== 0) {
            $mismatches[] = 'table is nonempty';
        }
        if (! self::membershipIdentityKeyIsAbsentOrExact($membershipIndexes)) {
            $mismatches[] = 'tenant membership composite key';
        }
        if ($mismatches !== []) {
            throw new RuntimeException('The Phase 07 progress table is not the exact empty interrupted schema expected for safe migration recovery (mismatches: '.implode(', ', $mismatches).').');
        }
    }

    /** @param array<string,mixed> $schema
     * @param  list<array{name:string,columns:list<string>,unique:bool}>  $membershipIndexes
     */
    public static function assertComplete(array $schema, int $rowCount, array $membershipIndexes = []): void
    {
        $baseSchema = $schema;
        $baseSchema['indexes'] = array_values(array_filter(
            $schema['indexes'],
            static fn (array $index): bool => $index['name'] !== 'delivery_progress_member_fk',
        ));
        $baseSchema['foreign_keys'] = array_values(array_filter(
            $schema['foreign_keys'],
            static fn (array $key): bool => $key['name'] !== 'delivery_progress_member_fk',
        ));
        if (! self::membershipIdentityKeyIsExact($membershipIndexes)) {
            throw new RuntimeException('The Phase 07 tenant membership key is not the required unique tenant/id index.');
        }

        self::assertRecoverable($baseSchema, $rowCount, $membershipIndexes);
        $expectedKeys = [
            ['name' => 'delivery_progress_member_fk', 'column' => 'tenant_id', 'referenced_table' => 'tenant_memberships', 'referenced_column' => 'tenant_id'],
            ['name' => 'delivery_progress_member_fk', 'column' => 'membership_id', 'referenced_table' => 'tenant_memberships', 'referenced_column' => 'id'],
        ];
        $actualKeys = array_values(array_filter(
            $schema['foreign_keys'],
            static fn (array $key): bool => $key['name'] === 'delivery_progress_member_fk',
        ));
        usort($actualKeys, self::compareForeignKeys(...));
        usort($expectedKeys, self::compareForeignKeys(...));
        $supportingIndex = array_values(array_filter(
            $schema['indexes'],
            static fn (array $index): bool => $index['name'] === 'delivery_progress_member_fk',
        ));
        if ($actualKeys !== $expectedKeys
            || $supportingIndex !== [['name' => 'delivery_progress_member_fk', 'columns' => ['tenant_id', 'membership_id'], 'unique' => false]]) {
            throw new RuntimeException('The Phase 07 progress membership foreign key or its supporting index is missing or malformed.');
        }
    }

    /** @param list<array{name:string,columns:list<string>,unique:bool}> $indexes */
    private static function membershipIdentityKeyIsAbsentOrExact(array $indexes): bool
    {
        $matching = array_values(array_filter($indexes, static fn (array $index): bool => $index['columns'] === ['tenant_id', 'id']));

        return $matching === [] || self::membershipIdentityKeyIsExact($indexes);
    }

    /** @param list<array{name:string,columns:list<string>,unique:bool}> $indexes */
    private static function membershipIdentityKeyIsExact(array $indexes): bool
    {
        $matching = array_values(array_filter($indexes, static fn (array $index): bool => $index['columns'] === ['tenant_id', 'id']));

        return count($matching) === 1
            && $matching[0] === ['name' => 'tenant_memberships_tenant_id_id_uq', 'columns' => ['tenant_id', 'id'], 'unique' => true];
    }

    /** @param array{name:string,column:string,referenced_table:string,referenced_column:string} $left
     * @param  array{name:string,column:string,referenced_table:string,referenced_column:string}  $right
     */
    private static function compareForeignKeys(array $left, array $right): int
    {
        return [$left['name'], $left['column'], $left['referenced_table'], $left['referenced_column']]
            <=> [$right['name'], $right['column'], $right['referenced_table'], $right['referenced_column']];
    }
}
