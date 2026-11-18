import { Head, Link, useForm } from '@inertiajs/react';
import { FormErrors } from '@/components/ui/form-errors';
import { PageHeader } from '@/components/ui/page-header';
import { WorkspaceLayout } from '@/layouts/workspace-layout';

type History = {
    id: number;
    event_type: string;
    from_status: string | null;
    to_status: string | null;
    from_campus_id: number | null;
    to_campus_id: number | null;
    from_academic_unit_id: number | null;
    to_academic_unit_id: number | null;
    from_designation: string | null;
    to_designation: string | null;
    effective_on: string;
    reason: string | null;
    recorded_by: string | null;
};
type Staff = {
    id: number;
    employee_number: string;
    name: string | null;
    email: string | null;
    designation: string;
    employment_type: string;
    status: string;
    joined_on: string | null;
    campus_id: number | null;
    campus: string | null;
    academic_unit_id: number | null;
    department: string | null;
    membership_id: number;
    institution_id: number;
    manager_id: number | null;
    manager_name: string | null;
    separated_on: string | null;
    personal_email: string | null;
    personal_phone: string | null;
    emergency_contact_name: string | null;
    emergency_contact_phone: string | null;
    history: History[];
};
type Option = { id: number; name: string };
type Props = {
    tenant: { slug: string };
    staff: Staff;
    canManage: boolean;
    canManageStatus: boolean;
    canManageSensitive: boolean;
    campuses: Option[];
    departments: Option[];
    managers: Option[];
};

