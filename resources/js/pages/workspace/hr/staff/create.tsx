import { Head, Link, useForm } from '@inertiajs/react';
import { FormErrors } from '@/components/ui/form-errors';
import { PageHeader } from '@/components/ui/page-header';
import { WorkspaceLayout } from '@/layouts/workspace-layout';

type Option = { id: number; name: string; email?: string | null };
type Props = {
    tenant: { slug: string };
    members: Option[];
    campuses: Option[];
    departments: Option[];
    managers: Option[];
    canManageSensitive: boolean;
    fixedCampusId: number | null;
};

export default function StaffCreate({
    tenant,
    members,
    campuses,
    departments,
    managers,
    canManageSensitive,
    fixedCampusId,
}: Props) {
    const form = useForm({
        membership_id: '',
        employee_number: '',
        designation: '',
        employment_type: 'full_time',
        joined_on: new Date().toISOString().slice(0, 10),
        campus_id: fixedCampusId ? String(fixedCampusId) : '',
        academic_unit_id: '',
        manager_id: '',
        personal_email: '',
        personal_phone: '',
        emergency_contact_name: '',
        emergency_contact_phone: '',
    });
    const submit = (event: React.FormEvent) => {
        event.preventDefault();
        form.post(`/t/${tenant.slug}/hr/staff`);
    };
    return (
        <WorkspaceLayout title="Add staff record" area="tenant">
            <Head title="Add staff record" />
            <PageHeader
                eyebrow="Human resources"
                title="Add staff record"
                description="Link an existing active institution account to an employment record. This does not create or change sign-in credentials."
            />
            <form
                onSubmit={submit}
                className="border-border bg-surface mt-6 grid gap-5 rounded-2xl border p-5 sm:p-7"
            >
                <FormErrors errors={form.errors} />
                <div className="grid gap-4 md:grid-cols-2">
                    <div>
                        <label htmlFor="membership" className="mb-1 block text-sm font-medium">
                            Existing staff account
                        </label>
                        <select
                            id="membership"
                            required
                            value={form.data.membership_id}
                            onChange={(event) => form.setData('membership_id', event.target.value)}
                            className="border-border bg-surface min-h-11 w-full rounded-lg border px-3"
                        >
                            <option value="">Choose an active member</option>
                            {members.map((member) => (
                                <option key={member.id} value={member.id}>
                                    {member.name}
                                    {member.email ? ` · ${member.email}` : ''}
                                </option>
                            ))}
                        </select>
                        {members.length === 0 && (
                            <p className="text-text-muted mt-1 text-xs">
                                No eligible active accounts are available in your scope.
                            </p>
                        )}
                    </div>
                    <div>
                        <label htmlFor="employee-number" className="mb-1 block text-sm font-medium">
                            Employee number
                        </label>
                        <input
                            id="employee-number"
                            required
                            maxLength={48}
                            value={form.data.employee_number}
                            onChange={(event) => form.setData('employee_number', event.target.value)}
                            className="border-border bg-surface min-h-11 w-full rounded-lg border px-3"
                        />
                    </div>
                    <div>
                        <label htmlFor="designation" className="mb-1 block text-sm font-medium">
                            Designation
                        </label>
                        <input
                            id="designation"
                            required
                            maxLength={120}
                            value={form.data.designation}
                            onChange={(event) => form.setData('designation', event.target.value)}
                            className="border-border bg-surface min-h-11 w-full rounded-lg border px-3"
                        />
                    </div>
                    <div>
                        <label htmlFor="employment-type" className="mb-1 block text-sm font-medium">
                            Employment type
                        </label>
                        <select
                            id="employment-type"
                            value={form.data.employment_type}
                            onChange={(event) => form.setData('employment_type', event.target.value)}
                            className="border-border bg-surface min-h-11 w-full rounded-lg border px-3"
                        >
                            {[
                                ['full_time', 'Full time'],
                                ['part_time', 'Part time'],
                                ['contract', 'Contract'],
                                ['temporary', 'Temporary'],
                            ].map(([value, label]) => (
                                <option key={value} value={value}>
                                    {label}
                                </option>
                            ))}
                        </select>
                    </div>
                    <div>
                        <label htmlFor="joined-on" className="mb-1 block text-sm font-medium">
                            Date joined
                        </label>
                        <input
                            id="joined-on"
                            type="date"
                            required
                            value={form.data.joined_on}
                            onChange={(event) => form.setData('joined_on', event.target.value)}
                            className="border-border bg-surface min-h-11 w-full rounded-lg border px-3"
                        />
                    </div>
                    <div>
                        <label htmlFor="campus" className="mb-1 block text-sm font-medium">
                            Campus
                        </label>
                        <select
                            id="campus"
                            value={form.data.campus_id}
                            onChange={(event) => form.setData('campus_id', event.target.value)}
                            disabled={fixedCampusId !== null}
                            className="border-border bg-surface min-h-11 w-full rounded-lg border px-3"
                        >
                            <option value="">Institution-wide</option>
                            {campuses.map((campus) => (
                                <option key={campus.id} value={campus.id}>
                                    {campus.name}
                                </option>
                            ))}
                        </select>
                    </div>
                    <div>
                        <label htmlFor="department" className="mb-1 block text-sm font-medium">
                            Department / academic unit
                        </label>
                        <select
                            id="department"
                            value={form.data.academic_unit_id}
                            onChange={(event) => form.setData('academic_unit_id', event.target.value)}
                            className="border-border bg-surface min-h-11 w-full rounded-lg border px-3"
                        >
                            <option value="">No department</option>
                            {departments.map((department) => (
                                <option key={department.id} value={department.id}>
                                    {department.name}
                                </option>
                            ))}
                        </select>
                    </div>
                    <div>
                        <label htmlFor="manager" className="mb-1 block text-sm font-medium">
                            Reporting manager
                        </label>
                        <select
                            id="manager"
                            value={form.data.manager_id}
                            onChange={(event) => form.setData('manager_id', event.target.value)}
                            className="border-border bg-surface min-h-11 w-full rounded-lg border px-3"
                        >
                            <option value="">No manager assigned</option>
                            {managers.map((manager) => (
                                <option key={manager.id} value={manager.id}>
                                    {manager.name}
                                </option>
                            ))}
                        </select>
                    </div>
                </div>
                {canManageSensitive && (
                    <fieldset className="border-border rounded-xl border p-4">
                        <legend className="px-2 text-sm font-semibold">Restricted contact details</legend>
                        <p className="text-text-muted mb-4 text-xs">
                            Encrypted at rest and visible only to users with sensitive HR access.
                        </p>
                        <div className="grid gap-4 md:grid-cols-2">
                            {(
                                [
                                    ['personal_email', 'Personal email', 'email'],
                                    ['personal_phone', 'Personal phone', 'tel'],
                                    ['emergency_contact_name', 'Emergency contact name', 'text'],
                                    ['emergency_contact_phone', 'Emergency contact phone', 'tel'],
                                ] as const
                            ).map(([key, label, type]) => (
                                <div key={key}>
                                    <label htmlFor={key} className="mb-1 block text-sm font-medium">
                                        {label}
                                    </label>
                                    <input
                                        id={key}
                                        type={type}
                                        value={form.data[key]}
                                        onChange={(event) => form.setData(key, event.target.value)}
                                        className="border-border bg-surface min-h-11 w-full rounded-lg border px-3"
                                    />
                                </div>
                            ))}
                        </div>
                    </fieldset>
                )}
                <div className="flex flex-wrap gap-3">
                    <button
                        type="submit"
                        disabled={form.processing || members.length === 0}
                        className="bg-brand-700 focus-ring min-h-11 rounded-lg px-4 text-sm font-semibold text-white disabled:opacity-60"
                    >
                        {form.processing ? 'Saving…' : 'Create staff record'}
                    </button>
                    <Link
                        href={`/t/${tenant.slug}/hr/staff`}
                        className="border-border inline-flex min-h-11 items-center rounded-lg border px-4 text-sm font-medium"
                    >
                        Cancel
                    </Link>
                </div>
            </form>
        </WorkspaceLayout>
    );
}
