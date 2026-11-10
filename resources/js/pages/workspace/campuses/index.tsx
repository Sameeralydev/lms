import { router, useForm, usePage } from '@inertiajs/react';
import { Button } from '@/components/ui/button';
import { EmptyState } from '@/components/ui/empty-state';
import { FormErrors } from '@/components/ui/form-errors';
import { PageHeader } from '@/components/ui/page-header';
import { TextField } from '@/components/ui/text-field';
import { WorkspaceLayout } from '@/layouts/workspace-layout';

type Campus = {
    id: number;
    code: string;
    name: string;
    city: string | null;
    contact_email: string | null;
    contact_phone: string | null;
    address: string | null;
    status: string;
    is_primary: boolean;
};
type Paged<T> = { data: T[]; links: { url: string | null; label: string; active: boolean }[] };
type SharedProps = { auth: { tenant: { permissions: string[] } | null } };
export default function Campuses({
    campuses,
    tenant,
}: {
    campuses: Paged<Campus>;
    tenant: { slug: string };
}) {
    const permissions = usePage<SharedProps>().props.auth.tenant?.permissions ?? [];
    const canCreate = permissions.includes('campus.create');
    const canUpdate = permissions.includes('campus.update');
    const form = useForm({
        code: '',
        name: '',
        contact_email: '',
        contact_phone: '',
        city: '',
        address: '',
        is_primary: false,
    });
    return (
        <WorkspaceLayout area="tenant" title="Campuses">
            <PageHeader
                title="Campuses"
                description="Create and maintain campus locations belonging to this institution. Campus IDs are only resolved inside the active tenant context."
            />
            {canCreate ? (
                <form
                    className="border-border bg-surface mb-6 grid gap-4 rounded-2xl border p-5"
                    onSubmit={(event) => {
                        event.preventDefault();
                        form.post(`/t/${tenant.slug}/campuses`, { onSuccess: () => form.reset() });
                    }}
                >
                    <div>
                        <h2 className="font-semibold">Add a campus</h2>
                        <p className="text-text-secondary mt-1 text-sm">
                            Codes are unique within this institution.
                        </p>
                    </div>
                    <FormErrors />
                    <div className="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
                        <TextField
                            label="Campus code"
                            name="code"
                            value={form.data.code}
                            onChange={(event) => form.setData('code', event.currentTarget.value)}
                            error={form.errors.code}
                        />
                        <TextField
                            label="Campus name"
                            name="name"
                            value={form.data.name}
                            onChange={(event) => form.setData('name', event.currentTarget.value)}
                            error={form.errors.name}
                        />
                        <TextField
                            label="City"
                            name="city"
                            value={form.data.city}
                            onChange={(event) => form.setData('city', event.currentTarget.value)}
                        />
                        <TextField
                            label="Contact email"
                            name="contact_email"
                            type="email"
                            value={form.data.contact_email}
                            onChange={(event) => form.setData('contact_email', event.currentTarget.value)}
                            error={form.errors.contact_email}
                        />
                        <TextField
                            label="Contact phone"
                            name="contact_phone"
                            type="tel"
                            value={form.data.contact_phone}
                            onChange={(event) => form.setData('contact_phone', event.currentTarget.value)}
                        />
                        <label className="text-text-primary flex min-h-11 items-center gap-2 text-sm">
                            <input
                                type="checkbox"
                                checked={form.data.is_primary}
                                onChange={(event) => form.setData('is_primary', event.currentTarget.checked)}
                            />
                            Set as primary campus
                        </label>
                        <div className="md:col-span-2 xl:col-span-3">
                            <TextField
                                label="Address"
                                name="address"
                                value={form.data.address}
                                onChange={(event) => form.setData('address', event.currentTarget.value)}
                            />
                        </div>
                    </div>
                    <div className="flex justify-end">
                        <Button type="submit" loading={form.processing}>
                            Create campus
                        </Button>
                    </div>
                </form>
            ) : (
                <p className="border-border bg-surface text-text-secondary mb-6 rounded-xl border p-4 text-sm">
                    You have read-only access to the institution campus directory.
                </p>
            )}
            {campuses.data.length ? (
                <div className="grid gap-4">
                    {campuses.data.map((campus) =>
                        canUpdate ? (
                            <CampusCard key={campus.id} campus={campus} slug={tenant.slug} />
                        ) : (
                            <article
                                key={campus.id}
                                className="border-border bg-surface rounded-2xl border p-5"
                            >
                                <h2 className="font-semibold">{campus.name}</h2>
                                <p className="text-text-secondary mt-1 text-sm">
                                    {campus.code} · {campus.city ?? 'City not set'} · {campus.status}
                                    {campus.is_primary ? ' · Primary' : ''}
                                </p>
                            </article>
                        ),
                    )}
                </div>
            ) : (
                <EmptyState
                    title="No campuses yet"
                    description={
                        canCreate
                            ? "Add the institution's first campus. Academic schedules and student records are outside this phase."
                            : 'No campus locations have been recorded yet.'
                    }
                />
            )}
            <nav aria-label="Campus pagination" className="mt-5 flex flex-wrap gap-2">
                {campuses.links.map((link, i) =>
                    link.url ? (
                        <button
                            key={i}
                            className="border-border rounded border px-3 py-2 text-xs"
                            onClick={() => router.visit(link.url!)}
                        >
                            {link.label.replace('&laquo;', '‹').replace('&raquo;', '›')}
                        </button>
                    ) : null,
                )}
            </nav>
        </WorkspaceLayout>
    );
}
function CampusCard({ campus, slug }: { campus: Campus; slug: string }) {
    const form = useForm({
        code: campus.code,
        name: campus.name,
        contact_email: campus.contact_email ?? '',
        contact_phone: campus.contact_phone ?? '',
        city: campus.city ?? '',
        address: campus.address ?? '',
        is_primary: campus.is_primary,
    });
    return (
        <form
            className="border-border bg-surface grid gap-4 rounded-2xl border p-5"
            onSubmit={(event) => {
                event.preventDefault();
                form.put(`/t/${slug}/campuses/${campus.id}`);
            }}
        >
            <div className="flex flex-wrap items-start justify-between gap-3">
                <div>
                    <h2 className="text-lg font-semibold">{campus.name}</h2>
                    <p className="text-text-secondary mt-1 text-xs">
                        {campus.code} · {campus.city ?? 'City not set'} · {campus.status}
                        {campus.is_primary ? ' · Primary' : ''}
                    </p>
                </div>
                <button
                    type="button"
                    className="border-border min-h-10 rounded-lg border px-3 text-xs font-semibold"
                    onClick={() =>
                        router.patch(`/t/${slug}/campuses/${campus.id}/status`, {
                            status: campus.status === 'active' ? 'inactive' : 'active',
                        })
                    }
                >
                    {campus.status === 'active' ? 'Deactivate' : 'Activate'}
                </button>
            </div>
            <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                <TextField
                    label="Code"
                    name={`code-${campus.id}`}
                    value={form.data.code}
                    onChange={(event) => form.setData('code', event.currentTarget.value)}
                    error={form.errors.code}
                />
                <TextField
                    label="Name"
                    name={`name-${campus.id}`}
                    value={form.data.name}
                    onChange={(event) => form.setData('name', event.currentTarget.value)}
                    error={form.errors.name}
                />
                <TextField
                    label="City"
                    name={`city-${campus.id}`}
                    value={form.data.city}
                    onChange={(event) => form.setData('city', event.currentTarget.value)}
                />
                <TextField
                    label="Contact email"
                    name={`email-${campus.id}`}
                    type="email"
                    value={form.data.contact_email}
                    onChange={(event) => form.setData('contact_email', event.currentTarget.value)}
                />
                <TextField
                    label="Contact phone"
                    name={`phone-${campus.id}`}
                    value={form.data.contact_phone}
                    onChange={(event) => form.setData('contact_phone', event.currentTarget.value)}
                />
                <label className="text-sm">
                    <input
                        type="checkbox"
                        checked={form.data.is_primary}
                        onChange={(event) => form.setData('is_primary', event.currentTarget.checked)}
                    />{' '}
                    Primary
                </label>
            </div>
            <div className="flex justify-end">
                <Button variant="secondary" type="submit" loading={form.processing}>
                    Save changes
                </Button>
            </div>
        </form>
    );
}
