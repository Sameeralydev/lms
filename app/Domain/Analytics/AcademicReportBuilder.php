<?php

namespace App\Domain\Analytics;

use App\Models\Campus;
use App\Models\TenantMembership;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Curated, tenant-scoped reports. Every source, field, and SQL expression is
 * declared here; callers can never supply a table, column, or SQL fragment.
 */
final class AcademicReportBuilder
{
    private const PAGE_SIZE = 25;

    private const MAX_PAGE_SIZE = 100;

    private const MAX_EXPORT_ROWS = 5000;

    /** @return array<string,array{label:string,permission:string,dimensions:array<string,string>,measures:array<string,array{label:string,expression:string}>,status:string|null,date:string,filters:array<string,array{label:string,expression:string}>}> */
    public static function definitions(): array
    {
        return [
            'enrollments' => [
                'label' => 'Course enrollments',
                'permission' => 'enrollment.registrar.view',
                'dimensions' => ['status' => 'Enrollment status', 'campus' => 'Campus', 'source' => 'Registration source', 'confirmed_date' => 'Confirmation date'],
                'measures' => ['records' => ['label' => 'Enrollments', 'expression' => 'COUNT(*)'], 'unique_learners' => ['label' => 'Unique learners', 'expression' => 'COUNT(DISTINCT enrollments.student_id)']],
                'status' => 'enrollments.status',
                'date' => 'enrollments.confirmed_at',
                'filters' => ['status' => ['label' => 'Enrollment status', 'expression' => 'enrollments.status'], 'source' => ['label' => 'Registration source', 'expression' => 'enrollments.registration_source']],
            ],
            'admissions' => [
                'label' => 'Admission applications',
                'permission' => 'admissions.applications.view',
                'dimensions' => ['status' => 'Application status', 'eligibility' => 'Eligibility status', 'campus' => 'Campus', 'intake' => 'Intake', 'submitted_date' => 'Submission date'],
                'measures' => ['records' => ['label' => 'Applications', 'expression' => 'COUNT(*)']],
                'status' => 'applications.status',
                'date' => 'applications.submitted_at',
                'filters' => ['status' => ['label' => 'Application status', 'expression' => 'applications.status'], 'eligibility' => ['label' => 'Eligibility', 'expression' => 'applications.eligibility_status']],
            ],
            'attendance' => [
                'label' => 'Finalized attendance',
                'permission' => 'attendance.report.view',
                'dimensions' => ['status' => 'Attendance status', 'campus' => 'Campus', 'date' => 'Occurrence date'],
                'measures' => ['records' => ['label' => 'Attendance entries', 'expression' => 'COUNT(*)'], 'unique_learners' => ['label' => 'Unique learners', 'expression' => 'COUNT(DISTINCT entries.student_id)']],
                'status' => 'statuses.code',
                'date' => 'registers.occurrence_date',
                'filters' => ['status' => ['label' => 'Attendance status', 'expression' => 'statuses.code']],
            ],
            'exam_sessions' => [
                'label' => 'Examination sessions',
                'permission' => 'exams.analytics.view',
                'dimensions' => ['status' => 'Session status', 'campus' => 'Campus', 'date' => 'Session date'],
                'measures' => ['records' => ['label' => 'Sessions', 'expression' => 'COUNT(*)']],
                'status' => 'sessions.status',
                'date' => 'sessions.starts_at_utc',
                'filters' => ['status' => ['label' => 'Session status', 'expression' => 'sessions.status']],
            ],
            'students' => [
                'label' => 'Student profiles',
                'permission' => 'sis.view',
                'dimensions' => ['status' => 'Student status', 'campus' => 'Current or home campus', 'student_type' => 'Student type', 'admitted_date' => 'Admission date'],
                'measures' => ['records' => ['label' => 'Students', 'expression' => 'COUNT(DISTINCT students.id)']],
                'status' => 'students.status',
                'date' => 'students.admission_date',
                'filters' => ['status' => ['label' => 'Student status', 'expression' => 'students.status'], 'student_type' => ['label' => 'Student type', 'expression' => 'students.student_type']],
            ],
            'gradebooks' => [
                'label' => 'Academic gradebooks',
                'permission' => 'academic.grades.book.view',
                'dimensions' => ['status' => 'Gradebook status', 'campus' => 'Campus', 'period' => 'Academic period'],
                'measures' => ['records' => ['label' => 'Gradebooks', 'expression' => 'COUNT(DISTINCT gradebooks.id)']],
                'status' => 'gradebooks.status',
                'date' => 'gradebooks.created_at',
                'filters' => ['status' => ['label' => 'Gradebook status', 'expression' => 'gradebooks.status']],
            ],
            'gpa_snapshots' => [
                'label' => 'Current GPA and academic-standing snapshots',
                'permission' => 'academic.records.view-all',
                'dimensions' => ['standing' => 'Academic standing', 'campus' => 'Current or home campus', 'calculation_type' => 'Calculation type', 'policy' => 'GPA policy', 'date' => 'Calculation date'],
                'measures' => ['records' => ['label' => 'GPA snapshots', 'expression' => 'COUNT(DISTINCT snapshots.id)'], 'unique_learners' => ['label' => 'Unique learners', 'expression' => 'COUNT(DISTINCT snapshots.student_id)'], 'average_gpa' => ['label' => 'Average snapshot GPA', 'expression' => 'AVG(snapshots.gpa)']],
                'status' => null,
                'date' => 'snapshots.calculated_at',
                'filters' => ['calculation_type' => ['label' => 'Calculation type', 'expression' => 'snapshots.calculation_type'], 'scope_key' => ['label' => 'Calculation scope', 'expression' => 'snapshots.scope_key']],
            ],
            'assignments' => [
                'label' => 'Published coursework assignments',
                'permission' => 'assignments.report.view',
                'dimensions' => ['status' => 'Assignment status', 'campus' => 'Campus', 'date' => 'Publication date', 'type' => 'Assignment type', 'category' => 'Category'],
                'measures' => ['records' => ['label' => 'Assignments', 'expression' => 'COUNT(DISTINCT assignments.id)']],
                'status' => 'assignments.status',
                'date' => 'assignments.published_at',
                'filters' => ['status' => ['label' => 'Assignment status', 'expression' => 'assignments.status'], 'type' => ['label' => 'Assignment type', 'expression' => 'assignments.assignment_type'], 'category' => ['label' => 'Category', 'expression' => 'assignments.category']],
            ],
            'assignment_submissions' => [
                'label' => 'Submitted coursework',
                'permission' => 'assignments.report.view',
                'dimensions' => ['status' => 'Submission status', 'campus' => 'Campus', 'date' => 'Latest submission date', 'type' => 'Assignment type'],
                'measures' => ['records' => ['label' => 'Submissions', 'expression' => 'COUNT(DISTINCT submissions.id)'], 'unique_learners' => ['label' => 'Unique learners', 'expression' => 'COUNT(DISTINCT recipients.student_id)']],
                'status' => 'submissions.status',
                'date' => 'attempts.submitted_at',
                'filters' => ['status' => ['label' => 'Submission status', 'expression' => 'submissions.status'], 'type' => ['label' => 'Assignment type', 'expression' => 'assignments.assignment_type']],
            ],
            'quizzes' => [
                'label' => 'Published quizzes',
                'permission' => 'assessment.analytics.view',
                'dimensions' => ['status' => 'Quiz status', 'campus' => 'Campus', 'date' => 'Publication date'],
                'measures' => ['records' => ['label' => 'Quizzes', 'expression' => 'COUNT(DISTINCT quizzes.id)']],
                'status' => 'quizzes.status',
                'date' => 'quizzes.published_at',
                'filters' => ['status' => ['label' => 'Quiz status', 'expression' => 'quizzes.status']],
            ],
            'quiz_results' => [
                'label' => 'Released quiz attempts',
                'permission' => 'assessment.analytics.view',
                'dimensions' => ['status' => 'Attempt status', 'campus' => 'Campus', 'date' => 'Result release date'],
                'measures' => ['records' => ['label' => 'Released attempts', 'expression' => 'COUNT(DISTINCT attempts.id)'], 'unique_learners' => ['label' => 'Unique learners', 'expression' => 'COUNT(DISTINCT attempts.student_id)']],
                'status' => 'attempts.status',
                'date' => 'attempts.result_released_at',
                'filters' => ['status' => ['label' => 'Attempt status', 'expression' => 'attempts.status']],
            ],
            'exam_results' => [
                'label' => 'Released examination results',
                'permission' => 'exams.analytics.view',
                'dimensions' => ['status' => 'Result status', 'outcome' => 'Outcome', 'campus' => 'Campus', 'date' => 'Release date'],
                'measures' => ['records' => ['label' => 'Released results', 'expression' => 'COUNT(DISTINCT results.id)']],
                'status' => 'results.status',
                'date' => 'results.released_at',
                'filters' => ['status' => ['label' => 'Result status', 'expression' => 'results.status'], 'outcome' => ['label' => 'Outcome', 'expression' => 'results.outcome']],
            ],
            'academic_records' => [
                'label' => 'Issued official academic records',
                'permission' => 'academic.records.view-all',
                'dimensions' => ['type' => 'Record type', 'campus' => 'Home campus', 'date' => 'Issue date'],
                'measures' => ['records' => ['label' => 'Issued records', 'expression' => 'COUNT(DISTINCT issues.id)']],
                'status' => null,
                'date' => 'issues.issued_at',
                'filters' => ['type' => ['label' => 'Record type', 'expression' => 'issues.record_type']],
            ],
            'admission_fees' => [
                'label' => 'Admission application fees',
                'permission' => 'admissions.fees.manage',
                'dimensions' => ['status' => 'Fee obligation status', 'campus' => 'Campus', 'currency' => 'Currency', 'date' => 'Application date'],
                'measures' => ['records' => ['label' => 'Fee obligations', 'expression' => 'COUNT(DISTINCT fee_obligations.id)'], 'billed_amount' => ['label' => 'Billed amount', 'expression' => 'SUM(fee_obligations.amount)']],
                'status' => 'fee_obligations.status',
                'date' => 'applications.created_at',
                'filters' => ['status' => ['label' => 'Fee status', 'expression' => 'fee_obligations.status'], 'currency' => ['label' => 'Currency', 'expression' => 'fee_obligations.currency']],
            ],
        ];
    }

