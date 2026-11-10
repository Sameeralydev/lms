import { Head, Link, useForm } from '@inertiajs/react';
import { useMemo, useState } from 'react';
import { WorkspaceLayout } from '@/layouts/workspace-layout';

type Props = {
    tenant: { slug: string };
    summary: {
        student_id: number;
        denominator: number;
        numerator: number;
        percentage: number | null;
        by_status: Record<string, number>;
        by_course: { course: string; denominator: number; numerator: number; percentage: number | null }[];
        by_month: { month: string; denominator: number; numerator: number }[];
        entries: {
            id: number;
            date: string;
            course: string | null;
            status: string;
            status_code: string;
            justification_status: string | null;
        }[];
    };
};

function JustificationForm({ tenant, entryId }: { tenant: string; entryId: number }) {
    const form = useForm({ category: 'illness', reason: '', evidence: null as File | null });
    return (
        <form
            className="mt-2 min-w-64 space-y-2"
            onSubmit={(event) => {
                event.preventDefault();
                form.post(`/t/${tenant}/attendance/entries/${entryId}/justifications`);
            }}
        >
            <label className="block text-xs font-medium">
                Justification category
                <select
                    className="mt-1 w-full rounded-md border px-2 py-1.5"
                    value={form.data.category}
                    onChange={(event) => form.setData('category', event.target.value)}
                >
                    <option value="illness">Illness</option>
                    <option value="family">Family matter</option>
                    <option value="official">Official duty</option>
                    <option value="other">Other</option>
                </select>
            </label>
            <label className="block text-xs font-medium">
                Reason
                <textarea
                    required
                    minLength={12}
                    maxLength={2000}
                    className="mt-1 w-full rounded-md border px-2 py-1.5"
                    value={form.data.reason}
                    onChange={(event) => form.setData('reason', event.target.value)}
                />
            </label>
            <label className="block text-xs font-medium">
                Supporting evidence (optional)
                <input
                    type="file"
                    accept="application/pdf,image/jpeg,image/png"
                    className="mt-1 block w-full text-xs"
                    onChange={(event) => form.setData('evidence', event.target.files?.[0] ?? null)}
                />
                <span className="text-text-secondary mt-1 block">
                    PDF, JPEG, or PNG; maximum 10 MB. Files remain quarantined until scanning completes.
                </span>
            </label>
            {form.errors.reason && (
                <p role="alert" className="text-danger text-xs">
                    {form.errors.reason}
                </p>
            )}
            <button
                disabled={form.processing}
                className="rounded-md border px-2 py-1 text-xs font-medium disabled:opacity-60"
                type="submit"
            >
                {form.processing ? 'Submitting…' : 'Request review'}
            </button>
        </form>
    );
}

