import { Head, Link } from '@inertiajs/react';
import { PageHeader } from '@/components/ui/page-header';
import { WorkspaceLayout } from '@/layouts/workspace-layout';

type HistoryItem = {
    id: number;
    event_type: string;
    actor: { name: string } | null;
    occurred_at: string | null;
};
type Props = {
    framework: { id: number; code: string; title: string; status: string };
    history: {
        data: HistoryItem[];
        links: { url: string | null; label: string; active: boolean }[];
    };
    backUrl: string;
};

const cardClass = 'border-border bg-surface rounded-2xl border p-5 shadow-sm';

export default function CurriculumFrameworkHistory({ framework, history, backUrl }: Props) {
    return (
        <WorkspaceLayout title="Framework history" area="tenant">
            <Head title={`History · ${framework.title}`} />
            <div className="grid gap-6">
                <PageHeader
                    title={`${framework.title} history`}
                    description={`${framework.code} · ${framework.status}`}
                />
                <Link
                    href={backUrl}
                    className="text-brand-link min-h-10 w-fit py-2 text-sm font-semibold underline underline-offset-4"
                >
                    Back to framework library
                </Link>
                <section className={cardClass} aria-labelledby="framework-history-heading">
                    <h2 id="framework-history-heading" className="text-lg font-semibold">
                        Recorded framework activity
                    </h2>
                    <p className="text-text-secondary mt-1 text-sm">
                        This read-only event history is scoped to this institution and framework. Audit
                        metadata and private payloads are not exposed here.
                    </p>
                    {history.data.length === 0 ? (
                        <p className="text-text-secondary mt-4 rounded-xl border border-dashed p-5 text-sm">
                            No framework activity has been recorded yet.
                        </p>
                    ) : (
                        <ol className="mt-4 grid gap-3">
                            {history.data.map((item) => (
                                <li
                                    key={item.id}
                                    className="border-border flex flex-wrap items-start justify-between gap-3 rounded-xl border p-4"
                                >
                                    <div>
                                        <h3 className="font-semibold">
                                            {item.event_type.replace(/[._]+/g, ' ')}
                                        </h3>
                                        <p className="text-text-secondary mt-1 text-sm">
                                            Actor: {item.actor?.name ?? 'System'}
                                        </p>
                                    </div>
                                    <time
                                        className="text-text-muted text-sm"
                                        dateTime={item.occurred_at ?? undefined}
                                    >
                                        {item.occurred_at
                                            ? new Date(item.occurred_at).toLocaleString()
                                            : 'Time unavailable'}
                                    </time>
                                </li>
                            ))}
                        </ol>
                    )}
                    {history.links.length > 3 && (
                        <nav className="mt-4 flex flex-wrap gap-2" aria-label="Framework history pages">
                            {history.links.map((link) => (
                                <Link
                                    key={`${link.label}-${link.url}`}
                                    href={link.url ?? backUrl}
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
