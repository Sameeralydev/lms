import { useForm, usePage } from '@inertiajs/react';
import { Button } from '@/components/ui/button';
import { FormErrors } from '@/components/ui/form-errors';
import { PageHeader } from '@/components/ui/page-header';
import { SelectField } from '@/components/ui/select-field';
import { TextField } from '@/components/ui/text-field';
import { WorkspaceLayout } from '@/layouts/workspace-layout';

type Branding = {
    display_name: string | null;
    primary_color: string;
    accent_color: string;
    theme: string;
    logo_path: string | null;
    favicon_path: string | null;
};
type Props = {
    branding: Branding;
    themes: Record<string, { name: string; description: string }>;
    auth: { tenant: { slug: string; permissions: string[] } };
};
export default function BrandingPage({ branding, themes }: { branding: Branding; themes: Props['themes'] }) {
    const tenant = usePage<Props>().props.auth.tenant;
    const canUpdate = tenant.permissions.includes('branding.update');
    const form = useForm({
        display_name: branding.display_name ?? '',
        primary_color: branding.primary_color,
        accent_color: branding.accent_color,
        theme: branding.theme,
        logo: null as File | null,
        favicon: null as File | null,
    });
    return (
        <WorkspaceLayout area="tenant" title="Branding & theme">
            <PageHeader
                title="Branding & theme"
                description="Institution branding and default theme are applied dynamically. Uploaded assets are validated raster images stored in private application storage and served only through the branding endpoint."
            />
            <form
                className="border-border bg-surface grid max-w-4xl gap-5 rounded-2xl border p-5 sm:p-7"
                encType="multipart/form-data"
                onSubmit={(event) => {
                    event.preventDefault();
                    form.put(`/t/${tenant.slug}/branding`);
                }}
            >
                <FormErrors />
                {!canUpdate ? (
                    <p className="text-text-secondary text-sm">
                        Branding is read-only for your current institution membership.
                    </p>
                ) : null}
                <fieldset disabled={!canUpdate} className="grid gap-5">
                    <TextField
                        label="Institution display name"
                        name="display_name"
                        value={form.data.display_name}
                        onChange={(event) => form.setData('display_name', event.currentTarget.value)}
                        error={form.errors.display_name}
                    />
                    <div className="grid gap-4 md:grid-cols-2">
                        <TextField
                            label="Primary brand color"
                            name="primary_color"
                            type="color"
                            value={form.data.primary_color}
                            onChange={(event) => form.setData('primary_color', event.currentTarget.value)}
                            error={form.errors.primary_color}
                        />
                        <TextField
                            label="Accent color"
                            name="accent_color"
                            type="color"
                            value={form.data.accent_color}
                            onChange={(event) => form.setData('accent_color', event.currentTarget.value)}
                            error={form.errors.accent_color}
                        />
                    </div>
                    <SelectField
                        label="Institution default theme"
                        name="theme"
                        options={Object.entries(themes).map(([value, item]) => ({ value, label: item.name }))}
                        value={form.data.theme}
                        onChange={(event) => form.setData('theme', event.currentTarget.value)}
                    />
                    <p className="text-text-secondary -mt-3 text-xs">
                        {themes[form.data.theme]?.description}
                    </p>
                    <div className="grid gap-4 md:grid-cols-2">
                        <label className="grid gap-2 text-sm font-medium">
                            Logo image{' '}
                            <input
                                className="border-border min-h-11 rounded-lg border p-2 text-sm"
                                type="file"
                                accept="image/png,image/jpeg,image/webp"
                                onChange={(event) =>
                                    form.setData('logo', event.currentTarget.files?.[0] ?? null)
                                }
                            />
                            <span className="text-text-muted text-xs">PNG, JPEG or WebP, up to 2 MB.</span>
                            {form.errors.logo ? (
                                <span role="alert" className="text-danger text-xs">
                                    {form.errors.logo}
                                </span>
                            ) : null}
                        </label>
                        <label className="grid gap-2 text-sm font-medium">
                            Favicon image{' '}
                            <input
                                className="border-border min-h-11 rounded-lg border p-2 text-sm"
                                type="file"
                                accept="image/png,image/jpeg,image/webp,image/x-icon"
                                onChange={(event) =>
                                    form.setData('favicon', event.currentTarget.files?.[0] ?? null)
                                }
                            />
                            <span className="text-text-muted text-xs">
                                PNG, JPEG, WebP or ICO, up to 1 MB.
                            </span>
                            {form.errors.favicon ? (
                                <span role="alert" className="text-danger text-xs">
                                    {form.errors.favicon}
                                </span>
                            ) : null}
                        </label>
                    </div>
                    <div className="flex flex-wrap gap-4">
                        {branding.logo_path ? (
                            <img
                                className="max-h-16 max-w-48 object-contain"
                                src={`/tenant-assets/${tenant.slug}/logo`}
                                alt={`${branding.display_name ?? 'Institution'} logo`}
                            />
                        ) : null}
                        {branding.favicon_path ? (
                            <img
                                className="size-10 object-contain"
                                src={`/tenant-assets/${tenant.slug}/favicon`}
                                alt="Current favicon"
                            />
                        ) : null}
                    </div>
                    <div className="border-border border-t pt-4">
                        {canUpdate ? (
                            <Button type="submit" loading={form.processing}>
                                Save branding
                            </Button>
                        ) : null}
                    </div>
                </fieldset>
            </form>
            <section className="border-border bg-surface mt-6 rounded-2xl border p-5">
                <h2 className="font-semibold">Six theme variants</h2>
                <ul className="mt-4 grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
                    {Object.entries(themes).map(([key, value]) => (
                        <li key={key} className="border-border rounded-xl border p-4">
                            <p className="font-medium">{value.name}</p>
                            <p className="text-text-secondary mt-1 text-sm">{value.description}</p>
                        </li>
                    ))}
                </ul>
                <p className="text-text-muted mt-4 text-xs">
                    Your user-level theme preference is available in the workspace header; the institution
                    default applies when no user preference is stored.
                </p>
            </section>
        </WorkspaceLayout>
    );
}
