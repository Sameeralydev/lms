import { PageHeader } from '@/components/ui/page-header';
import { WorkspaceLayout } from '@/layouts/workspace-layout';
import { useState } from 'react';

type Props = {
    tenant: { slug: string };
    metrics: {
        published_timetables: number;
        published_meetings: number;
        active_rooms: number;
        rooms_in_use: number;
        room_use_percent: number | null;
        unscheduled_open_sections: number;
        pending_change_approvals: number;
        approved_changes: Record<string, number>;
    };
    weeklyInstructorWorkload: { instructor: string; weekly_minutes: number }[];
    qualityFindings: {
        key: string;
        severity: string;
        category: string;
        title: string;
        description: string;
        href: string;
        action: string;
    }[];
};

export default function TimetableAnalytics({
    tenant,
    metrics,
    weeklyInstructorWorkload,
    qualityFindings,
}: Props) {
    const [findingFilter, setFindingFilter] = useState('all');
    const visibleFindings =
        findingFilter === 'all'
            ? qualityFindings
            : qualityFindings.filter((finding) => finding.category === findingFilter);
    const cards = [
        ['Published timetable versions', metrics.published_timetables],
        ['Published recurring meetings', metrics.published_meetings],
        ['Open sections without a published meeting', metrics.unscheduled_open_sections],
        ['Pending occurrence changes', metrics.pending_change_approvals],
    ] as const;
    return (
        <WorkspaceLayout area="tenant" title="Scheduling analytics">
            <main className="mx-auto max-w-6xl space-y-6 pb-12">
                <PageHeader
                    title="Scheduling analytics"
                    description="Current published schedule coverage and weekly workload for your institution scope."
                />
                <a
                    className="border-border focus-ring inline-flex min-h-10 items-center rounded-lg border px-4 text-sm"
                    href={`/t/${tenant.slug}/academics/timetables`}
                >
                    Back to timetable studio
                </a>
                <section className="grid gap-3 sm:grid-cols-2 xl:grid-cols-4" aria-label="Scheduling metrics">
                    {cards.map(([label, value]) => (
                        <article key={label} className="border-border bg-surface rounded-2xl border p-5">
                            <p className="text-text-secondary text-sm">{label}</p>
                            <p className="mt-2 text-3xl font-semibold tabular-nums">{value}</p>
                        </article>
                    ))}
                </section>
                <section className="border-border bg-surface rounded-2xl border p-5">
                    <h2 className="font-semibold">Room assignment coverage</h2>
                    <p className="text-text-secondary mt-1 text-sm">
                        Active rooms assigned to at least one published recurring meeting:{' '}
                        {metrics.rooms_in_use} of {metrics.active_rooms}
                        {metrics.room_use_percent === null
                            ? ' (no active rooms configured)'
                            : ` (${metrics.room_use_percent}%)`}
                        .
                    </p>
                </section>
                <section className="border-border bg-surface rounded-2xl border p-5">
                    <h2 className="font-semibold">Approved occurrence changes</h2>
                    {Object.keys(metrics.approved_changes).length ? (
                        <ul className="mt-3 grid gap-2 sm:grid-cols-2">
                            {Object.entries(metrics.approved_changes).map(([kind, count]) => (
                                <li
                                    key={kind}
                                    className="bg-surface-muted rounded-lg px-3 py-2 text-sm capitalize"
                                >
                                    {kind.replaceAll('_', ' ')}: <strong>{count}</strong>
                                </li>
                            ))}
                        </ul>
                    ) : (
                        <p className="text-text-secondary mt-2 text-sm">
                            No approved schedule changes recorded.
                        </p>
                    )}
                </section>
                <section
                    className="border-border bg-surface rounded-2xl border p-5"
                    aria-labelledby="schedule-quality-heading"
                >
                    <div className="flex flex-wrap items-end justify-between gap-3">
                        <div>
                            <h2 id="schedule-quality-heading" className="font-semibold">
                                Actionable schedule findings
                            </h2>
                            <p className="text-text-secondary mt-1 text-sm">
                                Conflicts, incomplete schedule coverage, and requests that need review.
                            </p>
                        </div>
                        <label
                            className="text-text-secondary text-sm font-medium"
                            htmlFor="schedule-quality-filter"
                        >
                            Filter findings
                            <select
                                id="schedule-quality-filter"
                                className="border-border bg-surface text-text-primary focus-ring ml-2 min-h-10 rounded-lg border px-3"
                                value={findingFilter}
                                onChange={(event) => setFindingFilter(event.currentTarget.value)}
                            >
                                <option value="all">All findings</option>
                                <option value="conflict">Conflicts</option>
                                <option value="coverage">Schedule coverage</option>
                                <option value="review">Pending review</option>
                            </select>
                        </label>
                    </div>
                    {visibleFindings.length ? (
                        <ul className="mt-4 space-y-2">
                            {visibleFindings.map((finding) => (
                                <li
                                    key={finding.key}
                                    className="border-border flex flex-wrap items-center justify-between gap-3 rounded-xl border p-3"
                                >
                                    <div>
                                        <p className="font-medium">{finding.title}</p>
                                        <p className="text-text-secondary mt-1 text-sm">
                                            {finding.description}
                                        </p>
                                    </div>
                                    <a
                                        className="text-brand-link focus-ring rounded px-2 py-1 text-sm font-medium underline"
                                        href={finding.href}
                                    >
                                        {finding.action}
                                    </a>
                                </li>
                            ))}
                        </ul>
                    ) : (
                        <p className="text-text-secondary bg-surface-muted mt-4 rounded-lg p-3 text-sm">
                            {qualityFindings.length
                                ? 'No findings match this filter.'
                                : 'No scheduling conflicts, uncovered open sections, or pending approvals were found.'}
                        </p>
                    )}
                </section>
                <section className="border-border bg-surface rounded-2xl border p-5">
                    <h2 className="font-semibold">Instructor weekly workload</h2>
                    {weeklyInstructorWorkload.length ? (
                        <div className="mt-3 overflow-x-auto">
                            <table className="w-full text-left text-sm">
                                <thead>
                                    <tr>
                                        <th scope="col" className="py-2">
                                            Instructor
                                        </th>
                                        <th scope="col" className="py-2">
                                            Minutes per week
                                        </th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {weeklyInstructorWorkload.map((item) => (
                                        <tr key={item.instructor} className="border-border border-t">
                                            <th scope="row" className="py-3 font-medium">
                                                {item.instructor}
                                            </th>
                                            <td className="py-3 tabular-nums">{item.weekly_minutes}</td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    ) : (
                        <p className="text-text-secondary mt-2 text-sm">
                            No published instructor workload is available.
                        </p>
                    )}
                </section>
            </main>
        </WorkspaceLayout>
    );
}
