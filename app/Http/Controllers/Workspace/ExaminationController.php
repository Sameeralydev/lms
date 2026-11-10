<?php

namespace App\Http\Controllers\Workspace;

use App\Domain\Academic\AcademicAccess;
use App\Domain\Audit\AuditRecorder;
use App\Domain\Examinations\ExamDateSheetWorkflow;
use App\Domain\Examinations\ExamOperationsWorkflow;
use App\Domain\Examinations\ExamSeatingPlanWorkflow;
use App\Domain\Tenancy\TenantContext;
use App\Http\Controllers\Controller;
use App\Models\AcademicCourse;
use App\Models\AcademicPeriod;
use App\Models\AcademicRoom;
use App\Models\Campus;
use App\Models\ExamCycle;
use App\Models\ExamSession;
use App\Models\Institution;
use App\Models\QuizQuestionVersion;
use App\Models\SisStudent;
use App\Models\Tenant;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

final class ExaminationController extends Controller
{
    public function index(Request $request, TenantContext $context, AcademicAccess $access, ExamDateSheetWorkflow $dateSheets, ExamSeatingPlanWorkflow $seatingPlans): Response
    {
        $tenantId = (int) $context->tenant()->getKey();
        $canRegister = $context->membership()->hasPermission('exams.candidates.manage');
        $canManageAccommodations = $context->membership()->hasPermission('exams.accommodation.manage');
        $canRecordAttendance = $context->membership()->hasPermission('exams.attendance.record') || $context->membership()->hasPermission('exams.manage');
        $cycles = ExamCycle::query()
            ->withCount(['sessions' => fn ($query) => $query->when($access->isCampusScoped(), fn ($q) => $q->where('campus_id', $access->campusId())), 'candidates'])
            ->with(['period:id,name,code', 'sessions' => fn ($query) => $query->with('course:id,title,code')->orderBy('starts_at_utc')])
            ->when($canRegister || $canManageAccommodations || $canRecordAttendance, fn ($query) => $query->with(['candidates' => fn ($candidates) => $candidates->with('student:id,student_number,legal_first_name,legal_last_name')->orderBy('candidate_number')]))
            ->when($access->isCampusScoped(), fn ($query) => $query->where('campus_id', $access->campusId()))
            ->when($request->filled('status'), fn ($query) => $query->where('status', $request->string('status')))
            ->orderByDesc('created_at')->paginate(15)->withQueryString();

        $sessionIds = $cycles->getCollection()->flatMap(fn (ExamCycle $cycle) => $cycle->sessions->pluck('id'))->all();
        $dateSheetData = $cycles->getCollection()->mapWithKeys(fn (ExamCycle $cycle): array => [(int) $cycle->getKey() => $dateSheets->forCycle((int) $cycle->getKey())])->all();
        // Online sessions have no physical seating plan. Avoid issuing the
        // candidate, room, allocation and revision reads for those sessions.
        $seatingPlanData = $cycles->getCollection()->flatMap(fn (ExamCycle $cycle) => $cycle->sessions)
            ->filter(fn (ExamSession $session): bool => $session->mode !== 'online')
            ->mapWithKeys(fn (ExamSession $session): array => [(int) $session->getKey() => $seatingPlans->forSession((int) $session->getKey())])->all();
        $paperVersions = DB::table('exam_papers as papers')
            ->join('exam_paper_versions as versions', function ($join): void {
                $join->on('versions.paper_id', '=', 'papers.id')->on('versions.tenant_id', '=', 'papers.tenant_id');
            })
            ->where('papers.tenant_id', $tenantId)->whereIn('papers.session_id', $sessionIds)
            ->orderByDesc('versions.version_number')
            ->get(['papers.session_id', 'versions.id', 'versions.version_number', 'versions.set_code', 'versions.title', 'versions.total_marks', 'versions.duration_minutes', 'versions.status']);
        $deliveryPaperVersions = DB::table('exam_papers as papers')
            ->join('exam_paper_versions as versions', function ($join): void {
                $join->on('versions.paper_id', '=', 'papers.id')->on('versions.tenant_id', '=', 'papers.tenant_id');
            })
            ->where('papers.tenant_id', $tenantId)->whereIn('papers.session_id', $sessionIds)->where('versions.status', 'published')
            ->whereColumn('versions.version_number', 'papers.current_version')->whereColumn('versions.set_code', 'papers.current_set_code')
            ->get(['papers.session_id', 'versions.id', 'versions.version_number', 'versions.set_code', 'versions.title', 'versions.total_marks', 'versions.duration_minutes', 'versions.status']);
        $questionOptions = QuizQuestionVersion::query()->where('status', 'active')->whereHas('question', fn ($question) => $question->where('status', 'active')->whereHas('bank', fn ($bank) => $bank->where('institution_id', Institution::query()->where('tenant_id', $tenantId)->value('id'))->where('status', 'active')))
            ->when($access->isCampusScoped(), fn ($query) => $query->whereHas('question.bank', fn ($bank) => $bank->where(fn ($scope) => $scope->whereNull('campus_id')->orWhere('campus_id', $access->campusId())->orWhere('sharing_scope', 'institution'))))
            ->with('question.bank:id,name,institution_id')->orderByDesc('activated_at')->limit(300)->get(['id', 'question_id', 'version_number', 'question_type', 'topic', 'difficulty', 'content', 'default_points']);
        $institutionId = (int) Institution::query()->where('tenant_id', $tenantId)->value('id');
        $deliveryQuizzes = DB::table('quizzes as quizzes')->join('quiz_versions as versions', function ($join): void {
            $join->on('versions.quiz_id', '=', 'quizzes.id')->on('versions.tenant_id', '=', 'quizzes.tenant_id')->on('versions.version_number', '=', 'quizzes.current_version');
        })->join('course_offerings as offerings', function ($join): void {
            $join->on('offerings.id', '=', 'quizzes.offering_id')->on('offerings.tenant_id', '=', 'quizzes.tenant_id');
        })->where('quizzes.tenant_id', $tenantId)->where('offerings.institution_id', $institutionId)
            ->whereIn('quizzes.status', ['published', 'scheduled'])->where('versions.status', 'published')
            ->whereNotExists(fn ($query) => $query->selectRaw('1')->from('quiz_question_pools as pools')->whereColumn('pools.tenant_id', 'versions.tenant_id')->whereColumn('pools.quiz_version_id', 'versions.id'))
            ->orderBy('versions.title')->limit(300)->get(['quizzes.id', 'versions.title', 'offerings.academic_course_id']);
        $sessionDeliveries = $sessionIds === [] ? collect() : DB::table('exam_session_deliveries as deliveries')
            ->join('quiz_versions as versions', function ($join): void {
                $join->on('versions.id', '=', 'deliveries.quiz_version_id')->on('versions.tenant_id', '=', 'deliveries.tenant_id');
            })
            ->where('deliveries.tenant_id', $tenantId)->whereIn('deliveries.session_id', $sessionIds)
            ->get(['deliveries.session_id', 'deliveries.quiz_id', 'deliveries.quiz_version_id', 'deliveries.paper_version_id', 'versions.title']);
        $staffOptions = DB::table('tenant_memberships as memberships')->join('users', 'users.id', '=', 'memberships.user_id')
            ->where('memberships.tenant_id', $tenantId)->where('memberships.institution_id', $institutionId)->where('memberships.status', 'active')
            ->when($access->isCampusScoped(), fn ($query) => $query->where(fn ($scope) => $scope->whereNull('memberships.campus_id')->orWhere('memberships.campus_id', $access->campusId())))
            ->orderBy('users.name')->limit(200)->get(['users.id', 'users.name']);
        $invigilatorAssignments = $sessionIds === [] ? collect() : DB::table('exam_invigilator_assignments as assignments')->join('users', 'users.id', '=', 'assignments.user_id')
            ->where('assignments.tenant_id', $tenantId)->whereIn('assignments.session_id', $sessionIds)->where('assignments.status', 'assigned')
            ->orderBy('users.name')->get(['assignments.session_id', 'assignments.user_id', 'assignments.duty_role', 'users.name']);
        $myInvigilatorAssignments = DB::table('exam_invigilator_assignments as assignments')
            ->join('exam_sessions as sessions', function ($join): void {
                $join->on('sessions.id', '=', 'assignments.session_id')->on('sessions.tenant_id', '=', 'assignments.tenant_id');
            })->join('exam_cycles as cycles', function ($join): void {
                $join->on('cycles.id', '=', 'sessions.cycle_id')->on('cycles.tenant_id', '=', 'sessions.tenant_id');
            })->where('assignments.tenant_id', $tenantId)->where('assignments.user_id', $request->user()->getKey())
            ->when($access->isCampusScoped(), fn ($query) => $query->where('sessions.campus_id', $access->campusId()))
            ->orderBy('sessions.starts_at_utc')->limit(100)
            ->get(['assignments.id', 'assignments.status', 'assignments.accepted_at', 'assignments.duty_role', 'sessions.title as session_title', 'sessions.starts_at_utc', 'sessions.campus_id', 'cycles.title as cycle_title']);
        $attendanceRecords = $sessionIds === [] ? collect() : DB::table('exam_attendance_records as attendance')->join('exam_candidates as candidates', function ($join): void {
            $join->on('candidates.id', '=', 'attendance.candidate_id')->on('candidates.tenant_id', '=', 'attendance.tenant_id');
        })->join('sis_students as students', function ($join): void {
            $join->on('students.id', '=', 'candidates.student_id')->on('students.tenant_id', '=', 'candidates.tenant_id');
        })->where('attendance.tenant_id', $tenantId)->whereIn('attendance.session_id', $sessionIds)
            ->get(['attendance.session_id', 'attendance.candidate_id', 'attendance.attendance_status', 'attendance.revision', 'students.student_number']);

        return Inertia::render('workspace/academics/examinations/index', [
            'cycles' => $cycles,
            'dateSheets' => $dateSheetData,
            'seatingPlans' => $seatingPlanData,
            'periods' => AcademicPeriod::query()->where('tenant_id', $tenantId)->whereIn('status', ['active', 'published'])->orderByDesc('starts_on')->get(['id', 'name', 'code']),
            'sourceCycles' => ExamCycle::query()->where('tenant_id', $tenantId)->where('institution_id', $institutionId)->whereIn('status', ['completed', 'closed'])->whereNotIn('category', ['retake', 'makeup'])->when($access->isCampusScoped(), fn ($query) => $query->where(fn ($scope) => $scope->whereNull('campus_id')->orWhere('campus_id', $access->campusId())))->orderByDesc('ends_on')->limit(100)->get(['id', 'code', 'title', 'category', 'ends_on']),
            'campuses' => Campus::query()->where('tenant_id', $tenantId)->where('status', 'active')->when($access->isCampusScoped(), fn ($q) => $q->whereKey($access->campusId()))->orderBy('name')->get(['id', 'name']),
            'courses' => AcademicCourse::query()->where('tenant_id', $tenantId)->where('institution_id', Institution::query()->where('tenant_id', $tenantId)->value('id'))->where('status', 'active')->orderBy('title')->get(['id', 'title', 'code']),
            'rooms' => AcademicRoom::query()->where('tenant_id', $tenantId)->where('status', 'active')->orderBy('name')->get(['id', 'campus_id', 'name', 'code', 'capacity']),
            'students' => $canRegister ? SisStudent::query()->where('tenant_id', $tenantId)->where('institution_id', Institution::query()->where('tenant_id', $tenantId)->value('id'))->where('status', 'active')->when($access->isCampusScoped(), fn ($q) => $q->where('home_campus_id', $access->campusId()))->orderBy('student_number')->limit(100)->get(['id', 'student_number', 'legal_first_name', 'legal_last_name']) : [],
            'filters' => $request->only('status'),
            'categories' => ['unit_test', 'class_test', 'monthly', 'midterm', 'final', 'term_end', 'annual', 'practical', 'laboratory', 'oral', 'viva', 'project', 'mock', 'supplementary', 'makeup', 'retake', 'institution_defined'],
            'canManage' => $context->membership()->hasPermission('exams.manage'),
            'canApprove' => $context->membership()->hasPermission('exams.approve'),
            'canPublishExams' => $context->membership()->hasPermission('exams.publish'),
            'canRegister' => $canRegister,
            'paperVersions' => $paperVersions,
            'deliveryPaperVersions' => $deliveryPaperVersions,
            'deliveryQuizzes' => $deliveryQuizzes,
            'sessionDeliveries' => $sessionDeliveries,
            'canConfigureDelivery' => $context->membership()->hasPermission('exams.manage'),
            'questionOptions' => $questionOptions,
            'canManagePapers' => $context->membership()->hasPermission('exams.paper.manage'),
            'canReviewPapers' => $context->membership()->hasPermission('exams.paper.review'),
            'canPublishPapers' => $context->membership()->hasPermission('exams.paper.publish'),
            'canPreviewPapers' => $context->membership()->hasPermission('exams.paper.confidential.view'),
            'staffOptions' => $staffOptions,
            'invigilatorAssignments' => $invigilatorAssignments,
            'myInvigilatorAssignments' => $myInvigilatorAssignments,
            'attendanceRecords' => $attendanceRecords,
            'canManageInvigilators' => $context->membership()->hasPermission('exams.invigilators.manage'),
            'canRecordAttendance' => $canRecordAttendance,
            'canManageAccommodations' => $canManageAccommodations,
        ]);
    }

