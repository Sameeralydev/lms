<?php

namespace App\Http\Controllers\Workspace;

use App\Domain\Tenancy\TenantContext;
use App\Models\Tenant;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class AcademicReportingController
{
    public function index(Tenant $tenant, Request $request, TenantContext $context): Response
    {
        $membership = $context->membership();
        abort_unless($membership->hasPermission('academic.records.view-all'), 403);
        $filters = $request->validate(['record_type' => ['nullable', 'in:report_card,transcript,certificate'], 'status' => ['nullable', 'in:draft,submitted,approved,rejected,issued,revoked'], 'from' => ['nullable', 'date'], 'to' => ['nullable', 'date', 'after_or_equal:from']]);
        $base = DB::table('academic_record_issues as records')->join('sis_students as students', function ($join): void {
            $join->on('students.id', '=', 'records.student_id')->on('students.tenant_id', '=', 'records.tenant_id');
        })->where('records.tenant_id', $membership->tenant_id)->where('records.institution_id', $membership->institution_id)
            ->when($membership->campus_id !== null, fn ($query) => $query->where('students.home_campus_id', $membership->campus_id));
        $filtered = clone $base;
        $filtered->when(isset($filters['record_type']), fn ($query) => $query->where('records.record_type', $filters['record_type']))
            ->when(isset($filters['status']), fn ($query) => $query->where('records.status', $filters['status']))
            ->when(isset($filters['from']), fn ($query) => $query->whereDate('records.created_at', '>=', $filters['from']))
            ->when(isset($filters['to']), fn ($query) => $query->whereDate('records.created_at', '<=', $filters['to']));
        $rows = (clone $filtered)->orderByDesc('records.created_at')->paginate(25)->withQueryString()
            ->through(fn ($row): array => ['id' => (int) $row->id, 'student_number' => $row->student_number, 'record_type' => $row->record_type, 'status' => $row->status, 'issue_number' => (int) $row->issue_number, 'created_at' => $row->created_at, 'issued_at' => $row->issued_at]);
        $standing = DB::table('academic_gpa_snapshots as snapshots')->join('sis_students as students', function ($join): void {
            $join->on('students.id', '=', 'snapshots.student_id')->on('students.tenant_id', '=', 'snapshots.tenant_id');
        })->where('snapshots.tenant_id', $membership->tenant_id)->where('students.institution_id', $membership->institution_id)
            ->when($membership->campus_id !== null, fn ($query) => $query->where('students.home_campus_id', $membership->campus_id))
            ->where('snapshots.calculation_type', 'cumulative')->whereIn('snapshots.id', function ($query) use ($membership): void {
                $query->selectRaw('MAX(latest.id)')->from('academic_gpa_snapshots as latest')->where('latest.tenant_id', $membership->tenant_id)->where('latest.calculation_type', 'cumulative')->groupBy('latest.student_id');
            })->select('snapshots.academic_standing', DB::raw('COUNT(*) AS learner_count'))->groupBy('snapshots.academic_standing')->orderBy('snapshots.academic_standing')->get();
        $gradebookQuery = DB::table('academic_gradebooks as gradebooks')->join('course_sections as sections', function ($join): void {
            $join->on('sections.id', '=', 'gradebooks.section_id')->on('sections.tenant_id', '=', 'gradebooks.tenant_id');
        })->where('gradebooks.tenant_id', $membership->tenant_id)->where('gradebooks.institution_id', $membership->institution_id)
            ->when($membership->campus_id !== null, fn ($query) => $query->where('sections.campus_id', $membership->campus_id));
        $gradebookCompletion = (clone $gradebookQuery)->select('gradebooks.status', DB::raw('COUNT(*) AS total'))->groupBy('gradebooks.status')->get()
            ->mapWithKeys(fn ($row): array => [(string) $row->status => (int) $row->total]);
        $publishedResults = DB::table('academic_grade_publication_entries as entries')
            ->join('academic_gradebooks as gradebooks', function ($join): void {
                $join->on('gradebooks.id', '=', 'entries.gradebook_id')->on('gradebooks.tenant_id', '=', 'entries.tenant_id');
            })->join('course_sections as sections', function ($join): void {
                $join->on('sections.id', '=', 'gradebooks.section_id')->on('sections.tenant_id', '=', 'gradebooks.tenant_id');
            })->join('course_enrollments as enrollments', function ($join): void {
                $join->on('enrollments.id', '=', 'entries.enrollment_id')->on('enrollments.tenant_id', '=', 'entries.tenant_id');
            })->join('sis_students as students', function ($join): void {
                $join->on('students.id', '=', 'enrollments.student_id')->on('students.tenant_id', '=', 'enrollments.tenant_id');
            })->where('entries.tenant_id', $membership->tenant_id)->where('gradebooks.institution_id', $membership->institution_id)->where('students.institution_id', $membership->institution_id)
            ->when($membership->campus_id !== null, fn ($query) => $query->where('sections.campus_id', $membership->campus_id)->where('students.home_campus_id', $membership->campus_id));
        $gradeDistribution = (clone $publishedResults)->whereNotNull('entries.scale_result')->select('entries.scale_result', DB::raw('COUNT(*) AS results'))
            ->groupBy('entries.scale_result')->orderBy('entries.scale_result')->get()
            ->map(fn ($row): array => ['grade' => (string) $row->scale_result, 'results' => (int) $row->results])->values();

        return Inertia::render('workspace/academics/reporting/index', ['tenant' => ['slug' => $tenant->slug], 'filters' => $filters, 'records' => $rows, 'metrics' => [
            'gradebooks_pending' => (clone $gradebookQuery)->where('gradebooks.status', 'submitted')->count(),
            'gradebook_completion' => ['draft' => (int) ($gradebookCompletion['draft'] ?? 0), 'submitted' => (int) ($gradebookCompletion['submitted'] ?? 0), 'approved' => (int) ($gradebookCompletion['approved'] ?? 0), 'published' => (int) ($gradebookCompletion['published'] ?? 0)],
            'published_results' => (clone $publishedResults)->count(),
            'grade_distribution' => $gradeDistribution,
            'record_approvals_pending' => (clone $base)->where('records.status', 'submitted')->count(),
            'issued_report_cards' => (clone $base)->where('records.record_type', 'report_card')->where('records.status', 'issued')->count(),
            'issued_transcripts' => (clone $base)->where('records.record_type', 'transcript')->where('records.status', 'issued')->count(),
            'issued_certificates' => (clone $base)->where('records.record_type', 'certificate')->where('records.status', 'issued')->count(),
            'revoked_certificates' => (clone $base)->where('records.record_type', 'certificate')->where('records.status', 'revoked')->count(),
            'standing' => $standing->map(fn ($row): array => ['standing' => $row->academic_standing, 'learners' => (int) $row->learner_count])->values(),
        ]]);
    }

    public function export(Tenant $tenant, Request $request, TenantContext $context): StreamedResponse
    {
        $membership = $context->membership();
        abort_unless($membership->hasPermission('academic.records.view-all'), 403);
        $filters = $request->validate(['record_type' => ['nullable', 'in:report_card,transcript,certificate'], 'status' => ['nullable', 'in:draft,submitted,approved,rejected,issued,revoked']]);
        $rows = DB::table('academic_record_issues as records')->join('sis_students as students', function ($join): void {
            $join->on('students.id', '=', 'records.student_id')->on('students.tenant_id', '=', 'records.tenant_id');
        })->where('records.tenant_id', $membership->tenant_id)->where('records.institution_id', $membership->institution_id)
            ->when($membership->campus_id !== null, fn ($query) => $query->where('students.home_campus_id', $membership->campus_id))
            ->when(isset($filters['record_type']), fn ($query) => $query->where('records.record_type', $filters['record_type']))
            ->when(isset($filters['status']), fn ($query) => $query->where('records.status', $filters['status']))
            ->orderByDesc('records.created_at')->limit(5000)->get(['students.student_number', 'records.record_type', 'records.status', 'records.issue_number', 'records.created_at', 'records.issued_at']);

        return response()->streamDownload(static function () use ($rows): void {
            $output = fopen('php://output', 'wb');
            fputcsv($output, ['student_number', 'record_type', 'status', 'issue_number', 'created_at', 'issued_at']);
            foreach ($rows as $row) {
                fputcsv($output, [self::safeCsvCell((string) $row->student_number), $row->record_type, $row->status, $row->issue_number, $row->created_at, $row->issued_at]);
            }
            fclose($output);
        }, 'academic-records.csv', ['Content-Type' => 'text/csv; charset=UTF-8', 'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff']);
    }

    private static function safeCsvCell(string $value): string
    {
        return preg_match('/^[=+\-@\t\r]/u', $value) === 1 ? "'".$value : $value;
    }
}