    /** @return array<string,array{label:string,permission:string,dimensions:array<string,string>,measures:array<string,array{label:string,expression:string}>,status:string|null,date:string,filters:array<string,array{label:string,expression:string}>}> */
    public static function definitionsFor(TenantMembership $membership): array
    {
        return array_filter(self::definitions(), static fn (array $definition): bool => $membership->hasPermission($definition['permission']));
    }

    public static function defaultDatasetFor(TenantMembership $membership): ?string
    {
        $key = array_key_first(self::definitionsFor($membership));

        return is_string($key) ? $key : null;
    }

    /** @param array{dataset?:string,measure?:string,dimensions?:list<string>,campus_id?:int|null,from?:string|null,to?:string|null,status?:string|null,filters?:mixed,search?:string|null,sort?:string|null,direction?:string|null,page?:int|null,per_page?:int|null} $input
     * @return array{definitions:array<string,mixed>,dataset:string,measure:string,measure_label:string,dimensions:list<string>,campus_id:int|null,from:string|null,to:string|null,status:string|null,filters:array<string,string>,search:string,sort:string,direction:string,page:int,per_page:int,total:int,rows:list<array<string,mixed>>,export_limit:int}
     */
    public function build(TenantMembership $membership, array $input, bool $forExport = false): array
    {
        $definitions = self::definitions();
        $dataset = $input['dataset'] ?? self::defaultDatasetFor($membership);
        abort_unless(is_string($dataset), 403, 'You do not have access to an academic report source.');
        abort_unless(isset($definitions[$dataset]), 422, 'Choose a supported report dataset.');
        $definition = $definitions[$dataset];
        abort_unless($membership->hasPermission($definition['permission']), 403, 'You do not have permission to use this report source.');
        $measure = $input['measure'] ?? 'records';
        abort_unless(isset($definition['measures'][$measure]), 422, 'Choose a supported measure for this dataset.');
        $dimensions = array_values(array_unique($input['dimensions'] ?? ['status']));
        abort_unless(count($dimensions) >= 1 && count($dimensions) <= 2, 422, 'Choose one or two report dimensions.');
        foreach ($dimensions as $dimension) {
            abort_unless(isset($definition['dimensions'][$dimension]), 422, 'One or more report dimensions are not available for this dataset.');
        }

        $campusId = $this->campusScope($membership, isset($input['campus_id']) ? (int) $input['campus_id'] : null);
        $from = $input['from'] ?? null;
        $to = $input['to'] ?? null;
        $status = $input['status'] ?? null;
        if ($status !== null && $definition['status'] === null) {
            abort(422, 'Status filtering is not available for this dataset.');
        }

        $base = $this->baseQuery($dataset, (int) $membership->tenant_id, (int) $membership->institution_id);
        if ($campusId !== null) {
            $base->whereRaw($this->campusIdExpression($dataset).' = ?', [$campusId]);
        }
        if ($from !== null) {
            $base->whereDate($definition['date'], '>=', $from);
        }
        if ($to !== null) {
            $base->whereDate($definition['date'], '<=', $to);
        }
        if ($status !== null) {
            $base->where($definition['status'], $status);
        }
        $fieldFilters = $input['filters'] ?? [];
        abort_unless(is_array($fieldFilters) && count($fieldFilters) <= 6, 422, 'Choose supported report filters.');
        foreach ($fieldFilters as $field => $value) {
            abort_unless(is_string($field) && isset($definition['filters'][$field]), 422, 'One or more report filters are not available for this dataset.');
            abort_unless(is_string($value) && mb_strlen($value) <= 100, 422, 'Report filter values must be text up to 100 characters.');
            $base->where($definition['filters'][$field]['expression'], $value);
        }

        $grouped = clone $base;
        $selects = [];
        $groupBy = [];
        foreach ($dimensions as $dimension) {
            $expression = $this->dimensionExpression($dataset, $dimension);
            $selects[] = $expression.' as `'.$dimension.'`';
            $groupBy[] = $expression;
        }
        $measureExpression = $definition['measures'][$measure]['expression'];
        $grouped->selectRaw(implode(', ', $selects).', '.$measureExpression.' as `total`')->groupByRaw(implode(', ', $groupBy));
        $query = DB::query()->fromSub($grouped, 'report_rows');

        $search = trim((string) ($input['search'] ?? ''));
        if ($search !== '') {
            $query->where(function (Builder $nested) use ($dimensions, $search): void {
                foreach ($dimensions as $dimension) {
                    $nested->orWhere($dimension, 'like', '%'.str_replace(['%', '_'], ['\\%', '\\_'], $search).'%');
                }
            });
        }

        $sort = $input['sort'] ?? 'total';
        abort_unless($sort === 'total' || in_array($sort, $dimensions, true), 422, 'Choose a report column to sort by.');
        $direction = ($input['direction'] ?? 'desc') === 'asc' ? 'asc' : 'desc';
        $total = (clone $query)->count();
        $limit = $forExport ? self::MAX_EXPORT_ROWS : min(self::MAX_PAGE_SIZE, max(1, (int) ($input['per_page'] ?? self::PAGE_SIZE)));
        $pageCount = max(1, (int) ceil($total / $limit));
        $page = min($pageCount, max(1, (int) ($input['page'] ?? 1)));
        $rows = $query->orderBy($sort, $direction)->orderBy('total', 'desc')->forPage($forExport ? 1 : $page, $limit)->get()->map(static fn (object $row): array => (array) $row)->all();

        return [
            'definitions' => array_map(static fn (array $item): array => ['label' => $item['label'], 'dimensions' => $item['dimensions'], 'measures' => array_map(static fn (array $measure): string => $measure['label'], $item['measures']), 'filters' => array_map(static fn (array $filter): string => $filter['label'], $item['filters']), 'status_filter' => $item['status'] !== null], self::definitionsFor($membership)),
            'dataset' => $dataset,
            'measure' => $measure,
            'measure_label' => $definition['measures'][$measure]['label'],
            'dimensions' => $dimensions,
            'campus_id' => $campusId,
            'from' => $from,
            'to' => $to,
            'status' => $status,
            'filters' => $fieldFilters,
            'search' => $search,
            'sort' => $sort,
            'direction' => $direction,
            'page' => $forExport ? 1 : $page,
            'per_page' => $limit,
            'total' => (int) $total,
            'rows' => $rows,
            'export_limit' => self::MAX_EXPORT_ROWS,
        ];
    }

