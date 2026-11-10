import { Head, router, useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';
import { EmptyState } from '@/components/ui/empty-state';
import { FormErrors } from '@/components/ui/form-errors';
import { PageHeader } from '@/components/ui/page-header';
import { WorkspaceLayout } from '@/layouts/workspace-layout';

type Extension = {
    id: number;
    status: string;
    reason: string;
    requested_due_at: string;
    previous_due_at: string | null;
    effective_due_at: string | null;
    student: string;
    assignment: string;
    course: string;
};
type Props = {
    tenant: { slug: string };
    extensions: { data: Extension[]; links: { url: string | null; label: string; active: boolean }[] };
};

function ExtensionActions({ tenant, extension }: { tenant: { slug: string }; extension: Extension }) {
    const revokeForm = useForm({ reason: '' });
    const decide = (decision: 'approve' | 'reject') =>
        router.post(
            `/t/${tenant.slug}/assignments/extensions/${extension.id}/decision`,
            { decision },
            { preserveScroll: true },
        );
    const revoke = (event: FormEvent) => {
        event.preventDefault();
        revokeForm.post(`/t/${tenant.slug}/assignments/extensions/${extension.id}/revoke`, {
            preserveScroll: true,
        });
    };
    if (extension.status === 'pending')
        return (
            <div className="mt-4 flex flex-wrap justify-end gap-2">
                <button
                    type="button"
                    onClick={() => decide('reject')}
                    className="border-border focus-ring min-h-10 rounded-lg border px-3 text-sm font-semibold"
                >
                    Reject request
                </button>
                <button
                    type="button"
                    onClick={() => decide('approve')}
                    className="bg-brand-600 focus-ring min-h-10 rounded-lg px-3 text-sm font-semibold text-white"
                >
                    Approve requested deadline
                </button>
            </div>
        );
    if (extension.status !== 'approved') return null;
    return (
        <form
            onSubmit={revoke}
            className="border-border mt-4 grid gap-2 border-t pt-4 sm:grid-cols-[1fr_auto]"
        >
            <FormErrors errors={revokeForm.errors} />
            <label className="grid gap-1 text-sm font-medium">
                Revocation reason
                <textarea
                    required
                    minLength={12}
                    rows={2}
                    value={revokeForm.data.reason}
                    onChange={(event) => revokeForm.setData('reason', event.currentTarget.value)}
                    className="border-border rounded-lg border p-3"
                />
            </label>
            <button
                type="submit"
                disabled={revokeForm.processing}
                className="border-border focus-ring min-h-11 self-end rounded-lg border px-3 text-sm font-semibold"
            >
                Revoke extension
            </button>
        </form>
    );
}

export default function AssignmentExtensions({ tenant, extensions }: Props) {
    return (
        <WorkspaceLayout title="Extension requests" area="tenant">
            <Head title="Extension requests" />
            <PageHeader
                eyebrow="Coursework exceptions"
                title="Extension requests"
                description="Review deadline exceptions for your authorized teaching sections. Decisions are audited and notifications are delivered in-app."
            />
            {extensions.data.length === 0 ? (
                <div className="mt-6">
                    <EmptyState
                        title="No extension requests"
                        description="Learner requests for coursework deadlines will appear here."
                    />
                </div>
            ) : (
                <ul className="mt-6 grid gap-4" aria-label="Extension requests">
                    {extensions.data.map((extension) => (
                        <li key={extension.id} className="border-border bg-surface rounded-2xl border p-5">
                            <div className="flex flex-wrap items-start justify-between gap-3">
                                <div>
                                    <p className="text-text-muted text-xs font-semibold uppercase">
                                        {extension.course} · {extension.student}
                                    </p>
                                    <h2 className="mt-1 font-semibold">{extension.assignment}</h2>
                                </div>
                                <span className="bg-surface-soft rounded-full px-3 py-1 text-xs capitalize">
                                    {extension.status}
                                </span>
                            </div>
                            <p className="mt-3 text-sm">{extension.reason}</p>
                            <dl className="text-text-muted mt-4 grid gap-2 text-xs sm:grid-cols-3">
                                <div>
                                    <dt>Original deadline</dt>
                                    <dd className="mt-1 font-medium">
                                        {extension.previous_due_at
                                            ? new Date(extension.previous_due_at).toLocaleString()
                                            : 'No original deadline'}
                                    </dd>
                                </div>
                                <div>
                                    <dt>Requested deadline</dt>
                                    <dd className="mt-1 font-medium">
                                        {new Date(extension.requested_due_at).toLocaleString()}
                                    </dd>
                                </div>
                                <div>
                                    <dt>Effective deadline</dt>
                                    <dd className="mt-1 font-medium">
                                        {extension.effective_due_at
                                            ? new Date(extension.effective_due_at).toLocaleString()
                                            : 'Not approved'}
                                    </dd>
                                </div>
                            </dl>
                            <ExtensionActions tenant={tenant} extension={extension} />
                        </li>
                    ))}
                </ul>
            )}
        </WorkspaceLayout>
    );
}
