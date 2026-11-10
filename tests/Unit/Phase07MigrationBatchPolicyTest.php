<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use Tests\Support\Phase07MigrationBatchPolicy;

final class Phase07MigrationBatchPolicyTest extends TestCase
{
    private const COMPLETE = [
        'foundation',
        '2026_10_27_000000_create_course_delivery_foundation',
        '2026_10_27_000001_create_course_delivery_progress',
        '2026_10_27_000002_add_tenant_safe_progress_membership_foreign_key',
    ];

    public function test_exact_recovery_prefix_and_completion_batches_are_accepted(): void
    {
        $prefix = [
            ['migration' => 'foundation', 'batch' => 1],
            ['migration' => '2026_10_27_000000_create_course_delivery_foundation', 'batch' => 1],
            ['migration' => '2026_10_27_000001_create_course_delivery_progress', 'batch' => 2],
        ];
        $complete = [...$prefix, ['migration' => '2026_10_27_000002_add_tenant_safe_progress_membership_foreign_key', 'batch' => 3]];

        Phase07MigrationBatchPolicy::assertAuthorizedPrefix($prefix, self::COMPLETE);
        Phase07MigrationBatchPolicy::assertAuthorizedTransition($prefix, $complete, self::COMPLETE);
        $this->assertCount(4, $complete);
    }

    public function test_unexpected_recovery_batch_and_migration_prefix_are_rejected(): void
    {
        $this->expectException(RuntimeException::class);
        Phase07MigrationBatchPolicy::assertRecoveredCompletion([
            ['migration' => 'foundation', 'batch' => 1],
            ['migration' => '2026_10_27_000000_create_course_delivery_foundation', 'batch' => 1],
            ['migration' => '2026_10_27_000001_create_course_delivery_progress', 'batch' => 4],
            ['migration' => '2026_10_27_000002_add_tenant_safe_progress_membership_foreign_key', 'batch' => 4],
        ], self::COMPLETE);
    }
}