    /** @param array{dataset?:string,dimension?:string,value?:string,campus_id?:int|null,from?:string|null,to?:string|null,status?:string|null,filters?:mixed,measure?:string,page?:int|null,per_page?:int|null} $input
     * @return array{dataset:string,dimension:string,value:string,measure:string,page:int,per_page:int,total:int,rows:list<array<string,mixed>>}
     */
    public function drilldown(TenantMembership $membership, array $input): array
    {
        $definitions = self::definitions();
        $dataset = (string) ($input['dataset'] ?? '');
        abort_unless(isset($definitions[$dataset]), 422, 'Choose a supported report dataset.');
        $dimension = (string) ($input['dimension'] ?? '');
        abort_unless(isset($definitions[$dataset]['dimensions'][$dimension]), 422, 'Choose a supported report dimension.');
        $value = (string) ($input['value'] ?? '');
        abort_unless(mb_strlen($value) <= 100, 422, 'The selected group value is too long.');
        $campusId = $this->campusScope($membership, isset($input['campus_id']) ? (int) $input['campus_id'] : null);
        $base = $this->baseQuery($dataset, (int) $membership->tenant_id, (int) $membership->institution_id);
        if ($campusId !== null) {
            $base->whereRaw($this->campusIdExpression($dataset).' = ?', [$campusId]);
        }
        $definition = $definitions[$dataset];
        abort_unless($membership->hasPermission($definition['permission']), 403, 'You do not have permission to inspect this report source.');
        $measure = (string) ($input['measure'] ?? 'records');
        abort_unless(isset($definition['measures'][$measure]), 422, 'Choose a supported measure for this dataset.');
        if ($measure === 'unique_learners') {
            abort_unless(in_array($dataset, ['enrollments', 'attendance', 'assignment_submissions', 'quiz_results', 'gpa_snapshots'], true), 422, 'Learner drill-down is not available for this dataset.');
        }
        if (! empty($input['from'])) {
            $base->whereDate($definition['date'], '>=', $input['from']);
        }
        if (! empty($input['to'])) {
            $base->whereDate($definition['date'], '<=', $input['to']);
        }
        if (! empty($input['status']) && $definition['status'] !== null) {
            $base->where($definition['status'], $input['status']);
        }
        $fieldFilters = $input['filters'] ?? [];
        abort_unless(is_array($fieldFilters) && count($fieldFilters) <= 6, 422, 'Choose supported report filters.');
        foreach ($fieldFilters as $field => $filterValue) {
            abort_unless(is_string($field) && isset($definition['filters'][$field]) && is_string($filterValue) && mb_strlen($filterValue) <= 100, 422, 'One or more report filters are invalid.');
            $base->where($definition['filters'][$field]['expression'], $filterValue);
        }
        $groupExpression = $this->dimensionExpression($dataset, $dimension);
        $base->whereRaw($groupExpression.' = ?', [$value]);

        $columns = $measure === 'unique_learners' ? match ($dataset) {
            'enrollments' => ['enrollments.student_id as record_id', DB::raw("'learner' as status"), DB::raw('NULL as occurred_at'), DB::raw('MIN(campuses.name) as campus')],
            'attendance' => ['entries.student_id as record_id', DB::raw("'learner' as status"), DB::raw('NULL as occurred_at'), DB::raw('MIN(campuses.name) as campus')],
            'assignment_submissions' => ['recipients.student_id as record_id', DB::raw("'learner' as status"), DB::raw('NULL as occurred_at'), DB::raw('MIN(campuses.name) as campus')],
            'quiz_results' => ['attempts.student_id as record_id', DB::raw("'learner' as status"), DB::raw('NULL as occurred_at'), DB::raw('MIN(campuses.name) as campus')],
            'gpa_snapshots' => ['students.id as record_id', DB::raw("'learner' as status"), DB::raw('NULL as occurred_at'), DB::raw("MIN(COALESCE(placement_campuses.name, campuses.name, 'Unassigned')) as campus")],
            default => throw new \LogicException('Unsupported unique learner drill-down.'),
        } : match ($dataset) {
            'enrollments' => ['enrollments.id as record_id', 'enrollments.status as status', 'enrollments.confirmed_at as occurred_at', 'campuses.name as campus'],
            'admissions' => ['applications.id as record_id', 'applications.status as status', 'applications.submitted_at as occurred_at', 'campuses.name as campus'],
            'attendance' => ['entries.id as record_id', 'statuses.code as status', 'registers.occurrence_date as occurred_at', 'campuses.name as campus'],
            'assignment_submissions' => ['submissions.id as record_id', 'submissions.status as status', 'attempts.submitted_at as occurred_at', 'campuses.name as campus'],
            'exam_sessions' => ['sessions.id as record_id', 'sessions.status as status', 'sessions.starts_at_utc as occurred_at', 'campuses.name as campus'],
            'students' => ['students.id as record_id', 'students.status as status', 'students.admission_date as occurred_at', DB::raw("COALESCE(placement_campuses.name, campuses.name, 'Unassigned') as campus")],
            'gradebooks' => ['gradebooks.id as record_id', 'gradebooks.status as status', 'gradebooks.created_at as occurred_at', 'campuses.name as campus'],
            'assignments' => ['assignments.id as record_id', 'assignments.status as status', 'assignments.published_at as occurred_at', 'campuses.name as campus'],
            'quizzes' => ['quizzes.id as record_id', 'quizzes.status as status', 'quizzes.published_at as occurred_at', 'campuses.name as campus'],
            'quiz_results' => ['attempts.id as record_id', 'attempts.status as status', 'attempts.result_released_at as occurred_at', 'campuses.name as campus'],
            'exam_results' => ['results.id as record_id', 'results.status as status', 'results.released_at as occurred_at', 'campuses.name as campus'],
            'academic_records' => ['issues.id as record_id', 'issues.record_type as status', 'issues.issued_at as occurred_at', DB::raw("COALESCE(campuses.name, 'Unassigned') as campus")],
            'gpa_snapshots' => ['snapshots.id as record_id', 'snapshots.calculation_type as status', 'snapshots.calculated_at as occurred_at', DB::raw("COALESCE(placement_campuses.name, campuses.name, 'Unassigned') as campus")],
            'admission_fees' => ['fee_obligations.id as record_id', 'fee_obligations.status as status', 'applications.created_at as occurred_at', DB::raw("COALESCE(campuses.name, 'Unassigned') as campus")],
            default => throw new \LogicException('Unregistered analytics dataset.'),
        };
        $valueExpression = match ($measure) {
            'average_gpa' => 'snapshots.gpa',
            'billed_amount' => 'fee_obligations.amount',
            default => '1',
        };
        $columns[] = DB::raw($valueExpression.' as measure_value');
        $query = clone $base;
        if ($measure === 'unique_learners') {
            $query->select($columns)->groupBy('record_id');
        } else {
            $query->select($columns)->distinct();
        }
        $total = DB::query()->fromSub(clone $query, 'detail_rows')->count();
        $perPage = min(self::MAX_PAGE_SIZE, max(1, (int) ($input['per_page'] ?? self::PAGE_SIZE)));
        $page = min(max(1, (int) ($input['page'] ?? 1)), max(1, (int) ceil($total / $perPage)));

        return ['dataset' => $dataset, 'dimension' => $dimension, 'value' => $value, 'measure' => $measure, 'page' => $page, 'per_page' => $perPage, 'total' => (int) $total, 'rows' => $query->orderBy('record_id')->forPage($page, $perPage)->get()->map(static fn (object $row): array => (array) $row)->all()];
    }

