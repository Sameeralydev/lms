<?php

namespace App\Http\Controllers\Workspace;

use App\Domain\Academic\Curriculum\CurriculumQualityAnalyzer;
use App\Domain\Academic\Curriculum\CurriculumVersionComparison;
use App\Domain\Tenancy\TenantContext;
use App\Models\AcademicCompetency;
use App\Models\AcademicCurriculum;
use App\Models\AcademicCurriculumFrameworkOutcome;
use App\Models\AcademicCurriculumNode;
use App\Models\AcademicCurriculumPrerequisite;
use App\Models\AcademicCurriculumVersion;
use App\Models\AcademicLearningOutcome;
use App\Models\AcademicLevel;
use App\Models\AcademicProgram;
use App\Models\AcademicYear;
use App\Models\Campus;
use App\Models\Institution;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

final class CurriculumInsightsController
{
    public function analytics(Request $request, Tenant $tenant, TenantContext $context): Response
    {
        $tenantId = (int) $context->tenant()->getKey();
        $institutionId = (int) Institution::query()->value('id');
        $filters = $request->validate([
            'academic_program_id' => ['nullable', 'integer', Rule::exists('academic_programs', 'id')->where(fn ($query) => $query->where('tenant_id', $tenantId)->where('institution_id', $institutionId))],
            'academic_year_id' => ['nullable', 'integer', Rule::exists('academic_years', 'id')->where(fn ($query) => $query->where('tenant_id', $tenantId)->where('institution_id', $institutionId))],
            'academic_level_id' => ['nullable', 'integer', Rule::exists('academic_levels', 'id')->where(fn ($query) => $query->where('tenant_id', $tenantId)->where('institution_id', $institutionId))],
            'campus_id' => ['nullable', 'integer', Rule::exists('campuses', 'id')->where(fn ($query) => $query->where('tenant_id', $tenantId)->where('institution_id', $institutionId))],
            'status' => ['nullable', Rule::in(['draft', 'published'])],
            'version_status' => ['nullable', Rule::in(['draft', 'in_review', 'approved', 'published', 'rejected'])],
            'owner_user_id' => ['nullable', 'integer', Rule::exists('users', 'id')->where(fn ($query) => $query->whereIn('id', DB::table('tenant_memberships')->select('user_id')->where('tenant_id', $tenantId)->where('status', 'active')))],
            'reviewer_user_id' => ['nullable', 'integer', Rule::exists('users', 'id')->where(fn ($query) => $query->whereIn('id', DB::table('tenant_memberships')->select('user_id')->where('tenant_id', $tenantId)->where('status', 'active')))],
            'outcome_id' => ['nullable', 'integer', Rule::exists('academic_learning_outcomes', 'id')->where(fn ($query) => $query->where('tenant_id', $tenantId)->where('institution_id', $institutionId))],
            'competency_id' => ['nullable', 'integer', Rule::exists('academic_competencies', 'id')->where('tenant_id', $tenantId)],
        ]);

        $query = $this->filteredCurricula($filters, $institutionId);
        $total = (clone $query)->count();
        $statusBreakdown = (clone $query)->selectRaw('status, COUNT(*) as total')->groupBy('status')->pluck('total', 'status')->map(fn ($count): int => (int) $count)->all();
        $curriculumIds = (clone $query)->select('id');
        $versionBreakdown = DB::table('academic_curriculum_versions')
            ->where('tenant_id', $tenantId)
            ->whereIn('curriculum_id', $curriculumIds)
            ->selectRaw('status, COUNT(*) as total')->groupBy('status')->pluck('total', 'status')->map(fn ($count): int => (int) $count)->all();
        $curricula = (clone $query)->with(['program:id,code,title', 'academicYear:id,code,name', 'latestVersion:id,tenant_id,curriculum_id,version_number,status,reviewer_user_id,updated_at'])->withCount('versions')
            ->orderByDesc('updated_at')->paginate(25)->withQueryString();

        return Inertia::render('workspace/academics/curriculum/analytics', [
            'metrics' => ['curricula' => $total, 'by_status' => $statusBreakdown, 'versions_by_status' => $versionBreakdown],
            'curricula' => $curricula,
            'filters' => $filters,
            'options' => [
                'programs' => AcademicProgram::query()->where('institution_id', $institutionId)->orderBy('title')->get(['id', 'code', 'title']),
                'years' => AcademicYear::query()->where('institution_id', $institutionId)->orderByDesc('starts_on')->get(['id', 'code', 'name']),
                'levels' => AcademicLevel::query()->where('institution_id', $institutionId)->orderBy('sequence')->get(['id', 'code', 'name']),
                'campuses' => Campus::query()->where('institution_id', $institutionId)->orderBy('name')->get(['id', 'code', 'name']),
                'owners' => User::query()->whereHas('memberships', fn ($membership) => $membership->where('tenant_id', $tenantId)->where('status', 'active'))->orderBy('name')->get(['id', 'name']),
                'reviewers' => User::query()->whereHas('memberships', fn ($membership) => $membership->where('tenant_id', $tenantId)->where('status', 'active')->whereHas('roles.permissions', fn ($permission) => $permission->where('key', 'academic.curriculum.review')))->orderBy('name')->get(['id', 'name']),
                'outcomes' => AcademicLearningOutcome::query()->where('institution_id', $institutionId)->orderBy('code')->get(['id', 'code', 'name']),
                'competencies' => AcademicCompetency::query()->whereHas('framework', fn ($framework) => $framework->where('institution_id', $institutionId))->orderBy('code')->get(['id', 'code', 'title']),
            ],
            'tenantSlug' => $context->tenant()->slug,
        ]);
    }

