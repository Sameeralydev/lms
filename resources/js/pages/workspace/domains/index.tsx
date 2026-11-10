import { router, useForm } from '@inertiajs/react';
import { Button } from '@/components/ui/button';
import { EmptyState } from '@/components/ui/empty-state';
import { FormErrors } from '@/components/ui/form-errors';
import { PageHeader } from '@/components/ui/page-header';
import { TextField } from '@/components/ui/text-field';
import { WorkspaceLayout } from '@/layouts/workspace-layout';

type Domain = {
    id: number;
    domain: string;
    verification_token: string | null;
    status: string;
    verified_at: string | null;
    is_primary: boolean;
};
type Props = { domains: Domain[]; tenant: { slug: string }; canManage: boolean };
export default function Domains({ domains, tenant, canManage }: Props) {
    const form = useForm({ domain: '' });
    return (
        <WorkspaceLayout area="tenant" title="Domains">
            <PageHeader
                title="Verified institution domains"
                description="Register domains, confirm ownership through DNS, and select a primary domain. The current workspace resolves through an institution slug; custom-host routing is a deployment follow-up."
            />
            {canManage ? (
                <form
                    className="border-border bg-surface mb-6 grid gap-4 rounded-2xl border p-5 sm:grid-cols-[1fr_auto]"
                    onSubmit={(event) => {
                        event.preventDefault();
                        form.post(`/t/${tenant.slug}/domains`, { onSuccess: () => form.reset() });
                    }}
                >
                    <FormErrors />
                    <TextField
                        label="Institution domain"
                        name="domain"
                        placeholder="academy.example.org"
                        value={form.data.domain}
                        onChange={(event) => form.setData('domain', event.currentTarget.value)}
                        error={form.errors.domain}
                    />
                    <Button className="self-end" type="submit" loading={form.processing}>
                        Add domain
                    </Button>
                </form>
            ) : (
                <p className="border-border bg-surface text-text-secondary mb-6 rounded-xl border p-4 text-sm">
                    Domain management is restricted to authorized institution owners.
                </p>
            )}
            {domains.length ? (
                <div className="grid gap-4">
                    {domains.map((domain) => (
                        <article key={domain.id} className="border-border bg-surface rounded-2xl border p-5">
                            <div className="flex flex-wrap items-start justify-between gap-4">
                                <div>
                                    <h2 className="font-semibold">{domain.domain}</h2>
                                    <p className="text-text-secondary mt-1 text-sm">
                                        {domain.is_primary
                                            ? 'Primary domain'
                                            : domain.status === 'verified'
                                              ? 'Verified'
                                              : 'Verification pending'}
                                    </p>
                                </div>
                                {canManage && domain.verified_at && !domain.is_primary ? (
                                    <Button
                                        variant="secondary"
                                        onClick={() =>
                                            router.post(`/t/${tenant.slug}/domains/${domain.id}/primary`)
                                        }
                                    >
                                        Set primary
                                    </Button>
                                ) : canManage && !domain.verified_at ? (
                                    <Button
                                        variant="secondary"
                                        onClick={() =>
                                            router.post(`/t/${tenant.slug}/domains/${domain.id}/verify`)
                                        }
                                    >
                                        Verify DNS
                                    </Button>
                                ) : null}
                            </div>
                            {!domain.verified_at && canManage ? (
                                <div className="bg-surface-soft mt-4 rounded-xl p-4">
                                    <p className="text-sm font-medium">Publish this DNS TXT record</p>
                                    <code className="mt-2 block text-xs break-all">
                                        _techmentors-verification.{domain.domain} = techmentors-verification=
                                        {domain.verification_token}
                                    </code>
                                    <p className="text-text-muted mt-2 text-xs">
                                        The token proves domain control. It is not an account credential.
                                    </p>
                                </div>
                            ) : domain.verified_at ? (
                                <p className="text-text-muted mt-3 text-xs">
                                    Verified {new Date(domain.verified_at).toLocaleString()}
                                </p>
                            ) : (
                                <p className="text-text-muted mt-3 text-xs">
                                    Ownership verification is pending.
                                </p>
                            )}
                        </article>
                    ))}
                </div>
            ) : (
                <EmptyState
                    title="No institution domains"
                    description="Add a domain and verify ownership before selecting it as the institution's primary custom domain."
                />
            )}
        </WorkspaceLayout>
    );
}
