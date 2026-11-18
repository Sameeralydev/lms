<?php

namespace App\Http\Controllers\Workspace;

use App\Domain\Academic\AcademicDefaults;
use App\Domain\Tenancy\TenantContext;
use App\Http\Controllers\Controller;
use App\Models\AcademicConfiguration;
use App\Models\AcademicCourse;
use App\Models\AdmissionApplication;
use App\Models\AdmissionIntake;
use App\Models\AssignmentRecipient;
use App\Models\AssignmentSubmission;
use App\Models\AttendanceEntry;
use App\Models\AuditEvent;
use App\Models\Campus;
use App\Models\CourseDeliveryAssignment;
use App\Models\CourseDeliveryWorkspace;
use App\Models\CourseEnrollment;
use App\Models\CourseOffering;
use App\Models\CourseSection;
use App\Models\SisStudent;
use App\Models\SisStudentGuardian;
use App\Models\TenantMembership;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function __invoke(TenantContext $context): Response
    {
        $tenant = $context->tenant();
        $membership = $context->membership();
        $tenantId = (int) $tenant->getKey();
        $campusId = $membership->campus_id === null ? null : (int) $membership->campus_id;
        $permissionCache = [];
        $can = static function (string $permission) use ($membership, &$permissionCache): bool {
            return $permissionCache[$permission] ??= $membership->hasPermission($permission);
        };
        $institutionWide = $can('tenant.update') || $can('members.manage');
        $isAcademicAdministrator = $membership->roles()->where('key', 'academic.admin')->exists();
        $isExaminer = $membership->roles()->where('key', 'examiner')->exists();

        $title = match (true) {
            $can('tenant.update') || $can('members.manage') => 'Institution administration',
            $can('campus.update') => 'Campus administration',
            $isAcademicAdministrator => 'Academic administration',
            $can('exams.manage') => 'Examination controller workspace',
            $isExaminer => 'Examiner workspace',
            $can('exams.mark') && ! $can('learning.delivery.teach') => 'Examiner workspace',
            $can('exams.attendance.record') && $can('exams.invigilators.view-own') => 'Invigilator workspace',
            $can('learning.delivery.teach') => 'Instructor workspace',
            $can('enrollment.registrar.manage') => 'Registrar workspace',
            $can('admissions.view') => 'Admissions workspace',
            $can('attendance.guardian.view') => 'Family attendance',
            $can('enrollment.student.view') || $can('assignments.submission.create') || $can('learning.delivery.learn') => 'Learner workspace',
            $can('academic.analytics.view') || $can('academic.grades.review') || $can('academic.grades.publish') => 'Academic leadership workspace',
            $can('academic.view') => 'Academic administration',
            default => 'Institution workspace',
        };

        $description = match ($title) {
            'Institution administration' => 'Manage institution operations and open the services available to your administrator role.',
            'Campus administration' => 'Review your assigned campus and open the operations available within its scope.',
            'Examination controller workspace' => 'Review examination readiness, candidates, result approvals, and operational queues in your institution scope.',
            'Examiner workspace' => 'Work only on examination marking allocations assigned to your examiner account.',
            'Invigilator workspace' => 'Review your assigned examination duties and upcoming sessions.',
            'Academic leadership workspace' => 'Review institution-scoped academic performance, grade approvals, and official records.',
            'Academic administration' => 'Continue academic planning, curriculum governance, scheduling, and education quality work.',
            'Registrar workspace' => 'Review course offerings, registration, rosters, and learner placement workflows.',
            'Admissions workspace' => 'Manage published intakes, applicant review, and admissions decisions.',
            'Instructor workspace' => 'Open your assigned teaching, course delivery, attendance, and assessment work.',
            'Family attendance' => 'View attendance only for learners with a verified, active guardian relationship and access grant.',
            'Learner workspace' => 'Continue your own course registration, learning, attendance, and assignment work.',
            default => 'Only services authorized for your active institution membership are listed here.',
        };

        $cards = [];
        $addCard = static function (string|array $permission, string $label, string $path, string $detail) use (&$cards, $can, $tenant): void {
            $required = is_array($permission) ? $permission : [$permission];
            if (collect($required)->every($can)) {
                $cards[] = [
                    'label' => $label,
                    'href' => '/t/'.$tenant->slug.'/'.$path,
                    'detail' => $detail,
                ];
            }
        };

        $addCard('tenant.view', 'Institution profile', 'institution', 'Institution identity, contacts, and configuration.');
        $addCard('campus.view', 'Campus directory', 'campuses', 'Campus records available within your assigned scope.');
        $addCard('academic.view', 'Academic administration', 'academics', 'Academic calendars, organization, catalog, and policy.');
        $addCard(['academic.view', 'academic.analytics.view'], 'Academic analytics', 'academics/analytics', 'Campus-scoped enrollment, admissions, attendance, grades, and records metrics.');
        $addCard(['academic.view', 'academic.curriculum.view'], 'Curriculum', 'academics/curriculum', 'Published curriculum, versions, and governance.');
        $addCard(['academic.view', 'academic.grades.book.view'], 'Gradebooks', 'academics/grades', 'Review and manage gradebooks available to your academic scope.');
        $addCard(['academic.view', 'academic.grades.book.view', 'academic.grades.review'], 'Grade approvals', 'academics/grades', 'Review submitted gradebooks under independent approval rules.');
        $addCard(['academic.view', 'academic.records.issue'], 'Official academic records', 'academics/records', 'Prepare, review, issue, and track authorized report cards, transcripts, and certificates.');
        $addCard(['academic.view', 'academic.records.view-all'], 'Academic reports', 'academics/reports', 'View institution-scoped official academic record reports.');
        $addCard(['academic.view', 'academic.records.gpa.manage'], 'GPA and standing', 'academics/gpa', 'Calculate and review academic standing using the active policy.');
        $addCard('academic.view', 'Academic calendar', 'academics/calendar', 'Review the institution academic calendar and session dates.');
        $addCard('academic.view', 'Timetables', 'academics/timetables', 'Manage published timetables and resource assignments.');
        $addCard('academic.view', 'Timetable analytics', 'academics/timetables/analytics', 'Review schedule utilization and conflict findings.');
        $addCard('enrollment.registrar.view', 'Course registrar', 'registrar/enrollment', 'Offerings, sections, enrollment decisions, and rosters.');
        $addCard('enrollment.instructor.roster.view', 'Instructor schedule', 'instructor/schedule', 'Your assigned timetable and section details.');
        $addCard('admissions.view', 'Admissions', 'admissions', 'Intakes, applicant records, and review queue.');
        $addCard('sis.view', 'Student registry', 'sis', 'Student records and authorized lifecycle operations.');
        $addCard('learning.delivery.view', 'Course delivery', 'learning', 'Assigned learning workspaces, lessons, and sessions.');
        $addCard('enrollment.student.view', 'Course registration', 'registration', 'Your course options, registrations, and history.');
        $addCard('assignments.view', 'My assignments', 'my-assignments', 'Your own assignment instructions, work, and feedback.');
        $addCard('assignments.submission.view', 'Assignment management', 'assignments', 'Authoring, review, submissions, and grading.');
        $addCard(['assignments.view', 'assignments.report.view'], 'Assignment analytics', 'assignments/analytics', 'Review scoped submission, completion, and grading activity.');
        $addCard('assessment.quiz.view', 'Assessments and quizzes', 'assessments', 'Author, review, take, or manage quizzes according to your role.');
        $addCard(['assessment.quiz.view', 'assessment.analytics.view'], 'Assessment analytics', 'assessments/analytics', 'Review authorized quiz attempt and result summaries.');
        $addCard('attendance.view', 'Attendance operations', 'attendance', 'Registers, corrections, reports, and authorized review.');
        $addCard('attendance.report.view', 'Attendance reports', 'attendance/reports', 'Review finalized attendance summaries within your scope.');
        $addCard('attendance.student.view', 'My attendance', 'attendance/my', 'Your own attendance record and justifications.');
        $addCard('attendance.guardian.view', 'Family attendance', 'attendance/guardian', 'Attendance for verified learners linked to your account.');
        $addCard(['academic.view', 'exams.view'], 'Examination operations', 'academics/examinations', 'Manage schedules, centers, seating, papers, and examination operations.');
        $addCard(['academic.view', 'exams.view', 'exams.results.compile'], 'Examination results', 'academics/examinations/results', 'Compile and review authorized examination result workflows.');
        $addCard(['academic.view', 'exams.view', 'exams.analytics.view'], 'Examination reports', 'academics/examinations/results', 'View scoped examination operations and released result reporting.');
        $addCard(['academic.view', 'exams.view', 'exams.mark'], 'Examiner marking', 'academics/examinations/marking', 'Mark scripts specifically allocated to your examiner account.');
        $addCard('exams.invigilators.view-own', 'Invigilation duties', 'examination-duties', 'View and respond to duties assigned to you.');
        $addCard('exams.student.view-own', 'My examination schedule', 'examinations/my', 'View your own published examination schedule and governance status.');
        $addCard('exams.student.view-own', 'My examination results', 'examinations/results', 'View released results and request an eligible recheck.');
        $addCard('assessment.attempt.start', 'Available examinations', 'exams', 'Open eligible online assessments assigned to your learner account.');
        $addCard('audit.view', 'Audit history', 'audit', 'Administrative events available to your role.');

        $configuration = AcademicConfiguration::query()->first();
        $enabledFeatures = $configuration ? AcademicDefaults::present($configuration)['enabled_features'] : [];
        $featureForPath = static function (string $path): ?string {
            if (in_array($path, ['academics/analytics', 'academics/curriculum', 'academics/grades', 'academics/gpa', 'academics/records', 'academics/reports'], true)) {
                return 'catalog';
            }
            if (in_array($path, ['academics/calendar', 'academics/timetables', 'academics/timetables/analytics', 'academics/examinations', 'academics/examinations/results', 'academics/examinations/marking'], true)) {
                return 'calendar';
            }
            if ($path === 'sis') {
                return 'student_information';
            }

            return null;
        };
        $cards = array_values(array_filter($cards, static function (array $card) use ($enabledFeatures, $featureForPath, $tenant): bool {
            $path = ltrim(substr($card['href'], strlen('/t/'.$tenant->slug.'/')), '/');
            $feature = $featureForPath($path);

            return $feature === null || ($enabledFeatures[$feature] ?? false);
        }));

        $metrics = [];
        $addMetric = static function (string $label, int $value, string $detail) use (&$metrics): void {
            $metrics[] = ['label' => $label, 'value' => $value, 'detail' => $detail];
        };

        if ($can('campus.view') && ($campusId !== null || $institutionWide)) {
            $campuses = Campus::query();
            if ($campusId !== null) {
                $campuses->whereKey($campusId);
            }
            $addMetric('Campuses in scope', (clone $campuses)->count(), 'Campus records visible to this membership.');
            $addMetric('Active campuses', (clone $campuses)->where('status', 'active')->count(), 'Active locations visible to this membership.');
        }

        if ($can('members.view') && ($campusId !== null || $institutionWide)) {
            $memberships = TenantMembership::query();
            if ($campusId !== null) {
                $memberships->where('campus_id', $campusId);
            }
            $addMetric('Memberships in scope', (clone $memberships)->count(), 'Institution access records visible to this membership.');
            $addMetric('Active memberships', (clone $memberships)->where('status', 'active')->count(), 'Current institution access records in scope.');
        }

        if ($can('academic.view')) {
            $addMetric('Active courses', AcademicCourse::query()->where('institution_id', $membership->institution_id)->where('status', 'active')->count(), 'Courses configured for this institution.');
        }

        if ($can('enrollment.registrar.view')) {
            $offerings = CourseOffering::query()->whereIn('status', ['open', 'published']);
            $enrollments = CourseEnrollment::query()->where('status', 'confirmed');
            if ($campusId !== null) {
                $offerings->whereHas('sections', fn ($query) => $query->where('campus_id', $campusId));
                $enrollments->whereHas('section', fn ($query) => $query->where('campus_id', $campusId));
            }
            $addMetric('Open course offerings', $offerings->count(), 'Offerings currently open or published in your scope.');
            $addMetric('Confirmed enrollments', $enrollments->count(), 'Confirmed learner registrations in your scope.');
        }

        if ($can('admissions.view')) {
            $intakes = AdmissionIntake::query();
            $applications = AdmissionApplication::query();
            if ($campusId !== null) {
                $intakes->where('campus_id', $campusId);
                $applications->whereHas('intake', fn ($query) => $query->where('campus_id', $campusId));
            }
            $addMetric('Admission intakes', $intakes->count(), 'Configured intakes in your scope.');
            if ($can('admissions.applications.view') || $can('admissions.applications.review')) {
                $addMetric('Applications', $applications->count(), 'Applicant records available to admissions staff in your scope.');
            }
        }

        if ($can('learning.delivery.teach') || $can('assignments.submission.grade') || $can('attendance.register.mark')) {
            $sectionQuery = DB::table('course_section_instructors')
                ->where('tenant_id', $tenantId)
                ->where('user_id', $membership->user_id);
            if ($campusId !== null) {
                $sectionQuery->whereIn('section_id', CourseSection::query()->where('campus_id', $campusId)->select('id'));
            }
            $sectionIds = $sectionQuery->distinct()->pluck('section_id');
            $addMetric('Assigned sections', $sectionIds->count(), 'Sections assigned to your instructor account.');

            if ($can('assignments.submission.grade') && $sectionIds->isNotEmpty()) {
                $pending = AssignmentSubmission::query()->where('status', 'submitted')
                    ->whereHas('assignment.sections', fn ($query) => $query->whereIn('course_sections.id', $sectionIds));
                $addMetric('Submissions to review', $pending->count(), 'Submitted work for your assigned sections.');
            }

            if ($can('learning.delivery.teach')) {
                $workspaces = CourseDeliveryWorkspace::query()->whereHas('assignments', fn ($query) => $query
                    ->where('membership_id', $membership->getKey())
                    ->effective()
                    ->whereIn('assignment_role', ['coordinator', 'instructor', 'content_author']));
                $addMetric('Teaching workspaces', $workspaces->count(), 'Active course delivery workspaces assigned to you.');
            }
        }

        if ($can('enrollment.student.view') || $can('assignments.submission.create') || $can('learning.delivery.learn') || $can('attendance.student.view')) {
            $student = SisStudent::query()->where('institution_id', $membership->institution_id)
                ->where('user_id', $membership->user_id)->where('status', 'active')
                ->when($campusId !== null, fn ($query) => $query->where(function ($scope) use ($campusId): void {
                    $scope->where('home_campus_id', $campusId)->orWhereHas('placements', fn ($placements) => $placements
                        ->where('campus_id', $campusId)->whereNull('effective_until')->whereDate('effective_from', '<=', today()));
                }))->first();

            if ($student) {
                if ($can('enrollment.student.view')) {
                    $addMetric('Confirmed registrations', CourseEnrollment::query()->where('student_id', $student->getKey())->where('status', 'confirmed')->count(), 'Your current confirmed course registrations.');
                }
                if ($can('assignments.submission.create')) {
                    $pendingAssignments = AssignmentRecipient::query()->where('membership_id', $membership->getKey())
                        ->where('status', 'eligible')
                        ->whereHas('assignment', fn ($query) => $query->where('status', 'published'));
                    $addMetric('Available assignments', $pendingAssignments->count(), 'Published assignments available to your membership.');
                }
                if ($can('learning.delivery.learn')) {
                    $workspaces = CourseDeliveryAssignment::query()->where('membership_id', $membership->getKey())
                        ->effective()->where('assignment_role', 'learner');
                    $addMetric('Learning workspaces', $workspaces->count(), 'Active learning workspaces assigned to you.');
                }
                if ($can('attendance.student.view')) {
                    $addMetric('Attendance records', AttendanceEntry::query()->where('student_id', $student->getKey())->count(), 'Attendance records linked to your student profile.');
                }
                if ($can('exams.student.view-own')) {
                    $candidateScope = DB::table('exam_candidates as candidates')
                        ->join('exam_cycles as cycles', function ($join): void {
                            $join->on('cycles.tenant_id', '=', 'candidates.tenant_id')
                                ->on('cycles.id', '=', 'candidates.cycle_id');
                        })
                        ->where('candidates.tenant_id', $tenantId)
                        ->where('cycles.institution_id', $membership->institution_id)
                        ->where('candidates.student_id', $student->getKey())
                        ->where('candidates.status', 'registered')
                        ->whereNull('candidates.withdrawn_at');
                    $candidateIds = (clone $candidateScope)->select('candidates.id');
                    $sessions = DB::table('exam_sessions as sessions')
                        ->where('sessions.tenant_id', $tenantId)
                        ->where('sessions.status', 'published')
                        ->whereIn('sessions.cycle_id', (clone $candidateScope)->select('candidates.cycle_id'));
                    if ($campusId !== null) {
                        $sessions->where('sessions.campus_id', $campusId);
                    }
                    $addMetric('Upcoming examinations', (clone $sessions)->where('sessions.starts_at_utc', '>=', now()->utc())->count(), 'Published future sessions for your active examination registrations.');
                    $addMetric('Released examination results', DB::table('exam_results as results')
                        ->where('results.tenant_id', $tenantId)
                        ->where('results.status', 'released')
                        ->whereIn('results.candidate_id', $candidateIds)
                        ->count(), 'Official examination results released for your own candidate records.');
                }
            }
        }

        if ($can('attendance.guardian.view')) {
            $authorizedLearners = SisStudentGuardian::query()
                ->where('guardian_user_id', $membership->user_id)
                ->where('authority_status', 'verified')
                ->where('can_view_profile', true)
                ->whereNull('revoked_at')
                ->when($campusId !== null, fn ($query) => $query->whereHas('student', fn ($students) => $students
                    ->where(function ($scope) use ($campusId): void {
                        $scope->where('home_campus_id', $campusId)->orWhereHas('placements', fn ($placements) => $placements
                            ->where('campus_id', $campusId)->whereNull('effective_until')->whereDate('effective_from', '<=', today()));
                    })));
            $addMetric('Linked learners', $authorizedLearners->count(), 'Verified and active profile access grants only.');
        }

        if ($can('exams.manage') || $can('exams.analytics.view')) {
            $sessionScope = DB::table('exam_sessions')->where('tenant_id', $tenantId)
                ->when($campusId !== null, fn ($query) => $query->where('campus_id', $campusId));
            $sessionIds = (clone $sessionScope)->select('id');
            $cycleIds = DB::table('exam_cycles')->where('tenant_id', $tenantId)
                ->where('institution_id', $membership->institution_id)
                ->whereIn('id', (clone $sessionScope)->select('cycle_id'))
                ->select('id');

            $addMetric('Published exam sessions', (clone $sessionScope)->where('status', 'published')->count(), 'Published sessions in your institution and campus scope.');
            $addMetric('Registered exam candidates', DB::table('exam_candidates')->where('tenant_id', $tenantId)
                ->whereIn('cycle_id', $cycleIds)->where('status', 'registered')->whereNull('withdrawn_at')->count(), 'Registered candidates in cycles with sessions in your scope.');
            $addMetric('Results awaiting approval', DB::table('exam_results')->where('tenant_id', $tenantId)
                ->whereIn('session_id', $sessionIds)->where('status', 'approval_pending')->count(), 'Compiled results awaiting independent approval.');
            $addMetric('Open examination incidents', DB::table('exam_incidents')->where('tenant_id', $tenantId)
                ->whereIn('session_id', $sessionIds)->whereIn('status', ['reported', 'reviewed', 'escalated', 'investigating'])->count(), 'Reported incidents requiring an authorized operational review.');
        }

        if ($can('exams.mark') && ! $can('learning.delivery.teach')) {
            $allocations = DB::table('exam_component_allocations as allocations')
                ->join('exam_components as components', function ($join): void {
                    $join->on('components.tenant_id', '=', 'allocations.tenant_id')
                        ->on('components.id', '=', 'allocations.component_id');
                })
                ->join('exam_sessions as sessions', function ($join): void {
                    $join->on('sessions.tenant_id', '=', 'components.tenant_id')
                        ->on('sessions.id', '=', 'components.session_id');
                })
                ->where('allocations.tenant_id', $tenantId)
                ->where('allocations.examiner_id', $membership->user_id)
                ->whereIn('allocations.status', ['assigned', 'in_progress']);
            if ($campusId !== null) {
                $allocations->where('sessions.campus_id', $campusId);
            }
            $addMetric('Assigned scripts to mark', (clone $allocations)->count(), 'Active marking allocations assigned to your examiner account.');
            $addMetric('Marking in progress', (clone $allocations)->where('allocations.status', 'in_progress')->count(), 'Your allocations with marking in progress.');
        }

        if ($can('exams.attendance.record') && $can('exams.invigilators.view-own')) {
            $duties = DB::table('exam_invigilator_assignments as duties')
                ->join('exam_sessions as sessions', function ($join): void {
                    $join->on('sessions.tenant_id', '=', 'duties.tenant_id')
                        ->on('sessions.id', '=', 'duties.session_id');
                })
                ->where('duties.tenant_id', $tenantId)
                ->where('duties.user_id', $membership->user_id)
                ->where('duties.status', 'assigned');
            if ($campusId !== null) {
                $duties->where('sessions.campus_id', $campusId);
            }
            $addMetric('Assigned invigilation duties', (clone $duties)->count(), 'Duties explicitly assigned to your account.');
            $addMetric('Upcoming invigilation sessions', (clone $duties)->where('sessions.status', 'published')
                ->where('sessions.starts_at_utc', '>=', now()->utc())->count(), 'Published assigned sessions that have not started.');
        }

        $recentEvents = [];
        if ($can('audit.view') && $campusId === null) {
            $recentEvents = AuditEvent::query()->where('tenant_id', $tenantId)->latest('occurred_at')->limit(8)->get();
        }

        return Inertia::render('workspace/dashboard', [
            'workspace' => [
                'title' => $title,
                'description' => $description,
                'auditHref' => '/t/'.$tenant->slug.'/audit',
            ],
            'metrics' => $metrics,
            'cards' => $cards,
            'recentEvents' => $recentEvents,
        ]);
    }
}
