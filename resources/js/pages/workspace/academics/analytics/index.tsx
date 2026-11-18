import { Link, router, useForm } from '@inertiajs/react';
import { useState } from 'react';
import { MetricCard } from '@/components/ui/metric-card';
import { PageHeader } from '@/components/ui/page-header';
import { WorkspaceLayout } from '@/layouts/workspace-layout';

type Metric = {
    key: string;
    name: string;
    value: number | null;
    display: string;
    definition: string;
    formula: string;
    sources: string[];
    dimensions: string[];
    date_basis: string;
};
type FilterSet = {
    campus_id: number | null;
    from: string | null;
    to: string | null;
    metrics: string[] | null;
};
type BuilderReport = {
    definitions: Record<
        string,
        {
            label: string;
            dimensions: Record<string, string>;
            measures: Record<string, string>;
            filters: Record<string, string>;
            status_filter: boolean;
        }
    >;
    dataset: string;
    measure: string;
    measure_label: string;
    dimensions: string[];
    campus_id: number | null;
    from: string | null;
    to: string | null;
    status: string | null;
    filters: Record<string, string>;
    search: string;
    sort: string;
    direction: 'asc' | 'desc';
    page: number;
    per_page: number;
    total: number;
    rows: Record<string, string | number | null>[];
    export_limit: number;
};