    public function quality(Request $request, Tenant $tenant, TenantContext $context, CurriculumQualityAnalyzer $analyzer): Response
    {
        $institutionId = (int) Institution::query()->value('id');
        $curricula = AcademicCurriculum::query()->where('institution_id', $institutionId)
            ->with('program:id,code,title')
            ->orderByDesc('updated_at')->paginate(20)->withQueryString();
        $curriculumIds = $curricula->getCollection()->modelKeys();
        $latestVersions = AcademicCurriculumVersion::query()->whereIn('curriculum_id', $curriculumIds)
            ->whereRaw('version_number = (select max(v2.version_number) from academic_curriculum_versions v2 where v2.tenant_id = academic_curriculum_versions.tenant_id and v2.curriculum_id = academic_curriculum_versions.curriculum_id)')
            ->get()->keyBy('curriculum_id');
        $latestVersions->load(['curriculum.framework.requiredOutcomes', 'nodes.outcomes', 'nodes.competencies', 'nodes.prerequisites']);
        $publishedCurriculumIds = AcademicCurriculumVersion::query()->whereIn('curriculum_id', $curriculumIds)->where('status', 'published')->distinct()->pluck('curriculum_id')->map(fn ($id): int => (int) $id)->all();
        $findings = [];
        foreach ($curricula as $curriculum) {
            $version = $latestVersions->get($curriculum->getKey());
            if (! $version instanceof AcademicCurriculumVersion) {
                $findings[] = ['curriculum_id' => (int) $curriculum->getKey(), 'curriculum_title' => $curriculum->title, 'version_number' => null, 'coverage_percent' => 0, 'completeness_percent' => 0, 'code' => 'missing_version', 'severity' => 'high', 'title' => 'Curriculum has no version', 'detail' => 'Create the first version and add its syllabus structure.', 'count' => 1];

                continue;
            }
            $analysis = $analyzer->analyze($version);
            foreach ($analysis['findings'] as $finding) {
                $findings[] = [
                    'curriculum_id' => (int) $curriculum->getKey(), 'curriculum_title' => $curriculum->title,
                    'version_number' => (int) $version->version_number, 'coverage_percent' => $analysis['coverage_percent'],
                    'completeness_percent' => $analysis['completeness_percent'], ...$finding,
                ];
            }
            $submittedAt = $version->getRawOriginal('submitted_at');
            if ($version->status === 'in_review' && is_string($submittedAt) && Carbon::parse($submittedAt)->isBefore(now()->subDays(7))) {
                $findings[] = ['curriculum_id' => (int) $curriculum->getKey(), 'curriculum_title' => $curriculum->title, 'version_number' => (int) $version->version_number, 'coverage_percent' => $analysis['coverage_percent'], 'completeness_percent' => $analysis['completeness_percent'], 'lesson_objective_coverage_percent' => $analysis['lesson_objective_coverage_percent'], 'code' => 'review_backlog', 'severity' => 'medium', 'title' => 'Independent review is overdue', 'detail' => 'The version has remained in review for more than seven days.', 'count' => 1];
            }
            if ($version->status === 'draft' && in_array((int) $curriculum->getKey(), $publishedCurriculumIds, true)) {
                $findings[] = ['curriculum_id' => (int) $curriculum->getKey(), 'curriculum_title' => $curriculum->title, 'version_number' => (int) $version->version_number, 'coverage_percent' => $analysis['coverage_percent'], 'completeness_percent' => $analysis['completeness_percent'], 'lesson_objective_coverage_percent' => $analysis['lesson_objective_coverage_percent'], 'code' => 'published_version_outdated', 'severity' => 'low', 'title' => 'Published version has a newer draft', 'detail' => 'Review or publish the latest draft when its changes are ready.', 'count' => 1];
            }
        }
        $tenantId = (int) $context->tenant()->getKey();

        return Inertia::render('workspace/academics/curriculum/quality', [
            'summary' => [
                'curricula' => AcademicCurriculum::query()->where('institution_id', $institutionId)->count(),
                'pending_approvals' => AcademicCurriculumVersion::query()->where('status', 'in_review')->count(),
                'published' => AcademicCurriculumVersion::query()->where('status', 'published')->count(),
                'drafts' => AcademicCurriculumVersion::query()->where('status', 'draft')->count(),
                'overdue_reviews' => AcademicCurriculumVersion::query()->where('status', 'in_review')->where('submitted_at', '<=', now()->subDays(7))->count(),
                'outdated_published' => AcademicCurriculum::query()->where('institution_id', $institutionId)->whereHas('versions', fn ($version) => $version->where('status', 'draft'))->whereHas('versions', fn ($version) => $version->where('status', 'published'))->count(),
                'findings_on_page' => count($findings),
            ],
            'findings' => $findings,
            'curricula' => $curricula,
            'tenantSlug' => $context->tenant()->slug,
            'tenantId' => $tenantId,
        ]);
    }

