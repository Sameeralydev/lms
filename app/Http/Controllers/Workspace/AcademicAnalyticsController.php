<?php

namespace App\Http\Controllers\Workspace;

use App\Domain\Analytics\AcademicAnalyticsScheduleService;
use App\Domain\Analytics\AcademicAnalyticsService;
use App\Domain\Analytics\AcademicMetricRegistry;
use App\Domain\Analytics\AcademicReportBuilder;
use App\Domain\Audit\AuditRecorder;
use App\Domain\Grading\AcademicRecordPdf;
use App\Domain\Tenancy\TenantContext;
use App\Http\Controllers\Controller;
use App\Models\AcademicAnalyticsReportRun;
use App\Models\AcademicAnalyticsReportSchedule;
use App\Models\AcademicAnalyticsSavedReport;
use App\Models\AuditEvent;
use App\Models\Tenant;
use App\Models\TenantMembership;
use Illuminate\Database\QueryException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class AcademicAnalyticsController extends Controller
{
    public function index(Tenant $tenant, Request $request, TenantContext $context, AcademicAnalyticsService $analytics, AcademicReportBuilder $builder): Response
    {
        $membership = $context->membership();
        abort_unless($membership->hasPermission('academic.analytics.view'), 403);
        $filters = $request->validate([
            'campus_id' => ['nullable', 'integer', 'min:1'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
            'metrics' => ['nullable', 'array', 'max:20'],
            'metrics.*' => ['string', Rule::in(array_column(AcademicMetricRegistry::definitions(), 'key'))],
            'dataset' => ['nullable', 'string', Rule::in(array_keys(AcademicReportBuilder::definitions()))],
            'report_measure' => ['nullable', 'string', Rule::in(['records', 'unique_learners', 'billed_amount', 'average_gpa'])],
            'dimensions' => ['nullable', 'array', 'min:1', 'max:2'],
            'dimensions.*' => ['string', 'max:32'],
            'report_status' => ['nullable', 'string', 'max:32'],
            'report_filters' => ['nullable', 'array', 'max:6'],
            'report_filters.*' => ['string', 'max:100'],
            'report_search' => ['nullable', 'string', 'max:80'],
            'report_sort' => ['nullable', 'string', 'max:32'],
            'report_direction' => ['nullable', Rule::in(['asc', 'desc'])],
            'report_page' => ['nullable', 'integer', 'min:1', 'max:100000'],
            'report_per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);
        $this->validateRange($filters['from'] ?? null, $filters['to'] ?? null);
        $builderDimensions = array_values(array_filter($filters['dimensions'] ?? ['status'], static fn (mixed $value): bool => is_string($value) && $value !== ''));
        $builderReport = $builder->build($membership, [
            'dataset' => $filters['dataset'] ?? AcademicReportBuilder::defaultDatasetFor($membership),
            'measure' => $filters['report_measure'] ?? 'records',
            'dimensions' => $builderDimensions ?: ['status'],
            'campus_id' => isset($filters['campus_id']) ? (int) $filters['campus_id'] : null,
            'from' => $filters['from'] ?? null,
            'to' => $filters['to'] ?? null,
            'status' => $filters['report_status'] ?? null,
            'filters' => $filters['report_filters'] ?? [],
            'search' => $filters['report_search'] ?? null,
            'sort' => $filters['report_sort'] ?? 'total',
            'direction' => $filters['report_direction'] ?? 'desc',
            'page' => isset($filters['report_page']) ? (int) $filters['report_page'] : 1,
            'per_page' => isset($filters['report_per_page']) ? (int) $filters['report_per_page'] : 25,
        ]);
        $report = $analytics->report($membership, [
            'campus_id' => isset($filters['campus_id']) ? (int) $filters['campus_id'] : null,
            'from' => $filters['from'] ?? null,
            'to' => $filters['to'] ?? null,
            'metrics' => isset($filters['metrics']) ? array_values(array_unique($filters['metrics'])) : null,
        ]);

        return Inertia::render('workspace/academics/analytics/index', [
            'tenant' => ['slug' => $tenant->slug],
            ...$report,
            'metric_options' => array_map(static fn (array $metric): array => ['key' => $metric['key'], 'name' => $metric['name']], AcademicMetricRegistry::definitions()),
            'saved_reports' => AcademicAnalyticsSavedReport::query()->where('institution_id', $membership->institution_id)->where('membership_id', $membership->getKey())
                ->latest('created_at')->limit(20)->get(['id', 'name', 'created_at'])->map(static fn (AcademicAnalyticsSavedReport $saved): array => ['id' => (int) $saved->getKey(), 'name' => $saved->name, 'created_at' => $saved->created_at?->toISOString()])->all(),
            'permissions' => ['export' => $membership->hasPermission('academic.analytics.export'), 'manage_reports' => $membership->hasPermission('academic.analytics.reports.manage')],
            'report_schedules' => AcademicAnalyticsReportSchedule::query()->where('membership_id', $membership->getKey())->with(['runs' => fn ($query) => $query->latest('created_at')->limit(5), 'recipients:id'])->latest()->limit(20)->get()->map(static fn (AcademicAnalyticsReportSchedule $schedule): array => [
                'id' => (int) $schedule->getKey(), 'name' => $schedule->name, 'frequency' => $schedule->frequency, 'timezone' => $schedule->timezone,
                'next_run_at' => $schedule->next_run_at?->toISOString(), 'status' => $schedule->status, 'saved_report_id' => (int) $schedule->saved_report_id,
                'local_time' => substr((string) $schedule->local_time, 0, 5), 'weekday' => $schedule->weekday, 'day_of_month' => $schedule->day_of_month,
                'recipient_ids' => $schedule->recipients->pluck('id')->map(static fn ($id): int => (int) $id)->all(),
                'runs' => $schedule->runs->map(static fn (AcademicAnalyticsReportRun $run): array => ['id' => (int) $run->getKey(), 'status' => $run->status, 'recipient_count' => (int) $run->recipient_count, 'created_at' => $run->created_at?->toISOString()])->all(),
            ])->all(),
            'report_recipients' => $membership->hasPermission('academic.analytics.reports.manage') ? TenantMembership::query()->where('institution_id', $membership->institution_id)->where('status', 'active')->with('user:id,name,email')->limit(200)->get()->filter(static fn (TenantMembership $candidate): bool => $candidate->hasPermission('academic.analytics.view') && $candidate->hasPermission('academic.analytics.export'))->map(static fn (TenantMembership $candidate): array => ['id' => (int) $candidate->getKey(), 'name' => $candidate->user?->name, 'email' => $candidate->user?->email])->values()->all() : [],
            'builder_report' => $builderReport,
            'export_history' => AuditEvent::query()
                ->where('tenant_id', $membership->tenant_id)
                ->where('actor_user_id', $request->user()->getKey())
                ->where('event_type', 'academic.analytics.builder_exported')
                ->latest('occurred_at')
                ->limit(10)
                ->get(['id', 'metadata', 'occurred_at'])
                ->map(static fn ($event): array => [
                    'id' => (int) $event->getKey(),
                    'dataset' => (string) data_get($event->metadata, 'dataset', 'unknown'),
                    'rows' => (int) data_get($event->metadata, 'rows', 0),
                    'truncated' => (bool) data_get($event->metadata, 'truncated', false),
                    'created_at' => $event->occurred_at,
                ])->all(),
        ]);
    }

    public function storeReport(Tenant $tenant, Request $request, TenantContext $context, AcademicAnalyticsService $analytics, AcademicReportBuilder $builder, AuditRecorder $audit): RedirectResponse
    {
        $membership = $context->membership();
        abort_unless($membership->hasPermission('academic.analytics.reports.manage'), 403);
        $filters = $request->validate([
            'name' => ['required', 'string', 'max:100', Rule::unique('academic_analytics_saved_reports', 'name')->where(fn ($query) => $query->where('tenant_id', $membership->tenant_id)->where('membership_id', $membership->getKey()))],
            'campus_id' => ['nullable', 'integer', 'min:1'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
            'metrics' => ['nullable', 'array', 'max:20'],
            'metrics.*' => ['string', Rule::in(array_column(AcademicMetricRegistry::definitions(), 'key'))],
            'dataset' => ['nullable', 'string', Rule::in(array_keys(AcademicReportBuilder::definitions()))],
            'report_measure' => ['nullable', 'string', Rule::in(['records', 'unique_learners', 'billed_amount', 'average_gpa'])],
            'dimensions' => ['nullable', 'array', 'min:1', 'max:2'],
            'dimensions.*' => ['string', 'max:32'],
            'report_status' => ['nullable', 'string', 'max:32'],
            'report_filters' => ['nullable', 'array', 'max:6'],
            'report_filters.*' => ['string', 'max:100'],
            'report_search' => ['nullable', 'string', 'max:80'],
            'report_sort' => ['nullable', 'string', 'max:32'],
            'report_direction' => ['nullable', Rule::in(['asc', 'desc'])],
        ]);
        $this->validateRange($filters['from'] ?? null, $filters['to'] ?? null);
        $verified = $analytics->report($membership, [
            'campus_id' => isset($filters['campus_id']) ? (int) $filters['campus_id'] : null,
            'from' => $filters['from'] ?? null,
            'to' => $filters['to'] ?? null,
            'metrics' => isset($filters['metrics']) ? array_values(array_unique($filters['metrics'])) : null,
        ]);
        $requestedDimensions = array_values(array_filter($filters['dimensions'] ?? ['status'], static fn (mixed $value): bool => is_string($value) && $value !== ''));
        $verifiedBuilder = $builder->build($membership, [
            'dataset' => $filters['dataset'] ?? 'enrollments',
            'measure' => $filters['report_measure'] ?? 'records',
            'dimensions' => $requestedDimensions ?: ['status'],
            'campus_id' => isset($filters['campus_id']) ? (int) $filters['campus_id'] : null,
            'from' => $filters['from'] ?? null,
            'to' => $filters['to'] ?? null,
            'status' => $filters['report_status'] ?? null,
            'filters' => $filters['report_filters'] ?? [],
            'search' => $filters['report_search'] ?? null,
            'sort' => $filters['report_sort'] ?? 'total',
            'direction' => $filters['report_direction'] ?? 'desc',
        ]);
        try {
            DB::transaction(function () use ($membership, $filters, $verified, $verifiedBuilder, $request, $audit): void {
                // Serialize the per-membership quota check with report creation. Locking
                // this stable parent row also works when the membership has no reports yet.
                $owner = TenantMembership::query()
                    ->whereKey($membership->getKey())
                    ->where('tenant_id', $membership->tenant_id)
                    ->lockForUpdate()
                    ->firstOrFail();

                // Use a current locking read after the stable membership lock.
                // Under MySQL REPEATABLE READ, a plain COUNT can reuse a
                // snapshot created before a concurrent request acquired this
                // lock and allow both requests through the final quota slot.
                $savedReportIds = AcademicAnalyticsSavedReport::query()
                    ->where('tenant_id', $owner->tenant_id)
                    ->where('membership_id', $owner->getKey())
                    ->limit(20)
                    ->lockForUpdate()
                    ->get(['id']);

                if (count($savedReportIds) >= 20) {
                    throw ValidationException::withMessages(['name' => 'Delete a saved report before creating another. The limit is 20 per membership.']);
                }

                $saved = AcademicAnalyticsSavedReport::query()->create([
                    'tenant_id' => $owner->tenant_id,
                    'institution_id' => $owner->institution_id,
                    'membership_id' => $owner->getKey(),
                    'campus_id' => $verified['filters']['campus_id'],
                    'name' => $filters['name'],
                    'filters' => [
                        ...$verified['filters'],
                        'builder' => [
                            'dataset' => $verifiedBuilder['dataset'],
                            'measure' => $verifiedBuilder['measure'],
                            'dimensions' => $verifiedBuilder['dimensions'],
                            'status' => $verifiedBuilder['status'],
                            'filters' => $verifiedBuilder['filters'],
                            'search' => $verifiedBuilder['search'],
                            'sort' => $verifiedBuilder['sort'],
                            'direction' => $verifiedBuilder['direction'],
                        ],
                    ],
                ]);

                $audit->record('academic.analytics.report.saved', $request->user(), (int) $owner->tenant_id, $saved, ['name' => $saved->name, 'campus_id' => $saved->campus_id]);
            });
        } catch (QueryException $exception) {
            if (in_array($exception->errorInfo[0] ?? null, ['23000', '23505'], true)) {
                throw ValidationException::withMessages(['name' => 'You already have a saved report with this name.']);
            }
            throw $exception;
        }

        return redirect()->route('tenant.academics.analytics.index', ['tenant' => $tenant->slug])->with('status', 'Analytics report saved.');
    }

    public function applyReport(Tenant $tenant, int $reportId, TenantContext $context): RedirectResponse
    {
        $membership = $context->membership();
        abort_unless($membership->hasPermission('academic.analytics.view'), 403);
        $saved = AcademicAnalyticsSavedReport::query()->where('institution_id', $membership->institution_id)->where('membership_id', $membership->getKey())->findOrFail($reportId);
        $filters = $saved->filters;
        $query = array_filter([
            'campus_id' => $filters['campus_id'] ?? null,
            'from' => $filters['from'] ?? null,
            'to' => $filters['to'] ?? null,
        ], static fn (mixed $value): bool => $value !== null && $value !== '');
        if (is_array($filters['metrics'] ?? null)) {
            $query['metrics'] = $filters['metrics'];
        }
        $builder = $filters['builder'] ?? [];
        foreach (['dataset' => 'dataset', 'measure' => 'report_measure', 'dimensions' => 'dimensions', 'status' => 'report_status', 'filters' => 'report_filters', 'search' => 'report_search', 'sort' => 'report_sort', 'direction' => 'report_direction'] as $savedKey => $queryKey) {
            if (isset($builder[$savedKey])) {
                $query[$queryKey] = $builder[$savedKey];
            }
        }

        return redirect()->route('tenant.academics.analytics.index', ['tenant' => $tenant->slug, ...$query]);
    }

    public function deleteReport(Tenant $tenant, int $reportId, Request $request, TenantContext $context, AuditRecorder $audit): RedirectResponse
    {
        $membership = $context->membership();
        abort_unless($membership->hasPermission('academic.analytics.reports.manage'), 403);
        $saved = AcademicAnalyticsSavedReport::query()->where('institution_id', $membership->institution_id)->where('membership_id', $membership->getKey())->findOrFail($reportId);
        AcademicAnalyticsReportSchedule::query()->where('membership_id', $membership->getKey())->where('saved_report_id', $saved->getKey())->update(['status' => 'cancelled', 'saved_report_id' => null, 'updated_at' => now()]);
        $audit->record('academic.analytics.report.deleted', $request->user(), (int) $membership->tenant_id, $saved, ['name' => $saved->name]);
        $saved->delete();

        return redirect()->route('tenant.academics.analytics.index', ['tenant' => $tenant->slug])->with('status', 'Analytics report deleted.');
    }

    public function storeSchedule(Tenant $tenant, Request $request, TenantContext $context, AcademicAnalyticsScheduleService $schedules, AuditRecorder $audit): RedirectResponse
    {
        $membership = $context->membership();
        abort_unless($membership->hasPermission('academic.analytics.reports.manage'), 403);
        $data = $request->validate([
            'saved_report_id' => ['required', 'integer', 'min:1'], 'name' => ['required', 'string', 'max:100'],
            'frequency' => ['required', Rule::in(['daily', 'weekly', 'monthly'])], 'timezone' => ['required', 'timezone'],
            'local_time' => ['required', 'date_format:H:i'], 'weekday' => ['nullable', 'integer', 'between:0,6'],
            'day_of_month' => ['nullable', 'integer', 'between:1,28'], 'recipients' => ['nullable', 'array', 'max:50'], 'recipients.*' => ['integer', 'min:1'],
        ]);
        $schedules->create($membership, $data, array_map('intval', $data['recipients'] ?? []), $audit);

        return redirect()->route('tenant.academics.analytics.index', ['tenant' => $tenant->slug])->with('status', 'Scheduled report created.');
    }

    public function setScheduleStatus(Tenant $tenant, int $scheduleId, Request $request, TenantContext $context, AuditRecorder $audit): RedirectResponse
    {
        $membership = $context->membership();
        abort_unless($membership->hasPermission('academic.analytics.reports.manage'), 403);
        $status = $request->validate(['status' => ['required', Rule::in(['active', 'paused', 'cancelled'])]])['status'];
        $schedule = AcademicAnalyticsReportSchedule::query()->where('membership_id', $membership->getKey())->findOrFail($scheduleId);
        app(AcademicAnalyticsScheduleService::class)->setStatus($schedule, $status, $audit);

        return redirect()->route('tenant.academics.analytics.index', ['tenant' => $tenant->slug])->with('status', 'Report schedule updated.');
    }

    public function updateSchedule(Tenant $tenant, int $scheduleId, Request $request, TenantContext $context, AuditRecorder $audit): RedirectResponse
    {
        $membership = $context->membership();
        abort_unless($membership->hasPermission('academic.analytics.reports.manage'), 403);
        $data = $request->validate([
            'saved_report_id' => ['required', 'integer', 'min:1'], 'name' => ['required', 'string', 'max:100'],
            'frequency' => ['required', Rule::in(['daily', 'weekly', 'monthly'])], 'timezone' => ['required', 'timezone'],
            'local_time' => ['required', 'date_format:H:i'], 'weekday' => ['nullable', 'integer', 'between:0,6'],
            'day_of_month' => ['nullable', 'integer', 'between:1,28'], 'recipients' => ['sometimes', 'array', 'max:50'], 'recipients.*' => ['integer', 'min:1'],
        ]);
        $schedule = AcademicAnalyticsReportSchedule::query()->where('membership_id', $membership->getKey())->findOrFail($scheduleId);
        app(AcademicAnalyticsScheduleService::class)->update($schedule, $membership, $data, isset($data['recipients']) ? array_map('intval', $data['recipients']) : null, $audit);

        return redirect()->route('tenant.academics.analytics.index', ['tenant' => $tenant->slug])->with('status', 'Report schedule saved.');
    }

    public function downloadScheduledRun(Tenant $tenant, int $runId, Request $request, TenantContext $context, AuditRecorder $audit): BinaryFileResponse
    {
        $membership = $context->membership();
        abort_unless($membership->hasPermission('academic.analytics.view') && $membership->hasPermission('academic.analytics.export'), 403);
        $run = AcademicAnalyticsReportRun::query()->where('tenant_id', $membership->tenant_id)->where('status', 'completed')->findOrFail($runId);
        $schedule = AcademicAnalyticsReportSchedule::query()->where('tenant_id', $membership->tenant_id)->whereKey($run->schedule_id)->firstOrFail();
        abort_unless($schedule->savedReport()->exists(), 404);
        $owner = TenantMembership::query()->where('tenant_id', $membership->tenant_id)->whereKey($schedule->membership_id)->firstOrFail();
        abort_unless($owner->status === 'active' && $owner->hasPermission('academic.analytics.view') && $owner->hasPermission('academic.analytics.export'), 404);
        abort_unless(DB::table('academic_analytics_report_schedule_recipients')->where('tenant_id', $membership->tenant_id)->where('schedule_id', $schedule->getKey())->where('membership_id', $membership->getKey())->exists(), 404);
        abort_unless(is_string($run->storage_path) && str_starts_with($run->storage_path, "analytics/{$membership->tenant_id}/") && Storage::disk('local')->exists($run->storage_path), 404);
        $audit->record('academic.analytics.schedule.downloaded', $request->user(), (int) $membership->tenant_id, $run, ['schedule_id' => $schedule->getKey()]);

        return response()->download(Storage::disk('local')->path($run->storage_path), 'scheduled-academic-report.pdf', ['Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff']);
    }

    public function export(Tenant $tenant, Request $request, TenantContext $context, AcademicAnalyticsService $analytics, AuditRecorder $audit): StreamedResponse
    {
        $membership = $context->membership();
        abort_unless($membership->hasPermission('academic.analytics.export'), 403);
        $filters = Validator::make($request->query(), [
            'campus_id' => ['nullable', 'integer', 'min:1'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
            'metrics' => ['sometimes', 'array', 'max:20'],
            'metrics.*' => ['string', Rule::in(array_column(AcademicMetricRegistry::definitions(), 'key'))],
        ])->validate();
        $this->validateRange($filters['from'] ?? null, $filters['to'] ?? null);
        $report = $analytics->report($membership, [
            'campus_id' => isset($filters['campus_id']) ? (int) $filters['campus_id'] : null,
            'from' => $filters['from'] ?? null,
            'to' => $filters['to'] ?? null,
            'metrics' => isset($filters['metrics']) ? array_values(array_unique($filters['metrics'])) : null,
        ]);
        $audit->record('academic.analytics.exported', $request->user(), (int) $membership->tenant_id, null, [
            'campus_id' => $report['filters']['campus_id'], 'from' => $report['filters']['from'], 'to' => $report['filters']['to'], 'metric_count' => count($report['metrics']),
        ]);

        return response()->streamDownload(function () use ($report): void {
            $output = fopen('php://output', 'wb');
            fputcsv($output, ['metric_key', 'metric', 'value', 'definition', 'formula', 'sources']);
            foreach ($report['metrics'] as $metric) {
                fputcsv($output, [$metric['key'], $metric['name'], $metric['value'] ?? '', $metric['definition'], $metric['formula'], implode('|', $metric['sources'])]);
            }
            fclose($output);
        }, 'academic-analytics.csv', ['Content-Type' => 'text/csv; charset=UTF-8', 'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff']);
    }

    public function exportBuilder(Tenant $tenant, Request $request, TenantContext $context, AcademicReportBuilder $builder, AuditRecorder $audit): StreamedResponse
    {
        $membership = $context->membership();
        abort_unless($membership->hasPermission('academic.analytics.export'), 403);
        $filters = $request->validate([
            'dataset' => ['required', 'string', Rule::in(array_keys(AcademicReportBuilder::definitions()))],
            'report_measure' => ['nullable', 'string', Rule::in(['records', 'unique_learners', 'billed_amount', 'average_gpa'])],
            'dimensions' => ['required', 'array', 'min:1', 'max:2'],
            'dimensions.*' => ['string', 'max:32'],
            'campus_id' => ['nullable', 'integer', 'min:1'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
            'report_status' => ['nullable', 'string', 'max:32'],
            'report_filters' => ['nullable', 'array', 'max:6'],
            'report_filters.*' => ['string', 'max:100'],
            'report_search' => ['nullable', 'string', 'max:80'],
            'report_sort' => ['nullable', 'string', 'max:32'],
            'report_direction' => ['nullable', Rule::in(['asc', 'desc'])],
        ]);
        $this->validateRange($filters['from'] ?? null, $filters['to'] ?? null);
        $dimensions = array_values(array_filter($filters['dimensions'], static fn (mixed $value): bool => is_string($value) && $value !== ''));
        $report = $builder->build($membership, [
            'dataset' => $filters['dataset'], 'measure' => $filters['report_measure'] ?? 'records', 'dimensions' => $dimensions ?: ['status'],
            'campus_id' => isset($filters['campus_id']) ? (int) $filters['campus_id'] : null,
            'from' => $filters['from'] ?? null, 'to' => $filters['to'] ?? null,
            'status' => $filters['report_status'] ?? null, 'search' => $filters['report_search'] ?? null,
            'filters' => $filters['report_filters'] ?? [],
            'sort' => $filters['report_sort'] ?? 'total', 'direction' => $filters['report_direction'] ?? 'desc',
        ], true);
        $audit->record('academic.analytics.builder_exported', $request->user(), (int) $membership->tenant_id, null, [
            'dataset' => $report['dataset'], 'measure' => $report['measure'], 'dimensions' => $report['dimensions'], 'campus_id' => $report['campus_id'],
            'from' => $report['from'], 'to' => $report['to'], 'rows' => count($report['rows']), 'truncated' => $report['total'] > $report['export_limit'],
        ]);

        return response()->streamDownload(function () use ($report): void {
            $output = fopen('php://output', 'wb');
            fputcsv($output, [...$report['dimensions'], $report['measure_label']]);
            foreach ($report['rows'] as $row) {
                fputcsv($output, [...array_map(fn (string $dimension): mixed => $this->safeCsvCell($row[$dimension] ?? ''), $report['dimensions']), $row['total']]);
            }
            fclose($output);
        }, 'academic-report.csv', [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
            'X-Report-Row-Limit' => (string) $report['export_limit'],
            'X-Report-Truncated' => $report['total'] > $report['export_limit'] ? 'true' : 'false',
        ]);
    }

    public function drilldown(Tenant $tenant, Request $request, TenantContext $context, AcademicReportBuilder $builder): Response
    {
        $membership = $context->membership();
        abort_unless($membership->hasPermission('academic.analytics.drilldown'), 403);
        $filters = $request->validate([
            'dataset' => ['required', 'string', Rule::in(array_keys(AcademicReportBuilder::definitions()))],
            'dimension' => ['required', 'string', 'max:32'], 'value' => ['required', 'string', 'max:100'],
            'campus_id' => ['nullable', 'integer', 'min:1'], 'from' => ['nullable', 'date'], 'to' => ['nullable', 'date', 'after_or_equal:from'],
            'report_measure' => ['nullable', 'string', Rule::in(['records', 'unique_learners', 'billed_amount', 'average_gpa'])],
            'report_status' => ['nullable', 'string', 'max:32'], 'report_filters' => ['nullable', 'array', 'max:6'], 'report_filters.*' => ['string', 'max:100'],
            'page' => ['nullable', 'integer', 'min:1', 'max:100000'],
        ]);
        $this->validateRange($filters['from'] ?? null, $filters['to'] ?? null);
        $detail = $builder->drilldown($membership, [
            'dataset' => $filters['dataset'], 'dimension' => $filters['dimension'], 'value' => $filters['value'],
            'campus_id' => isset($filters['campus_id']) ? (int) $filters['campus_id'] : null,
            'from' => $filters['from'] ?? null, 'to' => $filters['to'] ?? null, 'status' => $filters['report_status'] ?? null,
            'filters' => $filters['report_filters'] ?? [], 'page' => (int) ($filters['page'] ?? 1),
            'measure' => $filters['report_measure'] ?? 'records',
        ]);

        return Inertia::render('workspace/academics/analytics/drilldown', ['tenant' => ['slug' => $tenant->slug], 'report' => $detail, 'source_label' => AcademicReportBuilder::definitions()[$detail['dataset']]['label']]);
    }

    public function exportBuilderPdf(Tenant $tenant, Request $request, TenantContext $context, AcademicReportBuilder $builder, AuditRecorder $audit, AcademicRecordPdf $pdf): \Symfony\Component\HttpFoundation\Response
    {
        $membership = $context->membership();
        abort_unless($membership->hasPermission('academic.analytics.export'), 403);
        $filters = $request->validate([
            'dataset' => ['required', 'string', Rule::in(array_keys(AcademicReportBuilder::definitions()))],
            'report_measure' => ['nullable', 'string', Rule::in(['records', 'unique_learners', 'billed_amount', 'average_gpa'])],
            'dimensions' => ['required', 'array', 'min:1', 'max:2'], 'dimensions.*' => ['string', 'max:32'],
            'campus_id' => ['nullable', 'integer', 'min:1'], 'from' => ['nullable', 'date'], 'to' => ['nullable', 'date', 'after_or_equal:from'],
            'report_status' => ['nullable', 'string', 'max:32'], 'report_filters' => ['nullable', 'array', 'max:6'], 'report_filters.*' => ['string', 'max:100'],
            'report_search' => ['nullable', 'string', 'max:80'], 'report_sort' => ['nullable', 'string', 'max:32'], 'report_direction' => ['nullable', Rule::in(['asc', 'desc'])],
        ]);
        $this->validateRange($filters['from'] ?? null, $filters['to'] ?? null);
        $report = $builder->build($membership, [
            'dataset' => $filters['dataset'], 'measure' => $filters['report_measure'] ?? 'records',
            'dimensions' => array_values(array_filter($filters['dimensions'], static fn (mixed $value): bool => is_string($value) && $value !== '')),
            'campus_id' => isset($filters['campus_id']) ? (int) $filters['campus_id'] : null, 'from' => $filters['from'] ?? null, 'to' => $filters['to'] ?? null,
            'status' => $filters['report_status'] ?? null, 'filters' => $filters['report_filters'] ?? [], 'search' => $filters['report_search'] ?? null,
            'sort' => $filters['report_sort'] ?? 'total', 'direction' => $filters['report_direction'] ?? 'desc',
        ], true);
        $institutionName = DB::table('institutions')->where('tenant_id', $membership->tenant_id)->where('id', $membership->institution_id)->value('display_name');
        $campusName = $report['campus_id'] === null ? 'All authorized campuses' : (DB::table('campuses')->where('tenant_id', $membership->tenant_id)->where('institution_id', $membership->institution_id)->where('id', $report['campus_id'])->value('name') ?? 'Authorized campus');
        $lines = ['TechMentors.PK - Academic report', AcademicReportBuilder::definitions()[$report['dataset']]['label'], 'Institution: '.($institutionName ?: 'Institution '.$membership->institution_id), 'Campus scope: '.$campusName, 'Generated: '.now()->toDateTimeString(), 'Date range: '.($report['from'] ?? 'Any').' to '.($report['to'] ?? 'Any'), 'Measure: '.$report['measure_label'], 'Filters: '.json_encode(['status' => $report['status'], ...$report['filters']], JSON_UNESCAPED_UNICODE), 'Groups: '.implode(', ', $report['dimensions']), 'Matching groups: '.$report['total']];
        foreach ($report['rows'] as $row) {
            $lines[] = implode(' | ', array_map(static fn (string $dimension): string => $dimension.': '.(string) ($row[$dimension] ?? 'Unspecified'), $report['dimensions'])).' | '.$report['measure_label'].': '.$row['total'];
        }
        $audit->record('academic.analytics.builder_pdf_exported', $request->user(), (int) $membership->tenant_id, null, ['dataset' => $report['dataset'], 'measure' => $report['measure'], 'campus_id' => $report['campus_id'], 'group_count' => count($report['rows']), 'truncated' => $report['total'] > $report['export_limit']]);

        return response($pdf->render($lines), 200, ['Content-Type' => 'application/pdf', 'Content-Disposition' => 'attachment; filename="academic-report.pdf"', 'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff']);
    }

    private function validateRange(?string $from, ?string $to): void
    {
        if ($from !== null && $to !== null && now()->parse($from)->diffInDays(now()->parse($to)) > 366) {
            abort(422, 'The selected analytics date range cannot exceed 366 days.');
        }
    }

    private function safeCsvCell(mixed $value): string|int|float|null
    {
        if (! is_string($value)) {
            return $value;
        }

        return preg_match('/\A[\t\r ]*[=+@-]/u', $value) === 1 ? "'".$value : $value;
    }
}
