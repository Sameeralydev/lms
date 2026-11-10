<?php

namespace Tests\Unit;

use App\Domain\Learning\Phase07ProgressMigrationRecoveryPolicy;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class Phase07ProgressMigrationRecoveryPolicyTest extends TestCase
{
    public function test_exact_empty_mysql_ddl_interruption_is_recoverable(): void
    {
        $this->expectNotToPerformAssertions();
        Phase07ProgressMigrationRecoveryPolicy::assertRecoverable($this->schema(), 0);
    }

    public function test_persisted_progress_rows_block_recovery(): void
    {
        $this->expectException(RuntimeException::class);
        Phase07ProgressMigrationRecoveryPolicy::assertRecoverable($this->schema(), 1);
    }

    public function test_unexpected_index_blocks_recovery(): void
    {
        $schema = $this->schema();
        $schema['indexes'][] = ['name' => 'unexpected_idx', 'columns' => ['status'], 'unique' => false];

        $this->expectException(RuntimeException::class);
        Phase07ProgressMigrationRecoveryPolicy::assertRecoverable($schema, 0);
    }

    public function test_extra_foreign_key_blocks_recovery(): void
    {
        $schema = $this->schema();
        $schema['foreign_keys'][] = [
            'name' => 'unexpected_fk', 'column' => 'membership_id', 'referenced_table' => 'tenant_memberships', 'referenced_column' => 'id',
        ];

        $this->expectException(RuntimeException::class);
        Phase07ProgressMigrationRecoveryPolicy::assertRecoverable($schema, 0);
    }

    public function test_extra_column_blocks_recovery(): void
    {
        $schema = $this->schema();
        $schema['columns'][] = ['name' => 'unexpected', 'type' => 'int', 'nullable' => 'YES', 'default' => null];

        $this->expectException(RuntimeException::class);
        Phase07ProgressMigrationRecoveryPolicy::assertRecoverable($schema, 0);
    }

    public function test_completed_membership_constraint_and_composite_key_are_accepted(): void
    {
        $this->expectNotToPerformAssertions();
        $schema = $this->schema();
        $schema['indexes'][] = ['name' => 'delivery_progress_member_fk', 'columns' => ['tenant_id', 'membership_id'], 'unique' => false];
        $schema['foreign_keys'][] = ['name' => 'delivery_progress_member_fk', 'column' => 'tenant_id', 'referenced_table' => 'tenant_memberships', 'referenced_column' => 'tenant_id'];
        $schema['foreign_keys'][] = ['name' => 'delivery_progress_member_fk', 'column' => 'membership_id', 'referenced_table' => 'tenant_memberships', 'referenced_column' => 'id'];
        $membershipIndexes = [['name' => 'tenant_memberships_tenant_id_id_uq', 'columns' => ['tenant_id', 'id'], 'unique' => true]];

        Phase07ProgressMigrationRecoveryPolicy::assertComplete($schema, 0, $membershipIndexes);
    }

    /** @return array{exists:bool,columns:list<array{name:string,type:string,nullable:string,default:mixed}>,indexes:list<array{name:string,columns:list<string>,unique:bool}>,foreign_keys:list<array{name:string,column:string,referenced_table:string,referenced_column:string}>} */
    private function schema(): array
    {
        return [
            'exists' => true,
            'columns' => [
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
            ],
            'indexes' => [
                ['name' => 'PRIMARY', 'columns' => ['id'], 'unique' => true],
                ['name' => 'delivery_progress_member_uq', 'columns' => ['tenant_id', 'workspace_id', 'lesson_id', 'membership_id'], 'unique' => true],
                ['name' => 'delivery_progress_course_idx', 'columns' => ['tenant_id', 'workspace_id', 'membership_id', 'status'], 'unique' => false],
            ],
            'foreign_keys' => [
                ['name' => 'course_delivery_progress_tenant_id_foreign', 'column' => 'tenant_id', 'referenced_table' => 'tenants', 'referenced_column' => 'id'],
                ['name' => 'delivery_progress_lesson_fk', 'column' => 'tenant_id', 'referenced_table' => 'course_delivery_lessons', 'referenced_column' => 'tenant_id'],
                ['name' => 'delivery_progress_lesson_fk', 'column' => 'workspace_id', 'referenced_table' => 'course_delivery_lessons', 'referenced_column' => 'workspace_id'],
                ['name' => 'delivery_progress_lesson_fk', 'column' => 'lesson_id', 'referenced_table' => 'course_delivery_lessons', 'referenced_column' => 'id'],
                ['name' => 'delivery_progress_workspace_fk', 'column' => 'tenant_id', 'referenced_table' => 'course_delivery_workspaces', 'referenced_column' => 'tenant_id'],
                ['name' => 'delivery_progress_workspace_fk', 'column' => 'workspace_id', 'referenced_table' => 'course_delivery_workspaces', 'referenced_column' => 'id'],
            ],
        ];
    }
}
