import { Head, Link, useForm } from '@inertiajs/react';
import { FormErrors } from '@/components/ui/form-errors';
import { PageHeader } from '@/components/ui/page-header';
import { WorkspaceLayout } from '@/layouts/workspace-layout';

type Thread = { id: number; peer: string; preview: string | null; updated_at: string | null; unread: number };
type Recipient = { id: number; name: string; roles: string[] };
type Message = { id: number; body: string; sender: string; mine: boolean; sent_at: string | null };
type Props = {
    tenant: { slug: string };
    threads: { data: Thread[]; current_page: number; last_page: number; links: { url: string | null; label: string; active: boolean }[] };
    recipients: Recipient[];
    selected: { id: number; peer: string; messages: Message[] } | null;
    canSend: boolean;
};

export default function Messages({ tenant, threads, recipients, selected, canSend }: Props) {
    const base = `/t/${tenant.slug}/communications/messages`;
    const start = useForm({ recipient_user_id: '', body: '' });
    const reply = useForm({ body: '' });

    return (
        <WorkspaceLayout title="Messages" area="tenant">
            <Head title="Messages" />
            <PageHeader eyebrow="Institution communications" title="Messages" description="Private conversations with active members in your institution scope." />

            <div className="mt-6 grid gap-5 lg:grid-cols-[minmax(16rem,0.8fr)_minmax(0,1.4fr)]">
                <section className="border-border bg-surface rounded-2xl border p-4 sm:p-5" aria-labelledby="threads-heading">
                    <h2 id="threads-heading" className="text-lg font-semibold">Conversations</h2>
                    {threads.data.length === 0 ? <p className="text-text-muted mt-4 text-sm">No conversations yet.</p> : (
                        <ul className="mt-3 divide-border divide-y">
                            {threads.data.map((thread) => (
                                <li key={thread.id}>
                                    <Link href={`${base}?conversation=${thread.id}`} className="focus-ring block rounded-lg px-2 py-3 hover:bg-muted">
                                        <span className="flex items-center justify-between gap-2 font-medium">
                                            <span>{thread.peer}</span>
                                            {thread.unread > 0 && <span className="rounded-full bg-brand-100 px-2 py-0.5 text-xs text-brand-900">{thread.unread} new</span>}
                                        </span>
                                        <span className="text-text-muted mt-1 block truncate text-sm">{thread.preview ?? 'No messages'}</span>
                                        {thread.updated_at && <time className="text-text-muted mt-1 block text-xs">{new Date(thread.updated_at).toLocaleString()}</time>}
                                    </Link>
                                </li>
                            ))}
                        </ul>
                    )}
                    {threads.last_page > 1 && <nav className="mt-4 flex flex-wrap gap-2" aria-label="Conversation pages">{threads.links.map((link, index) => link.url ? <Link key={index} href={link.url} className="border-border rounded border px-2 py-1 text-sm">{link.label}</Link> : null)}</nav>}
                </section>

                <section className="border-border bg-surface rounded-2xl border p-4 sm:p-5" aria-label={selected ? `Conversation with ${selected.peer}` : 'Start a conversation'}>
                    {selected ? (
                        <>
                            <h2 className="text-lg font-semibold">{selected.peer}</h2>
                            <ol className="mt-4 grid max-h-[28rem] gap-3 overflow-y-auto" aria-label="Conversation messages">
                                {selected.messages.map((message) => <li key={message.id} className={`max-w-[90%] rounded-xl p-3 ${message.mine ? 'bg-brand-50 ml-auto' : 'bg-muted'}`}>
                                    <p className="text-xs font-semibold">{message.mine ? 'You' : message.sender}</p>
                                    <p className="mt-1 whitespace-pre-wrap text-sm leading-6">{message.body}</p>
                                    {message.sent_at && <time className="text-text-muted mt-2 block text-xs">{new Date(message.sent_at).toLocaleString()}</time>}
                                </li>)}
                                {selected.messages.length === 0 && <li className="text-text-muted py-8 text-center text-sm">Send the first message to begin.</li>}
                            </ol>
                            {canSend && <form onSubmit={(event) => { event.preventDefault(); reply.post(`${base}/${selected.id}`, { preserveScroll: true, onSuccess: () => reply.reset('body') }); }} className="mt-4">
                                <label htmlFor="reply-body" className="mb-1 block text-sm font-medium">Reply</label>
                                <textarea id="reply-body" required maxLength={10000} rows={3} value={reply.data.body} onChange={(event) => reply.setData('body', event.target.value)} className="border-border bg-surface w-full rounded-lg border px-3 py-2" />
                                <FormErrors errors={reply.errors} />
                                <button disabled={reply.processing} className="bg-brand-700 text-on-brand mt-3 min-h-11 rounded-lg px-4 font-semibold disabled:opacity-60">{reply.processing ? 'Sending…' : 'Send reply'}</button>
                            </form>}
                        </>
                    ) : (
                        <>
                            <h2 className="text-lg font-semibold">New message</h2>
                            {!canSend ? <p className="text-text-muted mt-3 text-sm">Your role can view conversations but cannot send messages.</p> : recipients.length === 0 ? <p className="text-text-muted mt-3 text-sm">No eligible active members are available in your campus scope.</p> : <form onSubmit={(event) => { event.preventDefault(); start.post(base, { onSuccess: () => start.reset('body') }); }} className="mt-4 space-y-4">
                                <div>
                                    <label htmlFor="message-recipient" className="mb-1 block text-sm font-medium">Recipient</label>
                                    <select id="message-recipient" required value={start.data.recipient_user_id} onChange={(event) => start.setData('recipient_user_id', event.target.value)} className="border-border bg-surface min-h-11 w-full rounded-lg border px-3">
                                        <option value="">Choose an active member</option>
                                        {recipients.map((recipient) => <option key={recipient.id} value={recipient.id}>{recipient.name}{recipient.roles.length ? ` — ${recipient.roles.join(', ')}` : ''}</option>)}
                                    </select>
                                </div>
                                <div>
                                    <label htmlFor="message-body" className="mb-1 block text-sm font-medium">Message</label>
                                    <textarea id="message-body" required maxLength={10000} rows={7} value={start.data.body} onChange={(event) => start.setData('body', event.target.value)} className="border-border bg-surface w-full rounded-lg border px-3 py-2" />
                                </div>
                                <FormErrors errors={start.errors} />
                                <button disabled={start.processing} className="bg-brand-700 text-on-brand min-h-11 rounded-lg px-4 font-semibold disabled:opacity-60">{start.processing ? 'Sending…' : 'Send message'}</button>
                            </form>}
                        </>
                    )}
                </section>
            </div>
        </WorkspaceLayout>
    );
}
