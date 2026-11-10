<?php

namespace App\Http\Controllers\Workspace;

use App\Domain\Academic\AcademicAccess;
use App\Domain\Audit\AuditRecorder;
use App\Domain\Examinations\ExamCandidateGovernanceWorkflow;
use App\Domain\Tenancy\TenantContext;
use App\Http\Controllers\Controller;
use App\Models\ExamCandidate;
use App\Models\Tenant;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

final class ExamCandidateGovernanceController extends Controller
{
    public function student(Tenant $tenant, Request $request, TenantContext $context): Response
    {
        $membership = $context->membership();
        abort_unless($membership->hasPermission('exams.student.view-own'), 403);
        $studentId = DB::table('sis_students')->where('tenant_id', $membership->tenant_id)->where('institution_id', $membership->institution_id)->where('user_id', $request->user()->getKey())->where('status', 'active')->value('id');
        $candidates = $studentId === null ? collect() : DB::table('exam_candidates as candidates')->join('exam_cycles as cycles', function ($join): void {
            $join->on('cycles.id', '=', 'candidates.cycle_id')->on('cycles.tenant_id', '=', 'candidates.tenant_id');
        })->where('candidates.tenant_id', $membership->tenant_id)->where('candidates.student_id', $studentId)->where('candidates.status', 'registered')->whereNull('candidates.withdrawn_at')
            ->orderByDesc('cycles.starts_on')->limit(100)->get(['candidates.id', 'candidates.candidate_number', 'candidates.cycle_id', 'cycles.title as cycle_title']);
        $candidateIds = $candidates->pluck('id')->all();
        $requests = $candidateIds === [] ? collect() : DB::table('exam_accommodation_requests')->where('tenant_id', $membership->tenant_id)->whereIn('candidate_id', $candidateIds)->orderByDesc('id')->limit(100)->get(['id', 'candidate_id', 'accommodation_type', 'status', 'review_reason', 'created_at']);
        $exceptions = $candidateIds === [] ? collect() : DB::table('exam_eligibility_exceptions')->where('tenant_id', $membership->tenant_id)->whereIn('candidate_id', $candidateIds)->orderByDesc('id')->limit(100)->get(['id', 'candidate_id', 'cycle_id', 'rule_key', 'student_reason', 'status', 'review_reason', 'created_at']);
        $documents = $candidateIds === [] ? collect() : DB::table('exam_admit_documents as docs')->join('exam_sessions as sessions', function ($join): void {
            $join->on('sessions.id', '=', 'docs.session_id')->on('sessions.tenant_id', '=', 'docs.tenant_id');
        })->where('docs.tenant_id', $membership->tenant_id)->whereIn('docs.candidate_id', $candidateIds)->whereNull('docs.revoked_at')->orderByDesc('docs.issued_at')->limit(100)->get(['docs.id', 'docs.safe_snapshot', 'docs.issued_at', 'docs.expires_at', 'sessions.title as session_title']);

        return Inertia::render('workspace/examinations/candidate-governance', [
            'mode' => 'student', 'tenant' => ['slug' => $tenant->slug], 'candidates' => $candidates,
            'accommodationRequests' => $requests, 'eligibilityExceptions' => $exceptions, 'admitDocuments' => $documents,
            'canRequestAccommodation' => $membership->hasPermission('exams.accommodation.request-own'), 'canRequestException' => true,
        ]);
    }

