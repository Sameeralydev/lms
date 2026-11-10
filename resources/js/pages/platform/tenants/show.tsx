import { router, useForm } from '@inertiajs/react';
import { Button } from '@/components/ui/button';
import { FormErrors } from '@/components/ui/form-errors';
import { PageHeader } from '@/components/ui/page-header';
import { SelectField } from '@/components/ui/select-field';
import { TextField } from '@/components/ui/text-field';
import { WorkspaceLayout } from '@/layouts/workspace-layout';

type Institution = {
    official_name: string;
    display_name: string;
    code: string | null;
    type: string;
    contact_email: string | null;
    contact_phone: string | null;
    website: string | null;
    country: string | null;
    region: string | null;
    city: string | null;
    address: string | null;
    timezone: string;
    locale: string;
};
type Event = { id: number; event_type: string; occurred_at: string; actor?: { name: string } | null };
export default function TenantShow({
    tenant,
    institution,
    recentEvents,
    ownerInvitation,
}: {
    tenant: {
        id: number;
        name: string;
        slug: string;
        status: string;
        campuses_count: number;
        memberships_count: number;
        domains: { domain: string; status: string; is_primary: boolean }[];
    };
    institution: Institution;
    recentEvents: Event[];
    ownerInvitation: { email: string; status: string; expires_at: string; accepted_at: string | null } | null;
}) {
    const form = useForm({ ...institution });
    const field = (name: keyof Institution, label: string, type = 'text') => (
        <TextField
            label={label}
            name={name}
            type={type}
            value={form.data[name] ?? ''}
            onChange={(event) => form.setData(name, event.currentTarget.value)}
            error={form.errors[name]}
        />
    );
    return (
        <WorkspaceLayout area="platform" title={institution.display_name}>
            <PageHeader
                title={institution.display_name}
                description={`Tenant ${tenant.slug} · ${tenant.status}`}
            />
            <div className="mb-6 grid gap-4 sm:grid-cols-3">
                <div className="border-border bg-surface rounded-xl border p-4">
                    <p className="text-text-secondary text-xs">Status</p>
                    <p className="mt-2 font-semibold capitalize">{tenant.status}</p>
                </div>
                <div className="border-border bg-surface rounded-xl border p-4">
                    <p className="text-text-secondary text-xs">Campuses</p>
                    <p className="mt-2 font-semibold">{tenant.campuses_count}</p>
                </div>
                <div className="border-border bg-surface rounded-xl border p-4">
                    <p className="text-text-secondary text-xs">Memberships</p>
                    <p className="mt-2 font-semibold">{tenant.memberships_count}</p>
                </div>
            </div>
            {ownerInvitation ? (
                <section className="border-border bg-surface mb-6 rounded-xl border p-4">
                    <h2 className="font-semibold">Initial institution owner</h2>
                    <div className="text-text-secondary mt-2 flex flex-wrap gap-x-2 gap-y-1 text-sm">
                        <span>{ownerInvitation.email}</span>
                        <span className="capitalize">{ownerInvitation.status}</span>
                    </div>
                    {ownerInvitation.status === 'pending' ? (
                        <p className="text-text-muted mt-1 text-xs">
                            The invitation expires {new Date(ownerInvitation.expires_at).toLocaleString()}.
                            Institution access begins after acceptance.
                        </p>
                    ) : null}
                </section>
            ) : null}
            <div className="grid gap-6 xl:grid-cols-[minmax(0,1.5fr)_minmax(18rem,0.8fr)]">
                <form
                    className="border-border bg-surface grid gap-5 rounded-2xl border p-5"
                    onSubmit={(event) => {
                        event.preventDefault();
                        form.put(`/platform/tenants/${tenant.slug}`);
                    }}
                >
                    <div>
                        <h2 className="text-lg font-semibold">Institution profile</h2>
                        <p className="text-text-secondary mt-1 text-sm">
                            Platform operators can update the institution record.
                        </p>
                    </div>
                    <FormErrors />
                    <div className="grid gap-4 md:grid-cols-2">
                        {field('official_name', 'Official name')}
                        {field('display_name', 'Display name')}
                        <SelectField
                            label="Institution type"
                            name="type"
                            options={[
                                'school',
                                'college',
                                'university',
                                'academy',
                                'corporate',
                                'online',
                                'other',
                            ].map((v) => ({ value: v, label: v }))}
                            value={form.data.type}
                            onChange={(event) => form.setData('type', event.currentTarget.value)}
                        />
                        {field('code', 'Institution code')}
                        {field('contact_email', 'Contact email', 'email')}
                        {field('contact_phone', 'Contact phone', 'tel')}
                        {field('website', 'Website', 'url')}
                        {field('country', 'Country code')}
                        {field('region', 'Region')}
                        {field('city', 'City')}
                        {field('timezone', 'Timezone')}
                        {field('locale', 'Locale')}
                        <div className="md:col-span-2">{field('address', 'Address')}</div>
                    </div>
                    <Button type="submit" loading={form.processing}>
                        Save institution
                    </Button>
                </form>
                <section className="grid content-start gap-6">
                    <div className="border-border bg-surface rounded-2xl border p-5">
                        <h2 className="font-semibold">Tenant lifecycle</h2>
                        <p className="text-text-secondary mt-1 text-sm">
                            Access changes take effect on the next request. Tenant records are never
                            hard-deleted.
                        </p>
                        <div className="mt-4 grid gap-2">
                            {tenant.status === 'active' ? (
                                <button
                                    className="min-h-11 rounded-lg border border-red-200 px-3 text-left text-sm font-semibold text-red-800"
                                    onClick={() =>
                                        router.patch(`/platform/tenants/${tenant.slug}/status`, {
                                            status: 'suspended',
                                            reason: 'Suspended by platform operator',
                                        })
                                    }
                                >
                                    Suspend tenant
                                </button>
                            ) : tenant.status === 'suspended' ? (
                                <button
                                    className="min-h-11 rounded-lg border border-emerald-200 px-3 text-left text-sm font-semibold"
                                    onClick={() =>
                                        router.patch(`/platform/tenants/${tenant.slug}/status`, {
                                            status: 'active',
                                        })
                                    }
                                >
                                    Reactivate tenant
                                </button>
                            ) : null}
                            {tenant.status !== 'archived' ? (
                                <button
                                    className="border-border min-h-11 rounded-lg border px-3 text-left text-sm font-semibold"
                                    onClick={() =>
                                        router.patch(`/platform/tenants/${tenant.slug}/status`, {
                                            status: 'archived',
                                        })
                                    }
                                >
                                    Archive tenant
                                </button>
                            ) : null}
                        </div>
                    </div>
                    <div className="border-border bg-surface rounded-2xl border p-5">
                        <h2 className="font-semibold">Verified domains</h2>
                        {tenant.domains.length ? (
                            <ul className="mt-3 grid gap-2">
                                {tenant.domains.map((domain) => (
                                    <li key={domain.domain} className="flex justify-between gap-2 text-sm">
                                        <span>{domain.domain}</span>
                                        <span className="text-text-muted text-xs">
                                            {domain.is_primary ? 'Primary' : domain.status}
                                        </span>
                                    </li>
                                ))}
                            </ul>
                        ) : (
                            <p className="text-text-secondary mt-3 text-sm">No domains registered.</p>
                        )}
                    </div>
                    <div className="border-border bg-surface rounded-2xl border p-5">
                        <h2 className="font-semibold">Recent tenant activity</h2>
                        {recentEvents.length ? (
                            <ul className="divide-border mt-3 divide-y">
                                {recentEvents.map((event) => (
                                    <li key={event.id} className="py-3">
                                        <p className="text-sm font-medium">
                                            {event.event_type.replaceAll('.', ' ')}
                                        </p>
                                        <p className="text-text-muted mt-1 text-xs">
                                            {event.actor?.name ?? 'System'} ·{' '}
                                            {new Date(event.occurred_at).toLocaleString()}
                                        </p>
                                    </li>
                                ))}
                            </ul>
                        ) : (
                            <p className="text-text-secondary mt-3 text-sm">No events yet.</p>
                        )}
                    </div>
                </section>
            </div>
        </WorkspaceLayout>
    );
}
