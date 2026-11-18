import { Head, router, useForm } from '@inertiajs/react';
import { useState } from 'react';
import { FormErrors } from '@/components/ui/form-errors';
import { PageHeader } from '@/components/ui/page-header';
import { WorkspaceLayout } from '@/layouts/workspace-layout';

type Announcement = {
    id: number;
    title: string;
    body: string;
    author: string | null;
    published_at: string | null;
    audience_roles: string[];
    campus_id: number | null;
    read: boolean;
    can_edit: boolean;
};
type Role = { key: string; name: string };
type Campus = { id: number; name: string };
type Props = {
    tenant: { slug: string };
    announcements: { data: Announcement[]; current_page: number; last_page: number; total: number; links: { url: string | null; label: string; active: boolean }[] };
    audienceRoles: Role[];
    campuses: Campus[];
    canManage: boolean;
};

export default function Announcements({ tenant, announcements, audienceRoles, campuses, canManage }: Props) {
    const [editing, setEditing] = useState<number | null>(null);
    const form = useForm<{ title: string; body: string; audience_roles: string[]; campus_id: string }>({
        title: '', body: '', audience_roles: [], campus_id: '',
    });
    const submit = (event: React.FormEvent) => {
        event.preventDefault();
        if (editing) {
            form.put(`/t/${tenant.slug}/communications/announcements/${editing}`, {
                preserveScroll: true,
                onSuccess: () => { setEditing(null); form.reset(); },
            });
            return;
        }
        form.post(`/t/${tenant.slug}/communications/announcements`, {
            preserveScroll: true,
            onSuccess: () => form.reset(),
        });
    };

    return (
        <WorkspaceLayout title="Announcements" area="tenant">
            <Head title="Announcements" />
            <PageHeader eyebrow="Institution communications" title="Announcements" description="Read role-specific updates for your active institution and publish announcements within your authorized campus scope." />

            {canManage && (
                <section className="border-border bg-surface mt-6 rounded-2xl border p-5 sm:p-6" aria-labelledby="announcement-form-title">
                    <h2 id="announcement-form-title" className="text-lg font-semibold">{editing ? 'Edit announcement' : 'Publish an announcement'}</h2>
                    <form className="mt-4 grid gap-4" onSubmit={submit}>
                        <FormErrors errors={form.errors} />
                        <div>
                            <label className="mb-1 block text-sm font-medium" htmlFor="announcement-title">Title</label>
                            <input id="announcement-title" required maxLength={180} value={form.data.title} onChange={(event) => form.setData('title', event.target.value)} className="border-border bg-surface min-h-11 w-full rounded-lg border px-3" />
                        </div>
                        <div>
                            <label className="mb-1 block text-sm font-medium" htmlFor="announcement-body">Message</label>
                            <textarea id="announcement-body" required maxLength={10000} rows={5} value={form.data.body} onChange={(event) => form.setData('body', event.target.value)} className="border-border bg-surface w-full rounded-lg border px-3 py-2" />
                        </div>
                        <fieldset>
                            <legend className="mb-2 text-sm font-medium">Recipient roles</legend>
                            <div className="grid gap-2 sm:grid-cols-2 lg:grid-cols-3">
                                {audienceRoles.map((role) => (
                                    <label key={role.key} className="border-border flex min-h-11 items-center gap-2 rounded-lg border px-3 text-sm">
                                        <input type="checkbox" checked={form.data.audience_roles.includes(role.key)} onChange={(event) => form.setData('audience_roles', event.target.checked ? [...form.data.audience_roles, role.key] : form.data.audience_roles.filter((key) => key !== role.key))} />
                                        {role.name}
                                    </label>
                                ))}
                            </div>
                        </fieldset>
                        {campuses.length > 1 && (
                            <div>
                                <label className="mb-1 block text-sm font-medium" htmlFor="announcement-campus">Campus</label>
                                <select id="announcement-campus" value={form.data.campus_id} onChange={(event) => form.setData('campus_id', event.target.value)} className="border-border bg-surface min-h-11 w-full rounded-lg border px-3 sm:max-w-md">
                                    <option value="">All campuses</option>
                                    {campuses.map((campus) => <option key={campus.id} value={campus.id}>{campus.name}</option>)}
                                </select>
                            </div>
                        )}
                        <div className="flex flex-wrap gap-3">
                            <button type="submit" disabled={form.processing || form.data.audience_roles.length === 0} className="bg-brand-700 text-white focus-ring min-h-11 rounded-lg px-4 text-sm font-semibold disabled:opacity-60">
                                {form.processing ? 'Saving…' : editing ? 'Save changes' : 'Publish announcement'}
                            </button>
                            {editing !== null && <button type="button" onClick={() => { setEditing(null); form.reset(); form.clearErrors(); }} className="border-border min-h-11 rounded-lg border px-4 text-sm font-medium">Cancel edit</button>}
                        </div>
                    </form>
                </section>
            )}

            <section className="mt-8" aria-labelledby="announcement-list-title">
                <h2 id="announcement-list-title" className="text-lg font-semibold">Published updates <span className="text-text-muted text-sm font-normal">({announcements.total})</span></h2>
                {announcements.data.length === 0 ? (
                    <div className="border-border bg-surface mt-4 rounded-2xl border p-8 text-center">
                        <h3 className="font-semibold">No announcements yet</h3>
                        <p className="text-text-muted mt-2 text-sm">Announcements addressed to your roles will appear here.</p>
                    </div>
                ) : (
                    <ul className="mt-4 grid gap-3">
                        {announcements.data.map((announcement) => (
                            <li key={announcement.id} className="border-border bg-surface rounded-xl border p-4 sm:p-5">
                                <div className="flex flex-wrap items-start justify-between gap-3">
                                    <div>
                                        <div className="flex flex-wrap items-center gap-2">
                                            <h3 className="font-semibold">{announcement.title}</h3>
                                            {!announcement.read && <span className="rounded-full bg-cyan-100 px-2 py-0.5 text-xs font-medium text-cyan-900">New</span>}
                                        </div>
                                        <p className="text-text-muted mt-1 text-xs">{announcement.author ? `From ${announcement.author} · ` : ''}{announcement.published_at ? new Date(announcement.published_at).toLocaleString() : 'Recently'}</p>
                                    </div>
                                    <div className="flex gap-2">
                                        {!announcement.read && <button type="button" onClick={() => router.post(`/t/${tenant.slug}/communications/announcements/${announcement.id}/read`, {}, { preserveScroll: true })} className="text-brand-700 focus-ring min-h-11 rounded-lg px-3 text-sm font-semibold underline">Mark as read</button>}
                                        {canManage && announcement.can_edit && <button type="button" onClick={() => { setEditing(announcement.id); form.setData({ title: announcement.title, body: announcement.body, audience_roles: announcement.audience_roles, campus_id: announcement.campus_id?.toString() ?? '' }); window.scrollTo({ top: 0, behavior: 'smooth' }); }} className="border-border min-h-11 rounded-lg border px-3 text-sm font-medium">Edit</button>}
                                    </div>
                                </div>
                                <p className="mt-4 whitespace-pre-wrap text-sm leading-6">{announcement.body}</p>
                            </li>
                        ))}
                    </ul>
                )}
                {announcements.last_page > 1 && <nav aria-label="Announcement pages" className="mt-5 flex flex-wrap gap-2">{announcements.links.map((link, index) => {
                    const label = link.label.includes('Previous') ? 'Previous' : link.label.includes('Next') ? 'Next' : link.label.replace(/<[^>]*>/g, '');
                    return <button key={`${index}-${link.label}`} type="button" disabled={!link.url} aria-current={link.active ? 'page' : undefined} onClick={() => link.url && router.get(link.url, {}, { preserveScroll: true })} className="border-border min-h-10 rounded-lg border px-3 text-sm disabled:opacity-50">{label}</button>;
                })}</nav>}
            </section>
        </WorkspaceLayout>
    );
}
