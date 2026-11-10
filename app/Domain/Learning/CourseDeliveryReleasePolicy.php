<?php

namespace App\Domain\Learning;

use RuntimeException;

/** Pure helpers for release dependency graphs and learner eligibility. */
final class CourseDeliveryReleasePolicy
{
    /** @param array<int, list<int>> $dependencies */
    public static function assertAcyclic(array $dependencies): void
    {
        $visiting = [];
        $visited = [];
        $visit = function (int $node) use (&$visit, &$visiting, &$visited, $dependencies): void {
            if (isset($visiting[$node])) {
                throw new RuntimeException('Lesson release prerequisites cannot contain a dependency cycle.');
            }
            if (isset($visited[$node])) {
                return;
            }
            $visiting[$node] = true;
            foreach ($dependencies[$node] ?? [] as $dependency) {
                if (isset($dependencies[$dependency])) {
                    $visit($dependency);
                }
            }
            unset($visiting[$node]);
            $visited[$node] = true;
        };

        foreach (array_keys($dependencies) as $node) {
            $visit((int) $node);
        }
    }

    /**
     * @param  list<int>  $lessonIds
     * @param  list<int>  $moduleIds
     * @param  list<int>  $completedLessonIds
     * @param  list<int>  $completedModuleIds
     * @return array{lessons:list<int>,modules:list<int>}
     */
    public static function unmetPrerequisites(array $lessonIds, array $moduleIds, array $completedLessonIds, array $completedModuleIds): array
    {
        return [
            'lessons' => array_values(array_diff($lessonIds, $completedLessonIds)),
            'modules' => array_values(array_diff($moduleIds, $completedModuleIds)),
        ];
    }
}
