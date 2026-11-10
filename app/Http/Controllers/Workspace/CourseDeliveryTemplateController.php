<?php

namespace App\Http\Controllers\Workspace;

use App\Domain\Learning\CourseDeliveryTemplateWorkflow;
use App\Domain\Tenancy\TenantContext;
use App\Http\Controllers\Controller;
use App\Models\AcademicYear;
use App\Models\CourseDeliveryTemplate;
use App\Models\CourseDeliveryTemplateVersion;
use App\Models\CourseDeliveryWorkspace;
use App\Models\Institution;
use App\Models\Tenant;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

final class CourseDeliveryTemplateController extends Controller
{
    public function index(Tenant $tenant, TenantContext $context, CourseDeliveryTemplateWorkflow $workflow): Response
    {
        abort_unless($context->membership()->hasPermission('learning.delivery.manage'), 403);
        $available = $workflow->availableTemplates();
        $membership = $context->membership();
        $institutionId = $available['institution_id'];
        $templateRows = $available['templates'];
        $templateVersions = CourseDeliveryTemplateVersion::query()
            ->whereIn('template_id', $templateRows->getCollection()->pluck('id'))
            ->whereIn('version_number', $templateRows->getCollection()->pluck('current_version'))
            ->get();
        $templates = $templateRows->through(function (CourseDeliveryTemplate $template) use ($templateVersions, $institutionId): array {
            $version = $templateVersions->first(fn (CourseDeliveryTemplateVersion $candidate): bool => (int) $candidate->template_id === (int) $template->getKey()
                && (int) $candidate->version_number === (int) $template->current_version);
            $snapshot = $version->content_snapshot;

            return [
                'id' => (int) $template->getKey(), 'name' => $template->name, 'description' => $template->description,
                'institution_id' => (int) $template->institution_id, 'current_version' => (int) $template->current_version,
                'is_shared' => (int) $template->institution_id !== $institutionId,
                'checksum' => $version?->content_checksum,
                'provenance' => $snapshot['provenance'],
                'modules' => array_map(static fn (array $module): array => [
                    'id' => (int) $module['source_id'], 'title' => $module['title'],
                    'lessons' => array_map(static fn (array $lesson): array => ['id' => (int) $lesson['source_id'], 'title' => $lesson['title']], $module['lessons']),
                ], $snapshot['modules']),
            ];
        });
        $workspacesQuery = CourseDeliveryWorkspace::query()->where('institution_id', $institutionId)->where('status', 'draft');
        $sourceWorkspacesQuery = CourseDeliveryWorkspace::query()->where('institution_id', $institutionId)
            ->whereIn('status', ['draft', 'published']);
        if ($membership->campus_id !== null) {
            $workspacesQuery->where('campus_id', $membership->campus_id);
            $sourceWorkspacesQuery->where('campus_id', $membership->campus_id);
        }
        $workspaces = $workspacesQuery->orderBy('title')->limit(100)
            ->get(['id', 'code', 'title'])->map(fn (CourseDeliveryWorkspace $workspace): array => [
                'id' => (int) $workspace->getKey(), 'code' => $workspace->code, 'title' => $workspace->title,
            ])->values();
        $sourceWorkspaces = $sourceWorkspacesQuery->orderByDesc('updated_at')->limit(100)
            ->get(['id', 'code', 'title'])->map(fn (CourseDeliveryWorkspace $workspace): array => [
                'id' => (int) $workspace->getKey(), 'code' => $workspace->code, 'title' => $workspace->title,
            ])->values();
        $shareTargets = Institution::query()->where('tenant_id', $context->tenant()->getKey())->whereKeyNot($institutionId)
            ->orderBy('display_name')->limit(100)->get(['id', 'display_name'])->map(fn (Institution $institution): array => [
                'id' => (int) $institution->getKey(), 'name' => $institution->display_name,
            ])->values();
        $academicYears = DB::table('academic_years')->where('institution_id', $institutionId)->orderByDesc('starts_on')
            ->get(['id', 'name', 'starts_on', 'ends_on'])->map(static fn (object $year): array => [
                'id' => (int) $year->id, 'name' => (string) $year->name,
                'starts_on' => $year->starts_on === null ? null : (string) $year->starts_on,
                'ends_on' => $year->ends_on === null ? null : (string) $year->ends_on,
            ])->values();

        return Inertia::render('workspace/learning/templates', [
            'tenant' => ['slug' => $tenant->slug], 'templates' => $templates,
            'workspaces' => $workspaces, 'sourceWorkspaces' => $sourceWorkspaces,
            'shareTargets' => $shareTargets,
            'academicYears' => $academicYears,
        ]);
    }

    public function store(Request $request, Tenant $tenant, CourseDeliveryTemplateWorkflow $workflow): RedirectResponse
    {
        $data = $request->validate([
            'workspace_id' => ['required', 'integer', 'min:1'],
            'name' => ['required', 'string', 'max:180'],
            'description' => ['nullable', 'string', 'max:2000'],
        ]);
        $workspace = CourseDeliveryWorkspace::query()->findOrFail($data['workspace_id']);
        $workflow->createFromWorkspace($request->user(), $workspace, $data['name'], $data['description'] ?? null);

        return to_route('tenant.learning.templates.index', $tenant)->with('status', 'A versioned course template was created.');
    }

