import { Head, Link } from '@inertiajs/react';
import { PageHeader } from '@/components/ui/page-header';
import { WorkspaceLayout } from '@/layouts/workspace-layout';

type ReviewAssignment = {
    id: number;
    assigned_at: string | null;
    version: {
        id: number;
        version_number: number;
        submitted_at: string | null;
        curriculum: {
            id: number;
            code: string;
            title: string;
            program: { code: string; title: string };
        };
    };
};

type Props = {
    tenantSlug: string;
    assignments: {
        data: ReviewAssignment[];
        links: { url: string | null; label: string; active: boolean }[];
    };
};

const cardClass = 'border-border bg-surface rounded-2xl border p-5 shadow-sm';

function formatDate(value: string | null): string {
    return value ? new Date(value).toLocaleString() : 'Time unavailable';
}

export default function CurriculumReviewQueue({ tenantSlug, assignments }: Props) {
    const base = `/t/${tenantSlug}/academics/curriculum`;

    return (
        <WorkspaceLayout title="Review queue" area="tenant">
            <Head title="Assigned curriculum reviews" />
            <div className="grid gap-6">
                <PageHeader
                    title="Assigned curriculum reviews"
                    description="Review only the curriculum versions assigned to your account for this institution."
                />
                <Link
                    href={base}
                    className="text-brand-link min-h-10 w-fit py-2 text-sm font-semibold underline underline-offset-4"
                >
                    Back to curriculum workspace
                </Link>
                <section className={cardClass} aria-labelledby="assigned-reviews-heading">
                    <h2 id="assigned-reviews-heading" className="text-lg font-semibold">
                        Pending assignments
                    </h2>
                    {assignments.data.length === 0 ? (
                        <p className="text-text-secondary mt-4 rounded-xl border border-dashed p-5 text-sm">
                            You have no pending curriculum reviews for this institution.
                        </p>
                    ) : (
                        <ul className="mt-4 grid gap-3">
                            {assignments.data.map((assignment) => {
                                const curriculum = assignment.version.curriculum;
                                const reviewUrl = `${base}/curricula/${curriculum.id}?version=${assignment.version.id}`;

                                return (
                                    <li
                                        key={assignment.id}
                                        className="border-border flex flex-col gap-3 rounded-xl border p-4 sm:flex-row sm:items-center sm:justify-between"
                                    >
                                        <div>
                                            <h3 className="font-semibold">{curriculum.title}</h3>
                                            <p className="text-text-secondary mt-1 text-sm">
                                                {curriculum.code} · {curriculum.program.code}{' '}
                                                {curriculum.program.title} · Version{' '}
                                                {assignment.version.version_number}
                                            </p>
                                            <p className="text-text-muted mt-1 text-sm">
                                                Submitted {formatDate(assignment.version.submitted_at)}
                                            </p>
                                        </div>
                                        <Link
                                            href={reviewUrl}
                                            className="bg-brand-action inline-flex min-h-11 items-center justify-center rounded-lg px-4 text-sm font-semibold text-white focus-visible:outline-2 focus-visible:outline-offset-2"
                                        >
                                            Open assigned review
                                        </Link>
                                    </li>
                                );
                            })}
                        </ul>
                    )}
                    {assignments.links.length > 3 && (
                        <nav className="mt-4 flex flex-wrap gap-2" aria-label="Review queue pages">
                            {assignments.links.map((link) => (
                                <Link
                                    key={`${link.label}-${link.url}`}
                                    href={link.url ?? `${base}/reviews`}
                                    preserveScroll
                                    aria-current={link.active ? 'page' : undefined}
                                    aria-disabled={!link.url}
                                    className={`border-border min-h-10 rounded-lg border px-3 py-2 text-sm ${link.active ? 'bg-brand-action text-white' : 'text-text-muted'} ${link.url ? '' : 'pointer-events-none'}`}
                                >
                                    {link.label.replaceAll('&laquo;', '«').replaceAll('&raquo;', '»')}
                                </Link>
                            ))}
                        </nav>
                    )}
                </section>
            </div>
        </WorkspaceLayout>
    );
}
