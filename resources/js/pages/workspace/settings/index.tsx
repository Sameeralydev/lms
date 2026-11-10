import { useForm, usePage } from '@inertiajs/react';
import { Button } from '@/components/ui/button';
import { FormErrors } from '@/components/ui/form-errors';
import { PageHeader } from '@/components/ui/page-header';
import { SelectField } from '@/components/ui/select-field';
import { TextField } from '@/components/ui/text-field';
import { WorkspaceLayout } from '@/layouts/workspace-layout';

export default function Settings({
    settings,
    dateFormat,
    tenant,
}: {
    settings: { timezone: string; locale: string };
    dateFormat: { value: string };
    tenant: { slug: string };
}) {
    const permissions =
        usePage<{ auth: { tenant: { permissions: string[] } | null } }>().props.auth.tenant?.permissions ??
        [];
    const canUpdate = permissions.includes('settings.update');
    const form = useForm({
        timezone: settings.timezone,
        locale: settings.locale,
        date_format: dateFormat.value ?? 'Y-m-d',
    });
    return (
        <WorkspaceLayout area="tenant" title="Institution settings">
            <PageHeader
                title="Institution settings"
                description="Locale, timezone, and date display settings are stored per institution and applied at presentation boundaries."
            />
            <form
                className="border-border bg-surface grid max-w-3xl gap-5 rounded-2xl border p-5 sm:p-7"
                onSubmit={(event) => {
                    event.preventDefault();
                    form.put(`/t/${tenant.slug}/settings`);
                }}
            >
                <FormErrors />
                {!canUpdate ? (
                    <p className="text-text-secondary text-sm">
                        These settings are read-only for your current institution membership.
                    </p>
                ) : null}
                <fieldset disabled={!canUpdate} className="grid gap-5">
                    <TextField
                        label="Timezone (IANA)"
                        name="timezone"
                        value={form.data.timezone}
                        onChange={(event) => form.setData('timezone', event.currentTarget.value)}
                        error={form.errors.timezone}
                        hint="For example, Asia/Karachi or Europe/London."
                    />
                    <TextField
                        label="Locale"
                        name="locale"
                        value={form.data.locale}
                        onChange={(event) => form.setData('locale', event.currentTarget.value)}
                        error={form.errors.locale}
                        hint="For example, en or en-GB."
                    />
                    <SelectField
                        label="Date format"
                        name="date_format"
                        options={[
                            { value: 'Y-m-d', label: '2026-10-22 (ISO)' },
                            { value: 'd/m/Y', label: '22/10/2026' },
                            { value: 'm/d/Y', label: '10/22/2026' },
                            { value: 'd M Y', label: '22 Oct 2026' },
                        ]}
                        value={form.data.date_format}
                        onChange={(event) => form.setData('date_format', event.currentTarget.value)}
                    />
                    <div className="border-border border-t pt-4">
                        {canUpdate ? (
                            <Button type="submit" loading={form.processing}>
                                Save settings
                            </Button>
                        ) : null}
                    </div>
                </fieldset>
            </form>
        </WorkspaceLayout>
    );
}
