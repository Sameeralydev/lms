import { Link } from '@inertiajs/react';
import { PageHeader } from '@/components/ui/page-header';
import { WorkspaceLayout } from '@/layouts/workspace-layout';

type Event = {
    id: number;
    event_type: string;
    occurred_at: string;
    subject_type: string | null;
    subject_id: string | null;
    request_id: string | null;
    metadata: Record<string, unknown> | null;
    actor?: { name: string; email: string } | null;
};
export default function WorkspaceAudit({
    events,
}: {
    events: { data: Event[]; links: { url: string | null; label: string; active: boolean }[] };
}) {
    return (
        <WorkspaceLayout area="tenant" title="Audit history">
            <PageHeader
                title="Institution audit history"
                description="A restricted, read-only record of administrative changes for this institution. Sensitive request fields and credentials are not captured."
            />
            <div className="border-border bg-surface overflow-hidden rounded-2xl border">
                {events.data.length ? (
                    <div className="divide-border divide-y">
                        {events.data.map((event) => (
                            <article
                                key={event.id}
                                className="grid gap-2 px-5 py-4 sm:grid-cols-[minmax(0,1fr)_auto]"
                            >
                                <div>
                                    <h2 className="text-sm font-semibold">
                                        {event.event_type.replaceAll(/[._]/g, ' ')}
                                    </h2>
                                    <p className="text-text-secondary mt-1 text-xs">
                                        {event.actor?.name ?? 'System'} ·{' '}
                                        {event.subject_type?.split('\\').pop() ?? '—'}{' '}
                                        {event.subject_id ?? ''}
                                    </p>
                                    <p className="text-text-muted mt-2 text-xs">
                                        {Object.entries(event.metadata ?? {})
                                            .map(([key, value]) => `${key}: ${String(value)}`)
                                            .join(' · ')}
                                    </p>
                                    {event.request_id ? (
                                        <p className="text-text-muted mt-1 text-[11px]">
                                            Request {event.request_id}
                                        </p>
                                    ) : null}
                                </div>
                                <time className="text-text-muted text-xs" dateTime={event.occurred_at}>
                                    {new Date(event.occurred_at).toLocaleString()}
                                </time>
                            </article>
                        ))}
                    </div>
                ) : (
                    <p className="text-text-secondary p-8 text-center text-sm">
                        No institution audit events yet.
                    </p>
                )}
            </div>
            <nav aria-label="Audit pages" className="mt-4 flex flex-wrap gap-2">
                {events.links.map((link, index) =>
                    link.url ? (
                        <Link
                            key={index}
                            href={link.url}
                            className={`rounded-md px-3 py-2 text-xs ${link.active ? 'bg-brand-action text-white' : 'border-border border'}`}
                        >
                            {link.label.replace('&laquo;', '‹').replace('&raquo;', '›')}
                        </Link>
                    ) : null,
                )}
            </nav>
        </WorkspaceLayout>
    );
}
