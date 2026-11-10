<?php

namespace App\Http\Controllers\Workspace;

use App\Domain\Academic\Curriculum\CurriculumGovernanceService;
use App\Domain\Academic\Curriculum\CurriculumImportService;
use App\Domain\Academic\Curriculum\CurriculumWorkflow;
use App\Domain\Audit\AuditRecorder;
use App\Domain\Tenancy\TenantContext;
use App\Jobs\ProcessAcademicCurriculumImportBatch;
use App\Models\AcademicCompetencyFramework;
use App\Models\AcademicCourse;
use App\Models\AcademicCurriculum;
use App\Models\AcademicCurriculumCompletionRule;
use App\Models\AcademicCurriculumFramework;
use App\Models\AcademicCurriculumImportBatch;
use App\Models\AcademicCurriculumNode;
use App\Models\AcademicCurriculumNodeOutcome;
use App\Models\AcademicCurriculumReview;
use App\Models\AcademicCurriculumVersion;
use App\Models\AcademicLearningOutcome;
use App\Models\AcademicProgram;
use App\Models\AcademicYear;
use App\Models\AuditEvent;
use App\Models\Institution;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

final class CurriculumController
{
    public function index(Request $request, Tenant $tenant, TenantContext $context, CurriculumWorkflow $workflow): Response
    {
        $institution = Institution::query()->firstOrFail();
        $query = AcademicCurriculum::query()->where('institution_id', $institution->getKey())->with(['program:id,title,code', 'framework:id,title,code', 'academicYear:id,name', 'versions:id,curriculum_id,version_number,status,updated_at']);
        if ($request->filled('q')) {
            $term = '%'.str_replace(['%', '_'], ['\\%', '\\_'], trim((string) $request->query('q'))).'%';
            $query->where(fn ($builder) => $builder->where('title', 'like', $term)->orWhere('code', 'like', $term));
        }
        if ($request->filled('status')) {
            $query->where('status', $request->query('status'));
        }

        return Inertia::render('workspace/academics/curriculum/index', [
            'metrics' => $workflow->dashboardMetrics(),
            'curricula' => $query->orderByDesc('updated_at')->paginate(15)->withQueryString(),
            'frameworks' => AcademicCurriculumFramework::query()->where('institution_id', $institution->getKey())->orderBy('title')->get(['id', 'code', 'title', 'status', 'lock_version', 'education_stage', 'description', 'effective_from', 'effective_until', 'required_learning_areas', 'terminology']),
            'learningOutcomes' => AcademicLearningOutcome::query()->where('institution_id', $institution->getKey())->where('status', 'active')->orderBy('code')->get(['id', 'code', 'name']),
            'frameworkRequirements' => DB::table('academic_curriculum_framework_outcomes as requirement')
                ->join('academic_curriculum_frameworks as framework', function ($join): void {
                    $join->on('framework.id', '=', 'requirement.framework_id')->on('framework.tenant_id', '=', 'requirement.tenant_id');
                })
                ->join('academic_learning_outcomes as outcome', function ($join): void {
                    $join->on('outcome.id', '=', 'requirement.learning_outcome_id')->on('outcome.tenant_id', '=', 'requirement.tenant_id');
                })
                ->where('framework.tenant_id', $context->tenant()->getKey())
                ->where('framework.institution_id', $institution->getKey())
                ->orderBy('framework.title')->orderBy('outcome.code')
                ->get(['requirement.framework_id', 'requirement.learning_outcome_id', 'requirement.required', 'outcome.code', 'outcome.name']),
            'sharedTemplates' => DB::table('academic_curricula as c')
                ->join('academic_programs as p', function ($join): void {
                    $join->on('p.id', '=', 'c.academic_program_id')->on('p.tenant_id', '=', 'c.tenant_id');
                })
                ->where('c.sharing_status', 'shared')
                ->whereExists(fn ($query) => $query->selectRaw('1')->from('academic_curriculum_versions as v')->whereColumn('v.curriculum_id', 'c.id')->whereColumn('v.tenant_id', 'c.tenant_id')->where('v.status', 'published'))
                ->select(['c.id', 'c.tenant_id', 'c.code', 'c.title', 'c.description', 'p.code as program_code', 'p.title as program_title'])
                ->addSelect(['published_version' => DB::table('academic_curriculum_versions as v')->select('v.version_number')->whereColumn('v.curriculum_id', 'c.id')->whereColumn('v.tenant_id', 'c.tenant_id')->where('v.status', 'published')->orderByDesc('v.version_number')->limit(1)])
                ->orderBy('c.title')->limit(30)->get(),
            'programs' => AcademicProgram::query()->where('institution_id', $institution->getKey())->where('status', 'active')->orderBy('title')->get(['id', 'code', 'title']),
            'academicYears' => AcademicYear::query()->where('institution_id', $institution->getKey())->orderByDesc('starts_on')->get(['id', 'name', 'code']),
            'filters' => ['q' => $request->query('q', ''), 'status' => $request->query('status', '')],
            'tenantSlug' => $context->tenant()->slug,
        ]);
    }