    public function storeCycle(Request $request, TenantContext $context, AcademicAccess $access, AuditRecorder $audit): RedirectResponse
    {
        $tenantId = (int) $context->tenant()->getKey();
        $data = $request->validate([
            'academic_period_id' => ['required', 'integer', Rule::exists('academic_periods', 'id')->where('tenant_id', $tenantId)],
            'campus_id' => ['nullable', 'integer', Rule::exists('campuses', 'id')->where('tenant_id', $tenantId)],
            'code' => ['required', 'string', 'max:48', 'regex:/^[A-Za-z0-9][A-Za-z0-9._-]*$/', Rule::unique('exam_cycles', 'code')->where('tenant_id', $tenantId)],
            'title' => ['required', 'string', 'max:180'],
            'category' => ['required', Rule::in(['unit_test', 'class_test', 'monthly', 'midterm', 'final', 'term_end', 'annual', 'practical', 'laboratory', 'oral', 'viva', 'project', 'mock', 'supplementary', 'makeup', 'retake', 'institution_defined'])],
            'source_cycle_id' => ['nullable', 'integer', Rule::exists('exam_cycles', 'id')->where('tenant_id', $tenantId)],
            'registration_opens_on' => ['nullable', 'date'],
            'registration_closes_on' => ['nullable', 'date', 'after_or_equal:registration_opens_on'],
            'starts_on' => ['nullable', 'date'],
            'ends_on' => ['nullable', 'date', 'after_or_equal:starts_on'],
        ]);
        $period = AcademicPeriod::query()->with('academicYear')->findOrFail($data['academic_period_id']);
        abort_unless(in_array($period->status, ['active', 'published'], true), 422, 'Examination cycles require an active academic period.');
        $access->assertCampus(isset($data['campus_id']) ? (int) $data['campus_id'] : null);
        if (isset($data['starts_on']) && ($data['starts_on'] < Carbon::parse($period->starts_on)->toDateString() || (isset($data['ends_on']) && $data['ends_on'] > Carbon::parse($period->ends_on)->toDateString()))) {
            throw ValidationException::withMessages(['starts_on' => 'Cycle dates must remain within the selected academic period.']);
        }
        $institutionId = (int) $period->academicYear->institution_id;
        $institution = Institution::query()->where('tenant_id', $tenantId)->findOrFail($institutionId);
        if (in_array($data['category'], ['retake', 'makeup'], true)) {
            if (! isset($data['source_cycle_id'])) {
                throw ValidationException::withMessages(['source_cycle_id' => 'Retake and makeup cycles must reference a completed or closed source examination cycle.']);
            }
            $sourceCycle = ExamCycle::query()->where('tenant_id', $tenantId)->findOrFail($data['source_cycle_id']);
            abort_unless((int) $sourceCycle->institution_id === $institutionId, 422, 'The source examination must belong to the same institution.');
            $access->assertCampus($sourceCycle->campus_id === null ? null : (int) $sourceCycle->campus_id);
            abort_unless(in_array($sourceCycle->status, ['completed', 'closed'], true) && ! in_array($sourceCycle->category, ['retake', 'makeup'], true), 422, 'The source examination must be a completed standard examination cycle.');
        } elseif (isset($data['source_cycle_id'])) {
            throw ValidationException::withMessages(['source_cycle_id' => 'Only a retake or makeup cycle can reference a source examination.']);
        }
        if (isset($data['campus_id'])) {
            Campus::query()->where('tenant_id', $tenantId)->where('institution_id', $institutionId)->findOrFail($data['campus_id']);
        }
        $cycle = ExamCycle::query()->create([...$data, 'institution_id' => $institution->getKey(), 'created_by' => $request->user()->getKey(), 'status' => 'draft']);
        $audit->record('exams.cycle.created', $request->user(), $tenantId, $cycle, ['code' => $cycle->code, 'category' => $cycle->category, 'source_cycle_id' => $cycle->source_cycle_id === null ? null : (int) $cycle->source_cycle_id]);

        return back()->with('success', 'Examination cycle created as a draft.');
    }

