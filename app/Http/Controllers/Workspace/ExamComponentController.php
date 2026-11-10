<?php

namespace App\Http\Controllers\Workspace;

use App\Domain\Examinations\ExamComponentWorkflow;
use App\Domain\Tenancy\TenantContext;
use App\Http\Controllers\Controller;
use App\Models\ExamComponent;
use App\Models\Tenant;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

final class ExamComponentController extends Controller
{
    public function index(Tenant $tenant, Request $request, ExamComponentWorkflow $workflow): Response
    {
        $context = app(TenantContext::class);
        $tenantId = (int) $context->tenant()->getKey();
        $campusId = $context->membership()->campus_id;

        return Inertia::render('workspace/examinations/marking', [
            'tenant' => ['slug' => $tenant->slug], 'workspace' => $workflow->workspace($request->user()),
            'components' => ExamComponent::query()->with('session')->when($campusId !== null, fn ($query) => $query->whereHas('session', fn ($sessionQuery) => $sessionQuery->where('campus_id', $campusId)))->orderByDesc('created_at')->limit(100)->get()->map(fn (ExamComponent $component): array => ['id' => (int) $component->getKey(), 'sessionId' => (int) $component->session_id, 'session' => $component->session->title, 'type' => $component->component_type, 'title' => $component->title, 'maximumMarks' => (string) $component->maximum_marks, 'status' => $component->status, 'secondMarking' => (bool) $component->second_marking_required])->all(),
            'sessions' => DB::table('exam_sessions')->where('tenant_id', $tenantId)->where('status', 'published')->when($campusId !== null, fn ($query) => $query->where('campus_id', $campusId))->orderBy('starts_at_utc')->limit(100)->get(['id', 'title'])->map(fn ($row): array => ['id' => (int) $row->id, 'title' => $row->title])->all(),
            'rooms' => DB::table('academic_rooms')->where('tenant_id', $tenantId)->where('status', 'active')->when($campusId !== null, fn ($query) => $query->where('campus_id', $campusId))->orderBy('name')->limit(300)->get(['id', 'name', 'code'])->map(fn ($row): array => ['id' => (int) $row->id, 'label' => $row->name.' · '.$row->code])->all(),
            'candidates' => DB::table('exam_candidates')->where('exam_candidates.tenant_id', $tenantId)->where('exam_candidates.status', 'registered')->whereNull('exam_candidates.withdrawn_at')->join('exam_cycles', function ($join): void {
                $join->on('exam_cycles.id', '=', 'exam_candidates.cycle_id')->on('exam_cycles.tenant_id', '=', 'exam_candidates.tenant_id');
            })->when($campusId !== null, fn ($query) => $query->where(fn ($scope) => $scope->whereNull('exam_cycles.campus_id')->orWhere('exam_cycles.campus_id', $campusId)))->orderBy('exam_candidates.candidate_number')->limit(500)->get(['exam_candidates.id', 'exam_candidates.candidate_number'])->map(fn ($row): array => ['id' => (int) $row->id, 'number' => $row->candidate_number])->all(),
            'examiners' => DB::table('tenant_memberships')->where('tenant_memberships.tenant_id', $tenantId)->where('tenant_memberships.status', 'active')->whereNull('tenant_memberships.revoked_at')->when($campusId !== null, fn ($query) => $query->where(fn ($scope) => $scope->whereNull('tenant_memberships.campus_id')->orWhere('tenant_memberships.campus_id', $campusId)))->join('users', 'users.id', '=', 'tenant_memberships.user_id')->orderBy('users.name')->limit(200)->get(['users.id', 'users.name'])->unique('id')->map(fn ($row): array => ['id' => (int) $row->id, 'name' => $row->name])->values()->all(),
        ]);
    }