    public function show(Request $request, Tenant $tenant, int $curriculumId, TenantContext $context): Response
    {
        $curriculum = AcademicCurriculum::query()->with(['program:id,title,code,credit_bearing,required_credits', 'framework:id,title,code', 'academicYear:id,name,code', 'versions.reviews'])->where('institution_id', Institution::query()->value('id'))->findOrFail($curriculumId);
        $versionId = (int) ($request->query('version') ?: $curriculum->versions->first()?->getKey());
        $version = AcademicCurriculumVersion::query()->where('curriculum_id', $curriculum->getKey())->findOrFail($versionId);
        $nodes = AcademicCurriculumNode::query()->where('curriculum_version_id', $version->getKey())->with(['course:id,code,title', 'courseVersion:id,academic_course_id,version_number,title,total_credits,contact_hours', 'academicPeriod:id,name,code', 'outcomes.learningOutcome:id,code,name', 'prerequisites', 'competencies.competency:id,framework_id,code,title'])->orderBy('parent_id')->orderBy('sort_order')->orderBy('id')->get();
        $completionRules = AcademicCurriculumCompletionRule::query()->where('curriculum_version_id', $version->getKey())->with('academicPeriod:id,name,code')->orderBy('sort_order')->orderBy('id')->get();
        $outcomeIds = AcademicCurriculumNodeOutcome::query()->where('curriculum_version_id', $version->getKey())->pluck('learning_outcome_id')->unique();
        $validation = app(CurriculumWorkflow::class)->validationSummary($version);
        $assignedReview = AcademicCurriculumReview::query()->where('curriculum_version_id', $version->getKey())->where('review_round', $version->review_round)
            ->where('reviewer_user_id', $request->user()?->getKey())->where('decision', 'assigned')->first();
        $tenantId = $context->tenant()->getKey();
        $competencyFrameworks = AcademicCompetencyFramework::query()->where('institution_id', $curriculum->institution_id)->with('competencies:id,framework_id,code,title,descriptor,status')->orderBy('title')->get(['id', 'code', 'title', 'status']);

        return Inertia::render('workspace/academics/curriculum/show', [
            'curriculum' => $curriculum,
            'version' => $version,
            'nodes' => $nodes,
            'completionRules' => $completionRules,
            'outcomes' => AcademicLearningOutcome::query()->where('institution_id', $curriculum->institution_id)->where('status', 'active')->orderBy('code')->get(['id', 'code', 'name', 'description']),
            'mappedOutcomeIds' => $outcomeIds,
            'competencyFrameworks' => $competencyFrameworks,
            'competencies' => $competencyFrameworks->flatMap(fn (AcademicCompetencyFramework $framework) => $framework->competencies->where('status', 'active')->map(fn ($competency): array => ['id' => $competency->getKey(), 'code' => $competency->code, 'title' => $competency->title, 'framework_title' => $framework->title]))->values(),
            'periods' => $curriculum->academic_year_id ? $curriculum->academicYear->periods()->orderBy('sequence')->get(['id', 'name', 'code', 'starts_on', 'ends_on']) : [],
            'courses' => AcademicCourse::query()->where('institution_id', $curriculum->institution_id)->where('status', 'active')->with(['versions' => fn ($query) => $query->where('status', 'published')->orderByDesc('version_number')->select(['id', 'academic_course_id', 'version_number', 'title', 'total_credits', 'contact_hours'])])->orderBy('title')->get(['id', 'code', 'title']),
            'reviewers' => User::query()->whereHas('memberships', fn ($membership) => $membership->where('tenant_id', $tenantId)->where('status', 'active')->whereNull('campus_id')->whereHas('roles.permissions', fn ($permission) => $permission->where('key', 'academic.curriculum.review')))->orderBy('name')->get(['id', 'name', 'email']),
            'validation' => $validation,
            'assignedReview' => $assignedReview ? ['id' => (int) $assignedReview->getKey(), 'checklist_snapshot' => $assignedReview->checklist_snapshot ?? [], 'policy_version_id' => $assignedReview->policy_version_id] : null,
            'competencyGaps' => app(CurriculumGovernanceService::class)->competencyGaps($version),
        ]);
    }

    public function printable(Tenant $tenant, int $curriculumId): Response
    {
        $curriculum = AcademicCurriculum::query()
            ->with(['program:id,title,code,credit_bearing,required_credits', 'framework:id,title,code', 'academicYear:id,name,code'])
            ->where('institution_id', Institution::query()->value('id'))
            ->findOrFail($curriculumId);
        $version = AcademicCurriculumVersion::query()
            ->where('curriculum_id', $curriculum->getKey())
            ->where('status', 'published')
            ->orderByDesc('version_number')
            ->firstOrFail();
        $nodes = AcademicCurriculumNode::query()
            ->where('curriculum_version_id', $version->getKey())
            ->with(['outcomes.learningOutcome:id,code,name', 'prerequisites.prerequisiteNode:id,title,code'])
            ->orderBy('parent_id')
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();
        $completionRules = AcademicCurriculumCompletionRule::query()->where('curriculum_version_id', $version->getKey())->with('academicPeriod:id,code,name')->orderBy('sort_order')->orderBy('id')->get();

        return Inertia::render('workspace/academics/curriculum/print', [
            'curriculum' => $curriculum,
            'version' => $version,
            'nodes' => $nodes,
            'completionRules' => $completionRules,
            'backUrl' => route('tenant.academics.curriculum.show', ['tenant' => $tenant, 'curriculumId' => $curriculum->getKey()]),
        ]);
    }

    public function frameworkHistory(Tenant $tenant, int $frameworkId, TenantContext $context): Response
    {
        abort_unless($tenant->is($context->tenant()), 404);
        $institutionId = Institution::query()->where('tenant_id', $context->tenant()->getKey())->value('id');
        $framework = AcademicCurriculumFramework::query()->where('institution_id', $institutionId)->findOrFail($frameworkId);
        $history = AuditEvent::query()->with('actor:id,name')
            ->where('tenant_id', $context->tenant()->getKey())
            ->where('subject_type', AcademicCurriculumFramework::class)
            ->where('subject_id', (string) $framework->getKey())
            ->orderByDesc('occurred_at')->orderByDesc('id')
            ->paginate(30, ['id', 'tenant_id', 'actor_user_id', 'event_type', 'occurred_at']);

        return Inertia::render('workspace/academics/curriculum/framework-history', [
            'framework' => $framework->only(['id', 'code', 'title', 'status']),
            'history' => $history,
            'backUrl' => route('tenant.academics.curriculum.index', ['tenant' => $tenant]),
        ]);
    }

    public function reviewQueue(Request $request, Tenant $tenant, TenantContext $context): Response
    {
        abort_unless($tenant->is($context->tenant()), 404);
        abort_unless($request->user() instanceof User, 401);

        $institutionId = Institution::query()->where('tenant_id', $context->tenant()->getKey())->value('id');
        $assignments = AcademicCurriculumReview::query()
            ->where('reviewer_user_id', $request->user()->getKey())
            ->where('decision', 'assigned')
            ->whereHas('version', fn ($version) => $version->where('status', 'in_review')->whereHas('curriculum', fn ($curriculum) => $curriculum->where('institution_id', $institutionId)))
            ->with([
                'version:id,tenant_id,curriculum_id,version_number,status,submitted_at',
                'version.curriculum:id,tenant_id,institution_id,academic_program_id,code,title',
                'version.curriculum.program:id,code,title',
            ])
            ->orderBy('assigned_at')
            ->orderBy('id')
            ->paginate(20)
            ->withQueryString();

        return Inertia::render('workspace/academics/curriculum/reviews', [
            'tenantSlug' => $context->tenant()->slug,
            'assignments' => $assignments,
        ]);
    }

    public function reviewHistory(Tenant $tenant, int $curriculumId, TenantContext $context): Response
    {
        abort_unless($tenant->is($context->tenant()), 404);
        $institutionId = Institution::query()->where('tenant_id', $context->tenant()->getKey())->value('id');
        $curriculum = AcademicCurriculum::query()->where('institution_id', $institutionId)->findOrFail($curriculumId);
        $history = AcademicCurriculumReview::query()
            ->whereHas('version', fn ($version) => $version->where('curriculum_id', $curriculum->getKey()))
            ->with([
                'version:id,tenant_id,curriculum_id,version_number,status',
                'reviewer:id,name',
                'decidedBy:id,name',
            ])
            ->orderByDesc('id')
            ->paginate(30)
            ->withQueryString();

        return Inertia::render('workspace/academics/curriculum/review-history', [
            'tenantSlug' => $context->tenant()->slug,
            'curriculum' => $curriculum->only(['id', 'code', 'title']),
            'history' => $history,
        ]);
    }

