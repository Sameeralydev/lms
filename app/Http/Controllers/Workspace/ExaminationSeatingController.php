<?php

namespace App\Http\Controllers\Workspace;

use App\Domain\Examinations\ExamSeatingPlanWorkflow;
use App\Domain\Tenancy\TenantContext;
use App\Http\Controllers\Controller;
use App\Models\Tenant;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

final class ExaminationSeatingController extends Controller
{
    public function store(Request $request, Tenant $tenant, int $sessionId, TenantContext $context, ExamSeatingPlanWorkflow $workflow): RedirectResponse
    {
        $tenantId = (int) $context->tenant()->getKey();
        $data = $request->validate([
            'assignments' => ['required', 'array', 'min:1', 'max:2000'],
            'assignments.*.candidate_id' => ['required', 'integer', 'distinct', Rule::exists('exam_candidates', 'id')->where('tenant_id', $tenantId)],
            'assignments.*.room_id' => ['required', 'integer', Rule::exists('academic_rooms', 'id')->where('tenant_id', $tenantId)],
            'assignments.*.seat_number' => ['required', 'string', 'max:24', 'regex:/^[A-Za-z0-9._-]+$/'],
        ]);
        $revision = $workflow->createRevision($sessionId, $data['assignments'], $request->user());

        return back()->with('success', 'Seating plan revision '.$revision->revision.' was saved for review.');
    }

    public function submit(Request $request, Tenant $tenant, int $revisionId, ExamSeatingPlanWorkflow $workflow): RedirectResponse
    {
        $workflow->submit($revisionId, $request->user());

        return back()->with('success', 'Seating plan submitted for independent review.');
    }

    public function decide(Request $request, Tenant $tenant, int $revisionId, ExamSeatingPlanWorkflow $workflow): RedirectResponse
    {
        $data = $request->validate(['decision' => ['required', Rule::in(['approved', 'rejected'])], 'notes' => ['nullable', 'string', 'max:2000']]);
        $workflow->decide($revisionId, $data['decision'], $data['notes'] ?? null, $request->user());

        return back()->with('success', 'Seating plan review recorded.');
    }

    public function publish(Request $request, Tenant $tenant, int $revisionId, ExamSeatingPlanWorkflow $workflow): RedirectResponse
    {
        $workflow->publish($revisionId, $request->user());

        return back()->with('success', 'Reviewed seating plan is now published.');
    }
}
