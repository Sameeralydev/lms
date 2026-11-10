<?php

namespace App\Http\Controllers\Workspace;

use App\Domain\Academic\Curriculum\CurriculumGovernanceService;
use App\Domain\Admissions\Documents\AdmissionDocumentScanner;
use App\Domain\Tenancy\TenantContext;
use App\Http\Controllers\Controller;
use App\Models\AcademicCompetency;
use App\Models\AcademicCompetencyFramework;
use App\Models\AcademicCurriculumFramework;
use App\Models\AcademicCurriculumFrameworkAdministrator;
use App\Models\AcademicCurriculumGovernancePolicy;
use App\Models\AcademicCurriculumNode;
use App\Models\AcademicCurriculumResource;
use App\Models\AcademicCurriculumVersion;
use App\Models\AcademicDocument;
use App\Models\AcademicLearningOutcome;
use App\Models\AcademicProgram;
use App\Models\AcademicProgramCompetencyRequirement;
use App\Models\Campus;
use App\Models\Institution;
use App\Models\Tenant;
use App\Models\TenantMembership;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class CurriculumGovernanceController extends Controller
{
    public function index(TenantContext $context): Response
    {
        $institution = Institution::query()->firstOrFail();

        return Inertia::render('workspace/academics/curriculum/governance', [
            'tenantSlug' => $context->tenant()->slug,
            'frameworks' => AcademicCurriculumFramework::query()->with(['administrators.user:id,name', 'owner:id,name', 'institution:id,display_name'])->orderBy('title')->get(),
            'competencyFrameworks' => AcademicCompetencyFramework::query()->with(['competencies', 'reviews'])->orderBy('code')->get(),
            'competencyOptions' => AcademicCompetencyFramework::query()->where('institution_id', $institution->getKey())->where('status', 'published')->with('competencies:id,framework_id,code,title')->orderBy('code')->get(['id', 'code', 'title'])->flatMap(fn (AcademicCompetencyFramework $framework) => $framework->competencies->map(fn (AcademicCompetency $competency): array => ['id' => (int) $competency->getKey(), 'code' => $competency->code, 'title' => $competency->title, 'framework' => $framework->code]))->values(),
            'programRequirements' => AcademicProgramCompetencyRequirement::query()->with(['program:id,code,title', 'competency:id,framework_id,code,title'])->where('institution_id', $institution->getKey())->where('status', 'active')->orderBy('academic_program_id')->get(),
            'members' => TenantMembership::query()->with('user:id,name')->where('institution_id', $institution->getKey())->where('status', 'active')->orderBy('id')->get()
                ->filter(fn (TenantMembership $membership): bool => $membership->hasPermission('academic.curriculum.manage'))
                ->map(fn (TenantMembership $membership): array => ['id' => (int) $membership->user_id, 'name' => $membership->user->name ?? 'Unavailable member', 'campus_id' => $membership->campus_id]),
            'reviewers' => TenantMembership::query()->with('user:id,name')->where('institution_id', $institution->getKey())->where('status', 'active')->orderBy('id')->get()
                ->filter(fn (TenantMembership $membership): bool => $membership->hasPermission('academic.curriculum.review'))
                ->map(fn (TenantMembership $membership): array => ['id' => (int) $membership->user_id, 'name' => $membership->user->name ?? 'Unavailable member']),
            'campuses' => Campus::query()->where('institution_id', $institution->getKey())->where('status', 'active')->orderBy('name')->get(['id', 'code', 'name']),
            'programs' => AcademicProgram::query()->where('institution_id', $institution->getKey())->where('status', 'active')->orderBy('code')->get(['id', 'code', 'title']),
            'policies' => AcademicCurriculumGovernancePolicy::query()->with(['framework:id,code,title', 'competencyFramework:id,code,title', 'checklistTemplate.items'])->orderByDesc('created_at')->limit(40)->get(),
            'assignedReviews' => DB::table('academic_competency_framework_reviews')->join('academic_competency_frameworks', 'academic_competency_frameworks.id', '=', 'academic_competency_framework_reviews.framework_id')
                ->where('academic_competency_framework_reviews.tenant_id', $context->tenant()->getKey())->where('academic_competency_framework_reviews.reviewer_user_id', auth()->id())
                ->where('academic_competency_framework_reviews.decision', 'assigned')->select('academic_competency_frameworks.id as framework_id', 'academic_competency_frameworks.code', 'academic_competency_frameworks.title', 'academic_competency_framework_reviews.id as review_id', 'academic_competency_framework_reviews.checklist_snapshot')->get()
                ->map(fn ($review): array => [...(array) $review, 'checklist_snapshot' => json_decode((string) $review->checklist_snapshot, true) ?: []]),
        ]);
    }

    public function assignAdministrator(Request $request, Tenant $tenant, int $frameworkId, TenantContext $context, CurriculumGovernanceService $governance): RedirectResponse
    {
        $institutionId = (int) Institution::query()->value('id');
        $data = $request->validate([
            'user_id' => ['required', 'integer', Rule::exists('tenant_memberships', 'user_id')->where(fn ($q) => $q->where('tenant_id', $context->tenant()->getKey())->where('institution_id', $institutionId)->where('status', 'active'))],
            'responsibility' => ['required', Rule::in(['administrator', 'coordinator', 'editor'])],
            'campus_id' => ['nullable', 'integer', Rule::exists('campuses', 'id')->where(fn ($q) => $q->where('tenant_id', $context->tenant()->getKey())->where('institution_id', $institutionId))],
            'academic_program_id' => ['nullable', 'integer', Rule::exists('academic_programs', 'id')->where(fn ($q) => $q->where('tenant_id', $context->tenant()->getKey())->where('institution_id', $institutionId))],
            'effective_from' => ['nullable', 'date'], 'effective_until' => ['nullable', 'date', 'after_or_equal:effective_from'], 'reason' => ['required', 'string', 'min:8', 'max:1000'],
        ]);
        $framework = AcademicCurriculumFramework::query()->findOrFail($frameworkId);
        $governance->assignAdministrator($framework, $data, $request->user());

        return back()->with('status', 'Framework administration assignment recorded. Existing permissions remain unchanged.');
    }

    public function revokeAdministrator(Request $request, Tenant $tenant, int $assignmentId, CurriculumGovernanceService $governance): RedirectResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'min:8', 'max:1000']]);
        $assignment = AcademicCurriculumFrameworkAdministrator::query()->findOrFail($assignmentId);
        $governance->revokeAdministrator($assignment, $data['reason'], $request->user());

        return back()->with('status', 'Framework assignment revoked with history retained.');
    }

    public function designateOwner(Request $request, Tenant $tenant, int $frameworkId, CurriculumGovernanceService $governance): RedirectResponse
    {
        $data = $request->validate(['user_id' => ['required', 'integer'], 'reason' => ['required', 'string', 'min:8', 'max:1000']]);
        $framework = AcademicCurriculumFramework::query()->findOrFail($frameworkId);
        $governance->designateOwner($framework, (int) $data['user_id'], $data['reason'], $request->user());

        return back()->with('status', 'Framework owner designation changed and audited.');
    }

    public function publishPolicy(Request $request, Tenant $tenant, TenantContext $context, CurriculumGovernanceService $governance): RedirectResponse
    {
        $institutionId = (int) Institution::query()->value('id');
        $data = $request->validate([
            'scope' => ['required', Rule::in(['curriculum', 'competency'])],
            'framework_id' => ['nullable', 'integer', Rule::exists('academic_curriculum_frameworks', 'id')->where(fn ($q) => $q->where('tenant_id', $context->tenant()->getKey())->where('institution_id', $institutionId))],
            'competency_framework_id' => ['nullable', 'integer', Rule::exists('academic_competency_frameworks', 'id')->where(fn ($q) => $q->where('tenant_id', $context->tenant()->getKey())->where('institution_id', $institutionId))],
            'reviewer_eligibility' => ['required', Rule::in(['institution_wide', 'framework_assignees', 'program_scope'])],
            'minimum_reviewers' => ['required', 'integer', 'min:1', 'max:3'],
            'require_independent_reviewer' => ['required', 'boolean'], 'require_separate_publisher' => ['required', 'boolean'],
            'require_changes_note' => ['required', 'boolean'], 'require_rejection_note' => ['required', 'boolean'],
            'require_checklist_completion' => ['required', 'boolean'], 'allow_resubmission' => ['required', 'boolean'],
            'effective_from' => ['required', 'date'], 'effective_until' => ['nullable', 'date', 'after_or_equal:effective_from'],
            'checklist_code' => ['nullable', 'string', 'max:48', 'regex:/^[A-Za-z0-9._-]+$/'],
            'checklist_title' => ['nullable', 'string', 'max:160'], 'checklist_items' => ['nullable', 'array', 'max:80'],
            'checklist_items.*.category' => ['required_with:checklist_items', 'string', 'max:80'],
            'checklist_items.*.prompt' => ['required_with:checklist_items', 'string', 'max:500'],
            'checklist_items.*.is_required' => ['sometimes', 'boolean'], 'checklist_items.*.requires_note' => ['sometimes', 'boolean'],
        ]);
        if (! empty($data['checklist_items']) && (empty($data['checklist_code']) || empty($data['checklist_title']))) {
            return back()->withErrors(['checklist_title' => 'A checklist code and title are required when checklist items are supplied.'])->withInput();
        }
        $governance->publishChecklistAndPolicy($data, $request->user());

        return back()->with('status', 'A versioned curriculum governance policy was published.');
    }

    public function storeCompetencyFramework(Request $request, Tenant $tenant, CurriculumGovernanceService $governance): RedirectResponse
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'max:48', 'regex:/^[A-Za-z0-9._-]+$/', Rule::unique('academic_competency_frameworks', 'code')->where('tenant_id', app(TenantContext::class)->tenant()->getKey())],
            'title' => ['required', 'string', 'max:180'], 'description' => ['nullable', 'string', 'max:5000'],
            'effective_from' => ['nullable', 'date'], 'effective_until' => ['nullable', 'date', 'after_or_equal:effective_from'],
            'is_shared_template' => ['sometimes', 'boolean'], 'owner_user_id' => ['nullable', 'integer'],
        ]);
        $governance->createCompetencyFramework($data, $request->user());

        return back()->with('status', 'Competency framework draft created.');
    }

    public function storeCompetency(Request $request, Tenant $tenant, int $frameworkId, CurriculumGovernanceService $governance): RedirectResponse
    {
        $data = $request->validate([
            'parent_id' => ['nullable', 'integer'], 'code' => ['required', 'string', 'max:64', 'regex:/^[A-Za-z0-9._-]+$/'],
            'title' => ['required', 'string', 'max:180'], 'descriptor' => ['required', 'string', 'max:5000'],
            'mastery_criteria' => ['nullable', 'string', 'max:3000'], 'level_label' => ['nullable', 'string', 'max:80'],
            'category' => ['nullable', 'string', 'max:80'], 'mastery_levels' => ['nullable', 'array', 'max:12'],
            'mastery_levels.*' => ['string', 'max:160'], 'evidence_requirements' => ['nullable', 'array', 'max:20'],
            'evidence_requirements.*' => ['string', 'max:500'], 'sort_order' => ['sometimes', 'integer', 'min:0', 'max:100000'],
        ]);
        $governance->addCompetency($frameworkId, $data, $request->user());

        return back()->with('status', 'Competency added to the draft framework.');
    }

    public function submitCompetency(Request $request, Tenant $tenant, int $frameworkId, CurriculumGovernanceService $governance): RedirectResponse
    {
        $data = $request->validate(['reviewer_user_ids' => ['required', 'array', 'min:1', 'max:3'], 'reviewer_user_ids.*' => ['integer']]);
        $governance->submitCompetencyFramework(AcademicCompetencyFramework::query()->findOrFail($frameworkId), $data['reviewer_user_ids'], $request->user());

        return back()->with('status', 'Competency framework submitted for independent review.');
    }

    public function decideCompetency(Request $request, Tenant $tenant, int $frameworkId, CurriculumGovernanceService $governance): RedirectResponse
    {
        $data = $request->validate([
            'decision' => ['required', Rule::in(['approve', 'request_changes', 'reject'])], 'review_note' => ['nullable', 'string', 'max:3000'],
            'checklist_responses' => ['nullable', 'array', 'max:80'], 'checklist_responses.*.complete' => ['sometimes', 'boolean'],
            'checklist_responses.*.note' => ['nullable', 'string', 'max:2000'], 'checklist_responses.*.evidence_reference' => ['nullable', 'string', 'max:500'],
        ]);
        $governance->decideCompetencyFramework(AcademicCompetencyFramework::query()->findOrFail($frameworkId), $data['decision'], $data['review_note'] ?? null, $data['checklist_responses'] ?? [], $request->user());

        return back()->with('status', 'Competency framework review decision recorded.');
    }

    public function publishCompetency(Tenant $tenant, int $frameworkId, Request $request, CurriculumGovernanceService $governance): RedirectResponse
    {
        $governance->publishCompetencyFramework(AcademicCompetencyFramework::query()->findOrFail($frameworkId), $request->user());

        return back()->with('status', 'Approved competency framework published.');
    }

    public function reviseCompetency(Request $request, Tenant $tenant, int $frameworkId, CurriculumGovernanceService $governance): RedirectResponse
    {
        $data = $request->validate(['revision_note' => ['required', 'string', 'min:8', 'max:1000']]);
        $revision = $governance->createCompetencyRevision(AcademicCompetencyFramework::query()->findOrFail($frameworkId), $data['revision_note'], $request->user());

        return back()->with('status', "Competency revision {$revision->version_number} created.");
    }

    public function mapProgramCompetency(Request $request, Tenant $tenant, int $programId, int $competencyId, CurriculumGovernanceService $governance): RedirectResponse
    {
        $data = $request->validate(['expected_level' => ['nullable', 'string', 'max:80'], 'evidence_requirements' => ['nullable', 'array', 'max:20'], 'evidence_requirements.*' => ['string', 'max:500'], 'is_required' => ['required', 'boolean']]);
        $governance->mapProgramRequirement($programId, $competencyId, $data, $request->user());

        return back()->with('status', 'Program competency requirement saved.');
    }

    public function resources(Request $request, TenantContext $context): Response
    {
        $institution = Institution::query()->firstOrFail();
        $query = AcademicCurriculumResource::query()->with(['version:id,curriculum_id,version_number', 'node:id,title', 'document:id,title,code,status'])->where('institution_id', $institution->getKey());
        if ($request->filled('q')) {
            $term = trim((string) $request->query('q'));
            $query->where(fn ($q) => $q->where('title', 'like', '%'.$term.'%')->orWhere('code', 'like', '%'.$term.'%')->orWhere('author_name', 'like', '%'.$term.'%'));
        }
        if ($request->filled('status') && in_array($request->query('status'), ['draft', 'in_review', 'approved', 'rejected', 'archived'], true)) {
            $query->where('status', $request->query('status'));
        }

        return Inertia::render('workspace/academics/curriculum/resources', [
            'tenantSlug' => $context->tenant()->slug, 'resources' => $query->orderByDesc('created_at')->paginate(20)->withQueryString(),
            'curriculumVersions' => AcademicCurriculumVersion::query()->with('curriculum:id,code,title')->orderByDesc('created_at')->limit(100)->get(['id', 'curriculum_id', 'version_number', 'status']),
            'nodes' => AcademicCurriculumNode::query()->orderBy('title')->limit(200)->get(['id', 'curriculum_version_id', 'title']),
            'outcomes' => AcademicLearningOutcome::query()->where('institution_id', $institution->getKey())->where('status', 'active')->orderBy('code')->get(['id', 'code', 'name']),
            'documents' => AcademicDocument::query()->where('institution_id', $institution->getKey())->where('status', 'approved')->orderBy('title')->get(['id', 'code', 'title']),
            'filters' => ['q' => (string) $request->query('q', ''), 'status' => (string) $request->query('status', '')],
        ]);
    }

    public function storeResource(Request $request, Tenant $tenant, TenantContext $context, CurriculumGovernanceService $governance): RedirectResponse
    {
        $institutionId = (int) Institution::query()->value('id');
        $data = $request->validate([
            'code' => ['required', 'string', 'max:48', 'regex:/^[A-Za-z0-9._-]+$/'], 'title' => ['required', 'string', 'max:180'],
            'description' => ['nullable', 'string', 'max:5000'], 'resource_type' => ['required', Rule::in(['external_link', 'private_document', 'learning_reference'])],
            'source_url' => ['nullable', 'url', 'max:2048'], 'academic_document_id' => ['nullable', 'integer', Rule::exists('academic_documents', 'id')->where(fn ($q) => $q->where('tenant_id', $context->tenant()->getKey())->where('institution_id', $institutionId)->where('status', 'approved'))],
            'curriculum_version_id' => ['nullable', 'integer', Rule::exists('academic_curriculum_versions', 'id')->where('tenant_id', $context->tenant()->getKey())],
            'node_id' => ['nullable', 'integer', Rule::exists('academic_curriculum_nodes', 'id')->where('tenant_id', $context->tenant()->getKey())],
            'learning_outcome_id' => ['nullable', 'integer', Rule::exists('academic_learning_outcomes', 'id')->where(fn ($q) => $q->where('tenant_id', $context->tenant()->getKey())->where('institution_id', $institutionId))],
            'author_name' => ['nullable', 'string', 'max:180'], 'publisher_name' => ['nullable', 'string', 'max:180'],
            'license_code' => ['nullable', 'string', 'max:80'], 'attribution' => ['nullable', 'string', 'max:3000'], 'language' => ['nullable', 'string', 'max:35'],
            'accessibility_notes' => ['nullable', 'string', 'max:3000'], 'captions_available' => ['required', 'boolean'], 'transcript_available' => ['required', 'boolean'],
            'alternate_formats' => ['nullable', 'array', 'max:10'], 'alternate_formats.*' => ['string', 'max:80'],
            'estimated_minutes' => ['nullable', 'integer', 'min:1', 'max:10000'], 'is_required' => ['required', 'boolean'],
            'replace_resource_id' => ['nullable', 'integer', Rule::exists('academic_curriculum_resources', 'id')->where(fn ($q) => $q->where('tenant_id', $context->tenant()->getKey())->where('institution_id', $institutionId))],
        ]);
        $governance->createResource($data, $request->user());

        return back()->with('status', 'A new draft resource version was added to the institution library.');
    }

    public function transitionResource(Request $request, Tenant $tenant, int $resourceId, TenantContext $context, CurriculumGovernanceService $governance): RedirectResponse
    {
        $data = $request->validate(['status' => ['required', Rule::in(['in_review', 'approved', 'rejected', 'archived'])]]);
        if (in_array($data['status'], ['approved', 'rejected'], true)) {
            abort_unless($context->membership()->hasPermission('academic.curriculum.review'), 403);
        }
        $governance->transitionResource(AcademicCurriculumResource::query()->findOrFail($resourceId), $data['status'], $request->user());

        return back()->with('status', "Resource status updated to {$data['status']}.");
    }

    public function downloadResource(Tenant $tenant, int $resourceId, CurriculumGovernanceService $governance, AdmissionDocumentScanner $scanner, Request $request): mixed
    {
        $resource = AcademicCurriculumResource::query()->findOrFail($resourceId);
        $path = $governance->verifyResourceDownload($resource, $scanner, $request->user());

        return Storage::disk('local')->download($path, 'curriculum-resource-'.$resource->code.'.'.pathinfo($path, PATHINFO_EXTENSION), [
            'X-Content-Type-Options' => 'nosniff', 'Cache-Control' => 'private, no-store', 'Content-Security-Policy' => "default-src 'none'; sandbox",
        ]);
    }
}