    public function importCenter(Request $request, Tenant $tenant, int $curriculumId, int $versionId, TenantContext $context): Response
    {
        [$curriculum, $version] = $this->draftVersion($tenant, $curriculumId, $versionId, $context);
        $key = $this->importSessionKey($tenant, $curriculumId);
        $stored = session()->get($key);
        $preview = is_array($stored)
            && ($stored['tenant_id'] ?? null) === $context->tenant()->getKey()
            && ($stored['curriculum_id'] ?? null) === $curriculum->getKey()
            && ($stored['user_id'] ?? null) === auth()->id()
            && ($stored['version_id'] ?? null) === $version->getKey()
            && ($stored['version_lock'] ?? null) === $version->lock_version
            && ($stored['expires_at'] ?? 0) >= now()->timestamp
            ? ($stored['preview'] ?? CurriculumImportService::emptyPreview())
            : CurriculumImportService::emptyPreview();
        $batch = null;
        if ($request->query->has('batch')) {
            abort_unless(ctype_digit((string) $request->query('batch')), 404);
            $record = AcademicCurriculumImportBatch::query()->withoutGlobalScope('tenant')
                ->where('tenant_id', $context->tenant()->getKey())
                ->where('institution_id', $curriculum->institution_id)
                ->where('curriculum_id', $curriculum->getKey())
                ->where('curriculum_version_id', $version->getKey())
                ->findOrFail((int) $request->query('batch'));
            $batch = [
                'id' => (int) $record->getKey(), 'status' => $record->status,
                'total_count' => (int) $record->total_count, 'processed_count' => (int) $record->processed_count,
                'imported_count' => (int) $record->imported_count, 'attempt_count' => (int) $record->attempt_count,
                'manual_retry_count' => (int) $record->manual_retry_count, 'failure_code' => $record->failure_code,
            ];
        }

        return Inertia::render('workspace/academics/curriculum/import', [
            'curriculum' => $curriculum->only(['id', 'code', 'title']),
            'version' => $version->only(['id', 'version_number', 'status', 'lock_version']),
            'preview' => $preview,
            'previewChecksum' => $preview['total_count'] > 0 ? ($stored['checksum'] ?? null) : null,
            'templateUrl' => route('tenant.academics.curriculum.import-template', ['tenant' => $tenant, 'format' => 'csv']),
            'jsonTemplateUrl' => route('tenant.academics.curriculum.import-template', ['tenant' => $tenant, 'format' => 'json']),
            'previewUrl' => route('tenant.academics.curriculum.import.preview', ['tenant' => $tenant, 'curriculumId' => $curriculumId, 'versionId' => $versionId]),
            'confirmUrl' => route('tenant.academics.curriculum.import.confirm', ['tenant' => $tenant, 'curriculumId' => $curriculumId, 'versionId' => $versionId]),
            'errorReportUrl' => route('tenant.academics.curriculum.import.errors', ['tenant' => $tenant, 'curriculumId' => $curriculumId, 'versionId' => $versionId]),
            'importBatch' => $batch,
            'retryUrl' => $batch !== null ? route('tenant.academics.curriculum.import.retry', ['tenant' => $tenant, 'curriculumId' => $curriculumId, 'versionId' => $versionId, 'batchId' => $batch['id']]) : null,
            'backUrl' => route('tenant.academics.curriculum.show', ['tenant' => $tenant, 'curriculumId' => $curriculumId, 'version' => $versionId]),
        ]);
    }

