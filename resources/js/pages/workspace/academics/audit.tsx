import { Link } from '@inertiajs/react';
import { PageHeader } from '@/components/ui/page-header';
import { WorkspaceLayout } from '@/layouts/workspace-layout';

type AuditEvent = {
    id: number;
    event_type: string;
    subject_type: string | null;
    subject_id: number | null;
    occurred_at: string;
    actor: { id: number; name: string } | null;
};

type Props = {
    tenant: { slug: string };
    events: {
        data: AuditEvent[];
        links: { url: string | null; label: string; active: boolean }[];
    };
};

export default function AcademicAuditPage({ tenant, events }: Props) {
    const root = `/t/${tenant.slug}/academics`;

    return (
        <WorkspaceLayout area="tenant" title="Academic history">
            <PageHeader
                title="Academic history"
                description="Immutable records of academic configuration, policy, quality, and document decisions."
            />
            <nav aria-label="Academic pages" className="mt-5 flex flex-wrap gap-3 text-sm">
                <Link href={`${root}/calendar`} className="text-brand-link font-semibold">
                    Calendar
                </Link>
                <Link href={`${root}/structure`} className="text-brand-link font-semibold">
                    Organization & programs
                </Link>
                <Link href={`${root}/catalog`} className="text-brand-link font-semibold">
                    Subjects & courses
                </Link>
            </nav>
            <section
                aria-label="Academic audit events"
                className="border-border bg-surface mt-6 rounded-2xl border p-5 sm:p-7"
            >
                {events.data.length > 0 ? (
                    <ol className="divide-border divide-y">
                        {events.data.map((event) => (
                            <li
                                key={event.id}
                                className="grid gap-2 py-4 first:pt-0 sm:grid-cols-[minmax(0,1fr)_auto] sm:items-start"
                            >
                                <div>
                                    <h2 className="font-semibold">{event.event_type.replaceAll('.', ' ')}</h2>
                                    <p className="text-text-secondary mt-1 text-sm">
                                        {event.subject_type?.split('\\').pop() ?? 'Academic record'}
                                        {event.subject_id ? ` #${event.subject_id}` : ''}
                                        {' · '}
                                        {event.actor?.name ?? 'System'}
                                    </p>
                                </div>
                                <time className="text-text-muted text-sm" dateTime={event.occurred_at}>
                                    {new Date(event.occurred_at).toLocaleString()}
                                </time>
                            </li>
                        ))}
                    </ol>
                ) : (
                    <p className="text-text-secondary py-6 text-sm">
                        No academic audit events are available.
                    </p>
                )}
                {events.links.length > 0 ? (
                    <nav aria-label="Academic history pages" className="mt-5 flex flex-wrap gap-2">
                        {events.links.map((link) => (
                            <Link
                                key={link.label}
                                href={link.url ?? '#'}
                                aria-current={link.active ? 'page' : undefined}
                                className={`border-border focus-ring rounded-lg border px-3 py-2 text-sm ${link.active ? 'bg-brand-action text-white' : ''}`}
                            >
                                {link.label.replace(/&laquo;/g, '‹').replace(/&raquo;/g, '›')}
                            </Link>
                        ))}
                    </nav>
                ) : null}
            </section>
        </WorkspaceLayout>
    );
}