export default function StaffShow({
    tenant,
    staff,
    canManage,
    canManageStatus,
    canManageSensitive,
    campuses,
    departments,
    managers,
}: Props) {
    const form = useForm({
        designation: staff.designation,
        employment_type: staff.employment_type,
        campus_id: staff.campus_id ? String(staff.campus_id) : '',
        academic_unit_id: staff.academic_unit_id ? String(staff.academic_unit_id) : '',
        manager_id: staff.manager_id ? String(staff.manager_id) : '',
        personal_email: staff.personal_email ?? '',
        personal_phone: staff.personal_phone ?? '',
        emergency_contact_name: staff.emergency_contact_name ?? '',
        emergency_contact_phone: staff.emergency_contact_phone ?? '',
    });
    const statusForm = useForm({
        status: staff.status === 'separated' ? 'active' : 'inactive',
        effective_on: new Date().toISOString().slice(0, 10),
        reason: '',
    });
    const update = (event: React.FormEvent) => {
        event.preventDefault();
        form.put(`/t/${tenant.slug}/hr/staff/${staff.id}`, { preserveScroll: true });
    };
    const changeStatus = (event: React.FormEvent) => {
        event.preventDefault();
        statusForm.patch(`/t/${tenant.slug}/hr/staff/${staff.id}/status`, { preserveScroll: true });
    };
    return (
        <WorkspaceLayout title="Staff record" area="tenant">
            <Head title={`${staff.name ?? staff.employee_number} · Staff record`} />
            <PageHeader
                eyebrow="Human resources"
                title={staff.name ?? 'Staff record'}
                description={`${staff.employee_number} · ${staff.designation} · ${staff.campus ?? 'Institution-wide'}`}
            />
            <div className="mt-5">
                <Link
                    href={`/t/${tenant.slug}/hr/staff`}
                    className="text-brand-700 focus-ring rounded text-sm font-semibold underline"
                >
                    Back to staff directory
                </Link>
            </div>
            <div className="mt-5 grid gap-5 xl:grid-cols-[minmax(0,1.2fr)_minmax(18rem,.8fr)]">
                <section
                    className="border-border bg-surface rounded-2xl border p-5 sm:p-6"
                    aria-labelledby="employment-details-title"
                >
                    <div className="flex flex-wrap items-center justify-between gap-3">
                        <h2 id="employment-details-title" className="text-lg font-semibold">
                            Employment details
                        </h2>
                        <span className="bg-surface-soft rounded-full px-3 py-1 text-sm font-medium capitalize">
                            {staff.status}
                        </span>
                    </div>
                    <dl className="mt-4 grid gap-4 sm:grid-cols-2">
                        <div>
                            <dt className="text-text-muted text-sm">Account</dt>
                            <dd className="mt-1 font-medium">
                                {staff.name ?? 'Staff account'}
                                {staff.email ? (
                                    <span className="text-text-muted block text-sm">{staff.email}</span>
                                ) : null}
                            </dd>
                        </div>
                        <div>
                            <dt className="text-text-muted text-sm">Employee number</dt>
                            <dd className="mt-1 font-medium">{staff.employee_number}</dd>
                        </div>
                        <div>
                            <dt className="text-text-muted text-sm">Employment type</dt>
                            <dd className="mt-1 font-medium capitalize">
                                {staff.employment_type.replace('_', ' ')}
                            </dd>
                        </div>
                        <div>
                            <dt className="text-text-muted text-sm">Joined</dt>
                            <dd className="mt-1 font-medium">{staff.joined_on ?? '—'}</dd>
                        </div>
                        <div>
                            <dt className="text-text-muted text-sm">Department</dt>
                            <dd className="mt-1 font-medium">{staff.department ?? 'Not assigned'}</dd>
                        </div>
                        <div>
                            <dt className="text-text-muted text-sm">Reporting manager</dt>
                            <dd className="mt-1 font-medium">{staff.manager_name ?? 'Not assigned'}</dd>
                        </div>
                        {staff.separated_on && (
                            <div>
                                <dt className="text-text-muted text-sm">Separated on</dt>
                                <dd className="mt-1 font-medium">{staff.separated_on}</dd>
                            </div>
                        )}
                    </dl>
                    {(staff.personal_email ||
                        staff.personal_phone ||
                        staff.emergency_contact_name ||
                        staff.emergency_contact_phone) && (
                        <div className="border-border mt-6 border-t pt-5">
                            <h3 className="font-semibold">Restricted contact details</h3>
                            <dl className="mt-3 grid gap-3 sm:grid-cols-2">
                                {[
                                    ['Personal email', staff.personal_email],
                                    ['Personal phone', staff.personal_phone],
                                    ['Emergency contact', staff.emergency_contact_name],
                                    ['Emergency phone', staff.emergency_contact_phone],
                                ]
                                    .filter((item) => item[1])
                                    .map(([label, value]) => (
                                        <div key={label}>
                                            <dt className="text-text-muted text-xs">{label}</dt>
                                            <dd className="mt-1 text-sm">{value}</dd>
                                        </div>
                                    ))}
                            </dl>
                        </div>
                    )}
                </section>
                {canManage && (
                    <section
                        className="border-border bg-surface rounded-2xl border p-5 sm:p-6"
                        aria-labelledby="edit-staff-title"
                    >
                        <h2 id="edit-staff-title" className="text-lg font-semibold">
                            Update staff record
                        </h2>
                        <form onSubmit={update} className="mt-4 grid gap-4">
                            <FormErrors errors={form.errors} />
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
                                <label htmlFor="campus" className="mb-1 block text-sm font-medium">
                                    Campus
                                </label>
                                <select
                                    id="campus"
                                    value={form.data.campus_id}
                                    onChange={(event) => form.setData('campus_id', event.target.value)}
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
                                    <option value="">No manager</option>
                                    {managers.map((manager) => (
                                        <option key={manager.id} value={manager.id}>
                                            {manager.name}
                                        </option>
                                    ))}
                                </select>
                            </div>
                            {canManageSensitive && (
                                <fieldset className="border-border grid gap-3 rounded-xl border p-4">
                                    <legend className="px-2 text-sm font-semibold">
                                        Sensitive contact details
                                    </legend>
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
                                </fieldset>
                            )}
                            <button
                                type="submit"
                                disabled={form.processing}
                                className="bg-brand-700 focus-ring min-h-11 rounded-lg px-4 text-sm font-semibold text-white disabled:opacity-60"
                            >
                                {form.processing ? 'Saving…' : 'Save changes'}
                            </button>
                        </form>
                    </section>
                )}
                {canManageStatus && (
                    <section
                        className="border-border bg-surface rounded-2xl border p-5 sm:p-6"
                        aria-labelledby="status-title"
                    >
                        <h2 id="status-title" className="text-lg font-semibold">
                            Employment status
                        </h2>
                        <p className="text-text-muted mt-1 text-sm">
                            Status changes are recorded with an effective date and reason. Sign-in access is
                            managed separately through institution membership.
                        </p>
                        <form onSubmit={changeStatus} className="mt-4 grid gap-4">
                            <FormErrors errors={statusForm.errors} />
                            <div>
                                <label htmlFor="new-status" className="mb-1 block text-sm font-medium">
                                    New status
                                </label>
                                <select
                                    id="new-status"
                                    value={statusForm.data.status}
                                    onChange={(event) => statusForm.setData('status', event.target.value)}
                                    className="border-border bg-surface min-h-11 w-full rounded-lg border px-3"
                                >
                                    {(staff.status === 'separated'
                                        ? [['active', 'Active (reactivation)']]
                                        : [
                                              ['active', 'Active'],
                                              ['inactive', 'Inactive'],
                                              ['suspended', 'Suspended'],
                                              ['separated', 'Separated'],
                                          ]
                                    )
                                        .filter(([value]) => value !== staff.status)
                                        .map(([value, label]) => (
                                            <option key={value} value={value}>
                                                {label}
                                            </option>
                                        ))}
                                </select>
                            </div>
                            <div>
                                <label htmlFor="effective-on" className="mb-1 block text-sm font-medium">
                                    Effective date
                                </label>
                                <input
                                    id="effective-on"
                                    type="date"
                                    required
                                    value={statusForm.data.effective_on}
                                    onChange={(event) =>
                                        statusForm.setData('effective_on', event.target.value)
                                    }
                                    className="border-border bg-surface min-h-11 w-full rounded-lg border px-3"
                                />
                            </div>
                            <div>
                                <label htmlFor="reason" className="mb-1 block text-sm font-medium">
                                    Reason
                                </label>
                                <textarea
                                    id="reason"
                                    maxLength={500}
                                    rows={3}
                                    value={statusForm.data.reason}
                                    onChange={(event) => statusForm.setData('reason', event.target.value)}
                                    className="border-border bg-surface w-full rounded-lg border px-3 py-2"
                                />
                            </div>
                            <button
                                type="submit"
                                disabled={statusForm.processing}
                                className="border-border min-h-11 rounded-lg border px-4 text-sm font-semibold disabled:opacity-60"
                            >
                                {statusForm.processing ? 'Saving…' : 'Record status change'}
                            </button>
                        </form>
                    </section>
                )}
            </div>
            <section className="mt-6" aria-labelledby="employment-history-title">
                <h2 id="employment-history-title" className="text-lg font-semibold">
                    Employment history
                </h2>
                {staff.history.length === 0 ? (
                    <p className="text-text-muted border-border bg-surface mt-3 rounded-xl border p-5 text-sm">
                        No history is available.
                    </p>
                ) : (
                    <ol className="mt-3 grid gap-3">
                        {staff.history.map((event) => (
                            <li key={event.id} className="border-border bg-surface rounded-xl border p-4">
                                <div className="flex flex-wrap justify-between gap-2">
                                    <h3 className="font-semibold capitalize">
                                        {event.event_type.replace('_', ' ')}
                                    </h3>
                                    <time className="text-text-muted text-sm" dateTime={event.effective_on}>
                                        {event.effective_on}
                                    </time>
                                </div>
                                <p className="text-text-muted mt-2 text-sm">
                                    {event.from_status && event.to_status
                                        ? `${event.from_status} → ${event.to_status}`
                                        : (event.to_designation ?? 'Employment record created')}
                                    {event.recorded_by ? ` · Recorded by ${event.recorded_by}` : ''}
                                </p>
                                {event.reason && <p className="mt-2 text-sm">{event.reason}</p>}
                            </li>
                        ))}
                    </ol>
                )}
            </section>
        </WorkspaceLayout>
    );
}