    private function baseQuery(string $dataset, int $tenantId, int $institutionId): Builder
    {
        return match ($dataset) {
            'enrollments' => DB::table('course_enrollments as enrollments')
                ->join('course_sections as sections', fn ($join) => $join->on('sections.id', '=', 'enrollments.section_id')->on('sections.tenant_id', '=', 'enrollments.tenant_id'))
                ->join('course_offerings as offerings', fn ($join) => $join->on('offerings.id', '=', 'enrollments.offering_id')->on('offerings.tenant_id', '=', 'enrollments.tenant_id')->on('offerings.institution_id', '=', 'enrollments.institution_id')->on('sections.offering_id', '=', 'offerings.id'))
                ->join('campuses', fn ($join) => $join->on('campuses.id', '=', 'sections.campus_id')->on('campuses.tenant_id', '=', 'sections.tenant_id')->on('campuses.institution_id', '=', 'enrollments.institution_id'))
                ->where('enrollments.tenant_id', $tenantId)->where('enrollments.institution_id', $institutionId),
            'admissions' => DB::table('admission_applications as applications')
                ->join('admission_intakes as intakes', fn ($join) => $join->on('intakes.id', '=', 'applications.intake_id')->on('intakes.tenant_id', '=', 'applications.tenant_id')->on('intakes.institution_id', '=', 'applications.institution_id'))
                ->leftJoin('campuses', fn ($join) => $join->on('campuses.id', '=', 'intakes.campus_id')->on('campuses.tenant_id', '=', 'intakes.tenant_id')->on('campuses.institution_id', '=', 'applications.institution_id'))
                ->where('applications.tenant_id', $tenantId)->where('applications.institution_id', $institutionId),
            'attendance' => DB::table('attendance_entries as entries')
                ->join('attendance_registers as registers', fn ($join) => $join->on('registers.id', '=', 'entries.register_id')->on('registers.tenant_id', '=', 'entries.tenant_id'))
                ->join('attendance_status_definitions as statuses', fn ($join) => $join->on('statuses.id', '=', 'entries.status_definition_id')->on('statuses.tenant_id', '=', 'entries.tenant_id'))
                ->join('campuses', fn ($join) => $join->on('campuses.id', '=', 'registers.campus_id')->on('campuses.tenant_id', '=', 'registers.tenant_id')->on('campuses.institution_id', '=', 'registers.institution_id'))
                ->where('entries.tenant_id', $tenantId)->where('registers.institution_id', $institutionId)->where('registers.status', 'finalized'),
            'exam_sessions' => DB::table('exam_sessions as sessions')
                ->join('exam_cycles as cycles', fn ($join) => $join->on('cycles.id', '=', 'sessions.cycle_id')->on('cycles.tenant_id', '=', 'sessions.tenant_id'))
                ->join('campuses', fn ($join) => $join->on('campuses.id', '=', 'sessions.campus_id')->on('campuses.tenant_id', '=', 'sessions.tenant_id')->on('campuses.institution_id', '=', 'cycles.institution_id'))
                ->where('sessions.tenant_id', $tenantId)->where('cycles.institution_id', $institutionId),
            'students' => $this->studentsQuery($tenantId, $institutionId),
            'gradebooks' => DB::table('academic_gradebooks as gradebooks')
                ->join('course_sections as sections', fn ($join) => $join->on('sections.id', '=', 'gradebooks.section_id')->on('sections.tenant_id', '=', 'gradebooks.tenant_id'))
                ->join('campuses', fn ($join) => $join->on('campuses.id', '=', 'sections.campus_id')->on('campuses.tenant_id', '=', 'sections.tenant_id')->on('campuses.institution_id', '=', 'gradebooks.institution_id'))
                ->join('academic_periods as periods', fn ($join) => $join->on('periods.id', '=', 'gradebooks.academic_period_id')->on('periods.tenant_id', '=', 'gradebooks.tenant_id'))
                ->where('gradebooks.tenant_id', $tenantId)->where('gradebooks.institution_id', $institutionId),
            'gpa_snapshots' => $this->gpaSnapshotsQuery($tenantId, $institutionId),
            'assignments' => DB::table('assignments')
                ->join('assignment_sections', fn ($join) => $join->on('assignment_sections.assignment_id', '=', 'assignments.id')->on('assignment_sections.tenant_id', '=', 'assignments.tenant_id'))
                ->join('course_sections as sections', fn ($join) => $join->on('sections.id', '=', 'assignment_sections.section_id')->on('sections.tenant_id', '=', 'assignment_sections.tenant_id')->on('sections.offering_id', '=', 'assignments.offering_id'))
                ->join('campuses', fn ($join) => $join->on('campuses.id', '=', 'sections.campus_id')->on('campuses.tenant_id', '=', 'sections.tenant_id')->on('campuses.institution_id', '=', 'assignments.institution_id'))
                ->where('assignments.tenant_id', $tenantId)->where('assignments.institution_id', $institutionId)->where('assignments.status', 'published'),
            'assignment_submissions' => DB::table('assignment_submissions as submissions')
                ->join('assignment_recipients as recipients', fn ($join) => $join->on('recipients.id', '=', 'submissions.recipient_id')->on('recipients.tenant_id', '=', 'submissions.tenant_id')->on('recipients.assignment_id', '=', 'submissions.assignment_id'))
                ->join('assignments', fn ($join) => $join->on('assignments.id', '=', 'submissions.assignment_id')->on('assignments.tenant_id', '=', 'submissions.tenant_id'))
                ->join('assignment_attempts as attempts', fn ($join) => $join->on('attempts.submission_id', '=', 'submissions.id')->on('attempts.tenant_id', '=', 'submissions.tenant_id'))
                ->join('course_sections as sections', fn ($join) => $join->on('sections.id', '=', 'recipients.section_id')->on('sections.tenant_id', '=', 'recipients.tenant_id')->on('sections.offering_id', '=', 'assignments.offering_id'))
                ->join('assignment_sections', fn ($join) => $join->on('assignment_sections.assignment_id', '=', 'assignments.id')->on('assignment_sections.tenant_id', '=', 'assignments.tenant_id')->on('assignment_sections.section_id', '=', 'sections.id'))
                ->join('campuses', fn ($join) => $join->on('campuses.id', '=', 'sections.campus_id')->on('campuses.tenant_id', '=', 'sections.tenant_id')->on('campuses.institution_id', '=', 'assignments.institution_id'))
                ->where('submissions.tenant_id', $tenantId)->where('assignments.institution_id', $institutionId)->where('assignments.status', 'published')->where('submissions.status', 'submitted')->where('attempts.status', 'submitted')->whereNull('attempts.withdrawn_at')->whereNotNull('attempts.submitted_at')
                ->whereNotExists(fn ($query) => $query->selectRaw('1')->from('assignment_attempts as newer')->whereColumn('newer.tenant_id', 'attempts.tenant_id')->whereColumn('newer.submission_id', 'attempts.submission_id')->where('newer.status', 'submitted')->whereNull('newer.withdrawn_at')->whereNotNull('newer.submitted_at')->where(fn ($nested) => $nested->whereColumn('newer.submitted_at', '>', 'attempts.submitted_at')->orWhere(fn ($tie) => $tie->whereColumn('newer.submitted_at', '=', 'attempts.submitted_at')->whereColumn('newer.id', '>', 'attempts.id')))),
            'quizzes' => DB::table('quizzes')
                ->join('quiz_sections', fn ($join) => $join->on('quiz_sections.quiz_id', '=', 'quizzes.id')->on('quiz_sections.tenant_id', '=', 'quizzes.tenant_id'))
                ->join('course_sections as sections', fn ($join) => $join->on('sections.id', '=', 'quiz_sections.section_id')->on('sections.tenant_id', '=', 'quiz_sections.tenant_id')->on('sections.offering_id', '=', 'quizzes.offering_id'))
                ->join('campuses', fn ($join) => $join->on('campuses.id', '=', 'sections.campus_id')->on('campuses.tenant_id', '=', 'sections.tenant_id')->on('campuses.institution_id', '=', 'quizzes.institution_id'))
                ->where('quizzes.tenant_id', $tenantId)->where('quizzes.institution_id', $institutionId)->where('quizzes.status', 'published'),
            'quiz_results' => DB::table('quiz_attempts as attempts')
                ->join('quizzes', fn ($join) => $join->on('quizzes.id', '=', 'attempts.quiz_id')->on('quizzes.tenant_id', '=', 'attempts.tenant_id'))
                ->join('quiz_sections', fn ($join) => $join->on('quiz_sections.quiz_id', '=', 'quizzes.id')->on('quiz_sections.tenant_id', '=', 'quizzes.tenant_id'))
                ->join('course_sections as sections', fn ($join) => $join->on('sections.id', '=', 'quiz_sections.section_id')->on('sections.tenant_id', '=', 'quiz_sections.tenant_id')->on('sections.offering_id', '=', 'quizzes.offering_id'))
                ->join('course_enrollments as enrollments', fn ($join) => $join->on('enrollments.section_id', '=', 'sections.id')->on('enrollments.tenant_id', '=', 'sections.tenant_id')->on('enrollments.student_id', '=', 'attempts.student_id')->on('enrollments.offering_id', '=', 'quizzes.offering_id'))
                ->join('campuses', fn ($join) => $join->on('campuses.id', '=', 'sections.campus_id')->on('campuses.tenant_id', '=', 'sections.tenant_id')->on('campuses.institution_id', '=', 'quizzes.institution_id'))
                ->where('attempts.tenant_id', $tenantId)->where('quizzes.institution_id', $institutionId)->where('quizzes.status', 'published')->where('enrollments.status', 'confirmed')->whereNotNull('attempts.result_released_at'),
            'exam_results' => DB::table('exam_results as results')
                ->join('exam_sessions as sessions', fn ($join) => $join->on('sessions.id', '=', 'results.session_id')->on('sessions.tenant_id', '=', 'results.tenant_id'))
                ->join('exam_cycles as cycles', fn ($join) => $join->on('cycles.id', '=', 'sessions.cycle_id')->on('cycles.tenant_id', '=', 'sessions.tenant_id'))
                ->join('campuses', fn ($join) => $join->on('campuses.id', '=', 'sessions.campus_id')->on('campuses.tenant_id', '=', 'sessions.tenant_id')->on('campuses.institution_id', '=', 'cycles.institution_id'))
                ->where('results.tenant_id', $tenantId)->where('cycles.institution_id', $institutionId)->whereNotNull('results.released_at'),
            'academic_records' => DB::table('academic_record_issues as issues')
                ->join('sis_students as students', fn ($join) => $join->on('students.id', '=', 'issues.student_id')->on('students.tenant_id', '=', 'issues.tenant_id'))
                ->leftJoin('campuses', fn ($join) => $join->on('campuses.id', '=', 'students.home_campus_id')->on('campuses.tenant_id', '=', 'students.tenant_id')->on('campuses.institution_id', '=', 'issues.institution_id'))
                ->where('issues.tenant_id', $tenantId)->where('issues.institution_id', $institutionId)->where('issues.status', 'issued')->whereNull('issues.revoked_at'),
            'admission_fees' => DB::table('admission_fee_obligations as fee_obligations')
                ->join('admission_applications as applications', fn ($join) => $join->on('applications.id', '=', 'fee_obligations.application_id')->on('applications.tenant_id', '=', 'fee_obligations.tenant_id'))
                ->join('admission_intakes as intakes', fn ($join) => $join->on('intakes.id', '=', 'applications.intake_id')->on('intakes.tenant_id', '=', 'applications.tenant_id')->on('intakes.institution_id', '=', 'applications.institution_id'))
                ->leftJoin('campuses', fn ($join) => $join->on('campuses.id', '=', 'intakes.campus_id')->on('campuses.tenant_id', '=', 'applications.tenant_id')->on('campuses.institution_id', '=', 'applications.institution_id'))
                ->where('fee_obligations.tenant_id', $tenantId)->where('applications.institution_id', $institutionId),
            default => throw new \LogicException('Unregistered analytics dataset.'),
        };
    }

