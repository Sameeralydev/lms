import { Link, router } from '@inertiajs/react';
import { EmptyState } from '@/components/ui/empty-state';
import { PageHeader } from '@/components/ui/page-header';
import { TextField } from '@/components/ui/text-field';
import { WorkspaceLayout } from '@/layouts/workspace-layout';

type Tenant = {
    id: number;
    name: string;
    slug: string;
    status: string;
    created_at: string;
    institution?: { official_name: string; display_name: string; type: string } | null;
    campuses_count: number;
    active_memberships_count: number;
};
type Page<T> = { data: T[]; links: { url: string | null; label: string; active: boolean }[]; total: number };
export default function TenantIndex({
    tenants,
    filters,
}: {
    tenants: Page<Tenant>;
    filters: { q?: string; status?: string };
}) {
    const search = (event: React.FormEvent<HTMLFormElement>) => {
        event.preventDefault();
        const form = new FormData(event.currentTarget);
        router.get(
            '/platform/tenants',
            { q: form.get('q'), status: form.get('status') },
            { preserveState: true },
        );
    };
    return (
        <WorkspaceLayout area="platform" title="Institutions">
            <PageHeader
                title="Institution directory"
                description="Search and review institution tenants, their current status, campuses, and active memberships."
                actions={
                    <Link
                        href="/platform/tenants/create"
                        className="bg-brand-action inline-flex min-h-11 items-center rounded-lg px-4 text-sm font-semibold text-white"
                    >
                        Create institution
                    </Link>
                }
            />
            <form
                onSubmit={search}
                className="border-border bg-surface mb-5 grid gap-3 rounded-2xl border p-4 sm:grid-cols-[1fr_12rem_auto]"
            >
                <TextField
                    label="Search institutions"
                    name="q"
                    placeholder="Name or slug"
                    defaultValue={filters.q}
                />
                <label className="grid gap-2 text-sm font-medium">
                    Status
                    <select
                        className="border-border bg-surface min-h-11 rounded-lg border px-3"
                        name="status"
                        defaultValue={filters.status ?? ''}
                    >
                        <option value="">All statuses</option>
                        <option value="active">Active</option>
                        <option value="suspended">Suspended</option>
                        <option value="archived">Archived</option>
                    </select>
                </label>
                <button className="border-border hover:bg-surface-soft min-h-11 self-end rounded-lg border px-4 text-sm font-semibold">
                    Filter
                </button>
            </form>
            {tenants.data.length ? (
                <div className="border-border bg-surface overflow-hidden rounded-2xl border">
                    <div className="overflow-x-auto">
                        <table className="w-full min-w-[760px] text-left text-sm">
                            <thead className="bg-surface-soft text-text-secondary">
                                <tr>
                                    <th className="px-5 py-3 font-medium">Institution</th>
                                    <th className="px-5 py-3 font-medium">Status</th>
                                    <th className="px-5 py-3 font-medium">Campuses</th>
                                    <th className="px-5 py-3 font-medium">Members</th>
                                    <th className="px-5 py-3 font-medium">Created</th>
                                    <th className="px-5 py-3" />
                                </tr>
                            </thead>
                            <tbody className="divide-border divide-y">
                                {tenants.data.map((tenant) => (
                                    <tr key={tenant.id} className="hover:bg-surface-soft/60">
                                        <td className="px-5 py-4">
                                            <Link
                                                className="text-text-primary hover:text-brand-link font-semibold"
                                                href={`/platform/tenants/${tenant.slug}`}
                                            >
                                                {tenant.institution?.display_name ?? tenant.name}
                                            </Link>
                                            <p className="text-text-muted mt-1 text-xs">
                                                {tenant.slug} · {tenant.institution?.type ?? 'Institution'}
                                            </p>
                                        </td>
                                        <td className="px-5 py-4">
                                            <span className="bg-surface-soft rounded-full px-2.5 py-1 text-xs font-medium capitalize">
                                                {tenant.status}
                                            </span>
                                        </td>
                                        <td className="px-5 py-4">{tenant.campuses_count}</td>
                                        <td className="px-5 py-4">{tenant.active_memberships_count}</td>
                                        <td className="text-text-secondary px-5 py-4 text-xs">
                                            {new Date(tenant.created_at).toLocaleDateString()}
                                        </td>
                                        <td className="px-5 py-4">
                                            <Link
                                                className="text-brand-link font-semibold"
                                                href={`/platform/tenants/${tenant.slug}`}
                                            >
                                                Details
                                            </Link>
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                    <nav
                        aria-label="Pagination"
                        className="border-border flex flex-wrap gap-2 border-t px-5 py-4"
                    >
                        {tenants.links.map((link, index) =>
                            link.url ? (
                                <Link
                                    key={index}
                                    href={link.url}
                                    preserveScroll
                                    className={`rounded-md px-3 py-2 text-xs ${link.active ? 'bg-brand-action text-white' : 'border-border border'}`}
                                >
                                    {link.label.replace('&laquo;', '‹').replace('&raquo;', '›')}
                                </Link>
                            ) : (
                                <span key={index} className="text-text-muted px-3 py-2 text-xs">
                                    {link.label.replace('&laquo;', '‹').replace('&raquo;', '›')}
                                </span>
                            ),
                        )}
                    </nav>
                </div>
            ) : (
                <EmptyState
                    title="No institutions found"
                    description="Adjust the filters or create the first institution tenant."
                />
            )}
        </WorkspaceLayout>
    );
}
