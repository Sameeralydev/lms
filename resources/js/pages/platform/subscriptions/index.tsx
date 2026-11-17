import { Link, router } from '@inertiajs/react';
import { PageHeader } from '@/components/ui/page-header';
import { WorkspaceLayout } from '@/layouts/workspace-layout';

type TenantRow = {
    id: number;
    name: string;
    slug: string;
    status: string;
    subscription: null | { status: string; plan: { id: number; name: string; key: string } | null };
};
type Page<T> = { data: T[]; links: { url: string | null; label: string; active: boolean }[] };

export default function SubscriptionsIndex({
    tenants,
    plans,
}: {
    tenants: Page<TenantRow>;
    plans: { id: number; name: string; key: string }[];
}) {
    return (
        <WorkspaceLayout area="platform" title="Tenant plans">
            <PageHeader
                title="Tenant plan assignments"
                description="Assign or pause feature access. Legacy tenants without an assignment remain unrestricted. This does not start billing or payment collection."
            />
            <div className="border-border bg-surface overflow-x-auto rounded-2xl border">
                <table className="w-full min-w-[46rem] text-left text-sm">
                    <thead className="bg-surface-soft text-text-secondary">
                        <tr>
                            <th className="px-4 py-3 font-medium">Tenant</th>
                            <th className="px-4 py-3 font-medium">Current access</th>
                            <th className="px-4 py-3 font-medium">Plan assignment</th>
                            <th className="px-4 py-3 font-medium">Action</th>
                        </tr>
                    </thead>
                    <tbody className="divide-border divide-y">
                        {tenants.data.map((tenant) => (
                            <tr key={tenant.id}>
                                <td className="px-4 py-4">
                                    <Link
                                        className="font-medium underline"
                                        href={`/platform/tenants/${tenant.slug}`}
                                    >
                                        {tenant.name}
                                    </Link>
                                    <span className="text-text-muted mt-1 block text-xs">
                                        {tenant.slug} · {tenant.status}
                                    </span>
                                </td>
                                <td className="px-4 py-4">
                                    {tenant.subscription ? (
                                        <>
                                            <span>
                                                {tenant.subscription.plan?.name ?? 'Plan unavailable'}
                                            </span>
                                            <span className="text-text-muted mt-1 block text-xs capitalize">
                                                {tenant.subscription.status}
                                            </span>
                                        </>
                                    ) : (
                                        <span className="text-text-secondary">Legacy unrestricted</span>
                                    )}
                                </td>
                                <td colSpan={2} className="px-4 py-4">
                                    <form
                                        className="flex flex-wrap items-center gap-2"
                                        onSubmit={(event) => {
                                            event.preventDefault();
                                            const data = new FormData(event.currentTarget);
                                            router.put(
                                                `/platform/tenants/${tenant.slug}/subscription`,
                                                {
                                                    plan_id: Number(data.get('plan_id')),
                                                    status: String(data.get('status')),
                                                },
                                                { preserveScroll: true },
                                            );
                                        }}
                                    >
                                        <label className="sr-only" htmlFor={`plan-${tenant.id}`}>
                                            Plan for {tenant.name}
                                        </label>
                                        <select
                                            id={`plan-${tenant.id}`}
                                            name="plan_id"
                                            required
                                            defaultValue={tenant.subscription?.plan?.id ?? ''}
                                            className="border-border bg-surface min-h-10 rounded-lg border px-3"
                                        >
                                            <option value="" disabled>
                                                Select plan
                                            </option>
                                            {plans.map((plan) => (
                                                <option key={plan.id} value={plan.id}>
                                                    {plan.name}
                                                </option>
                                            ))}
                                        </select>
                                        <label className="sr-only" htmlFor={`status-${tenant.id}`}>
                                            Assignment status for {tenant.name}
                                        </label>
                                        <select
                                            id={`status-${tenant.id}`}
                                            name="status"
                                            defaultValue={tenant.subscription?.status ?? 'active'}
                                            className="border-border bg-surface min-h-10 rounded-lg border px-3"
                                        >
                                            <option value="active">Active</option>
                                            <option value="paused">Paused</option>
                                        </select>
                                        <button
                                            className="bg-brand-action min-h-10 rounded-lg px-3 font-semibold text-white"
                                            type="submit"
                                        >
                                            Save
                                        </button>
                                    </form>
                                </td>
                            </tr>
                        ))}
                        {!tenants.data.length ? (
                            <tr>
                                <td className="text-text-secondary px-4 py-8" colSpan={4}>
                                    No tenants are available.
                                </td>
                            </tr>
                        ) : null}
                    </tbody>
                </table>
                <nav
                    aria-label="Pagination"
                    className="border-border flex flex-wrap gap-2 border-t px-4 py-3"
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
        </WorkspaceLayout>
    );
}
