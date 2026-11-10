<?php

namespace App\Http\Controllers\Workspace;

use App\Domain\Examinations\ExamDeliveryWorkflow;
use App\Http\Controllers\Controller;
use App\Models\Tenant;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

final class ExamDeliveryController extends Controller
{
    public function index(Tenant $tenant, Request $request, ExamDeliveryWorkflow $workflow): Response
    {
        return Inertia::render('workspace/examinations/available', [
            'tenant' => ['slug' => $tenant->slug], 'exams' => $workflow->learnerExams($request->user()),
        ]);
    }

    public function start(Tenant $tenant, int $sessionId, Request $request, ExamDeliveryWorkflow $workflow): RedirectResponse
    {
        $attempt = $workflow->start($sessionId, $request->user());

        return redirect()->route('tenant.assessments.attempt', [$tenant, $attempt->getKey()]);
    }

    public function configure(Tenant $tenant, int $sessionId, Request $request, ExamDeliveryWorkflow $workflow): RedirectResponse
    {
        $data = $request->validate(['quiz_id' => ['required', 'integer', 'min:1'], 'paper_version_id' => ['required', 'integer', 'min:1']]);
        $workflow->configure($sessionId, (int) $data['quiz_id'], (int) $data['paper_version_id'], $request->user());

        return back()->with('success', 'Published examination delivery is bound to its approved paper and quiz snapshot.');
    }
}
