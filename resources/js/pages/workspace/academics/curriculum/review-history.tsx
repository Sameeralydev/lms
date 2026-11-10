import { Head, Link } from '@inertiajs/react';
import { PageHeader } from '@/components/ui/page-header';
import { WorkspaceLayout } from '@/layouts/workspace-layout';

type ReviewHistoryItem = {
    id: number;
    decision: string;
    review_note: string | null;
    assigned_at: string | null;
    decided_at: string | null;
    reviewer: { name: string } | null;
    decided_by: { name: string } | null;
    version: { version_number: number; status: string };
};

type Props = {
    tenantSlug: string;
    curriculum: { id: number; code: string; title: string };
    history: {
        data: ReviewHistoryItem[];
        links: { url: string | null; label: string; active: boolean }[];
    };
};

const cardClass = 'border-border bg-surface rounded-2xl border p-5 shadow-sm';

function formatDate(value: string | null): string {
    return value ? new Date(value).toLocaleString() : 'Time unavailable';
}

export default function CurriculumReviewHistory({ tenantSlug, curriculum, history }: Props) {
    const base = `/t/${tenantSlug}/academics/curriculum`;

    return (
        <WorkspaceLayout title="Review history" area="tenant">
            <Head title={`Review history · ${curriculum.title}`} />
            <div className="grid gap-6">
                <PageHeader
                    title={`${curriculum.title} review history`}
                    description={`${curriculum.code} · Read-only record of review assignments and decisions.`}
                />
                <Link
                    href={`${base}/curricula/${curriculum.id}`}
                    className="text-brand-link min-h-10 w-fit py-2 text-sm font-semibold underline underline-offset-4"
                >
                    Back to curriculum
                </Link>
                <section className={cardClass} aria-labelledby="review-history-heading">
                    <h2 id="review-history-heading" className="text-lg font-semibold">
                        Recorded review activity
                    </h2>
                    <p className="text-text-secondary mt-1 text-sm">
                        This history is limited to the selected curriculum and institution. Entries are
                        read-only and do not expose private request data.
                    </p>
                    {history.data.length === 0 ? (
                        <p className="text-text-secondary mt-4 rounded-xl border border-dashed p-5 text-sm">
                            No curriculum review activity has been recorded yet.
                        </p>
                    ) : (
                        <ol className="mt-4 grid gap-3">
                            {history.data.map((item) => (
                                <li key={item.id} className="border-border rounded-xl border p-4">
                                    <div className="flex flex-wrap items-start justify-between gap-3">
                                        <div>
                                            <h3 className="font-semibold capitalize">
                                                {item.decision.replaceAll('_', ' ')} · Version{' '}
                                                {item.version.version_number}
                                            </h3>
                                            <p className="text-text-secondary mt-1 text-sm">
                                                Assigned reviewer:{' '}
                                                {item.reviewer?.name ?? 'Account unavailable'}
                                            </p>
                                            {item.decided_by && (
                                                <p className="text-text-secondary mt-1 text-sm">
                                                    Recorded by: {item.decided_by.name}
                                                </p>
                                            )}
                                        </div>
                                        <span className="border-border rounded-full border px-3 py-1 text-xs font-semibold capitalize">
                                            Version {item.version.status.replaceAll('_', ' ')}
                                        </span>
                                    </div>
                                    {item.review_note && (
                                        <p className="bg-muted/40 mt-3 rounded-lg p-3 text-sm">
                                            {item.review_note}
                                        </p>
                                    )}
                                    <div className="text-text-muted mt-3 flex flex-wrap gap-x-5 gap-y-1 text-xs">
                                        <p>
                                            Assigned{' '}
                                            <time dateTime={item.assigned_at ?? undefined}>
                                                {formatDate(item.assigned_at)}
                                            </time>
                                        </p>
                                        {item.decided_at && (
                                            <p>
                                                Decided{' '}
                                                <time dateTime={item.decided_at}>
                                                    {formatDate(item.decided_at)}
                                                </time>
                                            </p>
                                        )}
                                    </div>
                                </li>
                            ))}
                        </ol>
                    )}
                    {history.links.length > 3 && (
                        <nav className="mt-4 flex flex-wrap gap-2" aria-label="Review history pages">
                            {history.links.map((link) => (
                                <Link
                                    key={`${link.label}-${link.url}`}
                                    href={link.url ?? `${base}/curricula/${curriculum.id}/reviews`}
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
