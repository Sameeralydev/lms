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
    tenant?: { name: string } | null;
    actor?: { name: string; email: string } | null;
};
export default function PlatformAudit({ events }: { events: { data: Event[] } }) {
    return (
        <WorkspaceLayout area="platform" title="Platform audit">
            <PageHeader
                title="Platform audit history"
                description="Administrative events recorded by transactional application workflows. Secret values and request bodies are excluded."
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
                                        {event.event_type.replaceAll('.', ' ')}
                                    </h2>
                                    <p className="text-text-secondary mt-1 text-xs">
                                        {event.actor?.name ?? 'System'} · {event.tenant?.name ?? 'Platform'} ·{' '}
                                        {event.subject_type?.split('\\').pop() ?? '—'}{' '}
                                        {event.subject_id ?? ''}
                                    </p>
                                    <p className="text-text-muted mt-2 text-xs">
                                        {Object.entries(event.metadata ?? {})
                                            .map(([key, value]) => `${key}: ${String(value)}`)
                                            .join(' · ')}
                                    </p>
                                </div>
                                <time className="text-text-muted text-xs" dateTime={event.occurred_at}>
                                    {new Date(event.occurred_at).toLocaleString()}
                                </time>
                            </article>
                        ))}
                    </div>
                ) : (
                    <p className="text-text-secondary p-8 text-center text-sm">
                        No audit events have been recorded.
                    </p>
                )}
            </div>
        </WorkspaceLayout>
    );
}
