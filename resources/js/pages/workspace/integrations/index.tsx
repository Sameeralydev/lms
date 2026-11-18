import { router, useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';
import { FormErrors } from '@/components/ui/form-errors';
import { PageHeader } from '@/components/ui/page-header';
import { WorkspaceLayout } from '@/layouts/workspace-layout';

type Credential = {
    id: number;
    name: string;
    token_prefix: string;
    scopes: string[];
    last_used_at: string | null;
    expires_at: string | null;
    revoked_at: string | null;
    created_at: string;
};

type Props = {
    tenant: { slug: string };
    credentials: Credential[];
    newToken: string | null;
};

export default function IntegrationsIndex({ tenant, credentials, newToken }: Props) {
    const form = useForm({ name: '', expires_at: '' });
    const root = `/t/${tenant.slug}/integrations`;

    return (
        <WorkspaceLayout title="Integrations" area="tenant">
            <div className="space-y-6">
                <PageHeader
                    title="Standards integrations"
                    description="Manage read-only access to published competency frameworks."
                />

                <section className="border-border bg-surface rounded-2xl border p-5 sm:p-7">
                    <h2 className="text-lg font-semibold">CASE Service 1.1</h2>
                    <p className="text-text-secondary mt-2 max-w-3xl text-sm">
                        Published competency frameworks are available as CASE JSON. The API currently supports
                        listing and reading framework documents, items, and packages. Draft frameworks and
                        other tenant records are not exposed.
                    </p>
                    <code className="bg-canvas mt-4 block overflow-x-auto rounded-lg p-3 text-xs">
                        {`/api/tenants/${tenant.slug}/ims/case/v1p1`}
                    </code>
                    <p className="text-text-muted mt-2 text-xs">
                        Send credentials as a Bearer token. Scope: case.read. API calls are rate limited and
                        audited.
                    </p>
                </section>

                {newToken && (
                    <section className="border-border bg-canvas rounded-xl border p-5" aria-live="polite">
                        <h2 className="font-semibold">Copy this credential now</h2>
                        <p className="text-text-secondary mt-1 text-sm">
                            The full value is only shown once. Store it in the consuming system’s secret
                            manager.
                        </p>
                        <code className="bg-surface mt-3 block rounded-lg border border-amber-500/40 p-3 text-sm break-all">
                            {newToken}
                        </code>
                    </section>
                )}

                <section className="border-border bg-surface rounded-2xl border p-5 sm:p-7">
                    <h2 className="text-lg font-semibold">Create a CASE API credential</h2>
                    <form
                        className="mt-4 grid gap-4 sm:grid-cols-[minmax(0,1fr)_minmax(12rem,16rem)_auto] sm:items-end"
                        onSubmit={(event: FormEvent) => {
                            event.preventDefault();
                            form.post(`${root}/credentials`, { onSuccess: () => form.reset() });
                        }}
                    >
                        <label className="grid gap-1 text-sm">
                            Credential name
                            <input
                                className="border-border bg-surface min-h-10 rounded-lg border px-3"
                                required
                                maxLength={120}
                                value={form.data.name}
                                onChange={(event) => form.setData('name', event.currentTarget.value)}
                            />
                        </label>
                        <label className="grid gap-1 text-sm">
                            Expires (optional)
                            <input
                                className="border-border bg-surface min-h-10 rounded-lg border px-3"
                                type="date"
                                value={form.data.expires_at}
                                onChange={(event) => form.setData('expires_at', event.currentTarget.value)}
                            />
                        </label>
                        <button
                            type="submit"
                            disabled={form.processing}
                            className="bg-tenant-brand min-h-10 rounded-lg px-4 font-semibold text-white disabled:opacity-60"
                        >
                            {form.processing ? 'Creating…' : 'Create credential'}
                        </button>
                        <div className="sm:col-span-3">
                            <FormErrors errors={form.errors} />
                        </div>
                    </form>
                </section>

                <section className="border-border bg-surface overflow-hidden rounded-2xl border">
                    <div className="border-border border-b p-5 sm:px-7">
                        <h2 className="text-lg font-semibold">API credentials</h2>
                    </div>
                    {credentials.length === 0 ? (
                        <p className="text-text-secondary p-6 text-sm">
                            No API credentials have been created.
                        </p>
                    ) : (
                        <div className="overflow-x-auto">
                            <table className="w-full min-w-[44rem] text-left text-sm">
                                <thead className="bg-canvas text-text-secondary">
                                    <tr>
                                        <th className="px-5 py-3 font-medium">Name</th>
                                        <th className="px-5 py-3 font-medium">Credential</th>
                                        <th className="px-5 py-3 font-medium">Last used</th>
                                        <th className="px-5 py-3 font-medium">Status</th>
                                        <th className="px-5 py-3 font-medium">Action</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {credentials.map((credential) => {
                                        const expired =
                                            credential.expires_at !== null &&
                                            new Date(credential.expires_at) <= new Date();
                                        const status = credential.revoked_at
                                            ? 'Revoked'
                                            : expired
                                              ? 'Expired'
                                              : 'Active';
                                        return (
                                            <tr key={credential.id} className="border-border border-t">
                                                <td className="px-5 py-4">
                                                    <div className="font-medium">{credential.name}</div>
                                                    <div className="text-text-muted mt-1 text-xs">
                                                        Scope: {credential.scopes.join(', ')}
                                                    </div>
                                                </td>
                                                <td className="px-5 py-4 font-mono text-xs">
                                                    {credential.token_prefix}…
                                                </td>
                                                <td className="px-5 py-4">
                                                    {credential.last_used_at
                                                        ? new Date(credential.last_used_at).toLocaleString()
                                                        : 'Never'}
                                                </td>
                                                <td className="px-5 py-4">
                                                    {status}
                                                    {credential.expires_at ? (
                                                        <div className="text-text-muted mt-1 text-xs">
                                                            Expires{' '}
                                                            {new Date(
                                                                credential.expires_at,
                                                            ).toLocaleDateString()}
                                                        </div>
                                                    ) : null}
                                                </td>
                                                <td className="px-5 py-4">
                                                    {status === 'Active' ? (
                                                        <button
                                                            type="button"
                                                            onClick={() =>
                                                                router.delete(
                                                                    `${root}/credentials/${credential.id}`,
                                                                    {
                                                                        onBefore: () =>
                                                                            window.confirm(
                                                                                `Revoke “${credential.name}”? Existing consumers will lose access.`,
                                                                            ),
                                                                    },
                                                                )
                                                            }
                                                            className="rounded-lg border border-red-500/50 px-3 py-2 text-sm font-semibold text-red-700"
                                                        >
                                                            Revoke
                                                        </button>
                                                    ) : (
                                                        <span className="text-text-muted text-xs">
                                                            No action
                                                        </span>
                                                    )}
                                                </td>
                                            </tr>
                                        );
                                    })}
                                </tbody>
                            </table>
                        </div>
                    )}
                </section>
            </div>
        </WorkspaceLayout>
    );
}
