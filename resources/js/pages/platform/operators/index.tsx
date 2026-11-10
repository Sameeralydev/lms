import { useForm } from '@inertiajs/react';
import { Button } from '@/components/ui/button';
import { FormErrors } from '@/components/ui/form-errors';
import { PageHeader } from '@/components/ui/page-header';
import { SelectField } from '@/components/ui/select-field';
import { TextField } from '@/components/ui/text-field';
import { WorkspaceLayout } from '@/layouts/workspace-layout';

type Operator = {
    id: number;
    name: string;
    email: string;
    platform_roles: { id: number; key: string; name: string }[];
};
export default function Operators({
    operators,
    roles,
}: {
    operators: { data: Operator[] };
    roles: { id: number; key: string; name: string }[];
}) {
    const form = useForm({ email: '', role_key: roles[0]?.key ?? '' });
    return (
        <WorkspaceLayout area="platform" title="Platform operators">
            <PageHeader
                title="Platform operators"
                description="Assign only the operations or support role to an existing account. Super administrator access is restricted to the interactive first-admin bootstrap command."
            />
            <form
                className="border-border bg-surface mb-6 grid gap-4 rounded-2xl border p-5 sm:grid-cols-[1fr_1fr_auto]"
                onSubmit={(event) => {
                    event.preventDefault();
                    form.post('/platform/operators');
                }}
            >
                <FormErrors />
                <TextField
                    label="Existing account email"
                    name="email"
                    type="email"
                    value={form.data.email}
                    onChange={(event) => form.setData('email', event.currentTarget.value)}
                    error={form.errors.email}
                />
                <SelectField
                    label="Platform role"
                    name="role_key"
                    options={roles.map((role) => ({ value: role.key, label: role.name }))}
                    value={form.data.role_key}
                    onChange={(event) => form.setData('role_key', event.currentTarget.value)}
                />
                <Button className="self-end" type="submit" loading={form.processing}>
                    Assign role
                </Button>
            </form>
            <section className="border-border bg-surface overflow-hidden rounded-2xl border">
                <div className="border-border border-b px-5 py-4">
                    <h2 className="font-semibold">Current operators</h2>
                </div>
                {operators.data.length ? (
                    <div className="divide-border divide-y">
                        {operators.data.map((operator) => (
                            <div
                                key={operator.id}
                                className="flex flex-wrap items-center justify-between gap-3 px-5 py-4"
                            >
                                <div>
                                    <p className="font-medium">{operator.name}</p>
                                    <p className="text-text-secondary mt-1 text-sm">{operator.email}</p>
                                </div>
                                <div className="flex flex-wrap gap-2">
                                    {operator.platform_roles.map((role) => (
                                        <span
                                            key={role.id}
                                            className="bg-surface-soft rounded-full px-3 py-1 text-xs"
                                        >
                                            {role.name}
                                        </span>
                                    ))}
                                </div>
                            </div>
                        ))}
                    </div>
                ) : (
                    <p className="text-text-secondary p-6 text-sm">No additional operators assigned.</p>
                )}
            </section>
        </WorkspaceLayout>
    );
}
