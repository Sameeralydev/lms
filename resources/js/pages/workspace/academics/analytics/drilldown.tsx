import { Link } from '@inertiajs/react';
import { PageHeader } from '@/components/ui/page-header';
import { WorkspaceLayout } from '@/layouts/workspace-layout';

type Detail = {
    record_id: number;
    status: string | null;
    occurred_at: string | null;
    campus: string | null;
    measure_value: number | string;
};

export default function AcademicAnalyticsDrilldown({
    tenant,
    report,
    source_label,
}: {
    tenant: { slug: string };
    source_label: string;
    report: {
        dataset: string;
        dimension: string;
        value: string;
        measure: string;
        page: number;
        per_page: number;
        total: number;
        rows: Detail[];
    };
}) {
    const query = new URLSearchParams(window.location.search);
    const pageUrl = (page: number) => {
        query.set('page', String(page));
        return `${window.location.pathname}?${query.toString()}`;
    };

    return (
        <WorkspaceLayout area="tenant" title="Report record details">
            <PageHeader
                title="Report record details"
                description={`${source_label}: ${report.dimension} = ${report.value}`}
            />
            <section className="border-border bg-surface shadow-card rounded-2xl border p-4">
                <p className="text-text-secondary mb-4 text-sm">
                    {report.total.toLocaleString()} matching records. Details omit personal identifiers and
                    confidential marks.
                </p>
                <div
                    className="overflow-x-auto"
                    role="region"
                    aria-label="Matching report records"
                    tabIndex={0}
                >
                    <table className="w-full min-w-[36rem] text-left text-sm">
                        <caption className="sr-only">
                            Privacy-limited source records for the selected report group
                        </caption>
                        <thead>
                            <tr className="border-border border-b">
                                <th scope="col" className="px-3 py-2">
                                    Reference
                                </th>
                                <th scope="col" className="px-3 py-2">
                                    Status/type
                                </th>
                                <th scope="col" className="px-3 py-2">
                                    Date
                                </th>
                                <th scope="col" className="px-3 py-2">
                                    Campus
                                </th>
                                <th scope="col" className="px-3 py-2">
                                    Measure value
                                </th>
                            </tr>
                        </thead>
                        <tbody>
                            {report.rows.map((row) => (
                                <tr key={row.record_id} className="border-border border-b last:border-0">
                                    <th scope="row" className="px-3 py-2">
                                        #{row.record_id}
                                    </th>
                                    <td className="px-3 py-2">{row.status ?? 'Unspecified'}</td>
                                    <td className="px-3 py-2">{row.occurred_at ?? '—'}</td>
                                    <td className="px-3 py-2">{row.campus ?? 'Unassigned'}</td>
                                    <td className="px-3 py-2">
                                        {Number(row.measure_value).toLocaleString()}
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
                <div className="mt-4 flex flex-wrap items-center justify-between gap-3 text-sm">
                    <Link
                        href={`/t/${tenant.slug}/academics/analytics`}
                        className="text-primary min-h-10 underline focus-visible:outline-2"
                    >
                        Back to analytics
                    </Link>
                    <div className="flex gap-2">
                        {report.page > 1 && (
                            <a
                                className="border-border rounded-lg border px-3 py-2 underline focus-visible:outline-2"
                                href={pageUrl(report.page - 1)}
                            >
                                Previous
                            </a>
                        )}
                        {report.page * report.per_page < report.total && (
                            <a
                                className="border-border rounded-lg border px-3 py-2 underline focus-visible:outline-2"
                                href={pageUrl(report.page + 1)}
                            >
                                Next
                            </a>
                        )}
                    </div>
                </div>
            </section>
        </WorkspaceLayout>
    );
}
