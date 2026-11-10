<?php

namespace App\Domain\Academic\Curriculum;

use App\Models\AcademicCurriculumNode;
use App\Models\AcademicCurriculumVersion;
use Illuminate\Database\Eloquent\Collection;

/** Derives actionable quality findings only from saved curriculum design data. */
final class CurriculumQualityAnalyzer
{
    /** @return array{node_count:int,required_outcomes:int,mapped_required_outcomes:int,coverage_percent:int,completeness_percent:int,lesson_objective_coverage_percent:int,findings:list<array{code:string,severity:string,title:string,detail:string,count:int}>} */
    public function analyze(AcademicCurriculumVersion $version): array
    {
        $version->loadMissing(['curriculum.framework.requiredOutcomes', 'nodes.outcomes', 'nodes.competencies', 'nodes.prerequisites']);
        $nodes = $version->nodes;
        $required = $version->curriculum->framework?->requiredOutcomes->pluck('learning_outcome_id')->map(fn ($id): int => (int) $id) ?? collect();
        $mapped = $nodes->flatMap(fn (AcademicCurriculumNode $node) => $node->outcomes->pluck('learning_outcome_id'))->map(fn ($id): int => (int) $id)->unique();
        $missingOutcomes = $required->diff($mapped)->count();
        $nodeIds = $nodes->map(fn (AcademicCurriculumNode $node): int => (int) $node->getKey())->all();
        $objectiveParents = $nodes->filter(fn (AcademicCurriculumNode $node): bool => $node->node_type === 'objective')
            ->map(fn (AcademicCurriculumNode $node): ?int => $node->parent_id === null ? null : (int) $node->parent_id)->filter()->unique();
        $missingObjectives = $nodes->filter(fn (AcademicCurriculumNode $node): bool => $node->node_type === 'objective' && trim((string) $node->description) === '')->count()
            + $nodes->filter(fn (AcademicCurriculumNode $node): bool => $node->node_type === 'lesson' && ! $objectiveParents->contains((int) $node->getKey()))->count();
        $lessonNodes = $nodes->whereIn('node_type', ['objective', 'lesson']);
        $completeLessons = $lessonNodes->filter(fn (AcademicCurriculumNode $node): bool => trim((string) $node->description) !== '' && ($node->node_type !== 'lesson' || (trim((string) $node->instructional_strategy) !== '' && trim((string) $node->activity_specification) !== '')))->count();
        $lessonObjectiveCoverage = $lessonNodes->isEmpty() ? 100 : (int) floor(($completeLessons / $lessonNodes->count()) * 100);
        $completeness = $nodes->isEmpty() ? 0 : (int) floor((($nodes->filter(fn (AcademicCurriculumNode $node): bool => trim((string) $node->description) !== '')->count()) / $nodes->count()) * 100);
        $unmappedCompetencies = $nodes->whereIn('node_type', ['course', 'unit', 'topic', 'lesson'])
            ->filter(fn (AcademicCurriculumNode $node): bool => $node->competencies->isEmpty())->count();
        $cycleCount = $this->cycleCount($nodeIds, $nodes);
        $workloads = $nodes->filter(fn (AcademicCurriculumNode $node): bool => $node->parent_id === null && in_array($node->node_type, ['course', 'unit'], true) && $node->estimated_minutes !== null)
            ->pluck('estimated_minutes')->map(fn ($minutes): int => (int) $minutes)->sort()->values();
        $imbalancedUnits = 0;
        if ($workloads->count() >= 3) {
            $median = (int) $workloads->get((int) floor(($workloads->count() - 1) / 2));
            $maximum = (int) $workloads->last();
            $imbalancedUnits = $median > 0 && $maximum > $median * 2 ? $workloads->filter(fn (int $minutes): bool => $minutes > $median * 2)->count() : 0;
        }

        $findings = [];
        if ($nodes->isEmpty()) {
            $findings[] = $this->finding('empty_syllabus', 'high', 'Syllabus structure is empty', 'Add course or unit nodes before review.', 1);
        }
        if ($missingOutcomes > 0) {
            $findings[] = $this->finding('required_outcome_gaps', 'high', 'Required outcomes are unmapped', 'Map every required framework outcome to at least one syllabus node.', $missingOutcomes);
        }
        if ($missingObjectives > 0) {
            $findings[] = $this->finding('missing_objectives', 'medium', 'Lesson objectives need attention', 'Add objective descriptions and link at least one objective under each lesson.', $missingObjectives);
        }
        if ($unmappedCompetencies > 0) {
            $findings[] = $this->finding('unmapped_competencies', 'medium', 'Syllabus nodes lack competency links', 'Map relevant competencies to course, unit, topic, or lesson nodes.', $unmappedCompetencies);
        }
        if ($cycleCount > 0) {
            $findings[] = $this->finding('prerequisite_cycles', 'high', 'Prerequisite cycle detected', 'Resolve the circular learning path before publication.', $cycleCount);
        }
        if ($imbalancedUnits > 0) {
            $findings[] = $this->finding('workload_imbalance', 'medium', 'Unit workload is imbalanced', 'Review top-level units that exceed twice the median planned duration.', $imbalancedUnits);
        }

        return [
            'node_count' => $nodes->count(),
            'required_outcomes' => $required->count(),
            'mapped_required_outcomes' => $required->intersect($mapped)->count(),
            'coverage_percent' => $required->isEmpty() ? 100 : (int) floor(($required->intersect($mapped)->count() / $required->count()) * 100),
            'completeness_percent' => $completeness,
            'lesson_objective_coverage_percent' => $lessonObjectiveCoverage,
            'findings' => $findings,
        ];
    }

    /**
     * @param  list<int>  $nodeIds
     * @param  Collection<int, AcademicCurriculumNode>  $nodes
     */
    private function cycleCount(array $nodeIds, Collection $nodes): int
    {
        $edges = [];
        foreach ($nodes as $node) {
            foreach ($node->prerequisites as $edge) {
                $edges[(int) $node->getKey()][] = (int) $edge->prerequisite_node_id;
            }
        }
        $state = [];
        $cycles = 0;
        $visit = function (int $nodeId) use (&$visit, &$state, &$cycles, $edges): void {
            if (($state[$nodeId] ?? 0) === 1) {
                $cycles++;

                return;
            }
            if (($state[$nodeId] ?? 0) === 2) {
                return;
            }
            $state[$nodeId] = 1;
            foreach ($edges[$nodeId] ?? [] as $next) {
                $visit($next);
            }
            $state[$nodeId] = 2;
        };
        foreach ($nodeIds as $nodeId) {
            $visit($nodeId);
        }

        return $cycles;
    }

    /** @return array{code:string,severity:string,title:string,detail:string,count:int} */
    private function finding(string $code, string $severity, string $title, string $detail, int $count): array
    {
        return compact('code', 'severity', 'title', 'detail', 'count');
    }
}
