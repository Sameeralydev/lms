<?php

namespace App\Domain\Academic\Curriculum;

use App\Domain\Audit\AuditRecorder;
use App\Domain\Tenancy\TenantContext;
use App\Models\AcademicCompetency;
use App\Models\AcademicCompetencyFramework;
use App\Models\AcademicCourse;
use App\Models\AcademicCourseVersion;
use App\Models\AcademicCurriculum;
use App\Models\AcademicCurriculumCompletionRule;
use App\Models\AcademicCurriculumFramework;
use App\Models\AcademicCurriculumFrameworkAdministrator;
use App\Models\AcademicCurriculumFrameworkOutcome;
use App\Models\AcademicCurriculumGovernancePolicy;
use App\Models\AcademicCurriculumNode;
use App\Models\AcademicCurriculumNodeOutcome;
use App\Models\AcademicCurriculumPrerequisite;
use App\Models\AcademicCurriculumReview;
use App\Models\AcademicCurriculumVersion;
use App\Models\AcademicLearningOutcome;
use App\Models\AcademicPeriod;
use App\Models\AcademicProgram;
use App\Models\AcademicYear;
use App\Models\Institution;
use App\Models\TenantMembership;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/** Transactional, tenant-scoped curriculum lifecycle operations. */
final class CurriculumWorkflow
{
    private const NODE_TYPES = ['course', 'unit', 'topic', 'subtopic', 'objective', 'lesson'];

    private const CHILD_TYPES = [
        'course' => ['unit', 'topic', 'objective', 'lesson'],
        'unit' => ['topic', 'subtopic', 'objective', 'lesson'],
        'topic' => ['subtopic', 'objective', 'lesson'],
        'subtopic' => ['objective', 'lesson'],
        'objective' => [],
        'lesson' => [],
    ];

    public function __construct(private readonly TenantContext $context, private readonly AuditRecorder $audit) {}

    /** @param array<string,mixed> $data */
    public function createFramework(array $data, User $actor): AcademicCurriculumFramework
    {
        $institution = Institution::query()->firstOrFail();
        $framework = AcademicCurriculumFramework::query()->create([
            ...$data,
            'institution_id' => $institution->getKey(),
            'owner_user_id' => $actor->getKey(),
            'status' => 'draft',
        ]);
        $this->audit->record('academic.curriculum.framework.created', $actor, (int) $this->context->tenant()->getKey(), $framework, ['code' => $framework->code]);

        return $framework;
    }

    public function requireFrameworkOutcome(int $frameworkId, int $outcomeId, bool $required, User $actor): AcademicCurriculumFrameworkOutcome
    {
        return DB::transaction(function () use ($frameworkId, $outcomeId, $required, $actor): AcademicCurriculumFrameworkOutcome {
            $framework = AcademicCurriculumFramework::query()->lockForUpdate()->findOrFail($frameworkId);
            app(CurriculumGovernanceService::class)->assertFrameworkAdministrationAccess($framework, $actor);
            if ($framework->status !== 'draft') {
                throw new ConflictHttpException('Only a draft framework can change its required outcome configuration.');
            }
            $outcome = AcademicLearningOutcome::query()->whereKey($outcomeId)->where('institution_id', $framework->institution_id)->where('status', 'active')->firstOrFail();
            $link = AcademicCurriculumFrameworkOutcome::query()->updateOrCreate(
                ['framework_id' => $framework->getKey(), 'learning_outcome_id' => $outcome->getKey()],
                ['required' => $required],
            );
            $framework->increment('lock_version');
            $this->audit->record('academic.curriculum.framework.outcome_configured', $actor, (int) $this->context->tenant()->getKey(), $framework, ['outcome_id' => $outcome->getKey(), 'required' => $required]);

            return $link;
        }, 3);
    }

    /** @param array<string,mixed> $data */
    public function updateFramework(AcademicCurriculumFramework $framework, array $data, int $expectedLock, User $actor): AcademicCurriculumFramework
    {
        return DB::transaction(function () use ($framework, $data, $expectedLock, $actor): AcademicCurriculumFramework {
            $locked = AcademicCurriculumFramework::query()->lockForUpdate()->findOrFail($framework->getKey());
            app(CurriculumGovernanceService::class)->assertFrameworkAdministrationAccess($locked, $actor);
            if ($locked->status !== 'draft') {
                throw new ConflictHttpException('Only a draft curriculum framework can be edited.');
            }
            $this->assertVersion($locked, $expectedLock);
            $before = $locked->only(['title', 'education_stage', 'description', 'terminology', 'required_learning_areas', 'effective_from', 'effective_until']);
            $locked->fill($data);
            $locked->lock_version++;
            $locked->save();
            $this->audit->record('academic.curriculum.framework.updated', $actor, (int) $this->context->tenant()->getKey(), $locked, ['before' => $before, 'after' => $locked->only(array_keys($before))]);

            return $locked;
        });
    }

    public function transitionFramework(AcademicCurriculumFramework $framework, string $status, User $actor): void
    {
        if (! in_array($status, ['active', 'retired'], true)) {
            throw ValidationException::withMessages(['status' => 'Choose activate or retire.']);
        }
        DB::transaction(function () use ($framework, $status, $actor): void {
            $locked = AcademicCurriculumFramework::query()->lockForUpdate()->findOrFail($framework->getKey());
            app(CurriculumGovernanceService::class)->assertFrameworkAdministrationAccess($locked, $actor);
            $allowed = $status === 'active' ? $locked->status === 'draft' : $locked->status === 'active';
            if (! $allowed) {
                throw new ConflictHttpException('This framework lifecycle transition is no longer available.');
            }
            if ($status === 'active' && (trim((string) $locked->title) === '' || trim((string) $locked->code) === '')) {
                throw ValidationException::withMessages(['framework' => 'Complete the framework identity before activation.']);
            }
            $locked->forceFill(['status' => $status, 'lock_version' => $locked->lock_version + 1])->save();
            $this->audit->record('academic.curriculum.framework.'.($status === 'active' ? 'activated' : 'retired'), $actor, (int) $this->context->tenant()->getKey(), $locked, ['status' => $status]);
        });
    }

    /** @param array<string,mixed> $data
     * @return array{curriculum:AcademicCurriculum,version:AcademicCurriculumVersion}
     */
    public function createCurriculum(array $data, User $actor): array
    {
        return DB::transaction(function () use ($data, $actor): array {
            $institution = Institution::query()->firstOrFail();
            $program = AcademicProgram::query()->where('institution_id', $institution->getKey())->where('status', 'active')->findOrFail($data['academic_program_id']);
            $framework = empty($data['framework_id']) ? null : AcademicCurriculumFramework::query()->where('institution_id', $institution->getKey())->findOrFail($data['framework_id']);
            if ($framework !== null) {
                app(CurriculumGovernanceService::class)->assertFrameworkAdministrationAccess($framework, $actor, (int) $program->getKey());
            }
            $year = empty($data['academic_year_id']) ? null : AcademicYear::query()->where('institution_id', $institution->getKey())->findOrFail($data['academic_year_id']);

            $curriculum = AcademicCurriculum::query()->create([
                'institution_id' => $institution->getKey(), 'academic_program_id' => $program->getKey(),
                'framework_id' => $framework?->getKey(), 'academic_year_id' => $year?->getKey(),
                'code' => $data['code'], 'title' => $data['title'], 'description' => $data['description'] ?? null,
                'status' => 'draft', 'sharing_status' => 'private', 'current_version_number' => 1,
                'created_by' => $actor->getKey(),
            ]);
            $version = AcademicCurriculumVersion::query()->create([
                'curriculum_id' => $curriculum->getKey(), 'version_number' => 1,
                'title' => $data['title'], 'status' => 'draft', 'effective_from' => $data['effective_from'] ?? null,
                'effective_until' => $data['effective_until'] ?? null,
                'revision_note' => 'Initial curriculum draft.',
            ]);
            $this->audit->record('academic.curriculum.created', $actor, (int) $this->context->tenant()->getKey(), $curriculum, ['program_id' => $program->getKey(), 'version_id' => $version->getKey()]);

            return ['curriculum' => $curriculum, 'version' => $version];
        });
    }

