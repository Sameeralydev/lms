import { Head, Link } from '@inertiajs/react';
import { EmptyState } from '@/components/ui/empty-state';
import { PageHeader } from '@/components/ui/page-header';
import { WorkspaceLayout } from '@/layouts/workspace-layout';

type Assignment = {
    recipient_id: number;
    title: string;
    course: string;
    type: string;
    status: string;
    due_at: string | null;
    late: boolean;
    receipt: string | null;
    grade_released: boolean;
    score_points: number | null;
    feedback: string | null;
};
type Props = {
    tenant: { slug: string };
    assignments: Assignment[];
    metrics: { total: number; submitted: number; upcoming: number; graded: number };
    pagination?: { page: number; last_page: number };
};

export default function StudentAssignments({ tenant, assignments, metrics }: Props) {
    return (
        <WorkspaceLayout title="My assignments" area="tenant">
            <Head title="My assignments" />
            <PageHeader
                eyebrow="Coursework"
                title="My assignments"
                description="Review work assigned to your confirmed course sections, due dates, submission status and released feedback."
            />
            <section className="mt-6 grid gap-3 sm:grid-cols-4" aria-label="Personal coursework summary">
                {[
                    ['Assigned', metrics.total],
                    ['Submitted', metrics.submitted],
                    ['Upcoming', metrics.upcoming],
                    ['Feedback released', metrics.graded],
                ].map(([label, value]) => (
                    <div key={label} className="border-border bg-surface rounded-xl border p-4">
                        <p className="text-text-muted text-sm">{label}</p>
                        <p className="mt-1 text-2xl font-semibold">{value}</p>
                    </div>
                ))}
            </section>
            <section className="mt-6 grid gap-4" aria-label="Assignment list">
                {assignments.length === 0 ? (
                    <EmptyState
                        title="You have no assignments here"
                        description="Assignments for your active, confirmed course enrollments will appear here when they are published."
                    />
                ) : (
                    assignments.map((assignment) => (
                        <article
                            key={assignment.recipient_id}
                            className="border-border bg-surface rounded-2xl border p-5"
                        >
                            <div className="flex flex-wrap items-start justify-between gap-4">
                                <div>
                                    <p className="text-text-muted text-xs font-semibold uppercase">
                                        {assignment.course} · {assignment.type}
                                    </p>
                                    <h2 className="mt-1 text-lg font-semibold">{assignment.title}</h2>
                                    <p className="text-text-muted mt-2 text-sm">
                                        Due{' '}
                                        {assignment.due_at
                                            ? new Date(assignment.due_at).toLocaleString()
                                            : 'date not set'}
                                        {assignment.late ? ' · late window' : ''}
                                    </p>
                                    <p className="mt-2 text-sm capitalize">
                                        Status: {assignment.status.replaceAll('_', ' ')}
                                        {assignment.grade_released
                                            ? ` · ${assignment.score_points ?? '—'} points released`
                                            : ''}
                                    </p>
                                    {assignment.feedback && (
                                        <p className="bg-surface-soft mt-3 max-w-3xl rounded-lg p-3 text-sm">
                                            <span className="font-semibold">Instructor feedback: </span>
                                            {assignment.feedback}
                                        </p>
                                    )}
                                </div>
                                <Link
                                    href={`/t/${tenant.slug}/my-assignments/recipients/${assignment.recipient_id}`}
                                    className="border-border focus-ring min-h-11 rounded-lg border px-4 py-2 text-sm font-semibold"
                                >
                                    Open assignment
                                </Link>
                            </div>
                        </article>
                    ))
                )}
            </section>
        </WorkspaceLayout>
    );
}
