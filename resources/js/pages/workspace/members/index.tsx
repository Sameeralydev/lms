import { router, useForm, usePage } from '@inertiajs/react';
import { Button } from '@/components/ui/button';
import { FormErrors } from '@/components/ui/form-errors';
import { PageHeader } from '@/components/ui/page-header';
import { SelectField } from '@/components/ui/select-field';
import { TextField } from '@/components/ui/text-field';
import { WorkspaceLayout } from '@/layouts/workspace-layout';

type Role = { id: number; key: string; name: string; tenant_id: number | null };
type Member = {
    id: number;
    status: string;
    campus_id: number | null;
    user: { name: string; email: string };
    campus?: { name: string } | null;
    roles: Role[];
};
type Invitation = { id: number; email: string; role_key: string; expires_at: string; created_at: string };
type SharedProps = { auth: { tenant: { permissions: string[] } | null } };
export default function Members({
    memberships,
    invitations,
    roles,
    campuses,
    tenant,
}: {
    memberships: { data: Member[] };
    invitations: Invitation[];
    roles: Role[];
    campuses: { id: number; name: string }[];
    tenant: { slug: string };
}) {
    const permissions = usePage<SharedProps>().props.auth.tenant?.permissions ?? [];
    const canInvite = permissions.includes('members.invite');
    const canManage = permissions.includes('members.manage');
    const form = useForm({
        email: '',
        role_key: roles.find((role) => role.key === 'tenant.admin')?.key ?? roles[0]?.key ?? '',
        campus_id: '',
    });
    const roleOptions = roles.map((role) => ({ value: role.key, label: role.name }));
    return (
        <WorkspaceLayout area="tenant" title="Members">
            <PageHeader
                title="Members & invitations"
                description="Memberships grant access to this institution. Role permissions are enforced on the server and are scoped to this tenant."
            />
            {canInvite ? (
                <form
                    className="border-border bg-surface mb-6 grid gap-4 rounded-2xl border p-5 lg:grid-cols-[1fr_1fr_1fr_auto]"
                    onSubmit={(event) => {
                        event.preventDefault();
                        form.post(`/t/${tenant.slug}/members/invitations`, {
                            onSuccess: () => form.reset('email', 'campus_id'),
                        });
                    }}
                >
                    <div className="lg:col-span-4">
                        <h2 className="font-semibold">Invite a member</h2>
                        <p className="text-text-secondary mt-1 text-sm">
                            An expiring link is sent through the configured mail transport. The link is not
                            displayed in the response.
                        </p>
                    </div>
                    <FormErrors />
                    <TextField
                        label="Email address"
                        name="email"
                        type="email"
                        autoComplete="email"
                        value={form.data.email}
                        onChange={(event) => form.setData('email', event.currentTarget.value)}
                        error={form.errors.email}
                    />
                    <SelectField
                        label="Institution role"
                        name="role_key"
                        options={roleOptions}
                        value={form.data.role_key}
                        onChange={(event) => form.setData('role_key', event.currentTarget.value)}
                    />
                    <SelectField
                        label="Campus scope"
                        name="campus_id"
                        options={[
                            { value: '', label: 'All institution campuses' },
                            ...campuses.map((campus) => ({ value: String(campus.id), label: campus.name })),
                        ]}
                        value={form.data.campus_id}
                        onChange={(event) => form.setData('campus_id', event.currentTarget.value)}
                    />
                    <Button className="self-end" type="submit" loading={form.processing}>
                        Send invitation
                    </Button>
                </form>
            ) : (
                <p className="border-border bg-surface text-text-secondary mb-6 rounded-xl border p-4 text-sm">
                    You can review institution members. Invitation privileges are restricted to authorized
                    institution administrators.
                </p>
            )}
            <section className="border-border bg-surface mb-6 overflow-hidden rounded-2xl border">
                <div className="border-border border-b px-5 py-4">
                    <h2 className="font-semibold">Institution memberships</h2>
                </div>
                {memberships.data.length ? (
                    <div className="overflow-x-auto">
                        <table className="w-full min-w-[740px] text-left text-sm">
                            <thead className="bg-surface-soft text-text-secondary">
                                <tr>
                                    <th className="px-5 py-3 font-medium">Member</th>
                                    <th className="px-5 py-3 font-medium">Campus scope</th>
                                    <th className="px-5 py-3 font-medium">Role</th>
                                    <th className="px-5 py-3 font-medium">Status</th>
                                    <th className="px-5 py-3" />
                                </tr>
                            </thead>
                            <tbody className="divide-border divide-y">
                                {memberships.data.map((member) => (
                                    <MemberRow
                                        key={member.id}
                                        member={member}
                                        roles={roles}
                                        campuses={campuses}
                                        slug={tenant.slug}
                                        canManage={canManage}
                                    />
                                ))}
                            </tbody>
                        </table>
                    </div>
                ) : (
                    <p className="text-text-secondary p-6 text-sm">No memberships yet.</p>
                )}
            </section>
            <section className="border-border bg-surface overflow-hidden rounded-2xl border">
                <div className="border-border border-b px-5 py-4">
                    <h2 className="font-semibold">Pending invitations</h2>
                </div>
                {invitations.length ? (
                    <div className="divide-border divide-y">
                        {invitations.map((invitation) => (
                            <div
                                key={invitation.id}
                                className="flex flex-wrap items-center justify-between gap-3 px-5 py-4"
                            >
                                <div>
                                    <p className="text-sm font-medium">{invitation.email}</p>
                                    <p className="text-text-secondary mt-1 text-xs">
                                        {invitation.role_key} · Expires{' '}
                                        {new Date(invitation.expires_at).toLocaleString()}
                                    </p>
                                </div>
                                {canManage ? (
                                    <button
                                        type="button"
                                        className="border-border min-h-10 rounded-lg border px-3 text-xs font-semibold"
                                        onClick={() =>
                                            router.post(
                                                `/t/${tenant.slug}/invitations/${invitation.id}/cancel`,
                                            )
                                        }
                                    >
                                        Cancel
                                    </button>
                                ) : null}
                            </div>
                        ))}
                    </div>
                ) : (
                    <p className="text-text-secondary p-6 text-sm">No pending invitations.</p>
                )}
            </section>
        </WorkspaceLayout>
    );
}
function MemberRow({
    member,
    roles,
    campuses,
    slug,
    canManage,
}: {
    member: Member;
    roles: Role[];
    campuses: { id: number; name: string }[];
    slug: string;
    canManage: boolean;
}) {
    const form = useForm({
        role_key: member.roles[0]?.key ?? '',
        campus_id: member.campus_id ? String(member.campus_id) : '',
    });
    return (
        <tr>
            <td className="px-5 py-4">
                <p className="font-medium">{member.user.name}</p>
                <p className="text-text-secondary mt-1 text-xs">{member.user.email}</p>
            </td>
            <td className="text-text-secondary px-5 py-4">{member.campus?.name ?? 'Institution-wide'}</td>
            <td className="px-5 py-4">
                {canManage && member.status === 'active' ? (
                    <form
                        className="flex flex-wrap items-end gap-2"
                        onSubmit={(event) => {
                            event.preventDefault();
                            form.put(`/t/${slug}/members/${member.id}/role`);
                        }}
                    >
                        <label className="sr-only" htmlFor={`role-${member.id}`}>
                            Role for {member.user.name}
                        </label>
                        <select
                            id={`role-${member.id}`}
                            className="border-border bg-surface min-h-10 max-w-48 rounded-lg border px-2 text-xs"
                            value={form.data.role_key}
                            onChange={(event) => form.setData('role_key', event.currentTarget.value)}
                        >
                            {roles.map((role) => (
                                <option key={role.id} value={role.key}>
                                    {role.name}
                                </option>
                            ))}
                        </select>
                        <label className="sr-only" htmlFor={`campus-${member.id}`}>
                            Campus scope for {member.user.name}
                        </label>
                        <select
                            id={`campus-${member.id}`}
                            className="border-border bg-surface min-h-10 max-w-40 rounded-lg border px-2 text-xs"
                            value={form.data.campus_id}
                            onChange={(event) => form.setData('campus_id', event.currentTarget.value)}
                        >
                            <option value="">All campuses</option>
                            {campuses.map((campus) => (
                                <option key={campus.id} value={String(campus.id)}>
                                    {campus.name}
                                </option>
                            ))}
                        </select>
                        <Button
                            variant="secondary"
                            className="min-h-10 px-3 py-1 text-xs"
                            type="submit"
                            loading={form.processing}
                        >
                            Save
                        </Button>
                    </form>
                ) : (
                    <span>{member.roles.map((role) => role.name).join(', ') || 'No role assigned'}</span>
                )}
            </td>
            <td className="px-5 py-4">
                <span className="capitalize">{member.status}</span>
                {member.status === 'suspended' && (
                    <p className="text-text-muted mt-1 max-w-56 text-xs">
                        Reactivation follows the linked student record status policy.
                    </p>
                )}
            </td>
            <td className="px-5 py-4">
                {canManage && ['active', 'suspended'].includes(member.status) ? (
                    <button
                        type="button"
                        className="text-xs font-semibold text-red-800 hover:underline"
                        onClick={() => router.post(`/t/${slug}/members/${member.id}/revoke`)}
                    >
                        Revoke
                    </button>
                ) : (
                    '—'
                )}
            </td>
        </tr>
    );
}