export default function StudentAttendance({ tenant, summary }: Props) {
    const entries = summary.entries;
    const [calendarMonth, setCalendarMonth] = useState(
        entries[0]?.date.slice(0, 7) ?? new Date().toISOString().slice(0, 7),
    );
    const calendar = useMemo(() => {
        const [year, month] = calendarMonth.split('-').map(Number);
        const firstWeekday = new Date(year, month - 1, 1).getDay();
        const dayCount = new Date(year, month, 0).getDate();
        const cells: (number | null)[] = [
            ...Array.from({ length: firstWeekday }, () => null),
            ...Array.from({ length: dayCount }, (_, index) => index + 1),
        ];
        while (cells.length % 7 !== 0) cells.push(null);
        const entriesByDate = new Map<string, typeof entries>();
        for (const entry of entries) {
            if (!entry.date.startsWith(`${calendarMonth}-`)) continue;
            entriesByDate.set(entry.date, [...(entriesByDate.get(entry.date) ?? []), entry]);
        }
        return { year, month, cells, entriesByDate };
    }, [calendarMonth, entries]);
    const monthSummary = summary.by_month.find((item) => item.month === calendarMonth);
    const monthLabel = new Intl.DateTimeFormat(undefined, { month: 'long', year: 'numeric' }).format(
        new Date(calendar.year, calendar.month - 1, 1),
    );

    return (
        <WorkspaceLayout title="My attendance" area="tenant">
            <Head title="My attendance" />
            <main id="main-content" className="mx-auto max-w-6xl space-y-6 px-4 py-8 sm:px-6 lg:px-8">
                <header className="flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <p className="text-brand-link text-sm font-semibold uppercase">Learner portal</p>
                        <h1 className="text-text-primary mt-1 text-3xl font-semibold">My attendance</h1>
                        <p className="text-text-secondary mt-2">
                            The rate uses only submitted/finalized registers and policy statuses marked as
                            expected. Excused absences are excluded where configured.
                        </p>
                    </div>
                    <a
                        className="bg-brand-action rounded-lg px-4 py-2 text-sm font-medium text-white focus-visible:outline-2"
                        href={`/t/${tenant.slug}/attendance/my.csv`}
                    >
                        Download CSV
                    </a>
                </header>
                <section aria-label="Attendance summary" className="grid gap-4 sm:grid-cols-3">
                    <article className="surface rounded-2xl border p-5">
                        <h2 className="text-text-secondary text-sm">Attendance rate</h2>
                        <p className="text-text-primary mt-2 text-3xl font-semibold">
                            {summary.percentage === null ? '—' : `${summary.percentage}%`}
                        </p>
                        <p className="text-text-secondary mt-1 text-sm">
                            {summary.numerator} attended of {summary.denominator} expected meetings
                        </p>
                    </article>
                    {Object.entries(summary.by_status).map(([code, count]) => (
                        <article key={code} className="surface rounded-2xl border p-5">
                            <h2 className="text-text-secondary text-sm">
                                {code.replaceAll('_', ' ').toLowerCase()}
                            </h2>
                            <p className="text-text-primary mt-2 text-3xl font-semibold">{count}</p>
                        </article>
                    ))}
                </section>
                <section
                    className="surface space-y-4 rounded-2xl border p-5 sm:p-6"
                    aria-labelledby="calendar-heading"
                >
                    <div className="flex flex-wrap items-center justify-between gap-3">
                        <div>
                            <h2 id="calendar-heading" className="text-text-primary text-xl font-semibold">
                                Monthly attendance calendar
                            </h2>
                            <p className="text-text-secondary mt-1 text-sm">
                                {monthSummary
                                    ? `${monthSummary.numerator} attended of ${monthSummary.denominator} expected meetings`
                                    : 'No finalized attendance in this month'}
                            </p>
                        </div>
                        <div className="flex items-center gap-2">
                            <button
                                type="button"
                                aria-label="Previous month"
                                className="rounded-lg border px-3 py-2 focus-visible:outline-2"
                                onClick={() =>
                                    setCalendarMonth(
                                        new Date(Date.UTC(calendar.year, calendar.month - 2, 1))
                                            .toISOString()
                                            .slice(0, 7),
                                    )
                                }
                            >
                                Previous
                            </button>
                            <p className="min-w-32 text-center font-medium" aria-live="polite">
                                {monthLabel}
                            </p>
                            <button
                                type="button"
                                aria-label="Next month"
                                className="rounded-lg border px-3 py-2 focus-visible:outline-2"
                                onClick={() =>
                                    setCalendarMonth(
                                        new Date(Date.UTC(calendar.year, calendar.month, 1))
                                            .toISOString()
                                            .slice(0, 7),
                                    )
                                }
                            >
                                Next
                            </button>
                        </div>
                    </div>
                    <table
                        className="w-full table-fixed text-center text-xs sm:text-sm"
                        aria-label={`Attendance calendar for ${monthLabel}`}
                    >
                        <thead>
                            <tr>
                                {['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'].map((day) => (
                                    <th key={day} scope="col" className="text-text-secondary border-b py-2">
                                        {day}
                                    </th>
                                ))}
                            </tr>
                        </thead>
                        <tbody>
                            {Array.from({ length: calendar.cells.length / 7 }, (_, week) => (
                                <tr key={week}>
                                    {calendar.cells.slice(week * 7, week * 7 + 7).map((day, weekday) => {
                                        const key =
                                            day === null
                                                ? ''
                                                : `${calendarMonth}-${String(day).padStart(2, '0')}`;
                                        const dayEntries = calendar.entriesByDate.get(key) ?? [];
                                        return (
                                            <td
                                                key={`${week}-${weekday}`}
                                                className="border-border border-b p-1 align-top sm:p-2"
                                            >
                                                {day !== null && (
                                                    <div className="min-h-12 sm:min-h-16">
                                                        <span className="text-text-primary">{day}</span>
                                                        {dayEntries.length > 0 && (
                                                            <ul className="mt-1 space-y-1">
                                                                {dayEntries.map((entry) => (
                                                                    <li
                                                                        key={entry.id}
                                                                        title={`${entry.course}: ${entry.status}`}
                                                                        className="bg-brand-blue-soft text-text-primary truncate rounded px-1 py-0.5"
                                                                    >
                                                                        {entry.status_code}
                                                                    </li>
                                                                ))}
                                                            </ul>
                                                        )}
                                                    </div>
                                                )}
                                            </td>
                                        );
                                    })}
                                </tr>
                            ))}
                        </tbody>
                    </table>
                    <div
                        className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3"
                        aria-label="Course attendance summaries"
                    >
                        {summary.by_course.map((course) => (
                            <article key={course.course} className="border-border rounded-xl border p-4">
                                <h3 className="text-text-primary font-medium">{course.course}</h3>
                                <p className="text-text-secondary mt-1 text-sm">
                                    {course.numerator} attended / {course.denominator} expected ·{' '}
                                    {course.percentage === null ? 'No rate yet' : `${course.percentage}%`}
                                </p>
                            </article>
                        ))}
                    </div>
                </section>
                <section className="surface rounded-2xl border p-5 sm:p-6" aria-labelledby="history-heading">
                    <h2 id="history-heading" className="text-text-primary text-xl font-semibold">
                        Attendance history
                    </h2>
                    <div
                        className="mt-4 overflow-x-auto"
                        role="region"
                        aria-label="Attendance history table"
                        tabIndex={0}
                    >
                        <table className="w-full min-w-[52rem] text-left text-sm">
                            <thead>
                                <tr className="border-border text-text-secondary border-b">
                                    <th scope="col" className="py-3 pr-4">
                                        Date
                                    </th>
                                    <th scope="col" className="py-3 pr-4">
                                        Course
                                    </th>
                                    <th scope="col" className="py-3 pr-4">
                                        Status
                                    </th>
                                    <th scope="col" className="py-3">
                                        Absence review
                                    </th>
                                </tr>
                            </thead>
                            <tbody>
                                {summary.entries.map((entry) => (
                                    <tr
                                        key={entry.id}
                                        className="border-border border-b align-top last:border-0"
                                    >
                                        <td className="py-3 pr-4">{entry.date}</td>
                                        <td className="py-3 pr-4">{entry.course ?? 'Course'}</td>
                                        <td className="py-3 pr-4">{entry.status}</td>
                                        <td className="py-3">
                                            {['ABSENT', 'LATE'].includes(entry.status_code) &&
                                            !entry.justification_status ? (
                                                <JustificationForm tenant={tenant.slug} entryId={entry.id} />
                                            ) : (
                                                <span className="text-text-secondary">
                                                    {entry.justification_status ?? 'Not applicable'}
                                                </span>
                                            )}
                                        </td>
                                    </tr>
                                ))}
                                {summary.entries.length === 0 && (
                                    <tr>
                                        <td className="text-text-secondary py-8 text-center" colSpan={4}>
                                            No submitted attendance is available yet.
                                        </td>
                                    </tr>
                                )}
                            </tbody>
                        </table>
                    </div>
                    <Link
                        className="text-brand-link mt-5 inline-flex rounded-lg px-2 py-1 text-sm focus-visible:outline-2"
                        href={`/t/${tenant.slug}/schedule`}
                    >
                        View class timetable
                    </Link>
                </section>
            </main>
        </WorkspaceLayout>
    );
}