    public function studentSchedule(Tenant $tenant, Request $request): Response
    {
        $context = app(TenantContext::class);
        abort_unless($context->membership()->hasPermission('exams.student.view-own'), 403);
        $studentId = DB::table('sis_students')->where('tenant_id', $context->membership()->tenant_id)->where('institution_id', $context->membership()->institution_id)->where('user_id', $request->user()->getKey())->where('status', 'active')->value('id');
        $items = $studentId === null ? collect() : DB::table('exam_components as components')->join('exam_sessions as sessions', function ($join): void {
            $join->on('sessions.id', '=', 'components.session_id')->on('sessions.tenant_id', '=', 'components.tenant_id');
        })->join('exam_cycles as cycles', function ($join): void {
            $join->on('cycles.id', '=', 'sessions.cycle_id')->on('cycles.tenant_id', '=', 'sessions.tenant_id');
        })->join('exam_candidates as candidates', function ($join): void {
            $join->on('candidates.cycle_id', '=', 'cycles.id')->on('candidates.tenant_id', '=', 'cycles.tenant_id');
        })->where('components.tenant_id', $context->membership()->tenant_id)->where('components.status', 'published')->where('candidates.student_id', $studentId)->where('candidates.status', 'registered')->whereNull('candidates.withdrawn_at')->orderBy('components.starts_at_utc')->get(['components.id', 'components.component_type', 'components.title', 'components.instructions', 'components.starts_at_utc', 'components.ends_at_utc', 'sessions.title as session_title', 'candidates.id as candidate_id'])->map(function ($item): array {
            return ['id' => (int) $item->id, 'type' => $item->component_type, 'title' => $item->title, 'instructions' => $item->instructions, 'startsAt' => $item->starts_at_utc, 'endsAt' => $item->ends_at_utc, 'session' => $item->session_title, 'status' => DB::table('exam_component_results')->where('component_id', $item->id)->where('candidate_id', $item->candidate_id)->where('result_status', 'approved')->exists() ? 'completed' : 'scheduled'];
        });

        return Inertia::render('workspace/examinations/student-components', ['items' => $items]);
    }

    public function store(Tenant $tenant, int $sessionId, Request $request, ExamComponentWorkflow $workflow): RedirectResponse
    {
        $data = $request->validate([
            'component_type' => ['required', 'in:practical,oral'], 'code' => ['required', 'string', 'max:48', 'alpha_dash'],
            'title' => ['required', 'string', 'max:180'], 'instructions' => ['nullable', 'string', 'max:5000'],
            'room_id' => ['nullable', 'integer', 'min:1'], 'starts_at' => ['nullable', 'date'], 'ends_at' => ['nullable', 'date'],
            'maximum_marks' => ['required', 'numeric', 'gt:0', 'max:9999999.99'], 'second_marking_required' => ['sometimes', 'boolean'],
            'blind_second_marking' => ['sometimes', 'boolean'], 'moderation_difference_threshold' => ['nullable', 'numeric', 'min:0'],
            'rubric' => ['required', 'array', 'min:1', 'max:40'], 'rubric.*.key' => ['required', 'string', 'max:48', 'alpha_dash:ascii'],
            'rubric.*.title' => ['required', 'string', 'max:160'], 'rubric.*.description' => ['nullable', 'string', 'max:2000'], 'rubric.*.maximum_marks' => ['required', 'numeric', 'gt:0', 'max:9999999.99'],
        ]);
        $workflow->createComponent($sessionId, $data, $request->user());

        return back()->with('status', 'Practical/oral component and marking rubric saved as a draft.');
    }

    public function publish(Tenant $tenant, int $componentId, Request $request, ExamComponentWorkflow $workflow): RedirectResponse
    {
        $workflow->publishComponent($componentId, $request->user());

        return back()->with('status', 'Examination component published for controlled marking.');
    }

    public function assign(Tenant $tenant, int $componentId, Request $request, ExamComponentWorkflow $workflow): RedirectResponse
    {
        $data = $request->validate(['user_id' => ['required', 'integer', 'min:1'], 'role' => ['required', 'in:primary,second,moderator']]);
        $workflow->assignExaminer($componentId, (int) $data['user_id'], $data['role'], $request->user());

        return back()->with('status', 'Examiner assignment saved.');
    }