    public function index(Tenant $tenant, Request $request, TenantContext $context, AcademicAccess $access): Response
    {
        $membership = $context->membership();
        abort_unless($membership->hasPermission('exams.eligibility.manage') || $membership->hasPermission('exams.accommodation.manage') || $membership->hasPermission('exams.admit.issue'), 403);
        $tenantId = (int) $context->tenant()->getKey();
        $scope = fn ($query) => $query->where('institution_id', $membership->institution_id)->when($access->isCampusScoped(), fn ($q) => $q->where('home_campus_id', $access->campusId()));
        $students = DB::table('sis_students')->where('tenant_id', $tenantId)->where('institution_id', $membership->institution_id)->where('status', 'active')->when($access->isCampusScoped(), fn ($query) => $query->where('home_campus_id', $access->campusId()))->orderBy('student_number')->limit(250)->get(['id', 'student_number', 'legal_first_name', 'legal_last_name']);
        $candidates = DB::table('exam_candidates as candidates')->join('exam_cycles as cycles', function ($join): void {
            $join->on('cycles.id', '=', 'candidates.cycle_id')->on('cycles.tenant_id', '=', 'candidates.tenant_id');
        })->join('sis_students as students', function ($join): void {
            $join->on('students.id', '=', 'candidates.student_id')->on('students.tenant_id', '=', 'candidates.tenant_id');
        })->where('candidates.tenant_id', $tenantId)->where('cycles.institution_id', $membership->institution_id)->where('candidates.status', 'registered')->whereNull('candidates.withdrawn_at')
            ->when($access->isCampusScoped(), fn ($query) => $query->where(fn ($q) => $q->whereNull('cycles.campus_id')->orWhere('cycles.campus_id', $access->campusId())))
            ->orderBy('cycles.title')->orderBy('students.student_number')->limit(500)->get(['candidates.id', 'candidates.cycle_id', 'candidates.candidate_number', 'students.student_number', 'students.legal_first_name', 'students.legal_last_name', 'cycles.title as cycle_title']);
        $candidateIds = $candidates->pluck('id')->all();
        $sessions = DB::table('exam_sessions')->where('tenant_id', $tenantId)->whereIn('cycle_id', $candidates->pluck('cycle_id')->unique())->whereIn('status', ['published', 'active'])->when($access->isCampusScoped(), fn ($query) => $query->where('campus_id', $access->campusId()))->orderBy('starts_at_utc')->get(['id', 'cycle_id', 'title', 'starts_at_utc']);
        $canReviewAccommodations = $membership->hasPermission('exams.accommodation.manage');
        $accommodationRequests = $candidateIds === [] ? collect() : DB::table('exam_accommodation_requests')->where('tenant_id', $tenantId)->whereIn('candidate_id', $candidateIds)->orderByDesc('id')->limit(250)->get(['id', 'candidate_id', 'accommodation_type', 'extra_time_minutes', 'confidential_details', 'status', 'requested_by', 'review_reason', 'created_at'])->map(function (object $row) use ($canReviewAccommodations): array {
            return ['id' => (int) $row->id, 'candidate_id' => (int) $row->candidate_id, 'accommodation_type' => $row->accommodation_type, 'extra_time_minutes' => (int) $row->extra_time_minutes, 'confidential_details' => $canReviewAccommodations && $row->confidential_details !== null ? decrypt($row->confidential_details) : null, 'status' => $row->status, 'requested_by' => (int) $row->requested_by, 'review_reason' => $row->review_reason, 'created_at' => $row->created_at];
        });

        return Inertia::render('workspace/examinations/candidate-governance', [
            'mode' => 'review', 'tenant' => ['slug' => $tenant->slug], 'candidates' => $candidates, 'sessions' => $sessions,
            'accommodationRequests' => $accommodationRequests,
            'eligibilityExceptions' => $candidateIds === [] ? [] : DB::table('exam_eligibility_exceptions')->where('tenant_id', $tenantId)->whereIn('candidate_id', $candidateIds)->orderByDesc('id')->limit(250)->get(),
            'admitDocuments' => $candidateIds === [] ? [] : DB::table('exam_admit_documents')->where('tenant_id', $tenantId)->whereIn('candidate_id', $candidateIds)->orderByDesc('issued_at')->limit(250)->get(['id', 'candidate_id', 'session_id', 'revision', 'issued_at', 'expires_at', 'revoked_at']),
            'policy' => DB::table('exam_eligibility_policies')->where('tenant_id', $tenantId)->where('institution_id', $membership->institution_id)->first(),
            'canReviewAccommodations' => $canReviewAccommodations, 'canReviewEligibility' => $membership->hasPermission('exams.eligibility.manage'), 'canIssueDocuments' => $membership->hasPermission('exams.admit.issue'),
        ]);
    }

