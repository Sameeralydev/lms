import { Head, router } from '@inertiajs/react';
import { type FormEvent } from 'react';
import { PageHeader } from '@/components/ui/page-header';
import { WorkspaceLayout } from '@/layouts/workspace-layout';

type Props = {
    tenant: { slug: string };
    filters: { record_type?: string; status?: string; from?: string; to?: string };
    records: {
        data: {
            id: number;
            student_number: string;
            record_type: string;
            status: string;
            issue_number: number;
            created_at: string;
            issued_at: string | null;
        }[];
        links: { url: string | null; label: string; active: boolean }[];
        total: number;
    };
    metrics: {
        gradebooks_pending: number;
        gradebook_completion: { draft: number; submitted: number; approved: number; published: number };
        published_results: number;
        grade_distribution: { grade: string; results: number }[];
        record_approvals_pending: number;
        issued_report_cards: number;
        issued_transcripts: number;
        issued_certificates: number;
        revoked_certificates: number;
        standing: { standing: string; learners: number }[];
    };
};

export default function AcademicReporting({ tenant, filters, records, metrics }: Props) {
    const apply = (event: FormEvent<HTMLFormElement>) => {
        event.preventDefault();
        const data = new FormData(event.currentTarget);
        router.get(`/t/${tenant.slug}/academics/reports`, Object.fromEntries(data.entries()), {
            preserveState: true,
            preserveScroll: true,
        });
    };
    const cards = [
        ['Gradebooks awaiting review', metrics.gradebooks_pending],
        ['Published learner results', metrics.published_results],
        ['Records awaiting review', metrics.record_approvals_pending],
        ['Report cards issued', metrics.issued_report_cards],
        ['Transcripts issued', metrics.issued_transcripts],
        ['Certificates issued', metrics.issued_certificates],
        ['Certificates revoked', metrics.revoked_certificates],
    ];
    return (
        <WorkspaceLayout area="tenant" title="Academic reports">
            <Head title="Academic reports" />
            <PageHeader
                title="Academic operations reports"
                description="Live institution- and campus-scoped counts from persisted gradebooks, official records, GPA snapshots, and their lifecycle status."
            />
            <section
                aria-label="Academic summary metrics"
                className="grid gap-3 sm:grid-cols-2 xl:grid-cols-3"
            >
                {cards.map(([label, value]) => (
                    <article key={label} className="border-border bg-surface rounded-xl border p-4">
                        <h2 className="text-text-secondary text-sm">{label}</h2>
                        <p className="mt-1 text-2xl font-semibold">{value}</p>
                    </article>
                ))}
            </section>
            <section className="border-border bg-surface mt-6 rounded-2xl border p-5 sm:p-7">
                <h2 className="text-lg font-semibold">Gradebook completion</h2>
                <ul className="mt-3 flex flex-wrap gap-3">
                    {Object.entries(metrics.gradebook_completion).map(([status, count]) => (
                        <li key={status} className="border-border rounded-lg border px-3 py-2 text-sm">
                            {status}: {count}
                        </li>
                    ))}
                </ul>
                <h3 className="mt-5 font-semibold">Published grade distribution</h3>
                {metrics.grade_distribution.length ? (
                    <ul className="mt-2 flex flex-wrap gap-3">
                        {metrics.grade_distribution.map((row) => (
                            <li key={row.grade} className="border-border rounded-lg border px-3 py-2 text-sm">
                                {row.grade}: {row.results} results
                            </li>
                        ))}
                    </ul>
                ) : (
                    <p className="text-text-secondary mt-2 text-sm">
                        No scale-labelled results are published.
                    </p>
                )}
            </section>
            <section className="border-border bg-surface mt-6 rounded-2xl border p-5 sm:p-7">
                <div className="flex flex-wrap items-center justify-between gap-3">
                    <h2 className="text-lg font-semibold">Latest cumulative academic standing</h2>
                    <ul className="flex flex-wrap gap-3">
                        {metrics.standing.map((row) => (
                            <li
                                className="border-border rounded-lg border px-3 py-2 text-sm"
                                key={row.standing}
                            >
                                {row.standing || 'Not assigned'}: {row.learners}
                            </li>
                        ))}
                    </ul>
                </div>
            </section>
            <section className="border-border bg-surface mt-6 rounded-2xl border p-5 sm:p-7">
                <div className="flex flex-wrap items-center justify-between gap-3">
                    <h2 className="text-lg font-semibold">Official record activity</h2>
                    <a
                        className="text-brand-link focus-ring rounded px-2 py-1 text-sm font-semibold"
                        href={`/t/${tenant.slug}/academics/reports/records.csv?${new URLSearchParams(Object.entries(filters).filter((entry): entry is [string, string] => typeof entry[1] === 'string'))}`}
                    >
                        Export filtered CSV
                    </a>
                </div>
                <form onSubmit={apply} className="mt-4 grid gap-3 sm:grid-cols-2 xl:grid-cols-5">
                    <label className="text-sm">
                        Type
                        <select
                            name="record_type"
                            defaultValue={filters.record_type ?? ''}
                            className="border-border bg-surface focus-ring mt-1 min-h-11 w-full rounded-lg border px-3"
                        >
                            <option value="">All types</option>
                            <option value="report_card">Report card</option>
                            <option value="transcript">Transcript</option>
                            <option value="certificate">Certificate</option>
                        </select>
                    </label>
                    <label className="text-sm">
                        Status
                        <select
                            name="status"
                            defaultValue={filters.status ?? ''}
                            className="border-border bg-surface focus-ring mt-1 min-h-11 w-full rounded-lg border px-3"
                        >
                            <option value="">All statuses</option>
                            {['draft', 'submitted', 'approved', 'rejected', 'issued', 'revoked'].map(
                                (value) => (
                                    <option key={value}>{value}</option>
                                ),
                            )}
                        </select>
                    </label>
                    <label className="text-sm">
                        From
                        <input
                            type="date"
                            name="from"
                            defaultValue={filters.from ?? ''}
                            className="border-border bg-surface focus-ring mt-1 min-h-11 w-full rounded-lg border px-3"
                        />
                    </label>
                    <label className="text-sm">
                        To
                        <input
                            type="date"
                            name="to"
                            defaultValue={filters.to ?? ''}
                            className="border-border bg-surface focus-ring mt-1 min-h-11 w-full rounded-lg border px-3"
                        />
                    </label>
                    <button className="bg-brand-action focus-ring self-end rounded-lg px-4 py-2.5 font-semibold text-white">
                        Apply filters
                    </button>
                </form>
                <div className="mt-5 overflow-x-auto">
                    <table className="w-full min-w-[640px] text-left text-sm">
                        <caption className="sr-only">Academic official record activity</caption>
                        <thead>
                            <tr className="border-border border-b">
                                <th scope="col" className="p-3">
                                    Learner number
                                </th>
                                <th scope="col" className="p-3">
                                    Record
                                </th>
                                <th scope="col" className="p-3">
                                    Status
                                </th>
                                <th scope="col" className="p-3">
                                    Version
                                </th>
                                <th scope="col" className="p-3">
                                    Created
                                </th>
                            </tr>
                        </thead>
                        <tbody>
                            {records.data.map((row) => (
                                <tr key={row.id} className="border-border border-b">
                                    <td className="p-3">{row.student_number}</td>
                                    <td className="p-3">{row.record_type}</td>
                                    <td className="p-3">{row.status}</td>
                                    <td className="p-3">{row.issue_number}</td>
                                    <td className="p-3">{row.created_at}</td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                    {records.data.length === 0 && (
                        <p className="text-text-secondary py-6 text-center">
                            No records match these filters.
                        </p>
                    )}
                </div>
                <nav aria-label="Report pagination" className="mt-4 flex flex-wrap gap-2">
                    {records.links.map((link) => {
                        const label = link.label.replaceAll('&laquo;', '‹').replaceAll('&raquo;', '›');
                        return link.url ? (
                            <a
                                key={link.label}
                                href={link.url}
                                aria-current={link.active ? 'page' : undefined}
                                className={`focus-ring rounded border px-3 py-2 text-sm ${link.active ? 'border-brand-action font-semibold' : 'border-border'}`}
                            >
                                {label}
                            </a>
                        ) : (
                            <span
                                key={link.label}
                                className="text-text-secondary border-border rounded border px-3 py-2 text-sm"
                            >
                                {label}
                            </span>
                        );
                    })}
                </nav>
                <p className="text-text-secondary mt-2 text-xs">
                    {records.total} matching records. Export is capped at 5,000 rows and is private/no-store.
                </p>
            </section>
        </WorkspaceLayout>
    );
}