    public function transition(Request $request, Tenant $tenant, int $cycleId, TenantContext $context, AcademicAccess $access, AuditRecorder $audit): RedirectResponse
    {
        $data = $request->validate(['to' => ['required', Rule::in(['review', 'approved', 'published', 'active', 'completed', 'closed', 'archived'])]]);
        $cycle = ExamCycle::query()->findOrFail($cycleId);
        $access->assertCampus($cycle->campus_id === null ? null : (int) $cycle->campus_id);
        $this->assertCyclePermission($context, $cycle, (string) $data['to']);
        $allowed = ['draft' => ['review'], 'review' => ['draft', 'approved'], 'approved' => ['review', 'published'], 'published' => ['active', 'closed'], 'active' => ['completed'], 'completed' => ['closed'], 'closed' => ['archived']];
        if (! in_array($data['to'], $allowed[$cycle->status] ?? [], true)) {
            throw ValidationException::withMessages(['to' => 'That examination cycle status transition is not permitted.']);
        }
        DB::transaction(function () use ($cycle, $data, $request, $context, $audit): void {
            $locked = ExamCycle::query()->lockForUpdate()->findOrFail($cycle->getKey());
            $from = $locked->status;
            $transitions = ['draft' => ['review'], 'review' => ['draft', 'approved'], 'approved' => ['review', 'published'], 'published' => ['active', 'closed'], 'active' => ['completed'], 'completed' => ['closed'], 'closed' => ['archived']];
            if (! in_array($data['to'], $transitions[$from] ?? [], true)) {
                throw ValidationException::withMessages(['to' => 'That examination cycle status transition is no longer valid.']);
            }
            $locked->status = $data['to'];
            if ($locked->status === 'published') {
                $locked->published_at = now();
            }
            if ($locked->status === 'closed') {
                $locked->closed_at = now();
            }
            $locked->save();
            if ($locked->status === 'published') {
                ExamSession::query()->where('cycle_id', $locked->getKey())->where('status', 'draft')->update(['status' => 'published', 'updated_at' => now()]);
            }
            $audit->record('exams.cycle.transitioned', $request->user(), (int) $context->tenant()->getKey(), $locked, ['from' => $from, 'to' => $locked->status]);
        });

        return back()->with('success', 'Examination cycle status updated.');
    }

