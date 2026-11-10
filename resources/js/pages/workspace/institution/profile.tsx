import { useForm, usePage } from '@inertiajs/react';
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
    campuses_count: number;
};
export default function InstitutionProfile({
    institution,
    tenant,
}: {
    institution: Institution;
    tenant: { name: string; slug: string; status: string };
}) {
    const permissions =
        usePage<{ auth: { tenant: { permissions: string[] } | null } }>().props.auth.tenant?.permissions ??
        [];
    const canUpdate = permissions.includes('institution.update');
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
        <WorkspaceLayout area="tenant" title="Institution profile">
            <PageHeader
                title="Institution profile"
                description="Maintain the official institution identity and the local details used across the workspace."
            />
            <form
                className="border-border bg-surface grid gap-5 rounded-2xl border p-5 sm:p-7"
                onSubmit={(event) => {
                    event.preventDefault();
                    form.put(`/t/${tenant.slug}/institution`);
                }}
            >
                <FormErrors />
                {!canUpdate ? (
                    <p className="text-text-secondary text-sm">
                        Your current membership provides read-only access to this profile.
                    </p>
                ) : null}
                <fieldset disabled={!canUpdate} className="grid gap-5">
                    <div className="grid gap-4 md:grid-cols-2">
                        {field('official_name', 'Official institution name')}
                        {field('display_name', 'Display name')}
                        {field('code', 'Institution code')}
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
                            ].map((value) => ({ value, label: value }))}
                            value={form.data.type}
                            onChange={(event) => form.setData('type', event.currentTarget.value)}
                        />
                        {field('contact_email', 'Contact email', 'email')}
                        {field('contact_phone', 'Contact phone', 'tel')}
                        {field('website', 'Website', 'url')}
                        {field('country', 'Country code (optional)')}
                        {field('region', 'Region / state / province')}
                        {field('city', 'City')}
                        {field('timezone', 'Timezone (IANA)')}
                        {field('locale', 'Locale')}
                        <div className="md:col-span-2">{field('address', 'Address')}</div>
                    </div>
                    <div className="text-text-secondary border-border flex flex-wrap items-center justify-between gap-3 border-t pt-4 text-sm">
                        <span>
                            {institution.campuses_count} campus records · Tenant {tenant.slug}
                        </span>
                        {canUpdate ? (
                            <Button type="submit" loading={form.processing}>
                                Save profile
                            </Button>
                        ) : null}
                    </div>
                </fieldset>
            </form>
        </WorkspaceLayout>
    );
}
