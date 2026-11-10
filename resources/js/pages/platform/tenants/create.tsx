import { useForm } from '@inertiajs/react';
import { Button } from '@/components/ui/button';
import { FormErrors } from '@/components/ui/form-errors';
import { PageHeader } from '@/components/ui/page-header';
import { SelectField } from '@/components/ui/select-field';
import { TextField } from '@/components/ui/text-field';
import { WorkspaceLayout } from '@/layouts/workspace-layout';

type Data = {
    official_name: string;
    display_name: string;
    slug: string;
    code: string;
    type: string;
    contact_email: string;
    contact_phone: string;
    website: string;
    country: string;
    region: string;
    city: string;
    address: string;
    timezone: string;
    locale: string;
    owner_email: string;
};
const blank: Data = {
    official_name: '',
    display_name: '',
    slug: '',
    code: '',
    type: 'school',
    contact_email: '',
    contact_phone: '',
    website: '',
    country: '',
    region: '',
    city: '',
    address: '',
    timezone: 'UTC',
    locale: 'en',
    owner_email: '',
};
const types = ['school', 'college', 'university', 'academy', 'corporate', 'online', 'other'].map((value) => ({
    value,
    label: value[0].toUpperCase() + value.slice(1),
}));
export default function TenantCreate() {
    const form = useForm<Data>(blank);
    const field = (name: keyof Data, label: string, type = 'text') => (
        <TextField
            label={label}
            name={name}
            type={type}
            value={form.data[name]}
            onChange={(event) => form.setData(name, event.currentTarget.value)}
            error={form.errors[name]}
        />
    );
    return (
        <WorkspaceLayout area="platform" title="Create institution">
            <PageHeader
                title="Provision an institution"
                description="Creates an institution workspace and sends a single-use owner invitation. The platform operator stays separate from tenant membership."
            />
            <form
                className="border-border bg-surface shadow-card grid gap-6 rounded-2xl border p-5 sm:p-7"
                onSubmit={(event) => {
                    event.preventDefault();
                    form.post('/platform/tenants');
                }}
            >
                <FormErrors />
                <div className="grid gap-5 md:grid-cols-2">
                    {field('official_name', 'Official institution name')}
                    {field('display_name', 'Display name')}
                    {field('slug', 'Preferred workspace slug', 'text')}
                    {field('code', 'Institution code')}
                    <SelectField
                        label="Institution type"
                        name="type"
                        options={types}
                        value={form.data.type}
                        onChange={(event) => form.setData('type', event.currentTarget.value)}
                    />
                    {field('contact_email', 'Contact email', 'email')}
                    {field('owner_email', 'Initial institution owner email', 'email')}
                    {field('contact_phone', 'Contact phone', 'tel')}
                    {field('website', 'Website', 'url')}
                    {field('country', 'Country code (optional)', 'text')}
                    {field('region', 'Region / province')}
                    {field('city', 'City')}
                    {field('timezone', 'Timezone (IANA)')}
                    {field('locale', 'Locale')}
                    <div className="md:col-span-2">{field('address', 'Address')}</div>
                </div>
                <div className="border-border flex flex-wrap items-center justify-between gap-3 border-t pt-5">
                    <p className="text-text-secondary max-w-2xl text-xs leading-5">
                        The owner creates or signs in to a global account through the signed invitation. The
                        platform operator does not receive institution membership.
                    </p>
                    <Button type="submit" loading={form.processing}>
                        Provision institution
                    </Button>
                </div>
            </form>
        </WorkspaceLayout>
    );
}
