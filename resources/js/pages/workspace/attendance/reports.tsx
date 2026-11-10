import { Head, Link, router } from '@inertiajs/react';
import { useState } from 'react';
import { WorkspaceLayout } from '@/layouts/workspace-layout';

type Row = {
    occurrence_date: string;
    campus_id: number | null;
    meeting_id: number;
    course: string;
    section_code: string;
    roster_count: number;
    unmarked_count: number;
    expected_count: number;
    attended_count: number;
    absent_count: number;
};
type Props = {
    tenant: { slug: string };
    filters: { from: string; to: string; campus_id: number | null; instructor_user_id: number | null };
    rows: { data: Row[]; links: { url: string | null; label: string; active: boolean }[] };
    campuses: { id: number; name: string }[];
    instructors: { id: number; name: string }[];
};

export default function AttendanceReports({ tenant, filters, rows, campuses, instructors }: Props) {
    const [from, setFrom] = useState(filters.from);
    const [to, setTo] = useState(filters.to);
    const [campus, setCampus] = useState(filters.campus_id?.toString() ?? '');
    const [instructor, setInstructor] = useState(filters.instructor_user_id?.toString() ?? '');
    return (
        <WorkspaceLayout title="Attendance reports" area="tenant">
            <Head title="Attendance reports" />
            <main id="main-content" className="mx-auto max-w-7xl space-y-6 px-4 py-8 sm:px-6 lg:px-8">
                <header>
                    <p className="text-brand-link text-sm font-semibold uppercase">Academic operations</p>
                    <h1 className="text-text-primary mt-1 text-3xl font-semibold">Attendance reports</h1>
                    <p className="text-text-secondary mt-2">
                        Aggregates come from submitted or finalized registers. Draft and reopened data is
                        excluded.
                    </p>
                </header>
                <form
                    className="surface grid gap-4 rounded-2xl border p-4 sm:grid-cols-4 sm:items-end"
                    onSubmit={(event) => {
                        event.preventDefault();
                        router.get(
                            `/t/${tenant.slug}/attendance/reports`,
                            {
                                from,
                                to,
                                campus_id: campus || undefined,
                                instructor_user_id: instructor || undefined,
                            },
                            { preserveState: true },
                        );
                    }}
                >
                    <label className="text-sm font-medium">
                        From
                        <input
                            className="mt-1 w-full rounded-lg border px-3 py-2"
                            type="date"
                            value={from}
                            onChange={(event) => setFrom(event.target.value)}
                        />
                    </label>
                    <label className="text-sm font-medium">
                        To
                        <input
                            className="mt-1 w-full rounded-lg border px-3 py-2"
                            type="date"
                            value={to}
                            onChange={(event) => setTo(event.target.value)}
                        />
                    </label>
                    <label className="text-sm font-medium">
                        Campus
                        <select
                            className="mt-1 w-full rounded-lg border px-3 py-2"
                            value={campus}
                            onChange={(event) => setCampus(event.target.value)}
                        >
                            <option value="">All accessible campuses</option>
                            {campuses.map((item) => (
                                <option key={item.id} value={item.id}>
                                    {item.name}
                                </option>
                            ))}
                        </select>
                    </label>
                    <label className="text-sm font-medium">
                        Instructor
                        <select
                            className="mt-1 w-full rounded-lg border px-3 py-2"
                            value={instructor}
                            onChange={(event) => setInstructor(event.target.value)}
                        >
                            <option value="">All accessible instructors</option>
                            {instructors.map((item) => (
                                <option key={item.id} value={item.id}>
                                    {item.name}
                                </option>
                            ))}
                        </select>
                    </label>
                    <button
                        className="bg-brand-action rounded-lg px-4 py-2 font-medium text-white focus-visible:outline-2"
                        type="submit"
                    >
                        Apply filters
                    </button>
                </form>
                <a
                    className="text-brand-link inline-flex rounded-lg border px-4 py-2 text-sm font-medium focus-visible:outline-2"
                    href={`/t/${tenant.slug}/attendance/reports.csv?${new URLSearchParams({ from, to, ...(campus ? { campus_id: campus } : {}), ...(instructor ? { instructor_user_id: instructor } : {}) }).toString()}`}
                >
                    Download filtered CSV
                </a>
                <section
                    className="surface overflow-hidden rounded-2xl border"
                    aria-label="Attendance report results"
                >
                    <div className="overflow-x-auto">
                        <table className="w-full min-w-[50rem] text-left text-sm">
                            <thead>
                                <tr className="border-border text-text-secondary border-b">
                                    <th scope="col" className="p-3">
                                        Date
                                    </th>
                                    <th scope="col" className="p-3">
                                        Course / section
                                    </th>
                                    <th scope="col" className="p-3">
                                        Expected
                                    </th>
                                    <th scope="col" className="p-3">
                                        Attended
                                    </th>
                                    <th scope="col" className="p-3">
                                        Absent
                                    </th>
                                    <th scope="col" className="p-3">
                                        Unmarked
                                    </th>
                                </tr>
                            </thead>
                            <tbody>
                                {rows.data.map((row) => (
                                    <tr
                                        className="border-border border-b last:border-0"
                                        key={`${row.meeting_id}-${row.occurrence_date}`}
                                    >
                                        <td className="p-3">{row.occurrence_date}</td>
                                        <td className="p-3">
                                            {row.course} · {row.section_code}
                                        </td>
                                        <td className="p-3">{row.expected_count}</td>
                                        <td className="p-3">{row.attended_count}</td>
                                        <td className="p-3">{row.absent_count}</td>
                                        <td className="p-3">{row.unmarked_count}</td>
                                    </tr>
                                ))}
                                {rows.data.length === 0 && (
                                    <tr>
                                        <td className="text-text-secondary p-8 text-center" colSpan={6}>
                                            No submitted attendance matches these filters.
                                        </td>
                                    </tr>
                                )}
                            </tbody>
                        </table>
                    </div>
                </section>
                <nav aria-label="Report pages" className="flex flex-wrap gap-2">
                    {rows.links.map((link, index) =>
                        link.url ? (
                            <Link
                                key={`${index}-${link.label}`}
                                aria-current={link.active ? 'page' : undefined}
                                className={`rounded-lg border px-3 py-2 text-sm focus-visible:outline-2 ${link.active ? 'bg-brand-action text-white' : ''}`}
                                href={link.url}
                            >
                                {link.label.replace(/&laquo;|&raquo;/g, '').trim()}
                            </Link>
                        ) : (
                            <span
                                key={`${index}-${link.label}`}
                                className="text-text-secondary rounded-lg border px-3 py-2 text-sm"
                            >
                                {link.label.replace(/&laquo;|&raquo;/g, '').trim()}
                            </span>
                        ),
                    )}
                </nav>
                <Link
                    className="text-brand-link rounded-lg px-2 py-1 text-sm focus-visible:outline-2"
                    href={`/t/${tenant.slug}/attendance`}
                >
                    Back to attendance
                </Link>
            </main>
        </WorkspaceLayout>
    );
}