    private function dimensionExpression(string $dataset, string $dimension): string
    {
        $expressions = [
            'enrollments' => ['status' => 'enrollments.status', 'campus' => 'campuses.name', 'source' => 'enrollments.registration_source', 'confirmed_date' => 'DATE(enrollments.confirmed_at)'],
            'admissions' => ['status' => 'applications.status', 'eligibility' => 'applications.eligibility_status', 'campus' => "COALESCE(campuses.name, 'Unassigned')", 'intake' => 'intakes.name', 'submitted_date' => 'DATE(applications.submitted_at)'],
            'attendance' => ['status' => 'statuses.code', 'campus' => 'campuses.name', 'date' => 'DATE(registers.occurrence_date)'],
            'exam_sessions' => ['status' => 'sessions.status', 'campus' => 'campuses.name', 'date' => 'DATE(sessions.starts_at_utc)'],
            'students' => ['status' => 'students.status', 'campus' => "COALESCE(placement_campuses.name, campuses.name, 'Unassigned')", 'student_type' => 'students.student_type', 'admitted_date' => 'DATE(students.admission_date)'],
            'gradebooks' => ['status' => 'gradebooks.status', 'campus' => 'campuses.name', 'period' => 'periods.name'],
            'gpa_snapshots' => ['standing' => "COALESCE(snapshots.academic_standing, 'Unclassified')", 'campus' => "COALESCE(placement_campuses.name, campuses.name, 'Unassigned')", 'calculation_type' => 'snapshots.calculation_type', 'policy' => 'policies.name', 'date' => 'DATE(snapshots.calculated_at)'],
            'assignments' => ['status' => 'assignments.status', 'campus' => 'campuses.name', 'date' => 'DATE(assignments.published_at)', 'type' => 'assignments.assignment_type', 'category' => 'COALESCE(assignments.category, \'Uncategorized\')'],
            'assignment_submissions' => ['status' => 'submissions.status', 'campus' => 'campuses.name', 'date' => 'DATE(attempts.submitted_at)', 'type' => 'assignments.assignment_type'],
            'quizzes' => ['status' => 'quizzes.status', 'campus' => 'campuses.name', 'date' => 'DATE(quizzes.published_at)'],
            'quiz_results' => ['status' => 'attempts.status', 'campus' => 'campuses.name', 'date' => 'DATE(attempts.result_released_at)'],
            'exam_results' => ['status' => 'results.status', 'outcome' => 'results.outcome', 'campus' => 'campuses.name', 'date' => 'DATE(results.released_at)'],
            'academic_records' => ['type' => 'issues.record_type', 'campus' => "COALESCE(campuses.name, 'Unassigned')", 'date' => 'DATE(issues.issued_at)'],
            'admission_fees' => ['status' => 'fee_obligations.status', 'campus' => "COALESCE(campuses.name, 'Unassigned')", 'currency' => 'fee_obligations.currency', 'date' => 'DATE(applications.created_at)'],
        ];

        return $expressions[$dataset][$dimension] ?? throw new \LogicException('Unregistered analytics dimension.');
    }