    public function savePolicy(Tenant $tenant, Request $request, TenantContext $context): RedirectResponse
    {
        abort_unless($context->membership()->hasPermission('exams.eligibility.manage'), 403);
        $data = $request->validate(['name' => ['required', 'string', 'max:120'], 'require_active_student' => ['required', 'boolean'], 'require_confirmed_enrollment' => ['required', 'boolean'], 'active' => ['required', 'boolean']]);
        $institutionId = (int) $context->membership()->institution_id;
        DB::transaction(function () use ($context, $institutionId, $data): void {
            DB::table('exam_eligibility_policies')->insertOrIgnore([...$data, 'tenant_id' => $context->tenant()->getKey(), 'institution_id' => $institutionId, 'created_at' => now(), 'updated_at' => now()]);
            DB::table('exam_eligibility_policies')->where('tenant_id', $context->tenant()->getKey())->where('institution_id', $institutionId)->update([...$data, 'updated_at' => now()]);
        }, 3);
        app(AuditRecorder::class)->record('exams.eligibility.policy.updated', $request->user(), (int) $context->tenant()->getKey(), null, ['institution_id' => $institutionId, 'require_active_student' => (bool) $data['require_active_student'], 'require_confirmed_enrollment' => (bool) $data['require_confirmed_enrollment'], 'active' => (bool) $data['active']]);

        return back()->with('success', 'Examination eligibility policy saved.');
    }

    public function requestAccommodation(Tenant $tenant, int $cycleId, int $candidateId, Request $request, ExamCandidateGovernanceWorkflow $workflow): RedirectResponse
    {
        $data = $request->validate(['accommodation_type' => ['required', Rule::in(['extra_time', 'reader', 'scribe', 'accessible_room', 'assistive_technology', 'other'])], 'extra_time_minutes' => ['required', 'integer', 'min:0', 'max:300'], 'confidential_details' => ['nullable', 'string', 'max:2000']]);
        $workflow->requestAccommodation($cycleId, $candidateId, $data, $request->user());

        return back()->with('success', 'Your confidential accommodation request was submitted for independent review.');
    }

    public function decideAccommodation(Tenant $tenant, int $requestId, Request $request, ExamCandidateGovernanceWorkflow $workflow): RedirectResponse
    {
        $record = DB::table('exam_accommodation_requests')->where('tenant_id', app(TenantContext::class)->tenant()->getKey())->where('id', $requestId)->first(['candidate_id']);
        abort_unless($record !== null, 404);
        $this->assertCandidateCampus((int) $record->candidate_id);
        $data = $request->validate(['decision' => ['required', Rule::in(['approved', 'rejected'])], 'reason' => ['required', 'string', 'max:1000'], 'expires_at' => ['nullable', 'date', 'after:now']]);
        abort_unless($data['decision'] !== 'approved' || $data['expires_at'] !== null, 422, 'Approved accommodations require a review expiry date.');
        $workflow->decideAccommodation($requestId, $data['decision'], $data['reason'], $data['expires_at'] ?? null, $request->user());

        return back()->with('success', 'Accommodation decision recorded.');
    }

    public function requestException(Tenant $tenant, int $cycleId, int $candidateId, Request $request, ExamCandidateGovernanceWorkflow $workflow): RedirectResponse
    {
        $data = $request->validate(['rule_key' => ['required', Rule::in(['confirmed_course_enrollment'])], 'reason' => ['required', 'string', 'min:20', 'max:2000']]);
        $workflow->requestEligibilityException($candidateId, $cycleId, $data['rule_key'], $data['reason'], $request->user());

        return back()->with('success', 'Eligibility exception request submitted for review.');
    }

