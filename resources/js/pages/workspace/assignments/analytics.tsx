import { Head, Link } from '@inertiajs/react';
import { EmptyState } from '@/components/ui/empty-state';
import { PageHeader } from '@/components/ui/page-header';
import { WorkspaceLayout } from '@/layouts/workspace-layout';

type Assignment = {
    id: number;
    title: string;
    course: string;
    status: string;
    due_at: string | null;
    eligible: number;
    submissions: number;
};
type Props = {
    tenant: { slug: string };
    metrics: {
        assignments: number;
        eligible_learners: number;
        submissions: number;
        awaiting_marking: number;
        late_attempts: number;
        released_average: number;
    };
    assignments: { data: Assignment[]; links: { url: string | null; label: string; active: boolean }[] };
    filters: { status: string; due_from: string; due_to: string };
};

const statuses = ['draft', 'under_review', 'approved', 'scheduled', 'published', 'closed', 'archived'];

export default function AssignmentAnalytics({ tenant, metrics, assignments, filters }: Props) {
    const exportQuery = new URLSearchParams(
        Object.fromEntries(Object.entries(filters).filter(([, value]) => value !== '')),
    ).toString();
    return (
        <WorkspaceLayout title="Assignment analytics" area="tenant">
            <Head title="Assignment analytics" />
            <PageHeader
                eyebrow="Coursework"
                title="Assignment analytics"
                description="Institution-scoped counts from assignment, enrollment, submission and released-grade records."
            />
            <section
                className="border-border bg-surface mt-6 rounded-2xl border p-4 sm:p-5"
                aria-label="Report filters"
            >
                <form
                    method="get"
                    action={`/t/${tenant.slug}/assignments/analytics`}
                    className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4"
                >
                    <label className="grid gap-1 text-sm">
                        Status
                        <select
                            name="status"
                            defaultValue={filters.status}
                            className="border-border min-h-10 rounded-lg border px-3"
                        >
                            <option value="">All statuses</option>
                            {statuses.map((status) => (
                                <option key={status} value={status}>
                                    {status.replaceAll('_', ' ')}
                                </option>
                            ))}
                        </select>
                    </label>
                    <label className="grid gap-1 text-sm">
                        Due from
                        <input
                            name="due_from"
                            type="date"
                            defaultValue={filters.due_from}
                            className="border-border min-h-10 rounded-lg border px-3"
                        />
                    </label>
                    <label className="grid gap-1 text-sm">
                        Due to
                        <input
                            name="due_to"
                            type="date"
                            defaultValue={filters.due_to}
                            className="border-border min-h-10 rounded-lg border px-3"
                        />
                    </label>
                    <div className="flex items-end gap-2">
                        <button className="bg-brand-600 focus-ring min-h-10 rounded-lg px-4 text-sm font-semibold text-white">
                            Apply filters
                        </button>
                        <a
                            className="border-border focus-ring inline-flex min-h-10 items-center rounded-lg border px-3 text-sm font-semibold"
                            href={`/t/${tenant.slug}/assignments/analytics.csv${exportQuery ? `?${exportQuery}` : ''}`}
                        >
                            Export CSV
                        </a>
                    </div>
                </form>
            </section>
            <section
                className="mt-5 grid gap-3 sm:grid-cols-2 xl:grid-cols-3"
                aria-label="Assignment metrics"
            >
                {[
                    ['Assignments', metrics.assignments],
                    ['Eligible learners', metrics.eligible_learners],
                    ['Submitted or reviewed', metrics.submissions],
                    ['Awaiting marking', metrics.awaiting_marking],
                    ['Late attempts', metrics.late_attempts],
                    ['Released average', metrics.released_average],
                ].map(([label, value]) => (
                    <article key={label} className="border-border bg-surface rounded-xl border p-4">
                        <p className="text-text-muted text-sm">{label}</p>
                        <p className="mt-1 text-2xl font-semibold">{value}</p>
                    </article>
                ))}
            </section>
            <section
                className="border-border bg-surface mt-5 rounded-2xl border p-4 sm:p-6"
                aria-labelledby="assignment-analytics-table"
            >
                <h2 id="assignment-analytics-table" className="text-lg font-semibold">
                    Assignment detail
                </h2>
                {assignments.data.length === 0 ? (
                    <div className="mt-4">
                        <EmptyState
                            title="No matching assignments"
                            description="Change the status or due-date filters to view other assignments."
                        />
                    </div>
                ) : (
                    <div className="mt-4 overflow-x-auto">
                        <table className="w-full min-w-[680px] text-left text-sm">
                            <thead>
                                <tr className="border-border text-text-muted border-b">
                                    <th className="px-3 py-3 font-medium">Assignment</th>
                                    <th className="px-3 py-3 font-medium">Status</th>
                                    <th className="px-3 py-3 font-medium">Due</th>
                                    <th className="px-3 py-3 font-medium">Eligible</th>
                                    <th className="px-3 py-3 font-medium">Submissions</th>
                                </tr>
                            </thead>
                            <tbody>
                                {assignments.data.map((assignment) => (
                                    <tr key={assignment.id} className="border-border border-b last:border-0">
                                        <td className="px-3 py-4">
                                            <span className="font-semibold">{assignment.title}</span>
                                            <span className="text-text-muted mt-1 block">
                                                {assignment.course}
                                            </span>
                                        </td>
                                        <td className="px-3 py-4 capitalize">
                                            {assignment.status.replaceAll('_', ' ')}
                                        </td>
                                        <td className="px-3 py-4">
                                            {assignment.due_at
                                                ? new Date(assignment.due_at).toLocaleString()
                                                : 'No deadline'}
                                        </td>
                                        <td className="px-3 py-4">{assignment.eligible}</td>
                                        <td className="px-3 py-4">{assignment.submissions}</td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                )}
                {assignments.links.length > 3 && (
                    <nav className="mt-4 flex flex-wrap gap-2" aria-label="Analytics pages">
                        {assignments.links.map((link, index) =>
                            link.url ? (
                                <Link
                                    key={index}
                                    href={link.url}
                                    className={`focus-ring rounded border px-3 py-2 text-sm ${link.active ? 'border-brand-600 bg-brand-50' : 'border-border'}`}
                                    dangerouslySetInnerHTML={{ __html: link.label }}
                                />
                            ) : (
                                <span
                                    key={index}
                                    className="text-text-muted px-3 py-2 text-sm"
                                    dangerouslySetInnerHTML={{ __html: link.label }}
                                />
                            ),
                        )}
                    </nav>
                )}
            </section>
        </WorkspaceLayout>
    );
}
