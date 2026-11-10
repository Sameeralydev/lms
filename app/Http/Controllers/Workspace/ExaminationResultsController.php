<?php

namespace App\Http\Controllers\Workspace;

use App\Domain\Examinations\ExaminationAppealEvidenceWorkflow;
use App\Domain\Examinations\ExaminationIncidentEvidenceWorkflow;
use App\Domain\Examinations\ExaminationResultService;
use App\Domain\Tenancy\TenantContext;
use App\Http\Controllers\Controller;
use App\Models\Tenant;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class ExaminationResultsController extends Controller
{
    public function index(Tenant $tenant, Request $request, TenantContext $context, ExaminationResultService $results): Response
    {
        $filters = $request->validate(['session_id' => ['nullable', 'integer', 'min:1']]);

        return Inertia::render('workspace/examinations/results', [
            'tenant' => ['slug' => $tenant->slug],
            'workspace' => $results->controllerWorkspace(isset($filters['session_id']) ? (int) $filters['session_id'] : null),
            'actorId' => (int) $request->user()->getKey(),
            'canExport' => $context->membership()->hasPermission('exams.analytics.view'),
            'canReportIncidents' => $context->membership()->hasPermission('exams.incidents.report'),
            'canReviewIncidents' => $context->membership()->hasPermission('exams.incidents.review'),
        ]);
    }

    public function export(Tenant $tenant, Request $request): StreamedResponse
    {
        $membership = app(TenantContext::class)->membership();
        abort_unless($membership->hasPermission('exams.analytics.view'), 403);
        $data = $request->validate(['session_id' => ['nullable', 'integer', 'min:1']]);
        $query = DB::table('exam_results')->join('exam_candidates', function ($join): void {
            $join->on('exam_candidates.id', '=', 'exam_results.candidate_id')->on('exam_candidates.tenant_id', '=', 'exam_results.tenant_id');
        })->join('exam_sessions', function ($join): void {
            $join->on('exam_sessions.id', '=', 'exam_results.session_id')->on('exam_sessions.tenant_id', '=', 'exam_results.tenant_id');
        })->where('exam_results.tenant_id', $tenant->getKey());
        if ($membership->campus_id !== null) {
            $query->where('exam_sessions.campus_id', $membership->campus_id);
        }
        if (isset($data['session_id'])) {
            $query->where('exam_results.session_id', (int) $data['session_id']);
        }
        $rows = $query->orderBy('exam_sessions.starts_at_utc')->orderBy('exam_candidates.candidate_number')->limit(5000)->get([
            'exam_candidates.candidate_number', 'exam_sessions.title', 'exam_results.status', 'exam_results.outcome',
            'exam_results.total_marks', 'exam_results.maximum_marks', 'exam_results.percentage', 'exam_results.version', 'exam_results.released_at',
        ]);

        return response()->streamDownload(static function () use ($rows): void {
            $stream = fopen('php://output', 'wb');
            if ($stream === false) {
                return;
            }
            fputcsv($stream, ['Candidate number', 'Examination session', 'Status', 'Outcome', 'Marks', 'Maximum marks', 'Percentage', 'Result version', 'Released at']);
            foreach ($rows as $row) {
                $candidateNumber = (string) $row->candidate_number;
                if (preg_match('/\A[=+@\-]/', $candidateNumber) === 1) {
                    $candidateNumber = "'".$candidateNumber;
                }
                fputcsv($stream, [$candidateNumber, $row->title, $row->status, $row->outcome, $row->total_marks, $row->maximum_marks, $row->percentage, $row->version, $row->released_at]);
            }
            fclose($stream);
        }, 'examination-results.csv', ['Content-Type' => 'text/csv; charset=UTF-8', 'Cache-Control' => 'private, no-store']);
    }

    public function exportOperational(Tenant $tenant, Request $request, ExaminationResultService $results): StreamedResponse
    {
        $filters = $request->validate(['session_id' => ['nullable', 'integer', 'min:1']]);
        $rows = $results->exportOperationalReports($request->user(), isset($filters['session_id']) ? (int) $filters['session_id'] : null);

        return response()->streamDownload(static function () use ($rows): void {
            $stream = fopen('php://output', 'wb');
            if ($stream === false) {
                return;
            }
            fputcsv($stream, ['Session', 'Campus', 'Subject', 'Status', 'Starts at UTC', 'Registered', 'Present', 'Absent', 'Marking assigned', 'Marking submitted', 'Moderation backlog', 'Results pending', 'Results released', 'Open rechecks', 'Open appeals', 'Open incidents', 'Date sheet status', 'Seats allocated', 'Seat capacity', 'Seat utilization percent', 'Invigilators assigned', 'Invigilators accepted', 'Eligibility exceptions pending', 'Eligibility exceptions approved', 'Approved accommodations', 'Active admit documents', 'Retake registrations', 'Makeup registrations']);
            foreach ($rows as $row) {
                $safe = static fn (string $value): string => preg_match('/\A[=+@\-]/u', $value) === 1 ? "'".$value : $value;
                fputcsv($stream, [
                    $safe((string) $row['session']), $safe((string) $row['campus']), $safe((string) $row['subject']), $safe((string) $row['status']), $row['startsAt'],
                    $row['registered'], $row['present'], $row['absent'], $row['markingAssigned'], $row['markingSubmitted'], $row['moderationBacklog'],
                    $row['resultsPending'], $row['resultsReleased'], $row['openRechecks'], $row['openAppeals'], $row['openIncidents'],
                    $safe((string) $row['dateSheetStatus']), $row['seatsAllocated'], $row['seatCapacity'], $row['seatUtilizationPercent'],
                    $row['invigilatorsAssigned'], $row['invigilatorsAccepted'], $row['eligibilityExceptionsPending'], $row['eligibilityExceptionsApproved'],
                    $row['approvedAccommodations'], $row['activeAdmitDocuments'], $row['retakeRegistrations'], $row['makeupRegistrations'],
                ]);
            }
            fclose($stream);
        }, 'examination-operations.csv', ['Content-Type' => 'text/csv; charset=UTF-8', 'Cache-Control' => 'private, no-store']);
    }

    public function phase14Contract(Tenant $tenant, int $resultId, ExaminationResultService $results): JsonResponse
    {
        return response()->json(['data' => $results->phase14Contract($resultId)])->header('Cache-Control', 'private, no-store');
    }

    public function myResults(Tenant $tenant, Request $request, ExaminationResultService $results): Response
    {
        abort_unless(app(TenantContext::class)->membership()->hasPermission('exams.student.view-own'), 403);

        return Inertia::render('workspace/examinations/my-results', [
            'tenant' => ['slug' => $tenant->slug],
            'workspace' => $results->studentWorkspace($request->user()),
        ]);
    }

    public function savePolicy(Tenant $tenant, int $sessionId, Request $request, ExaminationResultService $results): RedirectResponse
    {
        $data = $request->validate([
            'written_weight' => ['required', 'numeric', 'min:0', 'max:100'],
            'pass_percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'recheck_window_days' => ['required', 'integer', 'between:1,90'],
            'components' => ['present', 'array', 'max:100'],
            'components.*.component_id' => ['required', 'integer', 'min:1'],
            'components.*.weight_percent' => ['required', 'numeric', 'gt:0', 'max:100'],
        ]);
        $results->savePolicy($sessionId, (string) $data['written_weight'], isset($data['pass_percent']) ? (string) $data['pass_percent'] : null, (int) $data['recheck_window_days'], $data['components'], $request->user());

        return back()->with('status', 'A new immutable examination result policy version was saved.');
    }

    public function compile(Tenant $tenant, int $sessionId, Request $request, ExaminationResultService $results): RedirectResponse
    {
        $totals = $results->compileSession($sessionId, $request->user());

        return back()->with('status', sprintf('Compiled %d candidate result snapshots; %d ready for approval, %d pending, %d absent, %d unchanged.', $totals['compiled'], $totals['ready'], $totals['pending'], $totals['absent'], $totals['unchanged']));
    }

    public function approve(Tenant $tenant, int $resultId, Request $request, ExaminationResultService $results): RedirectResponse
    {
        $data = $request->validate(['checklist' => ['required', 'array'], 'checklist.scores_verified' => ['required', 'accepted'], 'checklist.attendance_verified' => ['required', 'accepted'], 'checklist.paper_version_verified' => ['required', 'accepted'], 'reason' => ['required', 'string', 'max:2000']]);
        $results->approve($resultId, $data['checklist'], $data['reason'], $request->user());

        return back()->with('status', 'The compiled result was independently approved.');
    }

    public function release(Tenant $tenant, int $resultId, Request $request, ExaminationResultService $results): RedirectResponse
    {
        $results->release($resultId, $request->user());

        return back()->with('status', 'The approved result was released and a notification was queued.');
    }

    public function requestRecheck(Tenant $tenant, int $resultId, Request $request, ExaminationResultService $results): RedirectResponse
    {
        $data = $request->validate(['request_type' => ['required', 'in:clerical,calculation,review,appeal'], 'reason' => ['required', 'string', 'max:4000']]);
        $results->requestRecheck($resultId, $data['request_type'], $data['reason'], $request->user());

        return back()->with('status', $data['request_type'] === 'appeal' ? 'Your examination appeal was received for independent review.' : 'Your recheck request was received for independent review.');
    }

    public function assignRecheck(Tenant $tenant, int $requestId, Request $request, ExaminationResultService $results): RedirectResponse
    {
        $data = $request->validate(['reviewer_id' => ['required', 'integer', 'min:1']]);
        $results->assignRecheck($requestId, (int) $data['reviewer_id'], $request->user());

        return back()->with('status', 'The recheck was assigned to an eligible independent reviewer.');
    }

    public function decideRecheck(Tenant $tenant, int $requestId, Request $request, ExaminationResultService $results): RedirectResponse
    {
        $data = $request->validate(['decision' => ['required', 'in:upheld,adjusted,denied'], 'findings' => ['required', 'string', 'max:5000'], 'proposed_marks' => ['nullable', 'numeric', 'min:0', 'max:100']]);
        $results->decideRecheck($requestId, $data['decision'], $data['findings'], isset($data['proposed_marks']) ? (string) $data['proposed_marks'] : null, $request->user());

        return back()->with('status', $data['decision'] === 'adjusted' ? 'Adjustment recommendation recorded and sent for independent approval.' : 'The recheck decision was recorded and the student was notified.');
    }

    public function transitionAppeal(Tenant $tenant, int $requestId, Request $request, ExaminationResultService $results): RedirectResponse
    {
        $data = $request->validate(['action' => ['required', 'in:escalate,close,reopen'], 'reason' => ['required', 'string', 'max:2000']]);
        $results->transitionAppeal($requestId, $data['action'], $data['reason'], $request->user());

        return back()->with('status', 'The appeal lifecycle transition was recorded and a notification was queued.');
    }

    public function uploadAppealEvidence(Tenant $tenant, int $requestId, Request $request, ExaminationAppealEvidenceWorkflow $evidence): RedirectResponse
    {
        $data = $request->validate(['evidence' => ['required', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:10240']]);
        abort_unless($data['evidence'] instanceof UploadedFile, 422);
        $evidenceId = $evidence->upload($requestId, $data['evidence'], $request->user());

        return back()->with('status', 'Appeal evidence was placed in private quarantine and is unavailable until scanning approves it.')->with('evidence_id', $evidenceId);
    }

    public function downloadAppealEvidence(Tenant $tenant, int $evidenceId, Request $request, ExaminationAppealEvidenceWorkflow $evidence): BinaryFileResponse
    {
        return $evidence->download($evidenceId, $request->user());
    }

    public function approveRecheck(Tenant $tenant, int $requestId, Request $request, ExaminationResultService $results): RedirectResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:2000']]);
        $version = $results->approveRecheck($requestId, $data['reason'], $request->user());

        return back()->with('status', 'Recheck amendment approved as result version '.$version.'. Release it to publish the new version.');
    }

    public function reportIncident(Tenant $tenant, Request $request, ExaminationResultService $results): RedirectResponse
    {
        $data = $request->validate(['session_id' => ['required', 'integer', 'min:1'], 'candidate_id' => ['nullable', 'integer', 'min:1'], 'incident_type' => ['required', 'in:technical,attendance,paper_access,interruption,complaint,other'], 'severity' => ['required', 'in:standard,urgent'], 'description' => ['required', 'string', 'max:5000']]);
        $incidentId = $results->reportIncident((int) $data['session_id'], isset($data['candidate_id']) ? (int) $data['candidate_id'] : null, $data['incident_type'], $data['severity'], $data['description'], $request->user());

        return back()->with('status', 'The incident was recorded for restricted examination review. An allegation does not establish misconduct.')->with('incident_id', $incidentId);
    }

    public function uploadIncidentEvidence(Tenant $tenant, int $incidentId, Request $request, ExaminationIncidentEvidenceWorkflow $evidence): RedirectResponse
    {
        $data = $request->validate(['evidence' => ['required', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:10240']]);
        abort_unless($data['evidence'] instanceof UploadedFile, 422);
        $evidenceId = $evidence->upload($incidentId, $data['evidence'], $request->user());

        return back()->with('status', 'Evidence was quarantined for malware scanning and remains unavailable until clean.')->with('evidence_id', $evidenceId);
    }

    public function downloadIncidentEvidence(Tenant $tenant, int $evidenceId, Request $request, ExaminationIncidentEvidenceWorkflow $evidence): BinaryFileResponse
    {
        return $evidence->download($evidenceId, $request->user());
    }

    public function reviewIncident(Tenant $tenant, int $incidentId, Request $request, ExaminationResultService $results): RedirectResponse
    {
        $data = $request->validate(['decision' => ['required', 'in:resolved,follow_up,no_finding,refer'], 'notes' => ['required', 'string', 'max:5000']]);
        $results->reviewIncident($incidentId, $data['decision'], $data['notes'], $request->user());

        return back()->with('status', 'Incident review was recorded without presuming misconduct.');
    }

    public function transitionIncident(Tenant $tenant, int $incidentId, Request $request, ExaminationResultService $results): RedirectResponse
    {
        $data = $request->validate([
            'action' => ['required', 'in:assign,escalate,investigate,resolve,close,reopen'],
            'notes' => ['required', 'string', 'max:5000'],
            'assignee_id' => ['nullable', 'integer', 'min:1'],
        ]);
        $results->transitionIncident($incidentId, $data['action'], $data['notes'], isset($data['assignee_id']) ? (int) $data['assignee_id'] : null, $request->user());

        return back()->with('status', 'Incident lifecycle update saved.');
    }
}
