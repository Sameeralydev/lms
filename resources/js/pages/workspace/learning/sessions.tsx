import { Head, Link, router } from '@inertiajs/react';
import { PageHeader } from '@/components/ui/page-header';
import { WorkspaceLayout } from '@/layouts/workspace-layout';

type Session = {
    id: number;
    title: string;
    status: string;
    delivery_type: string;
    timezone: string;
    starts_at: string;
    ends_at: string;
    meeting_url: string | null;
    venue: string | null;
    workspace: { id: number; code: string; title: string };
    lesson: string | null;
    instructor: string;
};

type Props = {
    tenant: { slug: string };
    sessions: {
        data: Session[];
        links: { url: string | null; label: string; active: boolean }[];
    };
    filters: { from: string; to: string; status: string };
};

export default function SessionsCalendar({ tenant, sessions, filters }: Props) {
    return (
        <WorkspaceLayout title="Live session calendar" area="tenant">
            <Head title="Live session calendar" />
            <div className="space-y-6">
                <PageHeader
                    eyebrow="Learning delivery"
                    title="Live session calendar"
                    description="View institution sessions you are authorized to access, in each session’s own time zone."
                    actions={
                        <Link
                            href={`/t/${tenant.slug}/learning`}
                            className="border-border focus-ring min-h-11 rounded-lg border px-4 py-2 text-sm font-semibold"
                        >
                            Course workspaces
                        </Link>
                    }
                />
                <form
                    className="border-border bg-surface grid gap-3 rounded-xl border p-4 sm:grid-cols-4"
                    onSubmit={(event) => {
                        event.preventDefault();
                        const form = new FormData(event.currentTarget);
                        router.get(
                            `/t/${tenant.slug}/learning/sessions`,
                            Object.fromEntries(form.entries()),
                            {
                                preserveState: true,
                                replace: true,
                            },
                        );
                    }}
                >
                    <label className="grid gap-1 text-sm font-medium">
                        From
                        <input
                            className="border-border bg-surface min-h-11 rounded-lg border px-3"
                            type="date"
                            name="from"
                            defaultValue={filters.from}
                        />
                    </label>
                    <label className="grid gap-1 text-sm font-medium">
                        To
                        <input
                            className="border-border bg-surface min-h-11 rounded-lg border px-3"
                            type="date"
                            name="to"
                            defaultValue={filters.to}
                        />
                    </label>
                    <label className="grid gap-1 text-sm font-medium">
                        Status
                        <select
                            className="border-border bg-surface min-h-11 rounded-lg border px-3"
                            name="status"
                            defaultValue={filters.status}
                        >
                            <option value="scheduled">Scheduled</option>
                            <option value="cancelled">Cancelled</option>
                            <option value="all">All statuses</option>
                        </select>
                    </label>
                    <button className="bg-brand-action focus-ring min-h-11 self-end rounded-lg px-4 text-sm font-semibold text-white">
                        Apply filters
                    </button>
                </form>
                {sessions.data.length === 0 ? (
                    <p className="border-border bg-surface rounded-xl border p-6 text-sm">
                        No sessions match these filters.
                    </p>
                ) : (
                    <ol className="grid gap-3">
                        {sessions.data.map((session) => (
                            <li className="border-border bg-surface rounded-xl border p-4" key={session.id}>
                                <div className="flex flex-wrap items-start justify-between gap-3">
                                    <div>
                                        <h2 className="font-semibold">{session.title}</h2>
                                        <p className="text-text-muted mt-1 text-sm">
                                            {new Date(session.starts_at).toLocaleString(undefined, {
                                                timeZone: session.timezone,
                                                timeZoneName: 'short',
                                            })}{' '}
                                            –{' '}
                                            {new Date(session.ends_at).toLocaleTimeString(undefined, {
                                                timeZone: session.timezone,
                                                timeZoneName: 'short',
                                            })}{' '}
                                            · {session.timezone}
                                        </p>
                                        <p className="text-text-muted mt-1 text-sm">
                                            {session.workspace.code} · {session.workspace.title}
                                            {session.lesson ? ` · ${session.lesson}` : ''} ·{' '}
                                            {session.instructor}
                                        </p>
                                        {session.venue && (
                                            <p className="mt-1 text-sm">Venue: {session.venue}</p>
                                        )}
                                    </div>
                                    <div className="flex flex-wrap items-center gap-2">
                                        <span className="bg-surface-soft rounded-full px-3 py-1 text-xs font-semibold capitalize">
                                            {session.status}
                                        </span>
                                        {session.status === 'scheduled' && session.meeting_url && (
                                            <a
                                                className="text-brand-link focus-ring rounded underline"
                                                href={session.meeting_url}
                                                target="_blank"
                                                rel="noreferrer"
                                            >
                                                Join session
                                            </a>
                                        )}
                                        <Link
                                            className="border-border focus-ring min-h-10 rounded-lg border px-3 py-2 text-sm font-semibold"
                                            href={`/t/${tenant.slug}/learning/${session.workspace.id}`}
                                        >
                                            Open workspace
                                        </Link>
                                    </div>
                                </div>
                            </li>
                        ))}
                    </ol>
                )}
                {sessions.links.length > 3 && (
                    <nav aria-label="Session calendar pages" className="flex flex-wrap gap-2">
                        {sessions.links.map((link) => (
                            <Link
                                key={link.label}
                                href={link.url ?? '#'}
                                aria-current={link.active ? 'page' : undefined}
                                aria-disabled={!link.url}
                                className={`border-border focus-ring min-h-10 rounded-lg border px-3 py-2 text-sm ${link.active ? 'bg-surface-soft font-semibold' : ''} ${!link.url ? 'pointer-events-none opacity-50' : ''}`}
                            >
                                {link.label.replaceAll('&laquo;', '«').replaceAll('&raquo;', '»')}
                            </Link>
                        ))}
                    </nav>
                )}
            </div>
        </WorkspaceLayout>
    );
}