    public function downloadImportErrors(Request $request, Tenant $tenant, int $curriculumId, int $versionId, TenantContext $context): StreamedResponse
    {
        [$curriculum, $version] = $this->draftVersion($tenant, $curriculumId, $versionId, $context);
        abort_unless($request->user() instanceof User, 401);
        $stored = session()->get($this->importSessionKey($tenant, $curriculumId));
        abort_unless(is_array($stored)
            && ($stored['tenant_id'] ?? null) === $context->tenant()->getKey()
            && ($stored['curriculum_id'] ?? null) === $curriculum->getKey()
            && ($stored['user_id'] ?? null) === $request->user()->getKey()
            && ($stored['version_id'] ?? null) === $version->getKey()
            && ($stored['version_lock'] ?? null) === $version->lock_version
            && ($stored['expires_at'] ?? 0) >= now()->timestamp, 404);

        $preview = $stored['preview'] ?? null;
        $errors = is_array($preview) ? ($preview['errors'] ?? null) : null;
        abort_unless(is_array($errors) && $errors !== [], 404);

        return response()->streamDownload(static function () use ($errors): void {
            $stream = fopen('php://output', 'wb');
            if ($stream === false) {
                return;
            }
            fputcsv($stream, ['row', 'errors'], ',', '"', '');
            foreach ($errors as $issue) {
                if (! is_array($issue) || ! is_numeric($issue['row'] ?? null) || ! is_array($issue['errors'] ?? null)) {
                    continue;
                }
                $messages = array_values(array_filter($issue['errors'], 'is_string'));
                fputcsv($stream, [(int) $issue['row'], self::safeCsvErrorCell(implode(' ', $messages))], ',', '"', '');
            }
            fclose($stream);
        }, 'curriculum-import-errors.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    public function previewImport(Request $request, Tenant $tenant, int $curriculumId, int $versionId, TenantContext $context, CurriculumImportService $imports): RedirectResponse
    {
        $data = $request->validate([
            'file' => ['required', 'file', 'max:2048'],
            'version_lock' => ['required', 'integer', 'min:1'],
        ]);
        [$curriculum, $version] = $this->draftVersion($tenant, $curriculumId, $versionId, $context);
        if ($version->lock_version !== (int) $data['version_lock']) {
            throw new ConflictHttpException('The curriculum draft changed. Refresh before previewing an import.');
        }
        $file = $data['file'];
        if (! $file instanceof UploadedFile) {
            throw new \LogicException('The validated curriculum import file is unavailable.');
        }
        $preview = $imports->preview($file, $version);
        $path = $file->getRealPath();
        $checksum = is_string($path) ? hash_file('sha256', $path) : false;
        if (! is_string($checksum)) {
            throw new \RuntimeException('The curriculum import could not be fingerprinted for confirmation.');
        }
        session()->put($this->importSessionKey($tenant, $curriculumId), [
            'tenant_id' => $context->tenant()->getKey(), 'curriculum_id' => $curriculum->getKey(),
            'version_id' => $version->getKey(), 'version_lock' => $version->lock_version,
            'user_id' => $request->user()->getKey(), 'checksum' => $checksum,
            'preview' => $preview, 'expires_at' => now()->addMinutes(10)->timestamp,
        ]);

        return to_route('tenant.academics.curriculum.import', ['tenant' => $tenant, 'curriculumId' => $curriculumId, 'versionId' => $versionId])->with('status', 'Dry-run complete. No curriculum items were written.');
    }

    public function confirmImport(Request $request, Tenant $tenant, int $curriculumId, int $versionId, TenantContext $context, AuditRecorder $audit): RedirectResponse
    {
        $data = $request->validate([
            'file' => ['required', 'file', 'max:2048'],
            'version_lock' => ['required', 'integer', 'min:1'],
            'preview_checksum' => ['required', 'regex:/^[a-f0-9]{64}$/'],
        ]);
        [$curriculum, $version] = $this->draftVersion($tenant, $curriculumId, $versionId, $context);
        $key = $this->importSessionKey($tenant, $curriculumId);
        $stored = session()->get($key);
        $file = $data['file'];
        if (! $file instanceof UploadedFile) {
            throw new \LogicException('The validated curriculum import file is unavailable.');
        }
        $path = $file->getRealPath();
        $checksum = is_string($path) ? hash_file('sha256', $path) : false;
        if (! is_array($stored)
            || ($stored['tenant_id'] ?? null) !== $context->tenant()->getKey()
            || ($stored['curriculum_id'] ?? null) !== $curriculum->getKey()
            || ($stored['version_id'] ?? null) !== $version->getKey()
            || ($stored['user_id'] ?? null) !== $request->user()->getKey()
            || ($stored['version_lock'] ?? null) !== (int) $data['version_lock']
            || ($stored['expires_at'] ?? 0) < now()->timestamp
            || ! is_string($checksum)
            || ! hash_equals((string) ($stored['checksum'] ?? ''), $checksum)
            || ! hash_equals((string) ($stored['checksum'] ?? ''), (string) $data['preview_checksum'])) {
            throw new ConflictHttpException('The import preview is missing, expired, changed, or belongs to another draft. Run a new dry-run.');
        }
        if (($stored['preview']['errors'] ?? []) !== []) {
            throw ValidationException::withMessages(['file' => 'Resolve every dry-run validation error before confirming the import.']);
        }

        $preview = $stored['preview'] ?? null;
        if (! is_array($preview) || ! is_array($preview['rows'] ?? null) || ! is_string($preview['format'] ?? null)) {
            throw new ConflictHttpException('The stored curriculum preview is invalid. Run a new dry-run.');
        }
        $batch = AcademicCurriculumImportBatch::query()->create([
            'tenant_id' => $context->tenant()->getKey(),
            'institution_id' => $curriculum->institution_id,
            'curriculum_id' => $curriculum->getKey(),
            'curriculum_version_id' => $version->getKey(),
            'requested_by_user_id' => $request->user()->getKey(),
            'status' => 'queued', 'format' => $preview['format'],
            'source_checksum' => (string) $stored['checksum'],
            'expected_lock_version' => (int) $data['version_lock'],
            'total_count' => (int) $preview['valid_count'], 'payload' => $preview,
        ]);
        $audit->record('academic.curriculum.import_queued', $request->user(), (int) $context->tenant()->getKey(), $batch, [
            'format' => $batch->format, 'item_count' => (int) $batch->total_count,
            'source_checksum' => $batch->source_checksum,
        ]);
        session()->forget($key);
        ProcessAcademicCurriculumImportBatch::dispatch((int) $batch->getKey());
        $batch->refresh();

        if ($batch->status === 'completed') {
            return to_route('tenant.academics.curriculum.show', ['tenant' => $tenant, 'curriculumId' => $curriculumId, 'version' => $versionId])
                ->with('status', "{$batch->imported_count} curriculum items imported into the draft.");
        }

        return to_route('tenant.academics.curriculum.import', [
            'tenant' => $tenant, 'curriculumId' => $curriculumId, 'versionId' => $versionId, 'batch' => $batch->getKey(),
        ])->with('status', 'Curriculum import queued. Processing progress will update automatically.');
    }

    public function retryImport(Request $request, Tenant $tenant, int $curriculumId, int $versionId, int $batchId, TenantContext $context, AuditRecorder $audit): RedirectResponse
    {
        [$curriculum, $version] = $this->draftVersion($tenant, $curriculumId, $versionId, $context);
        $batch = DB::transaction(function () use ($curriculumId, $versionId, $batchId, $context, $curriculum, $version, $request, $audit): AcademicCurriculumImportBatch {
            $record = AcademicCurriculumImportBatch::query()->where('tenant_id', $context->tenant()->getKey())
                ->where('institution_id', $curriculum->institution_id)->where('curriculum_id', $curriculumId)
                ->where('curriculum_version_id', $versionId)->lockForUpdate()->findOrFail($batchId);
            abort_unless($record->status === 'failed'
                && $record->failure_code === 'processing_failed'
                && $record->payload !== null
                && (int) $record->requested_by_user_id === (int) $request->user()->getKey()
                && (int) $record->expected_lock_version === (int) $version->lock_version
                && (int) $record->manual_retry_count < 3, 409);

            $record->forceFill([
                'status' => 'queued', 'failure_code' => null, 'attempt_count' => 0,
                'manual_retry_count' => $record->manual_retry_count + 1,
            ])->save();
            $audit->record('academic.curriculum.import_retry_queued', $request->user(), (int) $context->tenant()->getKey(), $record, [
                'attempt' => (int) $record->manual_retry_count,
            ]);

            return $record;
        }, 3);
        ProcessAcademicCurriculumImportBatch::dispatch((int) $batch->getKey());
        $batch->refresh();

        return to_route('tenant.academics.curriculum.import', [
            'tenant' => $tenant, 'curriculumId' => $curriculumId, 'versionId' => $versionId, 'batch' => $batch->getKey(),
        ])->with('status', $batch->status === 'completed' ? "{$batch->imported_count} curriculum items imported into the draft." : 'Curriculum import retry queued.');
    }

    public function importTemplate(Tenant $tenant, string $format, TenantContext $context): StreamedResponse
    {
        abort_unless($tenant->is($context->tenant()), 404);
        abort_unless(in_array($format, ['csv', 'json'], true), 404);
        $content = $format === 'csv'
            ? "code,parent_code,type,title,description,is_required,estimated_minutes,workload_unit,period_code,planned_starts_on,planned_ends_on\nUNIT-01,,unit,Example unit,,true,60,minutes,,,\nTOPIC-01,UNIT-01,topic,Example topic,,true,30,minutes,,,\n"
            : json_encode(['schema_version' => 1, 'nodes' => [['code' => 'UNIT-01', 'parent_code' => null, 'type' => 'unit', 'title' => 'Example unit', 'is_required' => true, 'estimated_minutes' => 60, 'workload_unit' => 'minutes']]], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

        return response()->streamDownload(static function () use ($content): void {
            echo $content;
        }, 'curriculum-import-template.'.$format, ['Content-Type' => $format === 'csv' ? 'text/csv; charset=UTF-8' : 'application/json; charset=UTF-8']);
    }

    /** @return array{AcademicCurriculum,AcademicCurriculumVersion} */
    private function draftVersion(Tenant $tenant, int $curriculumId, int $versionId, TenantContext $context): array
    {
        abort_unless($tenant->is($context->tenant()), 404);
        $tenantId = $context->tenant()->getKey();
        $institutionId = Institution::query()->where('tenant_id', $tenantId)->value('id');
        $curriculum = AcademicCurriculum::query()->where('tenant_id', $tenantId)->where('institution_id', $institutionId)->findOrFail($curriculumId);
        $version = AcademicCurriculumVersion::query()->where('curriculum_id', $curriculum->getKey())->findOrFail($versionId);
        abort_unless(in_array($version->status, ['draft', 'changes_requested'], true), 409, 'Only an editable curriculum draft can be imported.');

        return [$curriculum, $version];
    }

    private function importSessionKey(Tenant $tenant, int $curriculumId): string
    {
        return 'academic.curriculum.import.'.$tenant->getKey().'.'.$curriculumId;
    }

    public function createFramework(Request $request, Tenant $tenant, CurriculumWorkflow $workflow, TenantContext $context): RedirectResponse
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'max:48', 'regex:/^[A-Za-z0-9._-]+$/', Rule::unique('academic_curriculum_frameworks', 'code')->where('tenant_id', app(TenantContext::class)->tenant()->getKey())],
            'title' => ['required', 'string', 'max:180'], 'education_stage' => ['nullable', 'string', 'max:80'],
            'description' => ['nullable', 'string', 'max:5000'], 'effective_from' => ['nullable', 'date'],
            'effective_until' => ['nullable', 'date', 'after_or_equal:effective_from'],
            'required_learning_areas' => ['nullable', 'array', 'max:30'], 'required_learning_areas.*' => ['string', 'max:100'],
            'terminology' => ['nullable', 'array', 'max:20'], 'terminology.*' => ['string', 'max:80'],
        ]);
        $framework = $workflow->createFramework($data, $request->user());