    public function storeSession(Request $request, Tenant $tenant, int $cycleId, TenantContext $context, AcademicAccess $access, AuditRecorder $audit): RedirectResponse
    {
        $tenantId = (int) $context->tenant()->getKey();
        $cycle = ExamCycle::query()->findOrFail($cycleId);
        abort_unless(in_array($cycle->status, ['draft', 'review', 'approved'], true), 409, 'Published examination schedules require a new reviewed revision.');
        $data = $request->validate([
            'campus_id' => ['required', 'integer', Rule::exists('campuses', 'id')->where('tenant_id', $tenantId)->where('institution_id', $cycle->institution_id)],
            'academic_course_id' => ['required', 'integer', Rule::exists('academic_courses', 'id')->where('tenant_id', $tenantId)],
            'room_id' => ['nullable', 'integer', Rule::exists('academic_rooms', 'id')->where('tenant_id', $tenantId)],
            'code' => ['required', 'string', 'max:48', Rule::unique('exam_sessions', 'code')->where('tenant_id', $tenantId)->where('cycle_id', $cycleId)],
            'title' => ['required', 'string', 'max:180'],
            'mode' => ['required', Rule::in(['onsite', 'online', 'practical', 'oral'])],
            'starts_at' => ['required', 'date'],
            'ends_at' => ['required', 'date', 'after:starts_at'],
            'reporting_at' => ['nullable', 'date', 'before_or_equal:starts_at'],
            'duration_minutes' => ['required', 'integer', 'min:1', 'max:600'],
            'capacity' => ['nullable', 'integer', 'min:1', 'max:5000'],
        ]);
        $access->assertCampus((int) $data['campus_id']);
        $scheduledMinutes = (int) Carbon::parse($data['starts_at'])->diffInMinutes(Carbon::parse($data['ends_at']));
        if ($scheduledMinutes != (int) $data['duration_minutes']) {
            throw ValidationException::withMessages(['duration_minutes' => 'Duration must match the scheduled start and end time.']);
        }
        $course = AcademicCourse::query()->where('tenant_id', $tenantId)->findOrFail($data['academic_course_id']);
        abort_unless((int) $course->institution_id === (int) $cycle->institution_id, 422, 'The course belongs to another institution.');
        $session = DB::transaction(function () use ($data, $cycle, $request, $tenantId, $audit): ExamSession {
            $lockedCycle = ExamCycle::query()->lockForUpdate()->findOrFail($cycle->getKey());
            abort_unless(in_array($lockedCycle->status, ['draft', 'review', 'approved'], true), 409, 'Published examination schedules require a new reviewed revision.');
            if (isset($data['room_id'])) {
                $room = AcademicRoom::query()->where('tenant_id', $tenantId)->lockForUpdate()->findOrFail($data['room_id']);
                abort_unless((int) $room->campus_id === (int) $data['campus_id'] && $room->status === 'active', 422, 'Select an active room in the assigned campus.');
                $data['capacity'] = min((int) ($data['capacity'] ?? $room->capacity), (int) $room->capacity);
                $overlap = ExamSession::query()->where('room_id', $room->getKey())->whereIn('status', ['draft', 'published', 'active'])
                    ->where('starts_at_utc', '<', Carbon::parse($data['ends_at'])->utc())->where('ends_at_utc', '>', Carbon::parse($data['starts_at'])->utc())->exists();
                if ($overlap) {
                    throw ValidationException::withMessages(['room_id' => 'This room already has an examination session during the requested time.']);
                }
            }
            $session = ExamSession::query()->create([
                ...$data, 'cycle_id' => $cycle->getKey(), 'created_by' => $request->user()->getKey(), 'status' => 'draft',
                'starts_at_utc' => Carbon::parse($data['starts_at'])->utc(), 'ends_at_utc' => Carbon::parse($data['ends_at'])->utc(),
                'reporting_at_utc' => isset($data['reporting_at']) ? Carbon::parse($data['reporting_at'])->utc() : null,
            ]);
            $audit->record('exams.session.scheduled', $request->user(), $tenantId, $session, ['cycle_id' => $cycle->getKey(), 'campus_id' => $session->campus_id, 'room_id' => $session->room_id]);

            return $session;
        });

        return back()->with('success', 'Examination session added to the draft date sheet.');
    }