    public function matrix(Request $request, Tenant $tenant, int $curriculumId, TenantContext $context): Response
    {
        $institutionId = (int) Institution::query()->value('id');
        $curriculum = AcademicCurriculum::query()->where('institution_id', $institutionId)->findOrFail($curriculumId);
        $validated = $request->validate(['version' => ['nullable', 'integer', Rule::exists('academic_curriculum_versions', 'id')->where('tenant_id', $context->tenant()->getKey())->where('curriculum_id', $curriculum->getKey())]]);
        $version = AcademicCurriculumVersion::query()->where('curriculum_id', $curriculum->getKey())->findOrFail($validated['version'] ?? $curriculum->versions()->value('id'));
        $nodes = AcademicCurriculumNode::query()->where('curriculum_version_id', $version->getKey())->with('outcomes.learningOutcome:id,code,name')->orderBy('parent_id')->orderBy('sort_order')->orderBy('id')->paginate(16)->withQueryString();
        $required = $curriculum->framework_id === null ? collect() : AcademicCurriculumFrameworkOutcome::query()->where('framework_id', $curriculum->framework_id)->with('learningOutcome:id,code,name')->get();
        $mappedIds = DB::table('academic_curriculum_node_outcomes')->where('tenant_id', $context->tenant()->getKey())->where('curriculum_version_id', $version->getKey())->distinct()->pluck('learning_outcome_id');
        $outcomeIds = $required->pluck('learning_outcome_id')->merge($mappedIds)->unique()->values();
        $outcomes = AcademicLearningOutcome::query()->whereIn('id', $outcomeIds)->orderBy('code')->get(['id', 'code', 'name']);
        $mappingStrength = DB::table('academic_curriculum_node_outcomes')->where('tenant_id', $context->tenant()->getKey())
            ->where('curriculum_version_id', $version->getKey())->whereIn('node_id', $nodes->getCollection()->modelKeys())
            ->get(['node_id', 'learning_outcome_id', 'mapping_strength'])
            ->mapWithKeys(fn ($row): array => [$row->learning_outcome_id.':'.$row->node_id => $row->mapping_strength]);
        $requiredIds = $required->where('required', true)->pluck('learning_outcome_id')->map(fn ($id): int => (int) $id)->all();
        $rows = $outcomes->map(fn (AcademicLearningOutcome $outcome): array => [
            'id' => (int) $outcome->getKey(), 'code' => $outcome->code, 'name' => $outcome->name,
            'required' => in_array((int) $outcome->getKey(), $requiredIds, true),
            'mappings' => $nodes->getCollection()->mapWithKeys(fn (AcademicCurriculumNode $node): array => [
                (string) $node->getKey() => $mappingStrength->get($outcome->getKey().':'.$node->getKey()),
            ])->all(),
        ])->values();

        return Inertia::render('workspace/academics/curriculum/matrix', [
            'curriculum' => $curriculum->only(['id', 'code', 'title']), 'version' => $version->only(['id', 'version_number', 'status', 'lock_version']),
            'nodes' => $nodes, 'outcomes' => $rows, 'tenantSlug' => $context->tenant()->slug,
        ]);
    }

