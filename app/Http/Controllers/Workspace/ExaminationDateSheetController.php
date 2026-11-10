<?php

namespace App\Http\Controllers\Workspace;

use App\Domain\Examinations\ExamDateSheetWorkflow;
use App\Domain\Tenancy\TenantContext;
use App\Http\Controllers\Controller;
use App\Models\Tenant;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

final class ExaminationDateSheetController extends Controller
{
    public function store(Request $request, Tenant $tenant, int $cycleId, TenantContext $context, ExamDateSheetWorkflow $workflow): RedirectResponse
    {
        $tenantId = (int) $context->tenant()->getKey();
        $data = $request->validate([
            'session_ids' => ['required', 'array', 'min:1', 'max:200'],
            'session_ids.*' => ['required', 'integer', 'distinct', Rule::exists('exam_sessions', 'id')->where('tenant_id', $tenantId)],
        ]);
        $revision = $workflow->createRevision($cycleId, array_map('intval', $data['session_ids']), $request->user());

        return back()->with('success', 'Date-sheet revision '.$revision->revision.' was saved as a draft.');
    }

    public function submit(Request $request, Tenant $tenant, int $revisionId, ExamDateSheetWorkflow $workflow): RedirectResponse
    {
        $revision = $workflow->submitForReview($revisionId, $request->user());

        return back()->with('success', 'Date-sheet revision '.$revision->revision.' was submitted for independent review.');
    }

    public function decide(Request $request, Tenant $tenant, int $revisionId, ExamDateSheetWorkflow $workflow): RedirectResponse
    {
        $data = $request->validate(['decision' => ['required', Rule::in(['approved', 'rejected'])], 'notes' => ['nullable', 'string', 'max:2000']]);
        $revision = $workflow->decide($revisionId, $data['decision'], $data['notes'] ?? null, $request->user());

        return back()->with('success', 'Date-sheet revision '.$revision->revision.' was '.$revision->status.'.');
    }

    public function publish(Request $request, Tenant $tenant, int $revisionId, ExamDateSheetWorkflow $workflow): RedirectResponse
    {
        $revision = $workflow->publish($revisionId, $request->user());

        return back()->with('success', 'Date-sheet revision '.$revision->revision.' is now published.');
    }
}