        return to_route('tenant.academics.curriculum.index', ['tenant' => $context->tenant()])->with('status', "Framework {$framework->code} created as a draft.");
    }

    public function updateFramework(Request $request, Tenant $tenant, int $frameworkId, CurriculumWorkflow $workflow): RedirectResponse
    {
        $framework = AcademicCurriculumFramework::query()->findOrFail($frameworkId);
        $data = $request->validate([
            'lock_version' => ['required', 'integer', 'min:1'], 'title' => ['required', 'string', 'max:180'],
            'education_stage' => ['nullable', 'string', 'max:80'], 'description' => ['nullable', 'string', 'max:5000'],
            'effective_from' => ['nullable', 'date'], 'effective_until' => ['nullable', 'date', 'after_or_equal:effective_from'],
            'required_learning_areas' => ['nullable', 'array', 'max:30'], 'required_learning_areas.*' => ['string', 'max:100'],
            'terminology' => ['nullable', 'array', 'max:20'], 'terminology.*' => ['string', 'max:80'],
        ]);
        $lockVersion = (int) $data['lock_version'];
        unset($data['lock_version']);
        $workflow->updateFramework($framework, $data, $lockVersion, $request->user());

        return back()->with('status', 'Draft framework updated.');
    }

    public function transitionFramework(Request $request, Tenant $tenant, int $frameworkId, CurriculumWorkflow $workflow): RedirectResponse
    {
        $data = $request->validate(['status' => ['required', Rule::in(['active', 'retired'])]]);
        $workflow->transitionFramework(AcademicCurriculumFramework::query()->findOrFail($frameworkId), $data['status'], $request->user());

        return back()->with('status', 'Framework status changed to '.$data['status'].'.');
    }

    public function addFrameworkOutcome(Request $request, Tenant $tenant, int $frameworkId, CurriculumWorkflow $workflow): RedirectResponse
    {
        $data = $request->validate(['learning_outcome_id' => ['required', 'integer'], 'required' => ['required', 'boolean']]);
        $workflow->requireFrameworkOutcome($frameworkId, $data['learning_outcome_id'], (bool) $data['required'], $request->user());

        return back()->with('status', 'Framework outcome requirement saved.');
    }

    public function create(Request $request, Tenant $tenant, CurriculumWorkflow $workflow, TenantContext $context): RedirectResponse
    {
        $tenantId = app(TenantContext::class)->tenant()->getKey();
        $institutionId = Institution::query()->value('id');
        $data = $request->validate([
            'academic_program_id' => ['required', 'integer', Rule::exists('academic_programs', 'id')->where(fn ($query) => $query->where('tenant_id', $tenantId)->where('institution_id', $institutionId)->where('status', 'active'))],
            'framework_id' => ['nullable', 'integer', Rule::exists('academic_curriculum_frameworks', 'id')->where(fn ($query) => $query->where('tenant_id', $tenantId)->where('institution_id', $institutionId))],
            'academic_year_id' => ['nullable', 'integer', Rule::exists('academic_years', 'id')->where(fn ($query) => $query->where('tenant_id', $tenantId)->where('institution_id', $institutionId))],
            'code' => ['required', 'string', 'max:48', 'regex:/^[A-Za-z0-9._-]+$/', Rule::unique('academic_curricula', 'code')->where('tenant_id', $tenantId)],
            'title' => ['required', 'string', 'max:180'], 'description' => ['nullable', 'string', 'max:5000'],
            'effective_from' => ['nullable', 'date'], 'effective_until' => ['nullable', 'date', 'after_or_equal:effective_from'],
        ]);
        ['curriculum' => $curriculum] = $workflow->createCurriculum($data, $request->user());

        return to_route('tenant.academics.curriculum.show', ['tenant' => $context->tenant(), 'curriculumId' => $curriculum->getKey()])->with('status', 'Curriculum created. Add syllabus units and validate the draft.');
    }

    public function shareTemplate(Request $request, Tenant $tenant, int $curriculumId, CurriculumWorkflow $workflow): RedirectResponse
    {
        $data = $request->validate(['sharing_status' => ['required', Rule::in(['private', 'shared'])]]);
        $workflow->setTemplateSharing(AcademicCurriculum::query()->findOrFail($curriculumId), $data['sharing_status'], $request->user());

        return back()->with('status', $data['sharing_status'] === 'shared' ? 'Published curriculum is now available as a read-only shared template.' : 'Curriculum template sharing has been disabled.');
    }

    public function adoptTemplate(Request $request, Tenant $tenant, int $sourceCurriculumId, CurriculumWorkflow $workflow, TenantContext $context): RedirectResponse
    {
        $tenantId = $context->tenant()->getKey();
        $institutionId = Institution::query()->value('id');
        $data = $request->validate([
            'academic_program_id' => ['required', 'integer', Rule::exists('academic_programs', 'id')->where(fn ($query) => $query->where('tenant_id', $tenantId)->where('institution_id', $institutionId)->where('status', 'active'))],
            'academic_year_id' => ['nullable', 'integer', Rule::exists('academic_years', 'id')->where(fn ($query) => $query->where('tenant_id', $tenantId)->where('institution_id', $institutionId))],
            'code' => ['required', 'string', 'max:48', 'regex:/^[A-Za-z0-9._-]+$/', Rule::unique('academic_curricula', 'code')->where('tenant_id', $tenantId)],
            'title' => ['required', 'string', 'max:180'], 'description' => ['nullable', 'string', 'max:5000'],
        ]);
        $adopted = $workflow->adoptSharedTemplate($sourceCurriculumId, $data, $request->user());

        return to_route('tenant.academics.curriculum.show', ['tenant' => $context->tenant(), 'curriculumId' => $adopted['curriculum']->getKey()])->with('status', 'A new editable copy was created from the shared template.');
    }

    public function addNode(Request $request, Tenant $tenant, int $versionId, CurriculumWorkflow $workflow, TenantContext $context): RedirectResponse
    {
        $data = $request->validate([
            'version_lock' => ['required', 'integer', 'min:1'], 'parent_id' => ['nullable', 'integer'],
            'node_type' => ['required', Rule::in(['course', 'unit', 'topic', 'subtopic', 'objective', 'lesson'])],
            'code' => ['nullable', 'string', 'max:64'], 'title' => ['required', 'string', 'max:180'], 'description' => ['nullable', 'string', 'max:5000'],
            'academic_course_id' => ['nullable', 'integer'], 'academic_course_version_id' => ['nullable', 'integer'], 'academic_period_id' => ['nullable', 'integer'],
            'is_required' => ['sometimes', 'boolean'], 'estimated_minutes' => ['nullable', 'integer', 'min:1', 'max:100000'],
            'workload_unit' => ['nullable', Rule::in(['minutes', 'hours', 'credits', 'sessions', 'weeks', 'competency'])],
            'instructional_strategy' => ['nullable', 'string', 'max:120'], 'activity_specification' => ['nullable', 'string', 'max:5000'],
            'assessment_blueprint' => ['nullable', 'string', 'max:5000'], 'planned_starts_on' => ['nullable', 'date'],
            'planned_ends_on' => ['nullable', 'date', 'after_or_equal:planned_starts_on'],
        ]);
        $version = AcademicCurriculumVersion::query()->findOrFail($versionId);
        $node = $workflow->addNode($version, $data, $request->user());

        return to_route('tenant.academics.curriculum.show', ['tenant' => $context->tenant(), 'curriculumId' => $node->version->curriculum_id, 'version' => $versionId])->with('status', 'Syllabus item added.');
    }

    public function updateNode(Request $request, Tenant $tenant, int $versionId, int $nodeId, CurriculumWorkflow $workflow): RedirectResponse
    {
        $data = $request->validate([
            'version_lock' => ['required', 'integer', 'min:1'], 'node_lock' => ['required', 'integer', 'min:1'],
            'title' => ['required', 'string', 'max:180'], 'description' => ['nullable', 'string', 'max:5000'],
            'estimated_minutes' => ['nullable', 'integer', 'min:1', 'max:100000'], 'workload_unit' => ['required', Rule::in(['minutes', 'hours', 'credits', 'sessions', 'weeks', 'competency'])],
            'instructional_strategy' => ['nullable', 'string', 'max:120'], 'activity_specification' => ['nullable', 'string', 'max:5000'],
            'assessment_blueprint' => ['nullable', 'string', 'max:5000'], 'planned_starts_on' => ['nullable', 'date'],
            'planned_ends_on' => ['nullable', 'date', 'after_or_equal:planned_starts_on'], 'is_required' => ['required', 'boolean'],
        ]);
        $version = AcademicCurriculumVersion::query()->findOrFail($versionId);
        $node = AcademicCurriculumNode::query()->where('curriculum_version_id', $version->getKey())->findOrFail($nodeId);
        $workflow->updateNode($node, $data, $request->user());

        return $this->detail($tenant, $version, 'Syllabus item updated.');
    }

    public function createCompletionRule(Request $request, Tenant $tenant, int $versionId, CurriculumWorkflow $workflow, TenantContext $context): RedirectResponse
    {
        $data = $this->completionRulePayload($request, $versionId, $context);
        $workflow->createCompletionRule($versionId, $data, $request->user());

        return $this->detail($tenant, AcademicCurriculumVersion::query()->findOrFail($versionId), 'Curriculum completion rule added to the draft plan.');
    }

    public function updateCompletionRule(Request $request, Tenant $tenant, int $versionId, int $ruleId, CurriculumWorkflow $workflow, TenantContext $context): RedirectResponse
    {
        $data = $this->completionRulePayload($request, $versionId, $context, $ruleId);
        $workflow->updateCompletionRule($versionId, $ruleId, $data, $request->user());

        return $this->detail($tenant, AcademicCurriculumVersion::query()->findOrFail($versionId), 'Curriculum completion rule updated.');
    }

    public function deleteCompletionRule(Request $request, Tenant $tenant, int $versionId, int $ruleId, CurriculumWorkflow $workflow): RedirectResponse
    {
        $data = $request->validate(['version_lock' => ['required', 'integer', 'min:1']]);
        $workflow->deleteCompletionRule($versionId, $ruleId, (int) $data['version_lock'], $request->user());

        return $this->detail($tenant, AcademicCurriculumVersion::query()->findOrFail($versionId), 'Curriculum completion rule removed from the draft plan.');
    }

    public function reorderNode(Request $request, Tenant $tenant, int $versionId, int $nodeId, CurriculumWorkflow $workflow): RedirectResponse
    {
        $data = $request->validate(['direction' => ['required', Rule::in(['up', 'down'])], 'version_lock' => ['required', 'integer', 'min:1']]);
        $version = AcademicCurriculumVersion::query()->findOrFail($versionId);
        $node = AcademicCurriculumNode::query()->where('curriculum_version_id', $version->getKey())->findOrFail($nodeId);
        $workflow->reorderNode($node, $data['direction'], $data['version_lock'], $request->user());

        return $this->detail($tenant, $version, 'Hierarchy order saved.');
    }

    public function mapOutcome(Request $request, Tenant $tenant, int $versionId, int $nodeId, CurriculumWorkflow $workflow): RedirectResponse
    {
        $data = $request->validate(['version_lock' => ['required', 'integer', 'min:1'], 'learning_outcome_id' => ['required', 'integer'], 'mapping_strength' => ['required', Rule::in(['introduced', 'developed', 'mastered'])], 'surface' => ['sometimes', Rule::in(['detail', 'matrix'])]]);
        $version = AcademicCurriculumVersion::query()->findOrFail($versionId);
        $node = AcademicCurriculumNode::query()->where('curriculum_version_id', $version->getKey())->findOrFail($nodeId);
        $workflow->mapOutcome($node, $data['learning_outcome_id'], $data['mapping_strength'], $data['version_lock'], $request->user());

        return $this->curriculumSurface($tenant, $version, $data['surface'] ?? 'detail', 'Learning outcome mapped to the syllabus item.');
    }

    public function unmapOutcome(Request $request, Tenant $tenant, int $versionId, int $nodeId, int $outcomeId, CurriculumWorkflow $workflow): RedirectResponse
    {
        $data = $request->validate(['version_lock' => ['required', 'integer', 'min:1'], 'surface' => ['sometimes', Rule::in(['detail', 'matrix'])]]);
        $version = AcademicCurriculumVersion::query()->findOrFail($versionId);
        $node = AcademicCurriculumNode::query()->where('curriculum_version_id', $version->getKey())->findOrFail($nodeId);
        $workflow->unmapOutcome($node, $outcomeId, (int) $data['version_lock'], $request->user());

        return $this->curriculumSurface($tenant, $version, $data['surface'] ?? 'detail', 'Learning outcome mapping removed from the draft.');
    }

    public function addPrerequisite(Request $request, Tenant $tenant, int $versionId, int $nodeId, CurriculumWorkflow $workflow): RedirectResponse
    {
        $data = $request->validate(['version_lock' => ['required', 'integer', 'min:1'], 'prerequisite_node_id' => ['required', 'integer'], 'dependency_type' => ['required', Rule::in(['required', 'recommended'])]]);
        $version = AcademicCurriculumVersion::query()->findOrFail($versionId);
        $node = AcademicCurriculumNode::query()->where('curriculum_version_id', $version->getKey())->findOrFail($nodeId);
        $workflow->addPrerequisite($node, $data['prerequisite_node_id'], $data['dependency_type'], $data['version_lock'], $request->user());

        return $this->detail($tenant, $version, 'Learning path dependency saved.');
    }

    public function createCompetencyFramework(Request $request, Tenant $tenant, CurriculumGovernanceService $governance): RedirectResponse
    {
        $data = $request->validate(['code' => ['required', 'string', 'max:48', 'regex:/^[A-Za-z0-9._-]+$/', Rule::unique('academic_competency_frameworks', 'code')->where('tenant_id', app(TenantContext::class)->tenant()->getKey())], 'title' => ['required', 'string', 'max:180'], 'description' => ['nullable', 'string', 'max:5000']]);
        $governance->createCompetencyFramework($data, $request->user());

        return back()->with('status', 'Competency framework created.');
    }

    public function addCompetency(Request $request, Tenant $tenant, int $frameworkId, CurriculumGovernanceService $governance): RedirectResponse
    {
        $data = $request->validate(['parent_id' => ['nullable', 'integer'], 'code' => ['required', 'string', 'max:64', 'regex:/^[A-Za-z0-9._-]+$/'], 'title' => ['required', 'string', 'max:180'], 'descriptor' => ['required', 'string', 'max:5000'], 'mastery_criteria' => ['nullable', 'string', 'max:3000'], 'level_label' => ['nullable', 'string', 'max:80'], 'sort_order' => ['sometimes', 'integer', 'min:0', 'max:100000']]);
        $governance->addCompetency($frameworkId, $data, $request->user());

        return back()->with('status', 'Competency added to its framework.');
    }

    public function mapCompetency(Request $request, Tenant $tenant, int $versionId, int $nodeId, CurriculumWorkflow $workflow): RedirectResponse
    {
        $data = $request->validate(['version_lock' => ['required', 'integer', 'min:1'], 'competency_id' => ['required', 'integer'], 'expected_level' => ['nullable', 'string', 'max:80'], 'mapping_strength' => ['required', Rule::in(['introduced', 'developed', 'mastered'])]]);
        $version = AcademicCurriculumVersion::query()->findOrFail($versionId);
        $node = AcademicCurriculumNode::query()->where('curriculum_version_id', $version->getKey())->findOrFail($nodeId);
        $workflow->mapCompetency($node, $data['competency_id'], $data['expected_level'] ?? null, $data['mapping_strength'], $data['version_lock'], $request->user());

        return back()->with('status', 'Competency mapping saved.');
    }

    public function submit(Request $request, Tenant $tenant, int $versionId, CurriculumWorkflow $workflow): RedirectResponse
    {
        $data = $request->validate([
            'reviewer_user_id' => ['required_without:reviewer_user_ids', 'integer'],
            'reviewer_user_ids' => ['required_without:reviewer_user_id', 'array', 'min:1', 'max:3'],
            'reviewer_user_ids.*' => ['integer'], 'version_lock' => ['required', 'integer', 'min:1'],
        ]);
        $version = AcademicCurriculumVersion::query()->findOrFail($versionId);
        $workflow->submit($version, $data['reviewer_user_ids'] ?? (int) $data['reviewer_user_id'], (int) $data['version_lock'], $request->user());

        return $this->detail($tenant, $version, 'Curriculum submitted to its assigned independent reviewer.');
    }

    public function decide(Request $request, Tenant $tenant, int $versionId, CurriculumWorkflow $workflow): RedirectResponse
    {
        $data = $request->validate([
            'decision' => ['required', Rule::in(['approve', 'request_changes', 'reject'])], 'review_note' => ['nullable', 'string', 'max:3000'],
            'checklist_responses' => ['nullable', 'array', 'max:80'], 'checklist_responses.*.complete' => ['sometimes', 'boolean'],
            'checklist_responses.*.note' => ['nullable', 'string', 'max:2000'], 'checklist_responses.*.evidence_reference' => ['nullable', 'string', 'max:500'],
        ]);
        $version = AcademicCurriculumVersion::query()->findOrFail($versionId);
        $workflow->decide($version, $data['decision'], $data['review_note'] ?? null, $request->user(), $data['checklist_responses'] ?? []);

        return $this->detail($tenant, $version, "Curriculum review recorded: {$data['decision']}.");
    }

    public function publish(Tenant $tenant, int $versionId, CurriculumWorkflow $workflow, Request $request): RedirectResponse
    {
        $version = AcademicCurriculumVersion::query()->findOrFail($versionId);
        $workflow->publish($version, $request->user());

        return $this->detail($tenant, $version, 'Approved curriculum version published and locked.');
    }

    public function revision(Request $request, Tenant $tenant, int $curriculumId, CurriculumWorkflow $workflow, TenantContext $context): RedirectResponse
    {
        $data = $request->validate(['revision_note' => ['required', 'string', 'max:2000']]);
        $curriculum = AcademicCurriculum::query()->findOrFail($curriculumId);
        $version = $workflow->createRevision($curriculum, $data['revision_note'], $request->user());

        return to_route('tenant.academics.curriculum.show', ['tenant' => $context->tenant(), 'curriculumId' => $curriculum->getKey(), 'version' => $version->getKey()])->with('status', "Revision {$version->version_number} created from the published version.");
    }

    public function export(Tenant $tenant, int $curriculumId): StreamedResponse
    {
        $curriculum = AcademicCurriculum::query()->with('program:id,code,title')->findOrFail($curriculumId);
        $published = AcademicCurriculumVersion::query()->with(['nodes.outcomes.learningOutcome:id,code,name', 'completionRules.academicPeriod:id,code,name'])->where('curriculum_id', $curriculum->getKey())->where('status', 'published')->orderByDesc('version_number')->first();
        if (! $published instanceof AcademicCurriculumVersion) {
            abort(404);
        }
        $payload = [
            'schema' => 'techmentors.curriculum.v1', 'code' => $curriculum->code, 'title' => $curriculum->title,
            'program' => ['code' => $curriculum->program->code, 'title' => $curriculum->program->title],
            'version' => $published->version_number, 'effective_from' => $published->getRawOriginal('effective_from'),
            'completion_rules' => $published->completionRules->map(fn (AcademicCurriculumCompletionRule $rule): array => [
                'code' => $rule->code, 'title' => $rule->title, 'type' => $rule->rule_type,
                'target' => $rule->target_value, 'unit' => $rule->unit, 'required' => $rule->is_required,
                'period' => $rule->academicPeriod?->code, 'description' => $rule->description,
            ])->values(),
            'nodes' => $published->nodes->map(fn (AcademicCurriculumNode $node): array => [
                'type' => $node->node_type, 'code' => $node->code, 'title' => $node->title, 'description' => $node->description,
                'parent_id' => $node->parent_id, 'sequence' => $node->sort_order, 'required' => $node->is_required,
                'estimated_minutes' => $node->estimated_minutes, 'outcomes' => $node->outcomes->map(fn (AcademicCurriculumNodeOutcome $mapping): ?string => $mapping->learningOutcome?->code)->filter()->values(),
            ])->values(),
        ];

        return response()->streamDownload(static function () use ($payload): void {
            echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        }, $curriculum->code.'-curriculum.json', ['Content-Type' => 'application/json; charset=utf-8']);
    }

    private function detail(Tenant $tenant, AcademicCurriculumVersion $version, string $status): RedirectResponse
    {
        return to_route('tenant.academics.curriculum.show', [
            'tenant' => $tenant,
            'curriculumId' => $version->curriculum_id,
            'version' => $version->getKey(),
        ])->with('status', $status);
    }

    private static function safeCsvErrorCell(string $value): string
    {
        $clean = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $value) ?? '';

        return preg_match('/^\s*[=+\-@]/u', $clean) === 1 ? "'".$clean : $clean;
    }

    private function curriculumSurface(Tenant $tenant, AcademicCurriculumVersion $version, string $surface, string $status): RedirectResponse
    {
        if ($surface === 'matrix') {
            return to_route('tenant.academics.curriculum.matrix', [
                'tenant' => $tenant,
                'curriculumId' => $version->curriculum_id,
                'version' => $version->getKey(),
            ])->with('status', $status);
        }

        return $this->detail($tenant, $version, $status);
    }

    /** @return array<string,mixed> */
    private function completionRulePayload(Request $request, int $versionId, TenantContext $context, ?int $ruleId = null): array
    {
        $uniqueCode = Rule::unique('academic_curriculum_completion_rules', 'code')
            ->where('tenant_id', $context->tenant()->getKey())
            ->where('curriculum_version_id', $versionId);
        if ($ruleId !== null) {
            $uniqueCode->ignore($ruleId);
        }
        $data = $request->validate([
            'version_lock' => ['required', 'integer', 'min:1'],
            'code' => ['required', 'string', 'max:48', 'regex:/^[A-Za-z0-9][A-Za-z0-9._-]*$/', $uniqueCode],
            'title' => ['required', 'string', 'max:160'],
            'rule_type' => ['required', Rule::in(['minimum_credits', 'minimum_contact_hours', 'minimum_workload', 'required_courses', 'required_units', 'required_competencies', 'completion_milestone'])],
            'target_value' => ['nullable', 'numeric', 'min:0', 'max:9999999.999'],
            'unit' => ['nullable', Rule::in(['credits', 'hours', 'minutes', 'sessions', 'weeks', 'courses', 'units', 'competencies'])],
            'academic_period_id' => ['nullable', 'integer', 'min:1'],
            'is_required' => ['required', 'boolean'],
            'description' => ['nullable', 'string', 'max:2000'],
        ]);
        $milestone = $data['rule_type'] === 'completion_milestone';
        $hasTarget = $data['target_value'] !== null && $data['target_value'] !== '';
        if ($milestone === $hasTarget) {
            throw ValidationException::withMessages(['target_value' => $milestone
                ? 'Manual milestones do not use a numeric target.'
                : 'Enter a numeric target for this completion rule.']);
        }
        if ($data['rule_type'] === 'minimum_workload' && empty($data['unit'])) {
            throw ValidationException::withMessages(['unit' => 'Choose the workload unit used by this target.']);
        }
        $fixedUnits = [
            'minimum_credits' => 'credits', 'minimum_contact_hours' => 'hours',
            'required_courses' => 'courses', 'required_units' => 'units',
            'required_competencies' => 'competencies', 'completion_milestone' => null,
        ];
        if (array_key_exists($data['rule_type'], $fixedUnits)) {
            $data['unit'] = $fixedUnits[$data['rule_type']];
        }

        return $data;
    }
}
