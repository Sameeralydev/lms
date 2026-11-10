<?php

namespace App\Domain\Academic\Curriculum;

use App\Models\AcademicCurriculumNode;
use App\Models\AcademicCurriculumVersion;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/** Builds a tenant-local, reviewer-readable comparison without mutating either version. */
final class CurriculumVersionComparison
{
    /** @return array{from:array{id:int,number:int,status:string},to:array{id:int,number:int,status:string},version_changes:array<string,array{from:mixed,to:mixed}>,added:list<array<string,mixed>>,removed:list<array<string,mixed>>,modified:list<array{key:string,title:string,changes:array<string,array{from:mixed,to:mixed}>}>,summary:array{added:int,removed:int,modified:int}} */
    public function compare(AcademicCurriculumVersion $from, AcademicCurriculumVersion $to): array
    {
        if ((int) $from->curriculum_id !== (int) $to->curriculum_id || (int) $from->tenant_id !== (int) $to->tenant_id) {
            throw new NotFoundHttpException;
        }

        $from->loadMissing(['nodes.outcomes.learningOutcome', 'nodes.prerequisites.prerequisiteNode', 'nodes.competencies.competency', 'completionRules.academicPeriod']);
        $to->loadMissing(['nodes.outcomes.learningOutcome', 'nodes.prerequisites.prerequisiteNode', 'nodes.competencies.competency', 'completionRules.academicPeriod']);
        $before = $this->snapshot($from);
        $after = $this->snapshot($to);
        $versionChanges = [];
        foreach (['title', 'revision_note', 'effective_from', 'effective_until'] as $field) {
            $beforeValue = in_array($field, ['effective_from', 'effective_until'], true) ? $from->getRawOriginal($field) : $from->getAttribute($field);
            $afterValue = in_array($field, ['effective_from', 'effective_until'], true) ? $to->getRawOriginal($field) : $to->getAttribute($field);
            if ($beforeValue !== $afterValue) {
                $versionChanges[$field] = ['from' => $beforeValue, 'to' => $afterValue];
            }
        }
        $added = [];
        $removed = [];
        $modified = [];

        foreach (array_diff(array_keys($after), array_keys($before)) as $key) {
            $added[] = ['key' => $key, ...$after[$key]];
        }
        foreach (array_diff(array_keys($before), array_keys($after)) as $key) {
            $removed[] = ['key' => $key, ...$before[$key]];
        }
        foreach (array_intersect(array_keys($before), array_keys($after)) as $key) {
            $changes = [];
            foreach ($before[$key] as $field => $value) {
                if ($value !== $after[$key][$field]) {
                    $changes[$field] = ['from' => $value, 'to' => $after[$key][$field]];
                }
            }
            if ($changes !== []) {
                $modified[] = ['key' => $key, 'title' => (string) $after[$key]['title'], 'changes' => $changes];
            }
        }

        return [
            'from' => ['id' => (int) $from->getKey(), 'number' => (int) $from->version_number, 'status' => (string) $from->status],
            'to' => ['id' => (int) $to->getKey(), 'number' => (int) $to->version_number, 'status' => (string) $to->status],
            'version_changes' => $versionChanges,
            'added' => $added,
            'removed' => $removed,
            'modified' => $modified,
            'summary' => ['added' => count($added), 'removed' => count($removed), 'modified' => count($modified) + count($versionChanges)],
        ];
    }

    /** @return array<string,array<string,mixed>> */
    private function snapshot(AcademicCurriculumVersion $version): array
    {
        $nodes = $version->nodes->keyBy(fn (AcademicCurriculumNode $node): int => (int) $node->getKey());
        $keys = [];
        $resolveKey = function (AcademicCurriculumNode $node) use (&$resolveKey, &$keys, $nodes): string {
            $id = (int) $node->getKey();
            if (isset($keys[$id])) {
                return $keys[$id];
            }
            $segment = $node->node_type.':'.($node->code !== null && $node->code !== '' ? 'code-'.$node->code : 'order-'.$node->sort_order);
            $parent = $node->parent_id === null ? null : $nodes->get((int) $node->parent_id);
            $keys[$id] = $parent instanceof AcademicCurriculumNode ? $resolveKey($parent).'/'.$segment : $segment;

            return $keys[$id];
        };

        foreach ($nodes as $node) {
            $resolveKey($node);
        }

        $snapshot = [];
        foreach ($nodes as $node) {
            $snapshot[$keys[(int) $node->getKey()]] = [
                'type' => (string) $node->node_type,
                'title' => (string) $node->title,
                'description' => $node->description,
                'parent' => $node->parent_id === null ? null : ($keys[(int) $node->parent_id] ?? null),
                'sequence' => (int) $node->sort_order,
                'required' => (bool) $node->is_required,
                'workload' => $node->estimated_minutes === null ? null : ['amount' => (int) $node->estimated_minutes, 'unit' => (string) $node->workload_unit],
                'instructional_strategy' => $node->instructional_strategy,
                'activity' => $node->activity_specification,
                'assessment' => $node->assessment_blueprint,
                'pacing' => ['starts_on' => $node->getRawOriginal('planned_starts_on'), 'ends_on' => $node->getRawOriginal('planned_ends_on')],
                'outcomes' => $node->outcomes->map(fn ($mapping): array => [
                    'code' => $mapping->learningOutcome->code,
                    'strength' => (string) $mapping->mapping_strength,
                ])->sortBy('code')->values()->all(),
                'prerequisites' => $node->prerequisites->map(fn ($edge): array => [
                    'node' => $keys[(int) $edge->prerequisite_node_id] ?? 'missing-node-'.$edge->prerequisite_node_id,
                    'type' => (string) $edge->dependency_type,
                ])->sortBy('node')->values()->all(),
                'competencies' => $node->competencies->map(fn ($mapping): array => [
                    'code' => $mapping->competency->code,
                    'level' => $mapping->expected_level,
                    'strength' => (string) $mapping->mapping_strength,
                ])->sortBy('code')->values()->all(),
            ];
        }

        foreach ($version->completionRules as $rule) {
            $snapshot['requirement:'.$rule->code] = [
                'type' => 'completion_rule',
                'title' => (string) $rule->title,
                'description' => $rule->description,
                'required' => (bool) $rule->is_required,
                'rule_type' => (string) $rule->rule_type,
                'target' => $rule->target_value === null ? null : (string) $rule->target_value,
                'unit' => $rule->unit,
                'period' => $rule->academicPeriod?->code,
                'sequence' => (int) $rule->sort_order,
            ];
        }

        ksort($snapshot);

        return $snapshot;
    }
}
