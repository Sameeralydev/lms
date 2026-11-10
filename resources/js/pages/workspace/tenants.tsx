import { useForm } from '@inertiajs/react';
import { Button } from '@/components/ui/button';
import { EmptyState } from '@/components/ui/empty-state';
import { PageHeader } from '@/components/ui/page-header';
import { WorkspaceLayout } from '@/layouts/workspace-layout';

type Workspace = { slug: string; name: string; status: string; roles: string[] };
export default function Workspaces({ workspaces }: { workspaces: Workspace[] }) {
    const form = useForm({ tenant: '' });
    return (
        <WorkspaceLayout area="tenant" title="My institutions">
            <PageHeader
                title="My institution memberships"
                description="Select an active institution membership to open its workspace."
            />
            {workspaces.length ? (
                <div className="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
                    {workspaces.map((workspace) => (
                        <section
                            key={workspace.slug}
                            className="border-border bg-surface rounded-2xl border p-5"
                        >
                            <p className="text-text-muted text-xs font-semibold uppercase">
                                {workspace.status}
                            </p>
                            <h2 className="text-text-primary mt-2 text-xl font-semibold">{workspace.name}</h2>
                            <p className="text-text-secondary mt-2 text-sm">
                                {workspace.roles.join(', ') || 'Member'}
                            </p>
                            <Button
                                className="mt-5 w-full"
                                loading={form.processing}
                                onClick={() => {
                                    form.setData('tenant', workspace.slug);
                                    form.post('/workspaces/switch');
                                }}
                            >
                                Open workspace
                            </Button>
                        </section>
                    ))}
                </div>
            ) : (
                <EmptyState
                    title="No institution memberships"
                    description="You will see an institution here after an administrator invites you and you accept the invitation."
                />
            )}
        </WorkspaceLayout>
    );
}
