import { useForm, usePage } from '@inertiajs/react';
import { useState } from 'react';
import { Button } from '@/components/ui/button';
import { FormErrors } from '@/components/ui/form-errors';
import { PageHeader } from '@/components/ui/page-header';
import { TextField } from '@/components/ui/text-field';
import { WorkspaceLayout } from '@/layouts/workspace-layout';

type Permission = { id: number; key: string; description: string };
type Role = {
    id: number;
    key: string;
    name: string;
    description: string | null;
    is_system: boolean;
    permissions: Permission[];
};
type SharedProps = { auth: { tenant: { permissions: string[] } | null } };
export default function Roles({
    roles,
    permissions,
    tenant,
}: {
    roles: Role[];
    permissions: Permission[];
    tenant: { slug: string };
}) {
    const permissionsHeld = usePage<SharedProps>().props.auth.tenant?.permissions ?? [];
    const canManage = permissionsHeld.includes('roles.manage');
    const [selected, setSelected] = useState<number[]>([]);
    const form = useForm({ key: '', name: '', description: '', permissions: selected as number[] });
    const toggle = (id: number) => {
        const next = selected.includes(id) ? selected.filter((item) => item !== id) : [...selected, id];
        setSelected(next);
        form.setData('permissions', next);
    };
    return (
        <WorkspaceLayout area="tenant" title="Roles & permissions">
            <PageHeader
                title="Roles & permissions"
                description="System roles are stable templates. Custom roles may include only permissions the current administrator already holds; unimplemented business capabilities have no grants."
            />
            <div className="grid gap-6 xl:grid-cols-[minmax(0,1fr)_minmax(20rem,0.8fr)]">
                <section className="grid content-start gap-3">
                    {roles.map((role) => (
                        <article key={role.id} className="border-border bg-surface rounded-2xl border p-5">
                            <div className="flex flex-wrap items-start justify-between gap-3">
                                <div>
                                    <h2 className="font-semibold">{role.name}</h2>
                                    <p className="text-text-secondary mt-1 text-xs">
                                        {role.key} · {role.is_system ? 'System template' : 'Institution role'}
                                    </p>
                                </div>
                                <span className="bg-surface-soft rounded-full px-2.5 py-1 text-xs">
                                    {role.permissions.length} permissions
                                </span>
                            </div>
                            <p className="text-text-secondary mt-3 text-sm">
                                {role.description ?? 'Custom institution role.'}
                            </p>
                            {role.permissions.length ? (
                                <ul className="mt-4 flex flex-wrap gap-2">
                                    {role.permissions.map((permission) => (
                                        <li
                                            key={permission.id}
                                            className="bg-brand-blue-soft text-brand-link rounded-md px-2 py-1 text-xs"
                                        >
                                            {permission.key}
                                        </li>
                                    ))}
                                </ul>
                            ) : (
                                <p className="text-text-muted mt-4 text-xs">No permissions are assigned.</p>
                            )}
                        </article>
                    ))}
                </section>
                {canManage ? (
                    <form
                        className="border-border bg-surface grid content-start gap-4 rounded-2xl border p-5"
                        onSubmit={(event) => {
                            event.preventDefault();
                            form.post(`/t/${tenant.slug}/roles`, {
                                onSuccess: () => {
                                    form.reset();
                                    setSelected([]);
                                },
                            });
                        }}
                    >
                        <div>
                            <h2 className="font-semibold">Create a custom role</h2>
                            <p className="text-text-secondary mt-1 text-sm">
                                The server rejects permission escalation.
                            </p>
                        </div>
                        <FormErrors />
                        <TextField
                            label="Role key"
                            name="key"
                            placeholder="campus-coordinator"
                            value={form.data.key}
                            onChange={(event) => form.setData('key', event.currentTarget.value)}
                            error={form.errors.key}
                        />
                        <TextField
                            label="Role name"
                            name="name"
                            value={form.data.name}
                            onChange={(event) => form.setData('name', event.currentTarget.value)}
                            error={form.errors.name}
                        />
                        <TextField
                            label="Description"
                            name="description"
                            value={form.data.description}
                            onChange={(event) => form.setData('description', event.currentTarget.value)}
                        />
                        <fieldset className="grid gap-2">
                            <legend className="text-sm font-medium">Available permissions</legend>
                            {permissions.map((permission) => (
                                <label
                                    key={permission.id}
                                    className="hover:bg-surface-soft flex gap-2 rounded-lg p-2"
                                >
                                    <input
                                        type="checkbox"
                                        checked={selected.includes(permission.id)}
                                        onChange={() => toggle(permission.id)}
                                    />
                                    <span>
                                        <span className="block text-xs font-semibold">{permission.key}</span>
                                        <span className="text-text-secondary block text-xs">
                                            {permission.description}
                                        </span>
                                    </span>
                                </label>
                            ))}
                        </fieldset>
                        <Button type="submit" loading={form.processing}>
                            Create role
                        </Button>
                    </form>
                ) : (
                    <p className="border-border bg-surface text-text-secondary rounded-2xl border p-5 text-sm">
                        Role definitions are read-only for your current institution membership.
                    </p>
                )}
            </div>
        </WorkspaceLayout>
    );
}