export default function AcademicAnalytics({
    tenant,
    metrics,
    metric_options,
    trend,
    campuses,
    filters,
    generated_at,
    permissions,
    saved_reports,
    builder_report,
    export_history,
    report_schedules,
    report_recipients,
}: {
    tenant: { slug: string };
    metrics: Metric[];
    metric_options: { key: string; name: string }[];
    trend: { date: string; value: number }[];
    campuses: { id: number; name: string }[];
    filters: FilterSet;
    generated_at: string;
    permissions: {
        export: boolean;
        academic_export: boolean;
        manage_reports: boolean;
        view_academic_metrics: boolean;
    };
    saved_reports: { id: number; name: string; created_at: string | null }[];
    builder_report: BuilderReport;
    export_history: {
        id: number;
        dataset: string;
        rows: number;
        truncated: boolean;
        created_at: string | null;
    }[];
    report_schedules: {
        id: number;
        name: string;
        frequency: string;
        timezone: string;
        next_run_at: string | null;
        status: string;
        saved_report_id: number;
        local_time: string;
        weekday: number | null;
        day_of_month: number | null;
        recipient_ids: number[];
        runs: { id: number; status: string; recipient_count: number; created_at: string | null }[];
    }[];
    report_recipients: { id: number; name: string | null; email: string | null }[];
}) {
    const exportHref = `/t/${tenant.slug}/academics/analytics.csv${window.location.search}`;
    const maxValue = Math.max(1, ...trend.map((point) => point.value));
    const maxBuilderValue = Math.max(1, ...builder_report.rows.map((row) => Number(row.total) || 0));
    const [builderDataset, setBuilderDataset] = useState(builder_report.dataset);
    const [builderMeasure, setBuilderMeasure] = useState(builder_report.measure);
    const [builderDimensions, setBuilderDimensions] = useState<[string, string]>([
        builder_report.dimensions[0] ?? 'status',
        builder_report.dimensions[1] ?? '',
    ]);
    const [builderStatus, setBuilderStatus] = useState(builder_report.status ?? '');
    const [builderSort, setBuilderSort] = useState(builder_report.sort);
    const saveForm = useForm<{ name: string }>({ name: '' });
    const scheduleForm = useForm<{
        saved_report_id: string;
        name: string;
        frequency: string;
        timezone: string;
        local_time: string;
        weekday: string;
        day_of_month: string;
        recipients: string[];
    }>({
        saved_report_id: '',
        name: '',
        frequency: 'weekly',
        timezone: Intl.DateTimeFormat().resolvedOptions().timeZone || 'UTC',
        local_time: '08:00',
        weekday: '1',
        day_of_month: '1',
        recipients: [],
    });
    const saveCurrentReport = (event: React.FormEvent<HTMLFormElement>) => {
        event.preventDefault();
        saveForm.transform((data) => ({
            ...data,
            campus_id: filters.campus_id,
            from: filters.from,
            to: filters.to,
            metrics: filters.metrics,
            dataset: builder_report.dataset,
            report_measure: builder_report.measure,
            dimensions: builder_report.dimensions,
            report_status: builder_report.status,
            report_filters: builder_report.filters,
            report_search: builder_report.search,
            report_sort: builder_report.sort,
            report_direction: builder_report.direction,
        }));
        saveForm.post(`/t/${tenant.slug}/academics/analytics/reports`, { onSuccess: () => saveForm.reset() });
    };

    return (
        <WorkspaceLayout area="tenant" title="Academic analytics">
            <PageHeader
                title="Academic analytics"
                description="Operational metrics calculated from authorized institution records. Each metric includes its definition, source, and date basis."
            />

            <div className="mb-4 flex justify-end print:hidden">
                <button
                    type="button"
                    onClick={() => window.print()}
                    className="border-border hover:bg-surface-soft min-h-10 rounded-lg border px-4 text-sm font-semibold"
                >
                    Print report
                </button>
            </div>

            <section
                className="border-border bg-surface shadow-card mb-6 rounded-2xl border p-4 print:hidden"
                aria-label="Report filters"
            >
                <form
                    method="get"
                    action={`/t/${tenant.slug}/academics/analytics`}
                    className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4"
                >
                    <label className="grid gap-1 text-sm font-medium" htmlFor="analytics-campus">
                        Campus
                        <select
                            id="analytics-campus"
                            name="campus_id"
                            defaultValue={filters.campus_id ?? ''}
                            className="border-border bg-surface min-h-11 rounded-lg border px-3"
                        >
                            {filters.campus_id === null && <option value="">All authorized campuses</option>}
                            {campuses.map((campus) => (
                                <option key={campus.id} value={campus.id}>
                                    {campus.name}
                                </option>
                            ))}
                        </select>
                    </label>
                    <label className="grid gap-1 text-sm font-medium" htmlFor="analytics-from">
                        From
                        <input
                            id="analytics-from"
                            name="from"
                            type="date"
                            defaultValue={filters.from ?? ''}
                            className="border-border bg-surface min-h-11 rounded-lg border px-3"
                        />
                    </label>
                    <label className="grid gap-1 text-sm font-medium" htmlFor="analytics-to">
                        To
                        <input
                            id="analytics-to"
                            name="to"
                            type="date"
                            defaultValue={filters.to ?? ''}
                            className="border-border bg-surface min-h-11 rounded-lg border px-3"
                        />
                    </label>
                    {permissions.view_academic_metrics && (
                        <label className="grid gap-1 text-sm font-medium" htmlFor="analytics-metrics">
                            Metrics <span className="text-text-muted font-normal">(leave empty for all)</span>
                            <select
                                id="analytics-metrics"
                                name="metrics[]"
                                multiple
                                defaultValue={filters.metrics ?? []}
                                className="border-border bg-surface min-h-24 rounded-lg border px-3 py-2"
                            >
                                {metric_options.map((metric) => (
                                    <option key={metric.key} value={metric.key}>
                                        {metric.name}
                                    </option>
                                ))}
                            </select>
                        </label>
                    )}
                    <div className="flex items-end gap-2">
                        <button
                            type="submit"
                            className="bg-primary text-on-primary min-h-11 rounded-lg px-4 text-sm font-semibold"
                        >
                            Apply filters
                        </button>
                        {permissions.academic_export && (
                            <a
                                href={exportHref}
                                className="border-border hover:bg-surface-soft min-h-11 rounded-lg border px-4 py-3 text-sm font-semibold"
                            >
                                Export CSV
                            </a>
                        )}
                    </div>
                </form>
                <p className="text-text-muted mt-3 text-xs">
                    Data calculated {new Date(generated_at).toLocaleString()}. Current-state metrics are not
                    changed by date filters. The enrollment trend covers the selected range, up to 366 days.
                </p>
            </section>

            <section
                className="border-border bg-surface shadow-card mb-6 rounded-2xl border p-4"
                aria-labelledby="report-builder-title"
            >
                <div className="mb-4 flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <h2 id="report-builder-title" className="text-text-primary text-lg font-semibold">
                            Report builder
                        </h2>
                        <p className="text-text-secondary mt-1 text-sm">
                            Build a grouped, aggregate report from authorized records. Personal identifiers
                            are excluded.
                        </p>
                    </div>
                    {permissions.export && (
                        <div className="flex gap-2">
                            <a
                                className="border-border hover:bg-surface-soft min-h-10 rounded-lg border px-3 py-2 text-sm font-semibold"
                                href={`/t/${tenant.slug}/academics/analytics/report-builder.csv${window.location.search}`}
                            >
                                Export report CSV
                            </a>
                            <a
                                className="border-border hover:bg-surface-soft min-h-10 rounded-lg border px-3 py-2 text-sm font-semibold"
                                href={`/t/${tenant.slug}/academics/analytics/report-builder.pdf${window.location.search}`}
                            >
                                Download PDF
                            </a>
                        </div>
                    )}
                </div>
                <form
                    method="get"
                    action={`/t/${tenant.slug}/academics/analytics`}
                    className="grid min-w-0 gap-3 sm:grid-cols-2 xl:grid-cols-4 print:hidden"
                >
                    {filters.campus_id !== null && (
                        <input type="hidden" name="campus_id" value={filters.campus_id} />
                    )}
                    {filters.from && <input type="hidden" name="from" value={filters.from} />}
                    {filters.to && <input type="hidden" name="to" value={filters.to} />}
                    {filters.metrics?.map((metric) => (
                        <input key={metric} type="hidden" name="metrics[]" value={metric} />
                    ))}
                    <label className="grid min-w-0 gap-1 text-sm font-medium" htmlFor="report-dataset">
                        Data source
                        <select
                            id="report-dataset"
                            name="dataset"
                            value={builderDataset}
                            onChange={(event) => {
                                const nextDataset = event.target.value;
                                setBuilderDataset(nextDataset);
                                setBuilderMeasure('records');
                                setBuilderDimensions([
                                    Object.keys(
                                        builder_report.definitions[nextDataset]?.dimensions ?? {},
                                    )[0] ?? '',
                                    '',
                                ]);
                                setBuilderStatus(
                                    builder_report.definitions[nextDataset]?.status_filter
                                        ? builderStatus
                                        : '',
                                );
                                setBuilderSort('total');
                            }}
                            className="border-border bg-surface min-h-11 w-full min-w-0 rounded-lg border px-3"
                        >
                            {Object.entries(builder_report.definitions).map(([key, item]) => (
                                <option key={key} value={key}>
                                    {item.label}
                                </option>
                            ))}
                        </select>
                    </label>
                    <label className="grid min-w-0 gap-1 text-sm font-medium" htmlFor="report-measure">
                        Measure
                        <select
                            id="report-measure"
                            name="report_measure"
                            value={builderMeasure}
                            onChange={(event) => setBuilderMeasure(event.target.value)}
                            className="border-border bg-surface min-h-11 w-full min-w-0 rounded-lg border px-3"
                        >
                            {Object.entries(builder_report.definitions[builderDataset]?.measures ?? {}).map(
                                ([key, label]) => (
                                    <option key={key} value={key}>
                                        {label}
                                    </option>
                                ),
                            )}
                        </select>
                    </label>
                    {[0, 1].map((index) => (
                        <label
                            key={index}
                            className="grid min-w-0 gap-1 text-sm font-medium"
                            htmlFor={`report-dimension-${index}`}
                        >
                            Group by {index + 1}
                            <select
                                id={`report-dimension-${index}`}
                                name="dimensions[]"
                                value={builderDimensions[index]}
                                onChange={(event) => {
                                    setBuilderDimensions((current) => {
                                        const next: [string, string] = [...current];
                                        next[index] = event.target.value;
                                        return next;
                                    });
                                    setBuilderSort('total');
                                }}
                                className="border-border bg-surface min-h-11 w-full min-w-0 rounded-lg border px-3"
                            >
                                <option value="">
                                    {index === 0 ? 'Choose a dimension' : 'No second grouping'}
                                </option>
                                {Object.entries(
                                    builder_report.definitions[builderDataset]?.dimensions ?? {},
                                ).map(([key, label]) => (
                                    <option key={key} value={key}>
                                        {label}
                                    </option>
                                ))}
                            </select>
                        </label>
                    ))}
                    {builder_report.definitions[builderDataset]?.status_filter && (
                        <label className="grid min-w-0 gap-1 text-sm font-medium" htmlFor="report-status">
                            Status filter
                            <input
                                id="report-status"
                                name="report_status"
                                maxLength={32}
                                value={builderStatus}
                                onChange={(event) => setBuilderStatus(event.target.value)}
                                className="border-border bg-surface min-h-11 w-full min-w-0 rounded-lg border px-3"
                                placeholder="Any status"
                            />
                        </label>
                    )}
                    {Object.entries(builder_report.definitions[builderDataset]?.filters ?? {}).map(
                        ([key, label]) => (
                            <label
                                key={key}
                                className="grid min-w-0 gap-1 text-sm font-medium"
                                htmlFor={`report-filter-${key}`}
                            >
                                {label}
                                <input
                                    id={`report-filter-${key}`}
                                    name={`report_filters[${key}]`}
                                    maxLength={100}
                                    defaultValue={builder_report.filters[key] ?? ''}
                                    className="border-border bg-surface min-h-11 w-full min-w-0 rounded-lg border px-3"
                                />
                            </label>
                        ),
                    )}
                    <label className="grid min-w-0 gap-1 text-sm font-medium" htmlFor="report-search">
                        Search grouped values
                        <input
                            id="report-search"
                            name="report_search"
                            maxLength={80}
                            defaultValue={builder_report.search}
                            className="border-border bg-surface min-h-11 w-full min-w-0 rounded-lg border px-3"
                        />
                    </label>
                    <label className="grid min-w-0 gap-1 text-sm font-medium" htmlFor="report-sort">
                        Sort by
                        <select
                            id="report-sort"
                            name="report_sort"
                            value={builderSort}
                            onChange={(event) => setBuilderSort(event.target.value)}
                            className="border-border bg-surface min-h-11 w-full min-w-0 rounded-lg border px-3"
                        >
                            <option value="total">Record count</option>
                            {builder_report.dimensions.map((dimension) => (
                                <option key={dimension} value={dimension}>
                                    {builder_report.definitions[builderDataset]?.dimensions[dimension]}
                                </option>
                            ))}
                        </select>
                    </label>
                    <label className="grid min-w-0 gap-1 text-sm font-medium" htmlFor="report-direction">
                        Direction
                        <select
                            id="report-direction"
                            name="report_direction"
                            defaultValue={builder_report.direction}
                            className="border-border bg-surface min-h-11 w-full min-w-0 rounded-lg border px-3"
                        >
                            <option value="desc">Descending</option>
                            <option value="asc">Ascending</option>
                        </select>
                    </label>
                    <input type="hidden" name="report_page" value="1" />
                    <div className="flex items-end gap-2">
                        <button
                            type="submit"
                            className="bg-primary text-on-primary min-h-11 rounded-lg px-4 text-sm font-semibold"
                        >
                            Run report
                        </button>
                        <span className="text-text-muted text-xs">
                            Count aggregation · 25 rows per page · CSV capped at{' '}
                            {builder_report.export_limit.toLocaleString()} groups
                        </span>
                    </div>
                </form>
                {builder_report.rows.length > 0 && (
                    <section className="mt-5" aria-labelledby="report-chart-title">
                        <h3 id="report-chart-title" className="text-text-primary text-sm font-semibold">
                            {builder_report.measure_label} by group
                        </h3>
                        <div
                            className="mt-2 grid gap-2"
                            role="img"
                            aria-label={`${builder_report.measure_label} chart for the current report page`}
                        >
                            {builder_report.rows.map((row, index) => {
                                const label = builder_report.dimensions
                                    .map((dimension) => String(row[dimension] ?? 'Unspecified'))
                                    .join(' · ');
                                const value = Number(row.total) || 0;
                                return (
                                    <div
                                        key={`${label}-${index}`}
                                        className="grid grid-cols-[minmax(5rem,0.5fr)_minmax(0,2fr)_auto] items-center gap-2 text-xs"
                                    >
                                        <span className="truncate" title={label}>
                                            {label}
                                        </span>
                                        <span
                                            className="bg-surface-soft h-3 overflow-hidden rounded"
                                            aria-hidden="true"
                                        >
                                            <span
                                                className="bg-primary block h-full rounded"
                                                style={{
                                                    width: `${Math.max(1, (value / maxBuilderValue) * 100)}%`,
                                                }}
                                            />
                                        </span>
                                        <span className="tabular-nums">{value.toLocaleString()}</span>
                                    </div>
                                );
                            })}
                        </div>
                    </section>
                )}
                <div className="mt-5 overflow-x-auto" role="region" aria-label="Report results" tabIndex={0}>
                    {builder_report.rows.length ? (
                        <table className="w-full min-w-[32rem] border-collapse text-left text-sm">
                            <caption className="sr-only">
                                Grouped report results for{' '}
                                {builder_report.definitions[builder_report.dataset]?.label}
                            </caption>
                            <thead>
                                <tr className="border-border border-b">
                                    {builder_report.dimensions.map((dimension) => (
                                        <th key={dimension} scope="col" className="px-3 py-2 font-semibold">
                                            {
                                                builder_report.definitions[builder_report.dataset]
                                                    ?.dimensions[dimension]
                                            }
                                        </th>
                                    ))}
                                    <th scope="col" className="px-3 py-2 text-right font-semibold">
                                        {builder_report.measure_label}
                                    </th>
                                </tr>
                            </thead>
                            <tbody>
                                {builder_report.rows.map((row, index) => (
                                    <tr key={index} className="border-border border-b last:border-0">
                                        {builder_report.dimensions.map((dimension) => (
                                            <td key={dimension} className="px-3 py-2">
                                                <a
                                                    className="text-primary underline focus-visible:outline-2"
                                                    href={reportDrilldownHref(
                                                        tenant.slug,
                                                        builder_report,
                                                        dimension,
                                                        String(row[dimension] ?? 'Unspecified'),
                                                    )}
                                                >
                                                    {String(row[dimension] ?? 'Unspecified')}
                                                </a>
                                            </td>
                                        ))}
                                        <td className="px-3 py-2 text-right tabular-nums">
                                            {Number(row.total).toLocaleString()}
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    ) : (
                        <p className="text-text-secondary bg-surface-soft rounded-xl px-4 py-8 text-center text-sm">
                            No records match this report configuration.
                        </p>
                    )}
                </div>
                <div className="mt-3 flex flex-wrap items-center justify-between gap-2 text-sm">
                    <p className="text-text-muted">
                        {builder_report.total.toLocaleString()} grouped results · page {builder_report.page}{' '}
                        of {Math.max(1, Math.ceil(builder_report.total / builder_report.per_page))}
                    </p>
                    <div className="flex gap-2">
                        {builder_report.page > 1 && (
                            <a
                                className="border-border rounded-lg border px-3 py-2 underline focus-visible:outline-2"
                                href={reportPageHref(builder_report.page - 1)}
                            >
                                Previous
                            </a>
                        )}
                        {builder_report.page * builder_report.per_page < builder_report.total && (
                            <a
                                className="border-border rounded-lg border px-3 py-2 underline focus-visible:outline-2"
                                href={reportPageHref(builder_report.page + 1)}
                            >
                                Next
                            </a>
                        )}
                    </div>
                </div>
            </section>

            {(permissions.manage_reports || saved_reports.length > 0) && (
                <section
                    className="border-border bg-surface shadow-card mb-6 grid gap-5 rounded-2xl border p-4 lg:grid-cols-[minmax(16rem,0.7fr)_minmax(0,1.3fr)]"
                    aria-label="Saved reports"
                >
                    {permissions.manage_reports && (
                        <form onSubmit={saveCurrentReport} className="grid content-start gap-3">
                            <div>
                                <h2 className="text-text-primary font-semibold">Save this report</h2>
                                <p className="text-text-secondary mt-1 text-sm">
                                    Save the current authorized campus, date and metric filters for your
                                    membership.
                                </p>
                            </div>
                            <label className="grid gap-1 text-sm font-medium" htmlFor="saved-report-name">
                                Report name
                                <input
                                    id="saved-report-name"
                                    value={saveForm.data.name}
                                    onChange={(event) => saveForm.setData('name', event.target.value)}
                                    maxLength={100}
                                    required
                                    className="border-border bg-surface min-h-11 rounded-lg border px-3"
                                />
                            </label>
                            {saveForm.errors.name && (
                                <p role="alert" className="text-danger text-sm">
                                    {saveForm.errors.name}
                                </p>
                            )}
                            <button
                                type="submit"
                                disabled={saveForm.processing}
                                className="bg-primary text-on-primary min-h-11 justify-self-start rounded-lg px-4 text-sm font-semibold disabled:opacity-60"
                            >
                                {saveForm.processing ? 'Saving…' : 'Save current filters'}
                            </button>
                        </form>
                    )}
                    <div>
                        <h2 className="text-text-primary font-semibold">Your saved reports</h2>
                        {saved_reports.length ? (
                            <ul className="divide-border mt-2 divide-y">
                                {saved_reports.map((report) => (
                                    <li
                                        key={report.id}
                                        className="flex flex-wrap items-center justify-between gap-3 py-3 first:pt-0 last:pb-0"
                                    >
                                        <Link
                                            href={`/t/${tenant.slug}/academics/analytics/reports/${report.id}`}
                                            className="text-primary min-h-10 rounded px-1 py-2 font-medium underline focus-visible:outline-2"
                                        >
                                            {report.name}
                                        </Link>
                                        {permissions.manage_reports && (
                                            <button
                                                type="button"
                                                onClick={() =>
                                                    window.confirm(`Delete saved report “${report.name}”?`) &&
                                                    router.delete(
                                                        `/t/${tenant.slug}/academics/analytics/reports/${report.id}`,
                                                    )
                                                }
                                                className="border-border hover:bg-surface-soft min-h-10 rounded-lg border px-3 text-sm"
                                            >
                                                Delete
                                            </button>
                                        )}
                                    </li>
                                ))}
                            </ul>
                        ) : (
                            <p className="text-text-secondary bg-surface-soft mt-2 rounded-xl px-4 py-6 text-sm">
                                You have not saved a report yet.
                            </p>
                        )}
                    </div>
                </section>
            )}

            <section
                className="border-border bg-surface shadow-card mb-6 rounded-2xl border p-4"
                aria-labelledby="report-export-history-title"
            >
                <h2 id="report-export-history-title" className="text-text-primary font-semibold">
                    Your recent report exports
                </h2>
                {export_history.length > 0 ? (
                    <div className="border-border mt-3 overflow-x-auto rounded-xl border">
                        <table className="w-full min-w-[36rem] text-left text-sm">
                            <caption className="sr-only">Your last ten grouped report CSV exports</caption>
                            <thead className="bg-surface-soft text-text-secondary">
                                <tr>
                                    <th scope="col" className="px-3 py-2">
                                        Dataset
                                    </th>
                                    <th scope="col" className="px-3 py-2">
                                        Exported rows
                                    </th>
                                    <th scope="col" className="px-3 py-2">
                                        Result
                                    </th>
                                    <th scope="col" className="px-3 py-2">
                                        Time
                                    </th>
                                </tr>
                            </thead>
                            <tbody>
                                {export_history.map((entry) => (
                                    <tr key={entry.id} className="border-border border-t">
                                        <th scope="row" className="px-3 py-2 font-medium">
                                            {entry.dataset}
                                        </th>
                                        <td className="px-3 py-2">{entry.rows}</td>
                                        <td className="px-3 py-2">
                                            {entry.truncated ? 'Truncated at safety limit' : 'Complete'}
                                        </td>
                                        <td className="px-3 py-2">
                                            {entry.created_at
                                                ? new Date(entry.created_at).toLocaleString()
                                                : 'Unknown'}
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                ) : (
                    <p className="text-text-secondary bg-surface-soft mt-2 rounded-xl px-4 py-6 text-sm">
                        No report exports have been recorded for your account.
                    </p>
                )}
                {permissions.manage_reports && (saved_reports.length > 0 || report_schedules.length > 0) && (
                    <section
                        className="border-border bg-surface-soft mt-5 rounded-xl border p-4"
                        aria-labelledby="scheduled-reports-title"
                    >
                        <h3 id="scheduled-reports-title" className="text-text-primary font-semibold">
                            Scheduled reports
                        </h3>
                        {saved_reports.length > 0 ? (
                            <form
                                className="mt-3 grid gap-3 sm:grid-cols-2 xl:grid-cols-4"
                                onSubmit={(event) => {
                                    event.preventDefault();
                                    scheduleForm.post(`/t/${tenant.slug}/academics/analytics/schedules`, {
                                        onSuccess: () => scheduleForm.reset('name'),
                                    });
                                }}
                            >
                                <label className="grid gap-1 text-sm font-medium">
                                    Saved report
                                    <select
                                        required
                                        value={scheduleForm.data.saved_report_id}
                                        onChange={(event) =>
                                            scheduleForm.setData('saved_report_id', event.target.value)
                                        }
                                        className="border-border bg-surface min-h-11 rounded-lg border px-3"
                                    >
                                        <option value="">Choose a saved report</option>
                                        {saved_reports.map((report) => (
                                            <option key={report.id} value={report.id}>
                                                {report.name}
                                            </option>
                                        ))}
                                    </select>
                                </label>
                                <label className="grid gap-1 text-sm font-medium">
                                    Schedule name
                                    <input
                                        required
                                        maxLength={100}
                                        value={scheduleForm.data.name}
                                        onChange={(event) => scheduleForm.setData('name', event.target.value)}
                                        className="border-border bg-surface min-h-11 rounded-lg border px-3"
                                    />
                                </label>
                                <label className="grid gap-1 text-sm font-medium">
                                    Frequency
                                    <select
                                        value={scheduleForm.data.frequency}
                                        onChange={(event) =>
                                            scheduleForm.setData('frequency', event.target.value)
                                        }
                                        className="border-border bg-surface min-h-11 rounded-lg border px-3"
                                    >
                                        <option value="daily">Daily</option>
                                        <option value="weekly">Weekly</option>
                                        <option value="monthly">Monthly</option>
                                    </select>
                                </label>
                                <label className="grid gap-1 text-sm font-medium">
                                    Timezone
                                    <input
                                        required
                                        list="analytics-timezones"
                                        value={scheduleForm.data.timezone}
                                        onChange={(event) =>
                                            scheduleForm.setData('timezone', event.target.value)
                                        }
                                        className="border-border bg-surface min-h-11 rounded-lg border px-3"
                                    />
                                    <datalist id="analytics-timezones">
                                        {[
                                            'UTC',
                                            'Asia/Karachi',
                                            'Asia/Dubai',
                                            'Europe/London',
                                            'America/New_York',
                                        ].map((zone) => (
                                            <option key={zone} value={zone} />
                                        ))}
                                    </datalist>
                                </label>
                                <label className="grid gap-1 text-sm font-medium">
                                    Local delivery time
                                    <input
                                        type="time"
                                        required
                                        value={scheduleForm.data.local_time}
                                        onChange={(event) =>
                                            scheduleForm.setData('local_time', event.target.value)
                                        }
                                        className="border-border bg-surface min-h-11 rounded-lg border px-3"
                                    />
                                </label>
                                {scheduleForm.data.frequency === 'weekly' && (
                                    <label className="grid gap-1 text-sm font-medium">
                                        Weekday
                                        <select
                                            value={scheduleForm.data.weekday}
                                            onChange={(event) =>
                                                scheduleForm.setData('weekday', event.target.value)
                                            }
                                            className="border-border bg-surface min-h-11 rounded-lg border px-3"
                                        >
                                            {[
                                                'Sunday',
                                                'Monday',
                                                'Tuesday',
                                                'Wednesday',
                                                'Thursday',
                                                'Friday',
                                                'Saturday',
                                            ].map((day, index) => (
                                                <option key={day} value={index}>
                                                    {day}
                                                </option>
                                            ))}
                                        </select>
                                    </label>
                                )}
                                {scheduleForm.data.frequency === 'monthly' && (
                                    <label className="grid gap-1 text-sm font-medium">
                                        Day of month
                                        <input
                                            type="number"
                                            min={1}
                                            max={28}
                                            value={scheduleForm.data.day_of_month}
                                            onChange={(event) =>
                                                scheduleForm.setData('day_of_month', event.target.value)
                                            }
                                            className="border-border bg-surface min-h-11 rounded-lg border px-3"
                                        />
                                    </label>
                                )}
                                <label className="grid gap-1 text-sm font-medium">
                                    Recipients (you are included)
                                    <select
                                        multiple
                                        value={scheduleForm.data.recipients}
                                        onChange={(event) =>
                                            scheduleForm.setData(
                                                'recipients',
                                                Array.from(
                                                    event.target.selectedOptions,
                                                    (option) => option.value,
                                                ),
                                            )
                                        }
                                        className="border-border bg-surface min-h-24 rounded-lg border px-3"
                                    >
                                        {report_recipients.map((recipient) => (
                                            <option key={recipient.id} value={recipient.id}>
                                                {recipient.name} — {recipient.email}
                                            </option>
                                        ))}
                                    </select>
                                </label>
                                <button
                                    disabled={scheduleForm.processing}
                                    className="bg-primary text-on-primary min-h-11 self-end rounded-lg px-4 text-sm font-semibold"
                                >
                                    Create schedule
                                </button>
                            </form>
                        ) : (
                            <p className="text-text-secondary mt-3 text-sm">
                                Save a report configuration before scheduling it. Existing schedule history
                                remains visible below.
                            </p>
                        )}
                        <ul className="divide-border mt-4 divide-y">
                            {report_schedules.map((schedule) => (
                                <li key={schedule.id} className="py-3">
                                    <div className="flex flex-wrap items-center justify-between gap-2">
                                        <p className="text-text-primary font-medium">
                                            {schedule.name} · {schedule.frequency} · {schedule.status}
                                        </p>
                                        <div className="flex gap-2">
                                            {schedule.status !== 'cancelled' && (
                                                <button
                                                    type="button"
                                                    onClick={() =>
                                                        router.post(
                                                            `/t/${tenant.slug}/academics/analytics/schedules/${schedule.id}/status`,
                                                            {
                                                                status:
                                                                    schedule.status === 'paused'
                                                                        ? 'active'
                                                                        : 'paused',
                                                            },
                                                        )
                                                    }
                                                    className="border-border min-h-9 rounded-lg border px-3 text-sm"
                                                >
                                                    {schedule.status === 'paused' ? 'Resume' : 'Pause'}
                                                </button>
                                            )}
                                            {schedule.status !== 'cancelled' && (
                                                <button
                                                    type="button"
                                                    onClick={() =>
                                                        window.confirm('Cancel this schedule?') &&
                                                        router.post(
                                                            `/t/${tenant.slug}/academics/analytics/schedules/${schedule.id}/status`,
                                                            { status: 'cancelled' },
                                                        )
                                                    }
                                                    className="border-border min-h-9 rounded-lg border px-3 text-sm"
                                                >
                                                    Cancel
                                                </button>
                                            )}
                                        </div>
                                    </div>
                                    <p className="text-text-muted mt-1 text-xs">
                                        Next:{' '}
                                        {schedule.next_run_at
                                            ? new Date(schedule.next_run_at).toLocaleString()
                                            : 'Not scheduled'}{' '}
                                        ({schedule.timezone})
                                    </p>
                                    {schedule.status !== 'cancelled' && (
                                        <details className="mt-2">
                                            <summary className="text-primary cursor-pointer underline">
                                                Edit schedule
                                            </summary>
                                            <form
                                                className="mt-2 grid gap-2 sm:grid-cols-2"
                                                onSubmit={(event) => {
                                                    event.preventDefault();
                                                    const form = new FormData(event.currentTarget);
                                                    router.put(
                                                        `/t/${tenant.slug}/academics/analytics/schedules/${schedule.id}`,
                                                        {
                                                            saved_report_id: Number(
                                                                form.get('saved_report_id'),
                                                            ),
                                                            name: String(form.get('name') ?? ''),
                                                            frequency: String(form.get('frequency') ?? ''),
                                                            timezone: String(form.get('timezone') ?? ''),
                                                            local_time: String(form.get('local_time') ?? ''),
                                                            weekday: Number(form.get('weekday')),
                                                            day_of_month: Number(form.get('day_of_month')),
                                                            recipients: Array.from(
                                                                (
                                                                    event.currentTarget.elements.namedItem(
                                                                        'recipients',
                                                                    ) as HTMLSelectElement
                                                                ).selectedOptions,
                                                                (option) => Number(option.value),
                                                            ),
                                                        },
                                                    );
                                                }}
                                            >
                                                <label className="grid gap-1 text-sm">
                                                    Saved report
                                                    <select
                                                        name="saved_report_id"
                                                        defaultValue={schedule.saved_report_id}
                                                        className="border-border bg-surface min-h-10 rounded-lg border px-2"
                                                    >
                                                        {saved_reports.map((report) => (
                                                            <option key={report.id} value={report.id}>
                                                                {report.name}
                                                            </option>
                                                        ))}
                                                    </select>
                                                </label>
                                                <label className="grid gap-1 text-sm">
                                                    Name
                                                    <input
                                                        name="name"
                                                        defaultValue={schedule.name}
                                                        maxLength={100}
                                                        className="border-border bg-surface min-h-10 rounded-lg border px-2"
                                                    />
                                                </label>
                                                <label className="grid gap-1 text-sm">
                                                    Frequency
                                                    <select
                                                        name="frequency"
                                                        defaultValue={schedule.frequency}
                                                        className="border-border bg-surface min-h-10 rounded-lg border px-2"
                                                    >
                                                        <option value="daily">Daily</option>
                                                        <option value="weekly">Weekly</option>
                                                        <option value="monthly">Monthly</option>
                                                    </select>
                                                </label>
                                                <label className="grid gap-1 text-sm">
                                                    Timezone
                                                    <input
                                                        name="timezone"
                                                        defaultValue={schedule.timezone}
                                                        className="border-border bg-surface min-h-10 rounded-lg border px-2"
                                                    />
                                                </label>
                                                <label className="grid gap-1 text-sm">
                                                    Local time
                                                    <input
                                                        name="local_time"
                                                        type="time"
                                                        defaultValue={schedule.local_time}
                                                        className="border-border bg-surface min-h-10 rounded-lg border px-2"
                                                    />
                                                </label>
                                                <label className="grid gap-1 text-sm">
                                                    Weekday for weekly reports (0 = Sunday)
                                                    <input
                                                        name="weekday"
                                                        type="number"
                                                        min={0}
                                                        max={6}
                                                        defaultValue={schedule.weekday ?? 1}
                                                        className="border-border bg-surface min-h-10 rounded-lg border px-2"
                                                    />
                                                </label>
                                                <label className="grid gap-1 text-sm">
                                                    Day for monthly reports (1–28)
                                                    <input
                                                        name="day_of_month"
                                                        type="number"
                                                        min={1}
                                                        max={28}
                                                        defaultValue={schedule.day_of_month ?? 1}
                                                        className="border-border bg-surface min-h-10 rounded-lg border px-2"
                                                    />
                                                </label>
                                                <label className="grid gap-1 text-sm">
                                                    Recipients
                                                    <select
                                                        name="recipients"
                                                        multiple
                                                        defaultValue={schedule.recipient_ids.map(String)}
                                                        className="border-border bg-surface min-h-20 rounded-lg border px-2"
                                                    >
                                                        {report_recipients.map((recipient) => (
                                                            <option key={recipient.id} value={recipient.id}>
                                                                {recipient.name} — {recipient.email}
                                                            </option>
                                                        ))}
                                                    </select>
                                                </label>
                                                <button className="bg-primary text-on-primary min-h-10 justify-self-start rounded-lg px-3 text-sm">
                                                    Save changes
                                                </button>
                                            </form>
                                        </details>
                                    )}
                                    {schedule.runs.map((run) => (
                                        <p key={run.id} className="mt-1 text-sm">
                                            Run {run.id}: {run.status} · {run.recipient_count} recipients{' '}
                                            {run.status === 'completed' ? (
                                                <a
                                                    className="text-primary underline"
                                                    href={`/t/${tenant.slug}/academics/analytics/schedules/runs/${run.id}`}
                                                >
                                                    Download PDF
                                                </a>
                                            ) : null}
                                        </p>
                                    ))}
                                </li>
                            ))}
                        </ul>
                    </section>
                )}
            </section>

            {permissions.view_academic_metrics && (
                <section
                    aria-label="Academic KPI summary"
                    className="grid gap-3 sm:grid-cols-2 xl:grid-cols-4"
                >
                    {metrics.map((metric) => (
                        <MetricCard
                            key={metric.key}
                            label={metric.name}
                            value={metric.display}
                            detail={metric.definition}
                        />
                    ))}
                </section>
            )}

            {permissions.view_academic_metrics && (
                <div className="mt-6 grid min-w-0 grid-cols-1 gap-5 xl:grid-cols-[minmax(0,1.4fr)_minmax(18rem,0.6fr)]">
                    <section
                        className="border-border bg-surface shadow-card min-w-0 rounded-2xl border p-5"
                        aria-labelledby="enrollment-trend-title"
                    >
                        <div className="flex flex-wrap items-start justify-between gap-3">
                            <div>
                                <h2
                                    id="enrollment-trend-title"
                                    className="text-text-primary text-base font-semibold"
                                >
                                    Confirmed enrollment activity
                                </h2>
                                <p className="text-text-secondary mt-1 text-sm">
                                    Daily confirmed registrations in the selected scope.
                                </p>
                            </div>
                            <span className="text-text-muted text-xs">Source: course enrollments</span>
                        </div>
                        {trend.some((point) => point.value > 0) ? (
                            <div
                                className="mt-5 overflow-x-auto"
                                role="region"
                                aria-label="Enrollment trend chart"
                                tabIndex={0}
                            >
                                <div
                                    className="border-border flex min-w-[36rem] items-end gap-1 border-b px-1 pb-2"
                                    role="img"
                                    aria-label={`Confirmed enrollments by day; peak ${maxValue}`}
                                >
                                    {trend.map((point) => (
                                        <div
                                            key={point.date}
                                            className="group flex min-w-0 flex-1 flex-col items-center justify-end gap-1"
                                            title={`${point.date}: ${point.value}`}
                                        >
                                            <span className="text-text-muted text-[10px]">
                                                {point.value || ''}
                                            </span>
                                            <span
                                                className="bg-primary w-full max-w-5 rounded-t-sm"
                                                style={{
                                                    height: `${Math.max(3, (point.value / maxValue) * 120)}px`,
                                                }}
                                                aria-hidden="true"
                                            />
                                        </div>
                                    ))}
                                </div>
                                <div className="text-text-muted mt-2 flex justify-between text-xs">
                                    <span>{trend[0]?.date}</span>
                                    <span>{trend.at(-1)?.date}</span>
                                </div>
                            </div>
                        ) : (
                            <p className="text-text-secondary bg-surface-soft mt-5 rounded-xl px-4 py-8 text-center text-sm">
                                No confirmed enrollment activity in this date range.
                            </p>
                        )}
                        <table className="sr-only">
                            <caption>Daily confirmed enrollment activity</caption>
                            <thead>
                                <tr>
                                    <th scope="col">Date</th>
                                    <th scope="col">Confirmed enrollments</th>
                                </tr>
                            </thead>
                            <tbody>
                                {trend.map((point) => (
                                    <tr key={point.date}>
                                        <th scope="row">{point.date}</th>
                                        <td>{point.value}</td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </section>

                    <section
                        className="border-border bg-surface shadow-card min-w-0 rounded-2xl border p-5"
                        aria-labelledby="metric-definitions-title"
                    >
                        <h2
                            id="metric-definitions-title"
                            className="text-text-primary text-base font-semibold"
                        >
                            Metric definitions
                        </h2>
                        <ul className="divide-border mt-3 divide-y">
                            {metrics.map((metric) => (
                                <li key={metric.key} className="py-3 first:pt-0 last:pb-0">
                                    <p className="text-text-primary text-sm font-medium">
                                        {metric.name}: {metric.display}
                                    </p>
                                    <p className="text-text-secondary mt-1 text-xs">{metric.formula}</p>
                                    <p className="text-text-muted mt-1 text-xs">
                                        Date basis: {metric.date_basis} · Sources: {metric.sources.join(', ')}
                                    </p>
                                </li>
                            ))}
                        </ul>
                    </section>
                </div>
            )}

            <nav aria-label="Related academic workspaces" className="mt-6 flex flex-wrap gap-2 text-sm">
                <Link
                    className="text-primary rounded px-2 py-2 underline focus-visible:outline-2"
                    href={`/t/${tenant.slug}/academics/grades`}
                >
                    Gradebooks
                </Link>
                <Link
                    className="text-primary rounded px-2 py-2 underline focus-visible:outline-2"
                    href={`/t/${tenant.slug}/academics/reports`}
                >
                    Academic reports
                </Link>
                <Link
                    className="text-primary rounded px-2 py-2 underline focus-visible:outline-2"
                    href={`/t/${tenant.slug}/academics/records`}
                >
                    Official records
                </Link>
            </nav>
        </WorkspaceLayout>
    );
}

function reportPageHref(page: number): string {
    const params = new URLSearchParams(window.location.search);
    params.set('report_page', String(page));
    return `${window.location.pathname}?${params.toString()}`;
}

function reportDrilldownHref(slug: string, report: BuilderReport, dimension: string, value: string): string {
    const params = new URLSearchParams(window.location.search);
    params.set('dataset', report.dataset);
    params.set('dimension', dimension);
    params.set('value', value);
    params.set('report_measure', report.measure);
    params.delete('dimensions[]');
    params.delete('report_search');
    params.delete('report_sort');
    params.delete('report_direction');
    return `/t/${slug}/academics/analytics/drilldown?${params.toString()}`;
}