    public function registerCandidate(Request $request, Tenant $tenant, int $cycleId, TenantContext $context, ExamOperationsWorkflow $workflow): RedirectResponse
    {
        $tenantId = (int) $context->tenant()->getKey();
        $cycle = ExamCycle::query()->findOrFail($cycleId);
        $data = $request->validate(['student_id' => ['required', 'integer', Rule::exists('sis_students', 'id')->where('tenant_id', $tenantId)->where('institution_id', $cycle->institution_id)->where('status', 'active')]]);
        $workflow->registerCandidate($cycleId, (int) $data['student_id'], $request->user());

        return back()->with('success', 'Candidate registration confirmed.');
    }

    public function allocateSeat(Request $request, Tenant $tenant, int $sessionId, TenantContext $context, ExamOperationsWorkflow $workflow): RedirectResponse
    {
        $tenantId = (int) $context->tenant()->getKey();
        $data = $request->validate([
            'candidate_id' => ['required', 'integer', Rule::exists('exam_candidates', 'id')->where('tenant_id', $tenantId)],
            'room_id' => ['required', 'integer', Rule::exists('academic_rooms', 'id')->where('tenant_id', $tenantId)->where('status', 'active')],
            'seat_number' => ['required', 'string', 'max:24', 'regex:/^[A-Za-z0-9._-]+$/'],
        ]);
        $workflow->allocateSeat($sessionId, (int) $data['candidate_id'], (int) $data['room_id'], $data['seat_number'], $request->user());

        return back()->with('success', 'Candidate seat allocation saved.');
    }

    private function assertCyclePermission(TenantContext $context, ExamCycle $cycle, string $target): void
    {
        $permission = $target === 'approved' ? 'exams.approve' : ($target === 'published' ? 'exams.publish' : 'exams.manage');
        abort_unless((int) $cycle->tenant_id === (int) $context->tenant()->getKey(), 404);
        abort_unless($context->membership()->hasPermission($permission), 403);
    }
}