    public function setTemplateSharing(AcademicCurriculum $curriculum, string $sharingStatus, User $actor): void
    {
        if (! in_array($sharingStatus, ['private', 'shared'], true)) {
            throw ValidationException::withMessages(['sharing_status' => 'Choose private or shared.']);
        }
        DB::transaction(function () use ($curriculum, $sharingStatus, $actor): void {
            $locked = AcademicCurriculum::query()->lockForUpdate()->findOrFail($curriculum->getKey());
            if ($sharingStatus === 'shared' && ! AcademicCurriculumVersion::query()->where('curriculum_id', $locked->getKey())->where('status', 'published')->exists()) {
                throw ValidationException::withMessages(['sharing_status' => 'Only a curriculum with a published version can be offered as a shared template.']);
            }
            $before = $locked->sharing_status;
            $locked->forceFill(['sharing_status' => $sharingStatus, 'lock_version' => $locked->lock_version + 1])->save();
            $this->audit->record('academic.curriculum.template.sharing_changed', $actor, (int) $this->context->tenant()->getKey(), $locked, ['before' => $before, 'after' => $sharingStatus]);
        });
    }

    /** @param array<string,mixed> $data
     * @return array{curriculum:AcademicCurriculum,version:AcademicCurriculumVersion}
     */
    public function adoptSharedTemplate(int $sourceCurriculumId, array $data, User $actor): array
    {
        return DB::transaction(function () use ($sourceCurriculumId, $data, $actor): array {
            $source = AcademicCurriculum::query()->withoutGlobalScope('tenant')->where('sharing_status', 'shared')->findOrFail($sourceCurriculumId);
            $sourceVersion = AcademicCurriculumVersion::query()->withoutGlobalScope('tenant')->where('tenant_id', $source->tenant_id)->where('curriculum_id', $source->getKey())->where('status', 'published')->orderByDesc('version_number')->firstOrFail();
            $created = $this->createCurriculum([
                'academic_program_id' => $data['academic_program_id'], 'academic_year_id' => $data['academic_year_id'] ?? null,
                'framework_id' => null, 'code' => $data['code'], 'title' => $data['title'],
                'description' => $data['description'] ?? $source->description,
                'effective_from' => $sourceVersion->getRawOriginal('effective_from'),
                'effective_until' => $sourceVersion->getRawOriginal('effective_until'),
            ], $actor);
            $sourceNodes = AcademicCurriculumNode::query()->withoutGlobalScope('tenant')->where('tenant_id', $source->tenant_id)->where('curriculum_version_id', $sourceVersion->getKey())->orderBy('id')->get();
            $nodeMap = [];
            $pending = $sourceNodes->all();
            while ($pending !== []) {
                $progress = false;
                foreach ($pending as $index => $old) {
                    if ($old->parent_id !== null && ! isset($nodeMap[$old->parent_id])) {
                        continue;
                    }
                    $copy = AcademicCurriculumNode::query()->create([
                        'institution_id' => $created['curriculum']->institution_id,
                        'curriculum_version_id' => $created['version']->getKey(),
                        'parent_id' => $old->parent_id === null ? null : $nodeMap[$old->parent_id],
                        'node_type' => $old->node_type, 'code' => $old->code, 'title' => $old->title,
                        'description' => $old->description, 'sort_order' => $old->sort_order,
                        'is_required' => $old->is_required, 'estimated_minutes' => $old->estimated_minutes,
                        'workload_unit' => $old->workload_unit, 'instructional_strategy' => $old->instructional_strategy,
                        'activity_specification' => $old->activity_specification, 'assessment_blueprint' => $old->assessment_blueprint,
                        'planned_starts_on' => $old->planned_starts_on, 'planned_ends_on' => $old->planned_ends_on,
                    ]);
                    $nodeMap[$old->getKey()] = $copy->getKey();
                    unset($pending[$index]);
                    $progress = true;
                }
                if (! $progress) {
                    throw new ConflictHttpException('The shared template hierarchy is invalid and cannot be adopted.');
                }
                $pending = array_values($pending);
            }

            $institutionId = $created['curriculum']->institution_id;
            foreach (DB::table('academic_curriculum_node_outcomes')->where('tenant_id', $source->tenant_id)->where('curriculum_version_id', $sourceVersion->getKey())->get() as $mapping) {
                $sourceOutcomeCode = DB::table('academic_learning_outcomes')->where('tenant_id', $source->tenant_id)->where('id', $mapping->learning_outcome_id)->value('code');
                if (! is_string($sourceOutcomeCode)) {
                    continue;
                }
                $localOutcomeId = AcademicLearningOutcome::query()->where('institution_id', $institutionId)->where('code', $sourceOutcomeCode)->where('status', 'active')->value('id');
                if ($localOutcomeId !== null) {
                    AcademicCurriculumNodeOutcome::query()->create([
                        'curriculum_version_id' => $created['version']->getKey(), 'node_id' => $nodeMap[$mapping->node_id],
                        'learning_outcome_id' => $localOutcomeId, 'mapping_strength' => $mapping->mapping_strength,
                    ]);
                }
            }
            foreach (DB::table('academic_curriculum_node_competencies as mapping')
                ->join('academic_competencies as competency', function ($join): void {
                    $join->on('competency.id', '=', 'mapping.competency_id')->on('competency.tenant_id', '=', 'mapping.tenant_id');
                })
                ->where('mapping.tenant_id', $source->tenant_id)
                ->where('mapping.curriculum_version_id', $sourceVersion->getKey())
                ->get(['mapping.node_id', 'mapping.expected_level', 'mapping.mapping_strength', 'competency.code']) as $mapping) {
                $localCompetency = AcademicCompetency::query()
                    ->where('status', 'active')
                    ->where('code', $mapping->code)
                    ->whereHas('framework', fn ($query) => $query->where('institution_id', $institutionId))
                    ->first();
                if ($localCompetency !== null && isset($nodeMap[$mapping->node_id])) {
                    DB::table('academic_curriculum_node_competencies')->insert([
                        'tenant_id' => $this->context->tenant()->getKey(),
                        'curriculum_version_id' => $created['version']->getKey(),
                        'node_id' => $nodeMap[$mapping->node_id],
                        'competency_id' => $localCompetency->getKey(),
                        'expected_level' => $mapping->expected_level,
                        'mapping_strength' => $mapping->mapping_strength,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }
            }
            foreach (DB::table('academic_curriculum_prerequisites')->where('tenant_id', $source->tenant_id)->where('curriculum_version_id', $sourceVersion->getKey())->get() as $edge) {
                AcademicCurriculumPrerequisite::query()->create([
                    'curriculum_version_id' => $created['version']->getKey(), 'node_id' => $nodeMap[$edge->node_id],
                    'prerequisite_node_id' => $nodeMap[$edge->prerequisite_node_id], 'dependency_type' => $edge->dependency_type,
                ]);
            }
            $this->audit->record('academic.curriculum.template.adopted', $actor, (int) $this->context->tenant()->getKey(), $created['curriculum'], ['template_code' => $source->code, 'source_version' => $sourceVersion->version_number]);

            return $created;
        });
    }

    /** @param array<string,mixed> $data */
    public function addNode(AcademicCurriculumVersion $version, array $data, User $actor): AcademicCurriculumNode
    {
        return DB::transaction(function () use ($version, $data, $actor): AcademicCurriculumNode {
            $locked = $this->lockDraft($version->getKey(), (int) $data['version_lock'], $actor);
            $parent = empty($data['parent_id']) ? null : AcademicCurriculumNode::query()->where('curriculum_version_id', $locked->getKey())->findOrFail($data['parent_id']);
            $type = (string) $data['node_type'];
            $allowed = $parent === null ? ['course', 'unit'] : self::CHILD_TYPES[$parent->node_type] ?? [];
            if (! in_array($type, self::NODE_TYPES, true) || ! in_array($type, $allowed, true)) {
                throw ValidationException::withMessages(['node_type' => 'This node type is not valid at the selected hierarchy level.']);
            }
            if ($parent !== null && $this->depth($parent) >= 5) {
                throw ValidationException::withMessages(['parent_id' => 'Curriculum hierarchy depth is limited to six levels.']);
            }
            if (AcademicCurriculumNode::query()->where('curriculum_version_id', $locked->getKey())->count() >= 2000) {
                throw ValidationException::withMessages(['node_type' => 'A curriculum version is limited to 2,000 nodes.']);
            }

            $course = null;
            $courseVersion = null;
            if (! empty($data['academic_course_id'])) {
                if ($type !== 'course') {
                    throw ValidationException::withMessages(['academic_course_id' => 'A catalog course may only be linked to a course node.']);
                }
                $course = AcademicCourse::query()->where('institution_id', $version->curriculum->institution_id)->where('status', 'active')->findOrFail($data['academic_course_id']);
                if (! empty($data['academic_course_version_id'])) {
                    $courseVersion = AcademicCourseVersion::query()->where('academic_course_id', $course->getKey())->where('status', 'published')->findOrFail($data['academic_course_version_id']);
                }
            } elseif (! empty($data['academic_course_version_id'])) {
                throw ValidationException::withMessages(['academic_course_version_id' => 'Select a catalog course before selecting its version.']);
            }

            $periodId = null;
            if (! empty($data['academic_period_id'])) {
                $period = AcademicPeriod::query()->where('academic_year_id', $version->curriculum->academic_year_id)->findOrFail($data['academic_period_id']);
                $periodId = $period->getKey();
            }

            $siblingCount = AcademicCurriculumNode::query()->where('curriculum_version_id', $locked->getKey())->where('parent_id', $parent?->getKey())->count();
            $node = AcademicCurriculumNode::query()->create([
                'institution_id' => $version->curriculum->institution_id, 'curriculum_version_id' => $locked->getKey(),
                'parent_id' => $parent?->getKey(), 'academic_course_id' => $course?->getKey(), 'academic_course_version_id' => $courseVersion?->getKey(),
                'academic_period_id' => $periodId, 'node_type' => $type, 'code' => $data['code'] ?? null,
                'title' => $data['title'], 'description' => $data['description'] ?? null, 'sort_order' => ($siblingCount + 1) * 10,
                'is_required' => $data['is_required'] ?? true, 'estimated_minutes' => $data['estimated_minutes'] ?? null,
                'workload_unit' => $data['workload_unit'] ?? 'minutes', 'instructional_strategy' => $data['instructional_strategy'] ?? null,
                'activity_specification' => $data['activity_specification'] ?? null, 'assessment_blueprint' => $data['assessment_blueprint'] ?? null,
                'planned_starts_on' => $data['planned_starts_on'] ?? null, 'planned_ends_on' => $data['planned_ends_on'] ?? null,
            ]);
            $locked->increment('lock_version');
            $this->audit->record('academic.curriculum.node.created', $actor, (int) $this->context->tenant()->getKey(), $node, ['version_id' => $locked->getKey(), 'node_type' => $node->node_type]);

            return $node;
        });
    }

    /** @param array<string,mixed> $data */
    public function updateNode(AcademicCurriculumNode $node, array $data, User $actor): AcademicCurriculumNode
    {
        return DB::transaction(function () use ($node, $data, $actor): AcademicCurriculumNode {
            $version = $this->lockDraft($node->curriculum_version_id, (int) $data['version_lock'], $actor);
            $lockedNode = AcademicCurriculumNode::query()->where('curriculum_version_id', $version->getKey())->lockForUpdate()->findOrFail($node->getKey());
            $this->assertVersion($lockedNode, (int) $data['node_lock']);
            $before = $lockedNode->only(['title', 'description', 'estimated_minutes', 'workload_unit', 'instructional_strategy', 'activity_specification', 'assessment_blueprint', 'planned_starts_on', 'planned_ends_on', 'is_required']);
            $lockedNode->fill(collect($data)->only(['title', 'description', 'estimated_minutes', 'workload_unit', 'instructional_strategy', 'activity_specification', 'assessment_blueprint', 'planned_starts_on', 'planned_ends_on', 'is_required'])->all());
            $lockedNode->lock_version++;
            $lockedNode->save();
            $version->increment('lock_version');
            $this->audit->record('academic.curriculum.node.updated', $actor, (int) $this->context->tenant()->getKey(), $lockedNode, ['before' => $before, 'after' => $lockedNode->only(array_keys($before))]);

            return $lockedNode;
        });
    }

    /** @param array<string,mixed> $data */
    public function createCompletionRule(int $versionId, array $data, User $actor): AcademicCurriculumCompletionRule
    {
        return DB::transaction(function () use ($versionId, $data, $actor): AcademicCurriculumCompletionRule {
            $version = $this->lockDraft($versionId, (int) $data['version_lock'], $actor);
            $periodId = $this->completionRulePeriod($version, $data['academic_period_id'] ?? null);
            $sortOrder = (int) (AcademicCurriculumCompletionRule::query()->where('curriculum_version_id', $version->getKey())->max('sort_order') ?? 0) + 10;
            $rule = AcademicCurriculumCompletionRule::query()->create([
                ...collect($data)->only(['code', 'title', 'rule_type', 'target_value', 'unit', 'is_required', 'description'])->all(),
                'curriculum_version_id' => $version->getKey(),
                'academic_period_id' => $periodId,
                'sort_order' => $sortOrder,
            ]);
            $version->increment('lock_version');
            $this->audit->record('academic.curriculum.completion_rule.created', $actor, (int) $this->context->tenant()->getKey(), $rule, ['version_id' => $version->getKey(), 'rule_type' => $rule->rule_type]);

            return $rule;
        }, 3);
    }

    /** @param array<string,mixed> $data */
    public function updateCompletionRule(int $versionId, int $ruleId, array $data, User $actor): AcademicCurriculumCompletionRule
    {
        return DB::transaction(function () use ($versionId, $ruleId, $data, $actor): AcademicCurriculumCompletionRule {
            $version = $this->lockDraft($versionId, (int) $data['version_lock'], $actor);
            $rule = AcademicCurriculumCompletionRule::query()->where('curriculum_version_id', $version->getKey())->lockForUpdate()->findOrFail($ruleId);
            $periodId = $this->completionRulePeriod($version, $data['academic_period_id'] ?? null);
            $before = $rule->only(['code', 'title', 'rule_type', 'target_value', 'unit', 'academic_period_id', 'is_required', 'description']);
            $rule->fill([
                ...collect($data)->only(['code', 'title', 'rule_type', 'target_value', 'unit', 'is_required', 'description'])->all(),
                'academic_period_id' => $periodId,
            ])->save();
            $version->increment('lock_version');
            $this->audit->record('academic.curriculum.completion_rule.updated', $actor, (int) $this->context->tenant()->getKey(), $rule, ['before' => $before, 'after' => $rule->only(array_keys($before))]);

            return $rule;
        }, 3);
    }

    public function deleteCompletionRule(int $versionId, int $ruleId, int $versionLock, User $actor): void
    {
        DB::transaction(function () use ($versionId, $ruleId, $versionLock, $actor): void {
            $version = $this->lockDraft($versionId, $versionLock, $actor);
            $rule = AcademicCurriculumCompletionRule::query()->where('curriculum_version_id', $version->getKey())->lockForUpdate()->findOrFail($ruleId);
            $metadata = ['version_id' => $version->getKey(), 'code' => $rule->code, 'rule_type' => $rule->rule_type];
            $rule->delete();
            $version->increment('lock_version');
            $this->audit->record('academic.curriculum.completion_rule.deleted', $actor, (int) $this->context->tenant()->getKey(), $version, $metadata);
        }, 3);
    }

    public function reorderNode(AcademicCurriculumNode $node, string $direction, int $versionLock, User $actor): void
    {
        if (! in_array($direction, ['up', 'down'], true)) {
            throw ValidationException::withMessages(['direction' => 'Choose up or down.']);
        }
        DB::transaction(function () use ($node, $direction, $versionLock, $actor): void {
            $version = $this->lockDraft($node->curriculum_version_id, $versionLock, $actor);
            $rows = AcademicCurriculumNode::query()->where('curriculum_version_id', $version->getKey())->where('parent_id', $node->parent_id)->orderBy('sort_order')->orderBy('id')->lockForUpdate()->get();
            $index = $rows->search(fn (AcademicCurriculumNode $candidate): bool => $candidate->is($node));
            if (! is_int($index)) {
                throw ValidationException::withMessages(['node' => 'Curriculum node no longer exists in this version.']);
            }
            $otherIndex = $direction === 'up' ? $index - 1 : $index + 1;
            if (! $rows->has($otherIndex)) {
                return;
            }
            $first = $rows->get($index);
            $second = $rows->get($otherIndex);
            if (! $first instanceof AcademicCurriculumNode || ! $second instanceof AcademicCurriculumNode) {
                throw ValidationException::withMessages(['node' => 'The selected hierarchy order is no longer available.']);
            }
            $ordered = $rows->values()->all();
            $ordered[$index] = $second;
            $ordered[$otherIndex] = $first;
            foreach ($ordered as $position => $row) {
                $row->forceFill(['sort_order' => 100000 + $position, 'lock_version' => $row->lock_version + 1])->save();
            }
            foreach ($ordered as $position => $row) {
                $row->forceFill(['sort_order' => ($position + 1) * 10])->save();
            }
            $version->increment('lock_version');
            $this->audit->record('academic.curriculum.nodes.reordered', $actor, (int) $this->context->tenant()->getKey(), $version, ['node_id' => $node->getKey(), 'direction' => $direction]);
        });
    }

    public function mapOutcome(AcademicCurriculumNode $node, int $outcomeId, string $strength, int $versionLock, User $actor): AcademicCurriculumNodeOutcome
    {
        return DB::transaction(function () use ($node, $outcomeId, $strength, $versionLock, $actor): AcademicCurriculumNodeOutcome {
            $version = $this->lockDraft($node->curriculum_version_id, $versionLock, $actor);
            $node = AcademicCurriculumNode::query()->where('curriculum_version_id', $version->getKey())->findOrFail($node->getKey());
            $outcome = AcademicLearningOutcome::query()->where('institution_id', $node->institution_id)->where('status', 'active')->findOrFail($outcomeId);
            $mapping = AcademicCurriculumNodeOutcome::query()->updateOrCreate(
                ['curriculum_version_id' => $version->getKey(), 'node_id' => $node->getKey(), 'learning_outcome_id' => $outcome->getKey()],
                ['mapping_strength' => $strength],
            );
            $version->increment('lock_version');
            $this->audit->record('academic.curriculum.outcome.mapped', $actor, (int) $this->context->tenant()->getKey(), $node, ['outcome_id' => $outcome->getKey(), 'strength' => $strength]);

            return $mapping;
        });
    }

    public function unmapOutcome(AcademicCurriculumNode $node, int $outcomeId, int $versionLock, User $actor): void
    {
        DB::transaction(function () use ($node, $outcomeId, $versionLock, $actor): void {
            $version = $this->lockDraft($node->curriculum_version_id, $versionLock, $actor);
            $node = AcademicCurriculumNode::query()->where('curriculum_version_id', $version->getKey())->lockForUpdate()->findOrFail($node->getKey());
            $mapping = AcademicCurriculumNodeOutcome::query()
                ->where('curriculum_version_id', $version->getKey())
                ->where('node_id', $node->getKey())
                ->where('learning_outcome_id', $outcomeId)
                ->firstOrFail();
            $mapping->delete();
            $version->increment('lock_version');
            $this->audit->record('academic.curriculum.outcome.unmapped', $actor, (int) $this->context->tenant()->getKey(), $node, ['outcome_id' => $outcomeId]);
        });
    }

    public function addPrerequisite(AcademicCurriculumNode $node, int $prerequisiteId, string $type, int $versionLock, User $actor): AcademicCurriculumPrerequisite
    {
        return DB::transaction(function () use ($node, $prerequisiteId, $type, $versionLock, $actor): AcademicCurriculumPrerequisite {
            $version = $this->lockDraft($node->curriculum_version_id, $versionLock, $actor);
            $node = AcademicCurriculumNode::query()->where('curriculum_version_id', $version->getKey())->lockForUpdate()->findOrFail($node->getKey());
            $prerequisite = AcademicCurriculumNode::query()->where('curriculum_version_id', $version->getKey())->findOrFail($prerequisiteId);
            if ($node->is($prerequisite) || $this->wouldCreateCycle((int) $node->getKey(), (int) $prerequisite->getKey(), (int) $version->getKey())) {
                throw ValidationException::withMessages(['prerequisite_node_id' => 'This prerequisite would create a learning-path cycle.']);
            }
            $edge = AcademicCurriculumPrerequisite::query()->create([
                'curriculum_version_id' => $version->getKey(), 'node_id' => $node->getKey(),
                'prerequisite_node_id' => $prerequisite->getKey(), 'dependency_type' => $type,
            ]);
            $version->increment('lock_version');
            $this->audit->record('academic.curriculum.prerequisite.added', $actor, (int) $this->context->tenant()->getKey(), $node, ['prerequisite_node_id' => $prerequisite->getKey(), 'dependency_type' => $type]);

            return $edge;
        });
    }

    /** @param array<string,mixed> $data */
    public function createCompetencyFramework(array $data, User $actor): AcademicCompetencyFramework
    {
        return app(CurriculumGovernanceService::class)->createCompetencyFramework($data, $actor);
    }

    /** @param array<string,mixed> $data */
    public function addCompetency(int $frameworkId, array $data, User $actor): AcademicCompetency
    {
        return app(CurriculumGovernanceService::class)->addCompetency($frameworkId, $data, $actor);
    }

    public function mapCompetency(AcademicCurriculumNode $node, int $competencyId, ?string $level, string $strength, int $versionLock, User $actor): void
    {
        DB::transaction(function () use ($node, $competencyId, $level, $strength, $versionLock, $actor): void {
            $version = $this->lockDraft($node->curriculum_version_id, $versionLock, $actor);
            $node = AcademicCurriculumNode::query()->where('curriculum_version_id', $version->getKey())->findOrFail($node->getKey());
            $competency = AcademicCompetency::query()->where('status', 'active')->findOrFail($competencyId);
            $sameInstitution = AcademicCompetencyFramework::query()->where('institution_id', $node->institution_id)->whereKey($competency->framework_id)->exists();
            if (! $sameInstitution) {
                throw ValidationException::withMessages(['competency_id' => 'Choose a competency from this institution.']);
            }
            DB::table('academic_curriculum_node_competencies')->updateOrInsert(
                ['tenant_id' => $this->context->tenant()->getKey(), 'curriculum_version_id' => $version->getKey(), 'node_id' => $node->getKey(), 'competency_id' => $competency->getKey()],
                ['expected_level' => $level, 'mapping_strength' => $strength, 'created_at' => now(), 'updated_at' => now()],
            );
            $version->increment('lock_version');
            $this->audit->record('academic.curriculum.competency.mapped', $actor, (int) $this->context->tenant()->getKey(), $node, ['competency_id' => $competency->getKey(), 'strength' => $strength]);
        });
    }

    /** @param int|list<int> $reviewerIds */
    public function submit(AcademicCurriculumVersion $version, int|array $reviewerIds, int $versionLock, User $actor): AcademicCurriculumReview
    {
        return DB::transaction(function () use ($version, $reviewerIds, $versionLock, $actor): AcademicCurriculumReview {
            $locked = AcademicCurriculumVersion::query()->with('curriculum')->lockForUpdate()->findOrFail($version->getKey());
            app(CurriculumGovernanceService::class)->assertCurriculumAdministrationAccess($locked, $actor);
            $frameworkId = $locked->curriculum->framework_id;
            $policy = $this->activeGovernancePolicy((int) $locked->curriculum->institution_id, $frameworkId);
            if (! in_array($locked->status, ['draft', 'changes_requested', 'rejected'], true)) {
                throw new ConflictHttpException('Only a draft or policy-authorized resubmission can be submitted for review.');
            }
            if ($versionLock !== (int) $locked->lock_version) {
                $this->assertVersion($locked, $versionLock);
            }
            if ($locked->status === 'rejected' && ! ($policy->allow_resubmission ?? false)) {
                throw new ConflictHttpException('The current policy requires a new curriculum revision after rejection.');
            }
            if ($locked->status === 'approved') {
                $priorPolicyId = AcademicCurriculumReview::query()->where('curriculum_version_id', $locked->getKey())->where('review_round', $locked->review_round)->where('decision', 'approve')->latest('id')->value('policy_version_id');
                if ($priorPolicyId === ($policy?->getKey())) {
                    throw new ConflictHttpException('This curriculum has no stale approval that requires resubmission.');
                }
            }
            $reviewerIds = is_array($reviewerIds) ? array_values(array_unique(array_map('intval', $reviewerIds))) : [(int) $reviewerIds];
            $minimumReviewers = (int) ($policy->minimum_reviewers ?? 1);
            if (count($reviewerIds) < $minimumReviewers || count($reviewerIds) > 3) {
                throw ValidationException::withMessages(['reviewer_user_ids' => "Select between {$minimumReviewers} and 3 distinct authorized reviewers."]);
            }
            $requireIndependence = $policy->require_independent_reviewer ?? true;
            if ($requireIndependence && in_array((int) $actor->getKey(), $reviewerIds, true)) {
                throw ValidationException::withMessages(['reviewer_user_ids' => 'The author cannot review their own curriculum version.']);
            }
            $validation = $this->validationSummary($locked);
            if ($validation['blocking'] !== []) {
                throw ValidationException::withMessages(['curriculum' => 'Resolve the required curriculum design gaps before review: '.implode('; ', $validation['blocking'])]);
            }
            foreach ($reviewerIds as $reviewerId) {
                $reviewer = User::query()->whereKey($reviewerId)->firstOrFail();
                $membership = TenantMembership::query()->where('tenant_id', $this->context->tenant()->getKey())->where('institution_id', $locked->curriculum->institution_id)->where('user_id', $reviewer->getKey())->where('status', 'active')->first();
                if ($membership === null || ! $membership->hasPermission('academic.curriculum.review')) {
                    throw ValidationException::withMessages(['reviewer_user_ids' => 'Every reviewer must be an active institution-wide member with curriculum review permission.']);
                }
                if ($policy?->reviewer_eligibility === 'institution_wide' && $membership->campus_id !== null) {
                    throw ValidationException::withMessages(['reviewer_user_ids' => 'This policy requires institution-wide reviewers.']);
                }
                if ($policy?->reviewer_eligibility === 'framework_assignees') {
                    $assigned = $frameworkId !== null && AcademicCurriculumFrameworkAdministrator::query()->where('framework_id', $frameworkId)->where('user_id', $reviewerId)->whereNull('revoked_at')
                        ->where(fn ($query) => $query->whereNull('campus_id')->orWhere('campus_id', $membership->campus_id))
                        ->where(fn ($query) => $query->whereNull('academic_program_id')->orWhere('academic_program_id', $locked->curriculum->academic_program_id))
                        ->where(fn ($query) => $query->whereNull('effective_from')->orWhere('effective_from', '<=', today()))
                        ->where(fn ($query) => $query->whereNull('effective_until')->orWhere('effective_until', '>=', today()))->exists();
                    if (! $assigned) {
                        throw ValidationException::withMessages(['reviewer_user_ids' => 'Each reviewer must hold an active framework administration assignment.']);
                    }
                }
                if ($policy?->reviewer_eligibility === 'program_scope') {
                    $programAssignment = $frameworkId !== null && AcademicCurriculumFrameworkAdministrator::query()->where('framework_id', $frameworkId)->where('user_id', $reviewerId)->where('academic_program_id', $locked->curriculum->academic_program_id)->whereNull('revoked_at')
                        ->where(fn ($query) => $query->whereNull('campus_id')->orWhere('campus_id', $membership->campus_id))
                        ->where(fn ($query) => $query->whereNull('effective_from')->orWhere('effective_from', '<=', today()))
                        ->where(fn ($query) => $query->whereNull('effective_until')->orWhere('effective_until', '>=', today()))->exists();
                    if (! $programAssignment) {
                        throw ValidationException::withMessages(['reviewer_user_ids' => 'Each reviewer must hold an active assignment for this program.']);
                    }
                }
            }
            $snapshot = $policy?->checklistTemplate?->items?->map(fn ($item): array => [
                'id' => (int) $item->getKey(), 'category' => $item->category, 'prompt' => $item->prompt,
                'is_required' => (bool) $item->is_required, 'requires_note' => (bool) $item->requires_note,
            ])->values()->all() ?? [];
            $round = (int) $locked->review_round + 1;
            $locked->forceFill(['status' => 'in_review', 'reviewer_user_id' => $reviewerIds[0], 'submitted_by' => $actor->getKey(), 'submitted_at' => now(), 'review_round' => $round, 'lock_version' => $locked->lock_version + 1])->save();
            $review = null;
            foreach ($reviewerIds as $reviewerId) {
                $review ??= AcademicCurriculumReview::query()->create([
                    'curriculum_version_id' => $locked->getKey(), 'reviewer_user_id' => $reviewerId,
                    'decision' => 'assigned', 'assigned_at' => now(), 'checklist_snapshot' => $snapshot, 'policy_version_id' => $policy?->getKey(), 'review_round' => $round,
                ]);
                if ((int) $review->reviewer_user_id !== $reviewerId) {
                    AcademicCurriculumReview::query()->create([
                        'curriculum_version_id' => $locked->getKey(), 'reviewer_user_id' => $reviewerId,
                        'decision' => 'assigned', 'assigned_at' => now(), 'checklist_snapshot' => $snapshot, 'policy_version_id' => $policy?->getKey(), 'review_round' => $round,
                    ]);
                }
            }
            $this->audit->record('academic.curriculum.submitted', $actor, (int) $this->context->tenant()->getKey(), $locked, ['reviewer_count' => count($reviewerIds), 'policy_version' => $policy?->version_number, 'version' => $locked->version_number]);

            return $review ?? throw new ConflictHttpException('At least one reviewer must be assigned.');
        }, 3);
    }

    /** @param array<int|string,array<string,mixed>> $responses */
    public function decide(AcademicCurriculumVersion $version, string $decision, ?string $note, User $actor, array $responses = []): void
    {
        if (! in_array($decision, ['approve', 'request_changes', 'reject'], true)) {
            throw ValidationException::withMessages(['decision' => 'Choose approve, request changes, or reject.']);
        }
        DB::transaction(function () use ($version, $decision, $note, $actor, $responses): void {
            $locked = AcademicCurriculumVersion::query()->lockForUpdate()->findOrFail($version->getKey());
            if ($locked->status !== 'in_review' || (int) $locked->submitted_by === (int) $actor->getKey()) {
                throw new ConflictHttpException('This review assignment is no longer available to this reviewer.');
            }
            $membership = TenantMembership::query()->where('tenant_id', $this->context->tenant()->getKey())->where('institution_id', $locked->curriculum()->value('institution_id'))->where('user_id', $actor->getKey())->where('status', 'active')->first();
            if (! $membership || ! $membership->hasPermission('academic.curriculum.review')) {
                throw ValidationException::withMessages(['reviewer_user_id' => 'An active member with curriculum review permission is required.']);
            }
            $review = AcademicCurriculumReview::query()->where('curriculum_version_id', $locked->getKey())->where('review_round', $locked->review_round)->where('reviewer_user_id', $actor->getKey())->where('decision', 'assigned')->latest('id')->lockForUpdate()->firstOrFail();
            $policy = $review->policy_version_id ? AcademicCurriculumGovernancePolicy::query()->with('checklistTemplate.items')->find($review->policy_version_id) : null;
            $this->assertGovernanceDecision($decision, $note, $policy);
            $normalizedResponses = $this->reviewChecklistResponses((array) $review->checklist_snapshot, $responses, (bool) ($policy->require_checklist_completion ?? false));
            $review->forceFill(['decision' => $decision, 'decided_by' => $actor->getKey(), 'review_note' => $note, 'checklist_responses' => $normalizedResponses, 'decided_at' => now()])->save();
            if ($decision === 'approve') {
                $approved = AcademicCurriculumReview::query()->where('curriculum_version_id', $locked->getKey())->where('review_round', $locked->review_round)->where('decision', 'approve')->count();
                $pending = AcademicCurriculumReview::query()->where('curriculum_version_id', $locked->getKey())->where('review_round', $locked->review_round)->where('decision', 'assigned')->exists();
                $next = $approved >= (int) ($policy->minimum_reviewers ?? 1) && ! $pending ? 'approved' : 'in_review';
            } else {
                AcademicCurriculumReview::query()->where('curriculum_version_id', $locked->getKey())->where('review_round', $locked->review_round)->where('decision', 'assigned')->update(['decision' => 'cancelled', 'updated_at' => now()]);
                $next = $decision === 'request_changes' ? 'changes_requested' : 'rejected';
            }
            $locked->forceFill(['status' => $next, 'lock_version' => $locked->lock_version + 1])->save();
            $this->audit->record('academic.curriculum.review.decided', $actor, (int) $this->context->tenant()->getKey(), $locked, ['decision' => $decision, 'review_id' => $review->getKey()]);
        });
    }

    public function publish(AcademicCurriculumVersion $version, User $actor): void
    {
        DB::transaction(function () use ($version, $actor): void {
            $locked = AcademicCurriculumVersion::query()->lockForUpdate()->findOrFail($version->getKey());
            $locked->loadMissing('curriculum');
            app(CurriculumGovernanceService::class)->assertCurriculumAdministrationAccess($locked, $actor);
            if ($locked->status !== 'approved' || (int) $locked->reviewer_user_id === (int) $actor->getKey()) {
                throw new ConflictHttpException('Only an independently approved curriculum version can be published by a different authorized member.');
            }
            $latestReview = AcademicCurriculumReview::query()->where('curriculum_version_id', $locked->getKey())->where('review_round', $locked->review_round)->latest('id')->first();
            $policy = $latestReview?->policy_version_id ? AcademicCurriculumGovernancePolicy::query()->find($latestReview->policy_version_id) : null;
            $currentPolicy = $this->activeGovernancePolicy((int) $locked->curriculum->institution_id, $locked->curriculum->framework_id);
            if (($policy?->getKey()) !== ($currentPolicy?->getKey())) {
                throw new ConflictHttpException('The approval policy changed after review. Submit this curriculum again under the current policy.');
            }
            $approvals = AcademicCurriculumReview::query()->where('curriculum_version_id', $locked->getKey())->where('review_round', $locked->review_round)->where('decision', 'approve')->pluck('reviewer_user_id');
            if ($approvals->contains($actor->getKey()) || (($policy->require_separate_publisher ?? false) && (int) $locked->submitted_by === (int) $actor->getKey())) {
                throw new ConflictHttpException('The author and approving reviewers cannot publish under the current policy.');
            }
            if ($approvals->count() < (int) ($policy->minimum_reviewers ?? 1)) {
                throw new ConflictHttpException('The required number of independent approvals has not been recorded.');
            }
            $validation = $this->validationSummary($locked);
            if ($validation['blocking'] !== []) {
                throw ValidationException::withMessages(['curriculum' => 'Curriculum validation changed after review; resolve the design gaps and submit a new revision.']);
            }
            $locked->forceFill(['status' => 'published', 'published_by' => $actor->getKey(), 'published_at' => now(), 'lock_version' => $locked->lock_version + 1])->save();
            $curriculum = AcademicCurriculum::query()->lockForUpdate()->findOrFail($locked->curriculum_id);
            $curriculum->forceFill(['status' => 'published', 'current_version_number' => $locked->version_number, 'lock_version' => $curriculum->lock_version + 1])->save();
            $this->audit->record('academic.curriculum.published', $actor, (int) $this->context->tenant()->getKey(), $locked, ['curriculum_id' => $curriculum->getKey(), 'version' => $locked->version_number]);
        });
    }

    public function createRevision(AcademicCurriculum $curriculum, string $note, User $actor): AcademicCurriculumVersion
    {
        return DB::transaction(function () use ($curriculum, $note, $actor): AcademicCurriculumVersion {
            $lockedCurriculum = AcademicCurriculum::query()->lockForUpdate()->findOrFail($curriculum->getKey());
            $published = AcademicCurriculumVersion::query()->where('curriculum_id', $lockedCurriculum->getKey())->where('status', 'published')->orderByDesc('version_number')->firstOrFail();
            if (AcademicCurriculumVersion::query()->where('curriculum_id', $lockedCurriculum->getKey())->where('status', 'draft')->exists()) {
                throw new ConflictHttpException('Finish or discard the existing draft before starting another revision.');
            }
            $new = AcademicCurriculumVersion::query()->create([
                'curriculum_id' => $lockedCurriculum->getKey(), 'based_on_version_id' => $published->getKey(),
                'version_number' => (int) $lockedCurriculum->versions()->max('version_number') + 1,
                'title' => $published->title, 'status' => 'draft', 'revision_note' => $note,
                'effective_from' => $published->effective_from, 'effective_until' => $published->effective_until,
            ]);
            $nodes = AcademicCurriculumNode::query()->where('curriculum_version_id', $published->getKey())->orderByRaw('CASE WHEN parent_id IS NULL THEN 0 ELSE 1 END')->orderBy('id')->get();
            $idMap = [];
            $pending = $nodes->all();
            while ($pending !== []) {
                $progress = false;
                foreach ($pending as $index => $old) {
                    if ($old->parent_id !== null && ! isset($idMap[$old->parent_id])) {
                        continue;
                    }
                    $copy = $old->replicate(['tenant_id', 'curriculum_version_id', 'parent_id', 'lock_version', 'created_at', 'updated_at']);
                    $copy->forceFill([
                        'tenant_id' => $this->context->tenant()->getKey(), 'curriculum_version_id' => $new->getKey(),
                        'parent_id' => $old->parent_id === null ? null : $idMap[$old->parent_id], 'lock_version' => 1,
                    ])->save();
                    $idMap[$old->getKey()] = $copy->getKey();
                    unset($pending[$index]);
                    $progress = true;
                }
                if (! $progress) {
                    throw new ConflictHttpException('The saved curriculum hierarchy is inconsistent and cannot be safely revised.');
                }
                $pending = array_values($pending);
            }
            foreach (AcademicCurriculumNodeOutcome::query()->where('curriculum_version_id', $published->getKey())->get() as $mapping) {
                AcademicCurriculumNodeOutcome::query()->create(['curriculum_version_id' => $new->getKey(), 'node_id' => $idMap[$mapping->node_id], 'learning_outcome_id' => $mapping->learning_outcome_id, 'mapping_strength' => $mapping->mapping_strength]);
            }
            foreach (AcademicCurriculumPrerequisite::query()->where('curriculum_version_id', $published->getKey())->get() as $edge) {
                AcademicCurriculumPrerequisite::query()->create(['curriculum_version_id' => $new->getKey(), 'node_id' => $idMap[$edge->node_id], 'prerequisite_node_id' => $idMap[$edge->prerequisite_node_id], 'dependency_type' => $edge->dependency_type]);
            }
            foreach (DB::table('academic_curriculum_node_competencies')->where('curriculum_version_id', $published->getKey())->get() as $mapping) {
                DB::table('academic_curriculum_node_competencies')->insert(['tenant_id' => $this->context->tenant()->getKey(), 'curriculum_version_id' => $new->getKey(), 'node_id' => $idMap[$mapping->node_id], 'competency_id' => $mapping->competency_id, 'expected_level' => $mapping->expected_level, 'mapping_strength' => $mapping->mapping_strength, 'created_at' => now(), 'updated_at' => now()]);
            }
            foreach (AcademicCurriculumCompletionRule::query()->where('curriculum_version_id', $published->getKey())->get() as $rule) {
                $copy = $rule->replicate(['tenant_id', 'curriculum_version_id', 'created_at', 'updated_at']);
                $copy->forceFill(['tenant_id' => $this->context->tenant()->getKey(), 'curriculum_version_id' => $new->getKey()])->save();
            }
            $lockedCurriculum->forceFill(['status' => 'draft', 'lock_version' => $lockedCurriculum->lock_version + 1])->save();
            $this->audit->record('academic.curriculum.revision.created', $actor, (int) $this->context->tenant()->getKey(), $new, ['curriculum_id' => $curriculum->getKey(), 'based_on_version' => $published->version_number]);

            return $new;
        });
    }

    /** @return array{blocking:list<string>,warnings:list<string>,required_outcomes:int,mapped_required_outcomes:int,node_count:int,coverage_percent:int} */
    public function validationSummary(AcademicCurriculumVersion $version): array
    {
        $version->loadMissing('curriculum');
        $frameworkId = $version->curriculum->framework_id;
        $required = $frameworkId === null ? collect() : AcademicCurriculumFrameworkOutcome::query()->where('framework_id', $frameworkId)->where('required', true)->pluck('learning_outcome_id');
        $mapped = $required->isEmpty() ? collect() : AcademicCurriculumNodeOutcome::query()->where('curriculum_version_id', $version->getKey())->whereIn('learning_outcome_id', $required)->distinct()->pluck('learning_outcome_id');
        $nodeCount = AcademicCurriculumNode::query()->where('curriculum_version_id', $version->getKey())->count();
        $blocking = [];
        if ($nodeCount === 0) {
            $blocking[] = 'Add at least one curriculum unit or course node.';
        }
        $planNodes = AcademicCurriculumNode::query()->where('curriculum_version_id', $version->getKey())->with(['courseVersion', 'competencies'])->get();
        foreach (AcademicCurriculumCompletionRule::query()->where('curriculum_version_id', $version->getKey())->where('is_required', true)->get() as $rule) {
            if ($rule->rule_type === 'completion_milestone' || $rule->target_value === null) {
                continue;
            }
            $scopedNodes = $planNodes->filter(fn (AcademicCurriculumNode $node): bool => $rule->academic_period_id === null || (int) $node->academic_period_id === (int) $rule->academic_period_id)
                ->filter(fn (AcademicCurriculumNode $node): bool => $node->is_required);
            $actual = match ($rule->rule_type) {
                'minimum_credits' => $scopedNodes->where('node_type', 'course')->sum(function (AcademicCurriculumNode $node): float {
                    $courseVersion = $node->getRelation('courseVersion');

                    return $courseVersion instanceof AcademicCourseVersion ? (float) $courseVersion->total_credits : 0.0;
                }),
                'minimum_contact_hours' => $scopedNodes->where('node_type', 'course')->sum(function (AcademicCurriculumNode $node): float {
                    $courseVersion = $node->getRelation('courseVersion');

                    return $courseVersion instanceof AcademicCourseVersion ? (float) ($courseVersion->contact_hours ?? 0) : 0.0;
                }),
                'required_courses' => $scopedNodes->where('node_type', 'course')->count(),
                'required_units' => $scopedNodes->where('node_type', 'unit')->count(),
                'required_competencies' => $scopedNodes->flatMap(fn (AcademicCurriculumNode $node) => $node->competencies->pluck('competency_id'))->unique()->count(),
                'minimum_workload' => $scopedNodes->where('workload_unit', $rule->unit)->sum(fn (AcademicCurriculumNode $node): int => (int) ($node->estimated_minutes ?? 0)),
                default => 0,
            };
            if ((float) $actual < (float) $rule->target_value) {
                $blocking[] = sprintf('Required completion plan rule “%s” is below its target (%s / %s %s).', $rule->title, rtrim(rtrim(number_format((float) $actual, 3, '.', ''), '0'), '.'), rtrim(rtrim(number_format((float) $rule->target_value, 3, '.', ''), '0'), '.'), $rule->unit ?? 'units');
            }
        }
        $missing = $required->diff($mapped)->count();
        if ($missing > 0) {
            $blocking[] = "Map all required framework outcomes (missing {$missing}).";
        }
        $mappedCount = $mapped->count();

        return [
            'blocking' => $blocking, 'warnings' => [], 'required_outcomes' => $required->count(),
            'mapped_required_outcomes' => $mappedCount, 'node_count' => $nodeCount,
            'coverage_percent' => $required->isEmpty() ? 100 : (int) floor(($mappedCount / $required->count()) * 100),
        ];
    }

    /** @return array<string,int> */
    public function dashboardMetrics(): array
    {
        return [
            'frameworks' => AcademicCurriculumFramework::query()->count(),
            'curricula' => AcademicCurriculum::query()->count(),
            'published' => AcademicCurriculumVersion::query()->where('status', 'published')->count(),
            'in_review' => AcademicCurriculumVersion::query()->where('status', 'in_review')->count(),
            'nodes' => AcademicCurriculumNode::query()->count(),
        ];
    }

    private function lockDraft(int $versionId, ?int $expectedLock, ?User $actor = null): AcademicCurriculumVersion
    {
        $version = AcademicCurriculumVersion::query()->with('curriculum')->lockForUpdate()->findOrFail($versionId);
        if ($actor !== null) {
            app(CurriculumGovernanceService::class)->assertCurriculumAdministrationAccess($version, $actor);
        }
        if (! in_array($version->status, ['draft', 'changes_requested'], true)) {
            throw new ConflictHttpException('Only a draft or changes-requested curriculum version can be edited.');
        }
        if ($expectedLock !== null) {
            $this->assertVersion($version, $expectedLock);
        }

        return $version;
    }

    private function activeGovernancePolicy(int $institutionId, ?int $frameworkId): ?AcademicCurriculumGovernancePolicy
    {
        return AcademicCurriculumGovernancePolicy::query()->with('checklistTemplate.items')
            ->where('scope', 'curriculum')->where('institution_id', $institutionId)->whereIn('status', ['active', 'scheduled', 'superseded'])
            ->where('effective_from', '<=', today())
            ->where(fn ($query) => $query->whereNull('effective_until')->orWhere('effective_until', '>=', today()))
            ->where(fn ($query) => $frameworkId === null ? $query->whereNull('framework_id') : $query->where(fn ($q) => $q->where('framework_id', $frameworkId)->orWhereNull('framework_id')))
            ->orderByRaw('CASE WHEN framework_id IS NULL THEN 1 ELSE 0 END')->orderByDesc('version_number')->first();
    }

    private function assertGovernanceDecision(string $decision, ?string $note, ?AcademicCurriculumGovernancePolicy $policy): void
    {
        $requiresNote = match ($decision) {
            'request_changes' => $policy->require_changes_note ?? true,
            'reject' => $policy->require_rejection_note ?? true,
            default => false,
        };
        if ($requiresNote && trim((string) $note) === '') {
            throw ValidationException::withMessages(['review_note' => 'Add a note that explains this review decision.']);
        }
    }

    /**
     * @param  list<array<string,mixed>>  $snapshot
     * @param  array<int|string,array<string,mixed>>  $responses
     *
     * @phpstan-return list<array<string,mixed>>
     */
    private function reviewChecklistResponses(array $snapshot, array $responses, bool $requireComplete): array
    {
        $items = [];
        foreach ($snapshot as $item) {
            $id = (string) ($item['id'] ?? '');
            if ($id !== '') {
                $items[$id] = $item;
            }
        }
        foreach ($responses as $id => $response) {
            if (! isset($items[(string) $id])) {
                throw ValidationException::withMessages(['checklist_responses' => 'A response references an item outside this review snapshot.']);
            }
        }
        $normalized = [];
        foreach ($items as $id => $item) {
            $response = $responses[$id] ?? $responses[(int) $id] ?? [];
            $complete = (bool) ($response['complete'] ?? false);
            $note = trim((string) ($response['note'] ?? ''));
            $evidence = trim((string) ($response['evidence_reference'] ?? ''));
            if (($requireComplete && (bool) ($item['is_required'] ?? false) && ! $complete)
                || ((bool) ($item['requires_note'] ?? false) && $complete && $note === '')) {
                throw ValidationException::withMessages(['checklist_responses' => 'Complete required checklist items and their required notes before deciding.']);
            }
            if (mb_strlen($note) > 2000 || mb_strlen($evidence) > 500) {
                throw ValidationException::withMessages(['checklist_responses' => 'Checklist notes or evidence references exceed the allowed length.']);
            }
            $normalized[] = ['item_id' => (int) $id, 'complete' => $complete, 'note' => $note ?: null, 'evidence_reference' => $evidence ?: null];
        }

        return $normalized;
    }

    private function assertVersion(Model $model, int $expected): void
    {
        if ((int) $model->getAttribute('lock_version') !== $expected) {
            throw new ConflictHttpException('This curriculum changed in another session. Reload the latest version before editing.');
        }
    }

    private function completionRulePeriod(AcademicCurriculumVersion $version, mixed $periodId): ?int
    {
        if ($periodId === null || $periodId === '') {
            return null;
        }

        $yearId = $version->curriculum->academic_year_id;
        $period = $yearId === null
            ? null
            : AcademicPeriod::query()->where('academic_year_id', $yearId)->find((int) $periodId);
        if ($period === null) {
            throw ValidationException::withMessages(['academic_period_id' => 'Choose a period from this curriculum year.']);
        }

        return (int) $period->getKey();
    }

    private function depth(AcademicCurriculumNode $node): int
    {
        $depth = 0;
        $cursor = $node;
        while ($cursor->parent_id !== null) {
            $depth++;
            if ($depth > 5) {
                throw new ConflictHttpException('The stored curriculum tree exceeds the supported depth.');
            }
            $cursor = AcademicCurriculumNode::query()->where('curriculum_version_id', $node->curriculum_version_id)->findOrFail($cursor->parent_id);
        }

        return $depth;
    }

    private function wouldCreateCycle(int $nodeId, int $prerequisiteId, int $versionId): bool
    {
        $edges = AcademicCurriculumPrerequisite::query()->where('curriculum_version_id', $versionId)->get(['node_id', 'prerequisite_node_id']);
        $graph = [];
        foreach ($edges as $edge) {
            $graph[(int) $edge->node_id][] = (int) $edge->prerequisite_node_id;
        }
        $stack = [$prerequisiteId];
        $seen = [];
        while ($stack !== []) {
            $current = array_pop($stack);
            if ($current === $nodeId) {
                return true;
            }
            if (isset($seen[$current])) {
                continue;
            }
            $seen[$current] = true;
            foreach ($graph[$current] ?? [] as $next) {
                $stack[] = $next;
            }
        }

        return false;
    }
}
