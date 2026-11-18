import { Head, Link, router } from '@inertiajs/react';
import { PageHeader } from '@/components/ui/page-header';
import { WorkspaceLayout } from '@/layouts/workspace-layout';

type Notice = { id: number; type: 'message' | 'announcement'; reference_id: number; context_id: number | null; title: string; summary: string; read: boolean; created_at: string | null };
type Props = {
    tenant: { slug: string };
    notifications: { data: Notice[]; current_page: number; last_page: number; total: number; links: { url: string | null; label: string; active: boolean }[] };
    unreadCount: number;
};

export default function Notifications({ tenant, notifications, unreadCount }: Props) {
    const base = `/t/${tenant.slug}/communications/notifications`;
    return (
        <WorkspaceLayout title="Communication inbox" area="tenant">
            <Head title="Communication inbox" />
            <PageHeader eyebrow="Institution communications" title="Inbox" description={`${unreadCount} unread communication${unreadCount === 1 ? '' : 's'}.`} />
            {notifications.data.length === 0 ? (
                <section className="border-border bg-surface mt-6 rounded-2xl border p-8 text-center">
                    <h2 className="font-semibold">Your inbox is clear</h2>
                    <p className="text-text-muted mt-2 text-sm">New announcements and direct message alerts will appear here.</p>
                </section>
            ) : <ul className="mt-6 grid gap-3" aria-label="Communication notifications">
                {notifications.data.map((item) => {
                    const href = item.type === 'message'
                        ? `/t/${tenant.slug}/communications/messages?conversation=${item.context_id}`
                        : `/t/${tenant.slug}/communications/announcements`;
                    return <li key={item.id} className="border-border bg-surface rounded-xl border p-4 sm:p-5">
                        <div className="flex flex-wrap items-start justify-between gap-3">
                            <div>
                                <h2 className="font-semibold">{item.title}{!item.read && <span className="ml-2 rounded-full bg-brand-100 px-2 py-0.5 text-xs text-brand-900">New</span>}</h2>
                                <p className="text-text-muted mt-1 text-sm">{item.summary}</p>
                                {item.created_at && <time className="text-text-muted mt-2 block text-xs">{new Date(item.created_at).toLocaleString()}</time>}
                            </div>
                            <div className="flex gap-3">
                                <Link href={href} className="text-brand-700 focus-ring min-h-11 rounded-lg px-2 py-2 text-sm font-semibold underline">Open</Link>
                                {!item.read && <button type="button" onClick={() => router.post(`${base}/${item.id}/read`, {}, { preserveScroll: true })} className="border-border min-h-11 rounded-lg border px-3 text-sm font-medium">Mark read</button>}
                            </div>
                        </div>
                    </li>;
                })}
            </ul>}
            {notifications.last_page > 1 && <nav className="mt-5 flex flex-wrap gap-2" aria-label="Inbox pages">{notifications.links.map((link, index) => link.url ? <Link key={index} href={link.url} className="border-border rounded border px-2 py-1 text-sm">{link.label}</Link> : null)}</nav>}
        </WorkspaceLayout>
    );
}