    public function pathway(Request $request, Tenant $tenant, int $curriculumId, TenantContext $context): Response
    {
        abort_unless($tenant->is($context->tenant()), 404);
        $tenantId = (int) $context->tenant()->getKey();
        $institutionId = (int) Institution::query()->where('tenant_id', $tenantId)->value('id');
        $curriculum = AcademicCurriculum::query()->where('institution_id', $institutionId)->findOrFail($curriculumId);
        $versions = AcademicCurriculumVersion::query()->where('curriculum_id', $curriculum->getKey())
            ->orderByDesc('version_number')->get(['id', 'tenant_id', 'curriculum_id', 'version_number', 'status']);
        abort_if($versions->isEmpty(), 404);

        $selection = $request->validate([
            'version' => ['nullable', 'integer', Rule::exists('academic_curriculum_versions', 'id')->where('tenant_id', $tenantId)->where('curriculum_id', $curriculum->getKey())],
        ]);
        $version = $versions->firstWhere('id', (int) ($selection['version'] ?? 0)) ?? $versions->first();

        $nodes = AcademicCurriculumNode::query()->where('curriculum_version_id', $version->getKey())
            ->orderBy('sort_order')->orderBy('id')
            ->get(['id', 'tenant_id', 'curriculum_version_id', 'parent_id', 'node_type', 'code', 'title', 'sort_order', 'is_required']);
        $edges = AcademicCurriculumPrerequisite::query()->where('curriculum_version_id', $version->getKey())
            ->with(['node:id,tenant_id,curriculum_version_id,code,title', 'prerequisiteNode:id,tenant_id,curriculum_version_id,code,title'])
            ->orderBy('node_id')->orderBy('id')->get()
            ->map(static fn (AcademicCurriculumPrerequisite $edge): array => [
                'id' => (int) $edge->getKey(), 'dependency_type' => $edge->dependency_type,
                'node_id' => (int) $edge->node_id, 'node_code' => $edge->node?->code, 'node_title' => $edge->node?->title,
                'prerequisite_node_id' => (int) $edge->prerequisite_node_id,
                'prerequisite_code' => $edge->prerequisiteNode?->code, 'prerequisite_title' => $edge->prerequisiteNode?->title,
            ])->values();

        return Inertia::render('workspace/academics/curriculum/pathway', [
            'curriculum' => $curriculum->only(['id', 'code', 'title']),
            'versions' => $versions,
            'version' => $version->only(['id', 'version_number', 'status']),
            'nodes' => $nodes,
            'edges' => $edges,
            'tenantSlug' => $context->tenant()->slug,
        ]);
    }