    public function revise(Request $request, Tenant $tenant, int $templateId, CourseDeliveryTemplateWorkflow $workflow): RedirectResponse
    {
        $data = $request->validate(['workspace_id' => ['required', 'integer', 'min:1']]);
        $template = CourseDeliveryTemplate::query()->findOrFail($templateId);
        $workspace = CourseDeliveryWorkspace::query()->findOrFail($data['workspace_id']);
        $workflow->reviseFromWorkspace($request->user(), $template, $workspace);

        return to_route('tenant.learning.templates.index', $tenant)->with('status', 'A new immutable template version was published.');
    }

    public function share(Request $request, Tenant $tenant, int $templateId, CourseDeliveryTemplateWorkflow $workflow): RedirectResponse
    {
        $data = $request->validate(['institution_id' => ['required', 'integer', 'min:1']]);
        $template = CourseDeliveryTemplate::query()->findOrFail($templateId);
        $workflow->share($request->user(), $template, (int) $data['institution_id']);

        return to_route('tenant.learning.templates.index', $tenant)->with('status', 'Template shared with the selected institution.');
    }

    public function copy(Request $request, Tenant $tenant, int $templateId, CourseDeliveryTemplateWorkflow $workflow): RedirectResponse
    {
        $data = $request->validate([
            'version' => ['required', 'integer', 'min:1'],
            'destination_workspace_id' => ['required', 'integer', 'min:1'],
            'idempotency_key' => ['required', 'uuid'],
            'module_ids' => ['required', 'array', 'min:1', 'max:100'],
            'module_ids.*' => ['required', 'integer', 'min:1', 'distinct'],
            'lesson_ids' => ['sometimes', 'array', 'max:100'],
            'lesson_ids.*' => ['array', 'max:300'],
            'lesson_ids.*.*' => ['required', 'integer', 'min:1', 'distinct'],
        ]);
        $template = CourseDeliveryTemplate::query()->findOrFail($templateId);
        $destination = CourseDeliveryWorkspace::query()->findOrFail($data['destination_workspace_id']);
        $operation = $workflow->copyToWorkspace(
            $request->user(), $template, (int) $data['version'], $destination,
            $data['module_ids'], $data['lesson_ids'] ?? [], $data['idempotency_key'],
        );

        return to_route('tenant.learning.show', [$tenant, $destination->getKey()])
            ->with('status', 'Selected template content copied as draft. No learner progress or responses were copied.')
            ->with('copy_operation_id', $operation->getKey());
    }

    public function rollOver(Request $request, Tenant $tenant, int $templateId, CourseDeliveryTemplateWorkflow $workflow): RedirectResponse
    {
        $data = $request->validate([
            'version' => ['required', 'integer', 'min:1'], 'academic_year_id' => ['required', 'integer', 'min:1'],
            'code' => ['required', 'string', 'max:64', 'regex:/^[A-Za-z0-9._-]+$/'],
            'title' => ['required', 'string', 'max:180'], 'idempotency_key' => ['required', 'uuid'],
            'module_ids' => ['required', 'array', 'min:1', 'max:100'], 'module_ids.*' => ['required', 'integer', 'min:1', 'distinct'],
            'lesson_ids' => ['sometimes', 'array', 'max:100'], 'lesson_ids.*' => ['array', 'max:300'],
            'lesson_ids.*.*' => ['required', 'integer', 'min:1', 'distinct'],
        ]);
        $template = CourseDeliveryTemplate::query()->findOrFail($templateId);
        $academicYear = AcademicYear::query()->findOrFail($data['academic_year_id']);
        $destination = $workflow->rollOverToAcademicYear(
            $request->user(), $template, (int) $data['version'], $academicYear,
            $data['code'], $data['title'], $data['module_ids'], $data['lesson_ids'] ?? [], $data['idempotency_key'],
        );

        return to_route('tenant.learning.show', [$tenant, $destination->getKey()])
            ->with('status', 'Academic session rollover created as a draft workspace. Learner records were not copied.');
    }

    public function versions(Tenant $tenant, int $templateId, TenantContext $context): Response
    {
        abort_unless($context->membership()->hasPermission('learning.delivery.manage'), 403);
        $template = CourseDeliveryTemplate::query()->where(function ($query) use ($context): void {
            $query->where('institution_id', $context->membership()->institution_id)
                ->orWhereHas('shares', fn ($shares) => $shares->where('institution_id', $context->membership()->institution_id));
        })
            ->with('versions')->findOrFail($templateId);

        return Inertia::render('workspace/learning/template-versions', [
            'tenant' => ['slug' => $tenant->slug],
            'template' => ['id' => (int) $template->getKey(), 'name' => $template->name, 'versions' => $template->versions->map(function (CourseDeliveryTemplateVersion $version): array {
                $publishedAt = $version->getRawOriginal('published_at');

                return [
                    'version' => (int) $version->version_number, 'source_workspace_id' => (int) $version->source_workspace_id,
                    'checksum' => $version->content_checksum,
                    'published_at' => $publishedAt === null ? null : Carbon::parse((string) $publishedAt)->toIso8601String(),
                ];
            })->values()],
        ]);
    }
}
