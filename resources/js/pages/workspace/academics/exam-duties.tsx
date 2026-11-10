import { Head, router } from '@inertiajs/react';
import { PageHeader } from '@/components/ui/page-header';
import { WorkspaceLayout } from '@/layouts/workspace-layout';

type Assignment = {
    id: number;
    status: string;
    acceptedAt: string | null;
    role: string;
    session: string;
    cycle: string;
    startsAt: string;
    room: string | null;
};

export default function ExamDuties({ assignments }: { assignments: Assignment[] }) {
    const root = `${window.location.pathname.replace(/\/$/, '')}`;

    return (
        <WorkspaceLayout title="My examination duties" area="tenant">
            <Head title="My examination duties" />
            <main id="main-content" className="mx-auto w-full max-w-5xl px-4 py-8 sm:px-6">
                <PageHeader
                    eyebrow="Examinations"
                    title="My examination duties"
                    description="Review only the examination duties assigned to your account."
                />
                {assignments.length === 0 ? (
                    <section
                        className="bg-surface border-border mt-6 rounded-xl border p-6"
                        aria-live="polite"
                    >
                        <h2 className="font-semibold">No examination duties assigned</h2>
                        <p className="text-text-secondary mt-2 text-sm">
                            Assigned duties will appear here when an examination controller adds you to a
                            roster.
                        </p>
                    </section>
                ) : (
                    <ul className="mt-6 grid gap-4">
                        {assignments.map((assignment) => (
                            <li key={assignment.id}>
                                <article className="bg-surface border-border rounded-xl border p-5">
                                    <div className="flex flex-wrap items-start justify-between gap-3">
                                        <div>
                                            <h2 className="text-lg font-semibold">{assignment.session}</h2>
                                            <p className="text-text-secondary mt-1 text-sm">
                                                {assignment.cycle} · {assignment.role}
                                            </p>
                                        </div>
                                        <span className="bg-surface-muted rounded-full px-3 py-1 text-sm capitalize">
                                            {assignment.status === 'declined'
                                                ? 'Declined'
                                                : assignment.acceptedAt
                                                  ? 'Accepted'
                                                  : 'Awaiting response'}
                                        </span>
                                    </div>
                                    <dl className="text-text-secondary mt-4 grid gap-2 text-sm sm:grid-cols-2">
                                        <div className="flex items-center gap-2">
                                            <dt className="font-medium">Examination cycle</dt>
                                            <dd>{assignment.cycle}</dd>
                                        </div>
                                        <div className="flex items-center gap-2">
                                            <dt className="font-medium">Start time</dt>
                                            <dd>{new Date(assignment.startsAt).toLocaleString()}</dd>
                                        </div>
                                        {assignment.room ? (
                                            <div className="flex items-center gap-2">
                                                <dt className="font-medium">Assigned room</dt>
                                                <dd>{assignment.room}</dd>
                                            </div>
                                        ) : null}
                                    </dl>
                                    {assignment.status === 'assigned' && assignment.acceptedAt === null ? (
                                        <div className="mt-5 flex flex-wrap gap-3">
                                            <button
                                                type="button"
                                                onClick={() =>
                                                    router.post(
                                                        `${root}/${assignment.id}/response`,
                                                        { decision: 'accept' },
                                                        { preserveScroll: true },
                                                    )
                                                }
                                                className="bg-brand-primary focus-ring inline-flex min-h-11 items-center gap-2 rounded-lg px-4 text-sm font-semibold text-white"
                                            >
                                                Accept duty
                                            </button>
                                            <button
                                                type="button"
                                                onClick={() =>
                                                    router.post(
                                                        `${root}/${assignment.id}/response`,
                                                        { decision: 'decline' },
                                                        { preserveScroll: true },
                                                    )
                                                }
                                                className="border-border focus-ring inline-flex min-h-11 items-center gap-2 rounded-lg border px-4 text-sm font-semibold"
                                            >
                                                Decline duty
                                            </button>
                                        </div>
                                    ) : null}
                                </article>
                            </li>
                        ))}
                    </ul>
                )}
            </main>
        </WorkspaceLayout>
    );
}