    public function compare(Request $request, Tenant $tenant, int $curriculumId, TenantContext $context, CurriculumVersionComparison $comparison): Response
    {
        $institutionId = (int) Institution::query()->value('id');
        $curriculum = AcademicCurriculum::query()->where('institution_id', $institutionId)->findOrFail($curriculumId);
        $versions = AcademicCurriculumVersion::query()->where('curriculum_id', $curriculum->getKey())->orderByDesc('version_number')->get(['id', 'tenant_id', 'curriculum_id', 'version_number', 'status']);
        abort_if($versions->count() < 2, 404, 'At least two curriculum versions are required for comparison.');
        $rules = ['from_version' => ['nullable', 'integer', Rule::exists('academic_curriculum_versions', 'id')->where('tenant_id', $context->tenant()->getKey())->where('curriculum_id', $curriculum->getKey())], 'to_version' => ['nullable', 'integer', Rule::exists('academic_curriculum_versions', 'id')->where('tenant_id', $context->tenant()->getKey())->where('curriculum_id', $curriculum->getKey())]];
        $selected = $request->validate($rules);
        $to = $versions->firstWhere('id', (int) ($selected['to_version'] ?? 0)) ?? $versions->first();
        $from = $versions->firstWhere('id', (int) ($selected['from_version'] ?? 0)) ?? $versions->first(fn ($version): bool => (int) $version->getKey() !== (int) $to->getKey());
        abort_if($from === null || $to === null || (int) $from->getKey() === (int) $to->getKey(), 422, 'Choose two different versions of the same curriculum.');

        return Inertia::render('workspace/academics/curriculum/compare', [
            'curriculum' => $curriculum->only(['id', 'code', 'title']), 'versions' => $versions,
            'comparison' => $comparison->compare($from, $to), 'filters' => ['from_version' => (int) $from->getKey(), 'to_version' => (int) $to->getKey()],
            'tenantSlug' => $context->tenant()->slug,
        ]);
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return Builder<AcademicCurriculum>
     */
    private function filteredCurricula(array $filters, int $institutionId): Builder
    {
        $query = AcademicCurriculum::query()->where('institution_id', $institutionId);
        if (isset($filters['academic_program_id'])) {
            $query->where('academic_program_id', $filters['academic_program_id']);
        }
        if (isset($filters['academic_year_id'])) {
            $query->where('academic_year_id', $filters['academic_year_id']);
        }
        if (isset($filters['academic_level_id'])) {
            $query->whereHas('program', fn ($program) => $program->where('academic_level_id', $filters['academic_level_id']));
        }
        if (isset($filters['campus_id'])) {
            $query->whereHas('program.campuses', fn ($campus) => $campus->where('campuses.id', $filters['campus_id']));
        }
        if (isset($filters['status'])) {
            $query->where('status', $filters['status']);
        }
        if (isset($filters['version_status'])) {
            $query->whereHas('versions', fn ($version) => $version->where('status', $filters['version_status']));
        }
        if (isset($filters['owner_user_id'])) {
            $query->where('created_by', $filters['owner_user_id']);
        }
        if (isset($filters['reviewer_user_id'])) {
            $query->whereHas('versions', fn ($version) => $version->where('reviewer_user_id', $filters['reviewer_user_id']));
        }
        if (isset($filters['outcome_id'])) {
            $query->whereHas('versions.nodes.outcomes', fn ($mapping) => $mapping->where('learning_outcome_id', $filters['outcome_id']));
        }
        if (isset($filters['competency_id'])) {
            $query->whereHas('versions.nodes.competencies', fn ($mapping) => $mapping->where('competency_id', $filters['competency_id']));
        }

        return $query;
    }
}
