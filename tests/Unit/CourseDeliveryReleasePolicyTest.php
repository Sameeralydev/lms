<?php

namespace Tests\Unit;

use App\Domain\Learning\CourseDeliveryReleasePolicy;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class CourseDeliveryReleasePolicyTest extends TestCase
{
    public function test_valid_dependency_graph_allows_independent_and_ordered_lessons(): void
    {
        CourseDeliveryReleasePolicy::assertAcyclic([10 => [], 11 => [10], 12 => [10]]);

        self::assertSame(
            ['lessons' => [12], 'modules' => []],
            CourseDeliveryReleasePolicy::unmetPrerequisites([10, 12], [], [10], []),
        );
    }

    public function test_direct_and_indirect_dependency_cycles_are_rejected(): void
    {
        foreach ([[10 => [10]], [10 => [11], 11 => [12], 12 => [10]]] as $graph) {
            try {
                CourseDeliveryReleasePolicy::assertAcyclic($graph);
                self::fail('A prerequisite cycle must be rejected.');
            } catch (RuntimeException $exception) {
                self::assertStringContainsString('cycle', $exception->getMessage());
            }
        }
    }
}
