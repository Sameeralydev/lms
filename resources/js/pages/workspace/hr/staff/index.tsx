import { Head, Link, router } from '@inertiajs/react';
import { useState } from 'react';
import { PageHeader } from '@/components/ui/page-header';
import { WorkspaceLayout } from '@/layouts/workspace-layout';

type Staff = {
    id: number;
    employee_number: string;
    name: string | null;
    email: string | null;
    designation: string;
    employment_type: string;
    status: string;
    joined_on: string | null;
    campus: string | null;
    department: string | null;
};
type Props = {
    tenant: { slug: string };
    staff: {
        data: Staff[];
        current_page: number;
        last_page: number;
        total: number;
        links: { url: string | null; label: string; active: boolean }[];
    };
    filters: { q: string; status: string; campus_id: string | number };
    canManage: boolean;
    campuses: { id: number; name: string }[];
    stats: { total: number; active: number; inactive_or_suspended: number };
};

export default function StaffIndex({ tenant, staff, filters, canManage, campuses, stats }: Props) {
    const [q, setQ] = useState(filters.q);
    const [status, setStatus] = useState(filters.status);
    const [campusId, setCampusId] = useState(String(filters.campus_id ?? ''));
    const applyFilters = (event: React.FormEvent) => {
        event.preventDefault();
        router.get(
            `/t/${tenant.slug}/hr/staff`,
            { q, status, campus_id: campusId },
            { preserveState: true, preserveScroll: true, replace: true },
        );
    };
    return (
        <WorkspaceLayout title="Staff directory" area="tenant">
            <Head title="Staff directory" />
            <PageHeader
                eyebrow="Human resources"
                title="Staff directory"
                description="Manage employment records for existing institution members. Staff details and actions follow your tenant and campus permissions."
            />
            <div className="mt-6 grid gap-3 sm:grid-cols-3">
                {[
                    ['Staff in view', stats.total],
                    ['Active staff', stats.active],
                    ['Inactive or suspended', stats.inactive_or_suspended],
                ].map(([label, value]) => (
                    <div key={label} className="border-border bg-surface rounded-xl border p-4">
                        <p className="text-text-muted text-sm">{label}</p>
                        <p className="mt-2 text-2xl font-semibold">{value}</p>
                    </div>
                ))}
            </div>
            <section
                className="border-border bg-surface mt-5 rounded-2xl border p-4 sm:p-5"
                aria-label="Staff search and filters"
            >
                <form
                    onSubmit={applyFilters}
                    className="grid gap-3 md:grid-cols-[minmax(12rem,1fr)_12rem_12rem_auto_auto] md:items-end"
                >
                    <div>
                        <label htmlFor="staff-search" className="mb-1 block text-sm font-medium">
                            Search
                        </label>
                        <input
                            id="staff-search"
                            value={q}
                            onChange={(event) => setQ(event.target.value)}
                            placeholder="Name, staff number, designation"
                            className="border-border bg-surface min-h-11 w-full rounded-lg border px-3"
                        />
                    </div>
                    <div>
                        <label htmlFor="staff-status" className="mb-1 block text-sm font-medium">
                            Status
                        </label>
                        <select
                            id="staff-status"
                            value={status}
                            onChange={(event) => setStatus(event.target.value)}
                            className="border-border bg-surface min-h-11 w-full rounded-lg border px-3"
                        >
                            <option value="">All statuses</option>
                            {['active', 'inactive', 'suspended', 'separated'].map((item) => (
                                <option key={item} value={item}>
                                    {item.replace('_', ' ')}
                                </option>
                            ))}
                        </select>
                    </div>
                    {campuses.length > 0 && (
                        <div>
                            <label htmlFor="staff-campus" className="mb-1 block text-sm font-medium">
                                Campus
                            </label>
                            <select
                                id="staff-campus"
                                value={campusId}
                                onChange={(event) => setCampusId(event.target.value)}
                                className="border-border bg-surface min-h-11 w-full rounded-lg border px-3"
                            >
                                <option value="">All campuses</option>
                                {campuses.map((campus) => (
                                    <option key={campus.id} value={campus.id}>
                                        {campus.name}
                                    </option>
                                ))}
                            </select>
                        </div>
                    )}
                    <button
                        type="submit"
                        className="bg-brand-700 focus-ring min-h-11 rounded-lg px-4 text-sm font-semibold text-white"
                    >
                        Apply
                    </button>
                    {canManage && (
                        <Link
                            href={`/t/${tenant.slug}/hr/staff/create`}
                            className="border-border focus-ring inline-flex min-h-11 items-center justify-center rounded-lg border px-4 text-sm font-semibold"
                        >
                            Add staff
                        </Link>
                    )}
                </form>
            </section>
            <div className="border-border bg-surface mt-4 overflow-hidden rounded-2xl border">
                {staff.data.length === 0 ? (
                    <div className="p-10 text-center">
                        <h2 className="font-semibold">No staff records found</h2>
                        <p className="text-text-muted mt-2 text-sm">
                            Try a different filter or add an employment record for an existing staff account.
                        </p>
                    </div>
                ) : (
                    <div className="overflow-x-auto">
                        <table className="w-full min-w-[760px] text-left text-sm">
                            <thead className="bg-surface-soft text-text-muted">
                                <tr>
                                    {[
                                        'Staff member',
                                        'Position',
                                        'Department / campus',
                                        'Employment',
                                        'Joined',
                                    ].map((label) => (
                                        <th key={label} scope="col" className="px-4 py-3 font-semibold">
                                            {label}
                                        </th>
                                    ))}
                                </tr>
                            </thead>
                            <tbody className="divide-border divide-y">
                                {staff.data.map((person) => (
                                    <tr key={person.id} className="hover:bg-surface-soft/60">
                                        <td className="px-4 py-4">
                                            <Link
                                                className="text-brand-700 focus-ring rounded font-semibold underline"
                                                href={`/t/${tenant.slug}/hr/staff/${person.id}`}
                                            >
                                                {person.name ?? 'Staff account'}
                                            </Link>
                                            <p className="text-text-muted mt-1 text-xs">
                                                {person.employee_number}
                                                {person.email ? ` · ${person.email}` : ''}
                                            </p>
                                        </td>
                                        <td className="px-4 py-4">
                                            {person.designation}
                                            <p className="text-text-muted mt-1 text-xs">
                                                {person.employment_type.replace('_', ' ')}
                                            </p>
                                        </td>
                                        <td className="px-4 py-4">
                                            {person.department ?? 'No department'}
                                            <p className="text-text-muted mt-1 text-xs">
                                                {person.campus ?? 'Institution-wide'}
                                            </p>
                                        </td>
                                        <td className="px-4 py-4">
                                            <span className="bg-surface-soft rounded-full px-2.5 py-1 text-xs font-medium capitalize">
                                                {person.status}
                                            </span>
                                        </td>
                                        <td className="px-4 py-4">{person.joined_on ?? '—'}</td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                )}
            </div>
            {staff.last_page > 1 && (
                <nav aria-label="Staff pages" className="mt-4 flex flex-wrap gap-2">
                    {staff.links.map((link, index) => {
                        const label = link.label.includes('Previous')
                            ? 'Previous'
                            : link.label.includes('Next')
                              ? 'Next'
                              : link.label.replace(/<[^>]*>/g, '');
                        return (
                            <button
                                key={`${index}-${label}`}
                                type="button"
                                disabled={!link.url}
                                aria-current={link.active ? 'page' : undefined}
                                onClick={() => link.url && router.get(link.url, {}, { preserveScroll: true })}
                                className="border-border min-h-10 rounded-lg border px-3 text-sm disabled:opacity-50"
                            >
                                {label}
                            </button>
                        );
                    })}
                </nav>
            )}
        </WorkspaceLayout>
    );
}