    private function campusIdExpression(string $dataset): string
    {
        return $dataset === 'students' ? 'COALESCE(placement_campuses.id, students.home_campus_id)' : 'campuses.id';
    }

    private function studentsQuery(int $tenantId, int $institutionId): Builder
    {
        $today = today()->toDateString();
        $placements = DB::table('sis_student_placements as placement')
            ->select(['placement.tenant_id', 'placement.student_id', 'placement.campus_id'])
            ->where('placement.status', 'active')->whereDate('placement.effective_from', '<=', $today)
            ->where(fn ($query) => $query->whereNull('placement.effective_until')->orWhereDate('placement.effective_until', '>=', $today))
            ->whereNotExists(function ($query) use ($today): void {
                $query->selectRaw('1')->from('sis_student_placements as newer')
                    ->whereColumn('newer.tenant_id', 'placement.tenant_id')->whereColumn('newer.student_id', 'placement.student_id')
                    ->where('newer.status', 'active')->whereDate('newer.effective_from', '<=', $today)
                    ->where(fn ($nested) => $nested->whereNull('newer.effective_until')->orWhereDate('newer.effective_until', '>=', $today))
                    ->where(fn ($nested) => $nested->whereColumn('newer.effective_from', '>', 'placement.effective_from')
                        ->orWhere(fn ($tie) => $tie->whereColumn('newer.effective_from', '=', 'placement.effective_from')->whereColumn('newer.id', '>', 'placement.id')));
            });

        return DB::table('sis_students as students')
            ->leftJoinSub($placements, 'current_placements', fn ($join) => $join->on('current_placements.tenant_id', '=', 'students.tenant_id')->on('current_placements.student_id', '=', 'students.id'))
            ->leftJoin('campuses as placement_campuses', fn ($join) => $join->on('placement_campuses.id', '=', 'current_placements.campus_id')->on('placement_campuses.tenant_id', '=', 'students.tenant_id')->on('placement_campuses.institution_id', '=', 'students.institution_id'))
            ->leftJoin('campuses', fn ($join) => $join->on('campuses.id', '=', 'students.home_campus_id')->on('campuses.tenant_id', '=', 'students.tenant_id')->on('campuses.institution_id', '=', 'students.institution_id'))
            ->where('students.tenant_id', $tenantId)->where('students.institution_id', $institutionId);
    }

