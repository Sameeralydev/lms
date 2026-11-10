<?php

namespace App\Http\Controllers\Workspace;

use App\Domain\Tenancy\TenantContext;
use App\Http\Controllers\Controller;
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

        $title = match (true) {
            $can('tenant.update') || $can('members.manage') => 'Institution administration',
            $can('campus.update') => 'Campus administration',
            $can('learning.delivery.teach') => 'Instructor workspace',
            $can('academic.view') => 'Academic administration',
            $can('enrollment.registrar.manage') => 'Registrar workspace',
            $can('admissions.view') => 'Admissions workspace',
            $can('attendance.guardian.view') => 'Family attendance',
            $can('enrollment.student.view') || $can('assignments.submission.create') || $can('learning.delivery.learn') => 'Learner workspace',
            default => 'Institution workspace',
        };

        $description = match ($title) {
            'Institution administration' => 'Manage institution operations and open the services available to your administrator role.',
            'Campus administration' => 'Review your assigned campus and open the operations available within its scope.',
            'Academic administration' => 'Continue academic planning, curriculum governance, scheduling, and education quality work.',
            'Registrar workspace' => 'Review course offerings, registration, rosters, and learner placement workflows.',
            'Admissions workspace' => 'Manage published intakes, applicant review, and admissions decisions.',
            'Instructor workspace' => 'Open your assigned teaching, course delivery, attendance, and assessment work.',
            'Family attendance' => 'View attendance only for learners with a verified, active guardian relationship and access grant.',
            'Learner workspace' => 'Continue your own course registration, learning, attendance, and assignment work.',
            default => 'Only services authorized for your active institution membership are listed here.',
        };

        $cards = [];
        $addCard = static function (string $permission, string $label, string $path, string $detail) use (&$cards, $can, $tenant): void {
            if ($can($permission)) {
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
        $addCard('academic.curriculum.view', 'Curriculum', 'academics/curriculum', 'Published curriculum, versions, and governance.');
        $addCard('enrollment.registrar.view', 'Course registrar', 'registrar/enrollment', 'Offerings, sections, enrollment decisions, and rosters.');
        $addCard('enrollment.instructor.roster.view', 'Instructor schedule', 'instructor/schedule', 'Your assigned timetable and section details.');
        $addCard('admissions.view', 'Admissions', 'admissions', 'Intakes, applicant records, and review queue.');
        $addCard('sis.view', 'Student registry', 'sis', 'Student records and authorized lifecycle operations.');
        $addCard('learning.delivery.view', 'Course delivery', 'learning', 'Assigned learning workspaces, lessons, and sessions.');
        $addCard('enrollment.student.view', 'Course registration', 'registration', 'Your course options, registrations, and history.');
        $addCard('assignments.view', 'My assignments', 'my-assignments', 'Your own assignment instructions, work, and feedback.');
        $addCard('assignments.submission.view', 'Assignment management', 'assignments', 'Authoring, review, submissions, and grading.');
        $addCard('attendance.view', 'Attendance operations', 'attendance', 'Registers, corrections, reports, and authorized review.');
        $addCard('attendance.student.view', 'My attendance', 'attendance/my', 'Your own attendance record and justifications.');
        $addCard('attendance.guardian.view', 'Family attendance', 'attendance/guardian', 'Attendance for verified learners linked to your account.');
        $addCard('audit.view', 'Audit history', 'audit', 'Administrative events available to your role.');

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