    public function allocate(Tenant $tenant, int $componentId, Request $request, ExamComponentWorkflow $workflow): RedirectResponse
    {
        $data = $request->validate(['candidate_id' => ['required', 'integer', 'min:1'], 'examiner_id' => ['required', 'integer', 'min:1'], 'round' => ['required', 'integer', 'in:1,2'], 'reason' => ['required', 'string', 'max:500']]);
        $workflow->allocate($componentId, (int) $data['candidate_id'], (int) $data['examiner_id'], (int) $data['round'], $data['reason'], $request->user());

        return back()->with('status', 'Candidate marking allocation saved with history.');
    }

    public function draft(Tenant $tenant, int $allocationId, Request $request, ExamComponentWorkflow $workflow): RedirectResponse
    {
        $data = $request->validate(['marks' => ['required', 'array'], 'marks.*' => ['required', 'numeric', 'min:0'], 'feedback' => ['nullable', 'string', 'max:4000'], 'private_notes' => ['nullable', 'string', 'max:4000'], 'expected_version' => ['required', 'integer', 'min:0']]);
        $workflow->saveDraft($allocationId, $data['marks'], $data['feedback'] ?? '', $data['private_notes'] ?? '', (int) $data['expected_version'], $request->user());

        return back()->with('status', 'Marking draft saved.');
    }

    public function submit(Tenant $tenant, int $allocationId, Request $request, ExamComponentWorkflow $workflow): RedirectResponse
    {
        $data = $request->validate(['expected_version' => ['required', 'integer', 'min:1']]);
        $marking = $workflow->submitMarking($allocationId, (int) $data['expected_version'], $request->user());

        return back()->with('status', 'Marking revision '.$marking->revision.' submitted and locked.');
    }

    public function approve(Tenant $tenant, int $markingId, Request $request, ExamComponentWorkflow $workflow): RedirectResponse
    {
        $workflow->approveSingleMark($markingId, $request->user());

        return back()->with('status', 'Single marking approved as a versioned result.');
    }

    public function moderate(Tenant $tenant, int $componentId, int $candidateId, Request $request, ExamComponentWorkflow $workflow): RedirectResponse
    {
        $data = $request->validate(['final_marks' => ['required', 'numeric', 'min:0'], 'decision' => ['required', 'in:confirmed,adjusted'], 'reason' => ['required', 'string', 'max:4000']]);
        $version = $workflow->moderate($componentId, $candidateId, (string) $data['final_marks'], $data['decision'], $data['reason'], $request->user());

        return back()->with('status', 'Moderation decision recorded as result version '.$version.'.');
    }

    public function uploadScript(Tenant $tenant, int $sessionId, Request $request, ExamComponentWorkflow $workflow): RedirectResponse
    {
        $data = $request->validate(['candidate_id' => ['required', 'integer', 'min:1'], 'component_id' => ['nullable', 'integer', 'min:1'], 'file' => ['required', 'file', 'max:10240', 'mimes:pdf,jpg,jpeg,png,webp,txt,doc,docx,odt']]);
        $script = $workflow->uploadScript($sessionId, isset($data['component_id']) ? (int) $data['component_id'] : null, (int) $data['candidate_id'], $data['file'], $request->user());

        return back()->with('status', $script->scan_status === 'clean' ? 'Script passed scanning and is available only to assigned exam roles.' : 'Script remains quarantined because scanning did not approve it.');
    }

    public function downloadScript(Tenant $tenant, int $scriptId, Request $request, ExamComponentWorkflow $workflow): BinaryFileResponse
    {
        [$path, $name, $mime] = $workflow->scriptDownload($scriptId, $request->user());
        $response = response()->download($path, $name, ['Content-Type' => $mime, 'X-Content-Type-Options' => 'nosniff']);
        $response->setPrivate();
        $response->headers->addCacheControlDirective('no-store');
        $response->headers->addCacheControlDirective('max-age', '0');

        return $response;
    }
}
