<?php

namespace App\Domain\Analytics;

use App\Models\Campus;
use App\Models\TenantMembership;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class AcademicAnalyticsService
{
    /** @param array{campus_id?:int|null,from?:string|null,to?:string|null,metrics?:list<string>|null} $filters
     * @return array{metrics:list<array{key:string,name:string,value:int|float|null,display:string,definition:string,sources:list<string>,formula:string}>,trend:list<array{date:string,value:int}>,campuses:list<array{id:int,name:string}>,filters:array{campus_id:int|null,from:string|null,to:string|null,metrics:list<string>|null},generated_at:string}
     */
    public function report(TenantMembership $membership, array $filters): array
    {
        $campusId = $this->campusScope($membership, $filters['campus_id'] ?? null);
        $normalized = ['campus_id' => $campusId, 'from' => $filters['from'] ?? null, 'to' => $filters['to'] ?? null, 'metrics' => $filters['metrics'] ?? null];
        $permissionKeys = $membership->roles()->with('permissions')->get()->flatMap(fn ($role) => $role->permissions->pluck('key'))->unique()->sort()->values()->all();
        $cacheKey = 'academic-analytics:v1:'.hash('sha256', json_encode([
            (int) $membership->tenant_id, (int) $membership->institution_id, (int) $membership->getKey(), (int) $membership->user_id,
            $membership->campus_id, $normalized, $permissionKeys,
        ], JSON_THROW_ON_ERROR));

        return Cache::remember($cacheKey, now()->addSeconds(60), fn (): array => $this->calculate($membership, $normalized));
    }

    /** @param array{campus_id:int|null,from:string|null,to:string|null,metrics:list<string>|null} $filters
     * @return array{metrics:list<array{key:string,name:string,value:int|float|null,display:string,definition:string,sources:list<string>,formula:string}>,trend:list<array{date:string,value:int}>,campuses:list<array{id:int,name:string}>,filters:array{campus_id:int|null,from:string|null,to:string|null,metrics:list<string>|null},generated_at:string}
     */
    private function calculate(TenantMembership $membership, array $filters): array
    {
        $tenantId = (int) $membership->tenant_id;
        $institutionId = (int) $membership->institution_id;
        $from = $filters['from'];
        $to = $filters['to'];
        $campusId = $filters['campus_id'];

        $students = DB::table('sis_students')->where('tenant_id', $tenantId)->where('institution_id', $institutionId)->where('status', 'active')
            ->when($campusId !== null, function ($query) use ($tenantId, $institutionId, $campusId): void {
                $query->where(function ($scope) use ($tenantId, $institutionId, $campusId): void {
                    $scope->whereExists(function ($placement) use ($tenantId, $institutionId, $campusId): void {
                        $placement->selectRaw('1')->from('sis_student_placements as placements')
                            ->whereColumn('placements.student_id', 'sis_students.id')
                            ->where('placements.tenant_id', $tenantId)->where('placements.institution_id', $institutionId)
                            ->where('placements.campus_id', $campusId)->where('placements.status', 'active')
                            ->whereDate('placements.effective_from', '<=', today())
                            ->where(function ($active): void {
                                $active->whereNull('placements.effective_until')->orWhereDate('placements.effective_until', '>=', today());
                            });
                    })->orWhere(function ($homeCampus) use ($tenantId, $institutionId, $campusId): void {
                        $homeCampus->where('sis_students.home_campus_id', $campusId)
                            ->whereNotExists(function ($placement) use ($tenantId, $institutionId): void {
                                $placement->selectRaw('1')->from('sis_student_placements as placements')
                                    ->whereColumn('placements.student_id', 'sis_students.id')
                                    ->where('placements.tenant_id', $tenantId)->where('placements.institution_id', $institutionId)
                                    ->where('placements.status', 'active')->whereDate('placements.effective_from', '<=', today())
                                    ->where(function ($active): void {
                                        $active->whereNull('placements.effective_until')->orWhereDate('placements.effective_until', '>=', today());
                                    });
                            });
                    });
                });
            });

        $enrollments = DB::table('course_enrollments as enrollments')->join('course_sections as sections', function ($join): void {
            $join->on('sections.id', '=', 'enrollments.section_id')->on('sections.tenant_id', '=', 'enrollments.tenant_id');
        })->where('enrollments.tenant_id', $tenantId)->where('enrollments.institution_id', $institutionId)->where('enrollments.status', 'confirmed')
            ->when($campusId !== null, fn ($query) => $query->where('sections.campus_id', $campusId))
            ->when($from !== null, fn ($query) => $query->whereDate('enrollments.confirmed_at', '>=', $from))
            ->when($to !== null, fn ($query) => $query->whereDate('enrollments.confirmed_at', '<=', $to));

        $applications = DB::table('admission_applications as applications')->join('admission_intakes as intakes', function ($join): void {
            $join->on('intakes.id', '=', 'applications.intake_id')->on('intakes.tenant_id', '=', 'applications.tenant_id');
        })->where('applications.tenant_id', $tenantId)->where('applications.institution_id', $institutionId)->whereNotNull('applications.submitted_at')
            ->when($campusId !== null, fn ($query) => $query->where('intakes.campus_id', $campusId))
            ->when($from !== null, fn ($query) => $query->whereDate('applications.submitted_at', '>=', $from))
            ->when($to !== null, fn ($query) => $query->whereDate('applications.submitted_at', '<=', $to));

        $attendance = DB::table('attendance_entries as entries')->join('attendance_registers as registers', function ($join): void {
            $join->on('registers.id', '=', 'entries.register_id')->on('registers.tenant_id', '=', 'entries.tenant_id');
        })->join('attendance_status_definitions as statuses', function ($join): void {
            $join->on('statuses.id', '=', 'entries.status_definition_id')->on('statuses.tenant_id', '=', 'entries.tenant_id');
        })->where('entries.tenant_id', $tenantId)->where('registers.institution_id', $institutionId)->where('registers.status', 'finalized')
            ->when($campusId !== null, fn ($query) => $query->where('registers.campus_id', $campusId))
            ->when($from !== null, fn ($query) => $query->whereDate('registers.occurrence_date', '>=', $from))
            ->when($to !== null, fn ($query) => $query->whereDate('registers.occurrence_date', '<=', $to));
        $expectedAttendance = (clone $attendance)->where('statuses.counts_expected', true)->count();
        $attended = (clone $attendance)->where('statuses.counts_expected', true)->where('statuses.counts_attended', true)->count();

        $gradebooks = DB::table('academic_gradebooks as gradebooks')->join('course_sections as sections', function ($join): void {
            $join->on('sections.id', '=', 'gradebooks.section_id')->on('sections.tenant_id', '=', 'gradebooks.tenant_id');
        })->where('gradebooks.tenant_id', $tenantId)->where('gradebooks.institution_id', $institutionId)
            ->when($campusId !== null, fn ($query) => $query->where('sections.campus_id', $campusId));

        $records = DB::table('academic_record_issues as records')->join('sis_students as students', function ($join): void {
            $join->on('students.id', '=', 'records.student_id')->on('students.tenant_id', '=', 'records.tenant_id');
        })->where('records.tenant_id', $tenantId)->where('records.institution_id', $institutionId)->where('records.status', 'issued')
            ->when($campusId !== null, fn ($query) => $query->where('students.home_campus_id', $campusId))
            ->when($from !== null, fn ($query) => $query->whereDate('records.issued_at', '>=', $from))
            ->when($to !== null, fn ($query) => $query->whereDate('records.issued_at', '<=', $to));

        $quizzes = DB::table('quizzes')->where('tenant_id', $tenantId)->where('institution_id', $institutionId)->where('status', 'published')
            ->when($campusId !== null, fn ($query) => $query->whereExists(function ($sections) use ($tenantId, $campusId): void {
                $sections->selectRaw('1')->from('quiz_sections as quiz_campuses')
                    ->join('course_sections as scoped_sections', function ($join): void {
                        $join->on('scoped_sections.id', '=', 'quiz_campuses.section_id')->on('scoped_sections.tenant_id', '=', 'quiz_campuses.tenant_id');
                    })->whereColumn('quiz_campuses.quiz_id', 'quizzes.id')->where('quiz_campuses.tenant_id', $tenantId)->where('scoped_sections.campus_id', $campusId);
            }))
            ->when($from !== null, fn ($query) => $query->whereDate('published_at', '>=', $from))
            ->when($to !== null, fn ($query) => $query->whereDate('published_at', '<=', $to));

        $publishedExamSessions = DB::table('exam_sessions as sessions')->join('exam_cycles as cycles', function ($join): void {
            $join->on('cycles.id', '=', 'sessions.cycle_id')->on('cycles.tenant_id', '=', 'sessions.tenant_id');
        })->where('sessions.tenant_id', $tenantId)->where('cycles.institution_id', $institutionId)->where('sessions.status', 'published')
            ->when($campusId !== null, fn ($query) => $query->where('sessions.campus_id', $campusId))
            ->when($from !== null, fn ($query) => $query->whereDate('sessions.starts_at_utc', '>=', $from))
            ->when($to !== null, fn ($query) => $query->whereDate('sessions.starts_at_utc', '<=', $to));

        $releasedExamResults = DB::table('exam_results as results')->join('exam_sessions as sessions', function ($join): void {
            $join->on('sessions.id', '=', 'results.session_id')->on('sessions.tenant_id', '=', 'results.tenant_id');
        })->join('exam_cycles as cycles', function ($join): void {
            $join->on('cycles.id', '=', 'sessions.cycle_id')->on('cycles.tenant_id', '=', 'sessions.tenant_id');
        })->where('results.tenant_id', $tenantId)->where('cycles.institution_id', $institutionId)->whereNotNull('results.released_at')
            ->when($campusId !== null, fn ($query) => $query->where('sessions.campus_id', $campusId))
            ->when($from !== null, fn ($query) => $query->whereDate('results.released_at', '>=', $from))
            ->when($to !== null, fn ($query) => $query->whereDate('results.released_at', '<=', $to));

        $publishedAssignments = DB::table('assignments as assignments')->join('assignment_sections as assignment_sections', function ($join): void {
            $join->on('assignment_sections.assignment_id', '=', 'assignments.id')->on('assignment_sections.tenant_id', '=', 'assignments.tenant_id');
        })->join('course_sections as sections', function ($join): void {
            $join->on('sections.id', '=', 'assignment_sections.section_id')->on('sections.tenant_id', '=', 'assignment_sections.tenant_id')->on('sections.offering_id', '=', 'assignments.offering_id');
        })->where('assignments.tenant_id', $tenantId)->where('assignments.institution_id', $institutionId)->where('assignments.status', 'published')
            ->when($campusId !== null, fn ($query) => $query->where('sections.campus_id', $campusId))
            ->when($from !== null, fn ($query) => $query->whereDate('assignments.published_at', '>=', $from))
            ->when($to !== null, fn ($query) => $query->whereDate('assignments.published_at', '<=', $to));

        $assignmentSubmissions = DB::table('assignment_submissions as submissions')->join('assignments as assignments', function ($join): void {
            $join->on('assignments.id', '=', 'submissions.assignment_id')->on('assignments.tenant_id', '=', 'submissions.tenant_id');
        })->leftJoin('assignment_recipients as recipients', function ($join): void {
            $join->on('recipients.id', '=', 'submissions.recipient_id')->on('recipients.tenant_id', '=', 'submissions.tenant_id');
        })->leftJoin('assignment_groups as groups', function ($join): void {
            $join->on('groups.id', '=', 'submissions.group_id')->on('groups.tenant_id', '=', 'submissions.tenant_id');
        })->join('course_sections as sections', function ($join): void {
            $join->on('sections.tenant_id', '=', 'submissions.tenant_id')->on('sections.offering_id', '=', 'assignments.offering_id')
                ->on(DB::raw('sections.id'), '=', DB::raw('COALESCE(recipients.section_id, groups.section_id)'));
        })->join('assignment_attempts as attempts', function ($join): void {
            $join->on('attempts.submission_id', '=', 'submissions.id')->on('attempts.tenant_id', '=', 'submissions.tenant_id');
        })->where('submissions.tenant_id', $tenantId)->where('assignments.institution_id', $institutionId)->whereNotNull('assignments.published_at')->where('attempts.status', 'submitted')
            ->when($campusId !== null, fn ($query) => $query->where('sections.campus_id', $campusId))
            ->when($from !== null, fn ($query) => $query->whereDate('attempts.submitted_at', '>=', $from))
            ->when($to !== null, fn ($query) => $query->whereDate('attempts.submitted_at', '<=', $to));

        $values = [
            'students.active' => (int) (clone $students)->count(),
            'enrollments.confirmed' => (int) (clone $enrollments)->count(),
            'admissions.submitted' => (int) (clone $applications)->count(),
            'attendance.rate' => $expectedAttendance > 0 ? round(100 * $attended / $expectedAttendance, 1) : null,
            'gradebooks.pending_review' => (int) (clone $gradebooks)->where('gradebooks.status', 'submitted')->count(),
            'gradebooks.published' => (int) (clone $gradebooks)->where('gradebooks.status', 'published')->count(),
            'records.issued' => (int) (clone $records)->count(),
            'assignments.published' => (int) (clone $publishedAssignments)->distinct()->count('assignments.id'),
            'assignments.submissions' => (int) (clone $assignmentSubmissions)->distinct()->count('submissions.id'),
            'quizzes.published' => (int) (clone $quizzes)->count(),
            'exams.sessions.published' => (int) (clone $publishedExamSessions)->count(),
            'exams.results.released' => (int) (clone $releasedExamResults)->count(),
        ];
        $metrics = [];
        foreach (AcademicMetricRegistry::definitions() as $definition) {
            if ($filters['metrics'] !== null && ! in_array($definition['key'], $filters['metrics'], true)) {
                continue;
            }
            $value = $values[$definition['key']];
            $metrics[] = [...$definition, 'value' => $value, 'display' => $value === null ? 'No finalized attendance data' : ($definition['key'] === 'attendance.rate' ? number_format((float) $value, 1).'%' : number_format((int) $value))];
        }

        $trendQuery = DB::table('course_enrollments as enrollments')->join('course_sections as sections', function ($join): void {
            $join->on('sections.id', '=', 'enrollments.section_id')->on('sections.tenant_id', '=', 'enrollments.tenant_id');
        })->where('enrollments.tenant_id', $tenantId)->where('enrollments.institution_id', $institutionId)->where('enrollments.status', 'confirmed')
            ->when($campusId !== null, fn ($query) => $query->where('sections.campus_id', $campusId))
            ->when($from !== null, fn ($query) => $query->whereDate('enrollments.confirmed_at', '>=', $from), fn ($query) => $query->whereDate('enrollments.confirmed_at', '>=', today()->subDays(29)))
            ->when($to !== null, fn ($query) => $query->whereDate('enrollments.confirmed_at', '<=', $to))
            ->selectRaw('DATE(enrollments.confirmed_at) as day, COUNT(*) as total')->groupBy('day')->orderBy('day')->get()->keyBy('day');
        $trendStart = $from ? today()->parse($from) : today()->subDays(29);
        $trendEnd = $to ? today()->parse($to) : today();
        $trend = [];
        for ($day = $trendStart->copy(); $day->lte($trendEnd) && $day->diffInDays($trendStart) <= 365; $day->addDay()) {
            $date = $day->toDateString();
            $trend[] = ['date' => $date, 'value' => (int) ($trendQuery->get($date)->total ?? 0)];
        }
        $campuses = Campus::query()->where('tenant_id', $tenantId)->where('institution_id', $institutionId)
            ->when($membership->campus_id !== null, fn ($query) => $query->whereKey($membership->campus_id))
            ->orderBy('name')->get(['id', 'name'])->map(fn ($campus): array => ['id' => (int) $campus->getKey(), 'name' => (string) $campus->name])->values()->all();

        return ['metrics' => $metrics, 'trend' => $trend, 'campuses' => $campuses, 'filters' => [...$filters, 'metrics' => $filters['metrics']], 'generated_at' => now()->toIso8601String()];
    }

    private function campusScope(TenantMembership $membership, ?int $requestedCampus): ?int
    {
        if ($membership->campus_id !== null) {
            if ($requestedCampus !== null && $requestedCampus !== (int) $membership->campus_id) {
                throw new NotFoundHttpException;
            }

            return (int) $membership->campus_id;
        }
        if ($requestedCampus === null) {
            return null;
        }
        $exists = Campus::query()->where('tenant_id', $membership->tenant_id)->where('institution_id', $membership->institution_id)->whereKey($requestedCampus)->exists();
        if (! $exists) {
            throw new NotFoundHttpException;
        }

        return $requestedCampus;
    }
}