    private function gpaSnapshotsQuery(int $tenantId, int $institutionId): Builder
    {
        $latestPolicies = DB::table('academic_gpa_policies as policies')
            ->where('policies.tenant_id', $tenantId)->where('policies.institution_id', $institutionId)->where('policies.status', 'active')
            ->whereNotExists(fn ($query) => $query->selectRaw('1')->from('academic_gpa_policies as newer_policy')
                ->whereColumn('newer_policy.tenant_id', 'policies.tenant_id')->whereColumn('newer_policy.institution_id', 'policies.institution_id')
                ->whereColumn('newer_policy.policy_key', 'policies.policy_key')->where('newer_policy.status', 'active')
                ->whereColumn('newer_policy.version', '>', 'policies.version'))
            ->select('policies.id');
        $students = $this->studentsQuery($tenantId, $institutionId);

        return $students
            ->join('academic_gpa_snapshots as snapshots', fn ($join) => $join->on('snapshots.student_id', '=', 'students.id')->on('snapshots.tenant_id', '=', 'students.tenant_id')->on('snapshots.institution_id', '=', 'students.institution_id'))
            ->join('academic_gpa_policies as policies', fn ($join) => $join->on('policies.id', '=', 'snapshots.policy_id')->on('policies.tenant_id', '=', 'snapshots.tenant_id'))
            ->leftJoin('academic_periods as periods', fn ($join) => $join->on('periods.id', '=', 'snapshots.academic_period_id')->on('periods.tenant_id', '=', 'snapshots.tenant_id'))
            ->whereIn('snapshots.policy_id', $latestPolicies)
            ->whereNotExists(fn ($query) => $query->selectRaw('1')->from('academic_gpa_snapshots as newer_snapshot')
                ->whereColumn('newer_snapshot.tenant_id', 'snapshots.tenant_id')->whereColumn('newer_snapshot.student_id', 'snapshots.student_id')
                ->whereColumn('newer_snapshot.policy_id', 'snapshots.policy_id')->whereColumn('newer_snapshot.scope_key', 'snapshots.scope_key')
                ->where(fn ($nested) => $nested->whereColumn('newer_snapshot.snapshot_number', '>', 'snapshots.snapshot_number')
                    ->orWhere(fn ($tie) => $tie->whereColumn('newer_snapshot.snapshot_number', '=', 'snapshots.snapshot_number')->whereColumn('newer_snapshot.id', '>', 'snapshots.id'))))
            ->where('students.tenant_id', $tenantId)->where('students.institution_id', $institutionId);
    }

    private function campusScope(TenantMembership $membership, ?int $requestedCampus): ?int
    {
        if ($membership->campus_id !== null) {
            if ($requestedCampus !== null && $requestedCampus !== (int) $membership->campus_id) {
                throw new NotFoundHttpException;
            }

            return (int) $membership->campus_id;
        }
        if ($requestedCampus !== null && ! Campus::query()->where('tenant_id', $membership->tenant_id)->where('institution_id', $membership->institution_id)->whereKey($requestedCampus)->exists()) {
            throw new NotFoundHttpException;
        }

        return $requestedCampus;
    }
}