    public function decideException(Tenant $tenant, int $exceptionId, Request $request, ExamCandidateGovernanceWorkflow $workflow): RedirectResponse
    {
        $record = DB::table('exam_eligibility_exceptions')->where('tenant_id', app(TenantContext::class)->tenant()->getKey())->where('id', $exceptionId)->first(['candidate_id']);
        abort_unless($record !== null, 404);
        $this->assertCandidateCampus((int) $record->candidate_id);
        $data = $request->validate(['decision' => ['required', Rule::in(['approved', 'rejected'])], 'reason' => ['required', 'string', 'max:1000'], 'expires_at' => ['nullable', 'date', 'after:now']]);
        abort_unless($data['decision'] !== 'approved' || $data['expires_at'] !== null, 422, 'Eligibility exceptions require an expiry date.');
        $workflow->decideEligibilityException($exceptionId, $data['decision'], $data['reason'], $data['expires_at'] ?? null, $request->user());

        return back()->with('success', 'Eligibility decision recorded.');
    }

    public function revokeException(Tenant $tenant, int $exceptionId, Request $request, ExamCandidateGovernanceWorkflow $workflow): RedirectResponse
    {
        $record = DB::table('exam_eligibility_exceptions')->where('tenant_id', app(TenantContext::class)->tenant()->getKey())->where('id', $exceptionId)->first(['candidate_id']);
        abort_unless($record !== null, 404);
        $this->assertCandidateCampus((int) $record->candidate_id);
        $data = $request->validate(['reason' => ['required', 'string', 'max:1000']]);
        $workflow->revokeEligibilityException($exceptionId, $data['reason'], $request->user());

        return back()->with('success', 'Eligibility exception revoked.');
    }

    public function issueAdmit(Tenant $tenant, int $sessionId, int $candidateId, Request $request, ExamCandidateGovernanceWorkflow $workflow): RedirectResponse
    {
        $session = DB::table('exam_sessions')->where('tenant_id', app(TenantContext::class)->tenant()->getKey())->where('id', $sessionId)->first(['campus_id']);
        abort_unless($session !== null, 404);
        app(AcademicAccess::class)->assertCampus((int) $session->campus_id);
        $document = $workflow->issueAdmitDocument($sessionId, $candidateId, $request->user());

        return back()->with('success', 'Admit document issued and made available to the registered student.');
    }

    public function revokeAdmit(Tenant $tenant, int $documentId, Request $request, ExamCandidateGovernanceWorkflow $workflow): RedirectResponse
    {
        $session = DB::table('exam_admit_documents as docs')->join('exam_sessions as sessions', function ($join): void {
            $join->on('sessions.id', '=', 'docs.session_id')->on('sessions.tenant_id', '=', 'docs.tenant_id');
        })->where('docs.tenant_id', app(TenantContext::class)->tenant()->getKey())->where('docs.id', $documentId)->first(['sessions.campus_id']);
        abort_unless($session !== null, 404);
        app(AcademicAccess::class)->assertCampus((int) $session->campus_id);
        $data = $request->validate(['reason' => ['required', 'string', 'max:1000']]);
        $workflow->revokeAdmitDocument($documentId, $data['reason'], $request->user());

        return back()->with('success', 'Admit document revoked.');
    }

    public function showOwnAdmit(Tenant $tenant, int $documentId, Request $request, ExamCandidateGovernanceWorkflow $workflow): Response
    {
        abort_unless(app(TenantContext::class)->membership()->hasPermission('exams.student.view-own'), 403);

        return Inertia::render('workspace/examinations/admit-document', ['tenant' => ['slug' => $tenant->slug], ...$workflow->ownAdmitDocument($documentId, $request->user())]);
    }

    public function verify(Request $request, ExamCandidateGovernanceWorkflow $workflow): RedirectResponse
    {
        $data = $request->validate(['verification_code' => ['required', 'string', 'min:32', 'max:96']]);

        return back()->with('status', $workflow->verifyAdmitDocument($data['verification_code']) ? 'This admit document is valid.' : 'This admit document could not be verified.');
    }

    private function assertCandidateCampus(int $candidateId): void
    {
        $candidate = ExamCandidate::query()->with('cycle')->findOrFail($candidateId);
        $campusId = $candidate->cycle->campus_id;
        app(AcademicAccess::class)->assertCampus($campusId === null ? null : (int) $campusId);
    }
}
