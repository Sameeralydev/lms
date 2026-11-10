import { PageHeader } from '@/components/ui/page-header';
import { WorkspaceLayout } from '@/layouts/workspace-layout';

type Meeting = {
    id: number;
    starts_on: string;
    ends_on: string;
    weekdays: number[];
    starts_at_local: string;
    duration_minutes: number;
    delivery_mode: string;
    online_location: string | null;
    section: { section_code: string; offering: { course: { code: string; title: string } } };
    instructor: { name: string };
    room: { name: string } | null;
    timetable: { timezone: string };
};

const weekday = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];

export default function MySchedule({
    tenant,
    kind,
    timezone,
    meetings,
    scheduleEvents,
    changeHistory,
}: {
    tenant: { slug: string };
    kind: 'student' | 'instructor';
    timezone: string;
    meetings: Meeting[];
    scheduleEvents: {
        id: string;
        date: string;
        starts_at: string;
        ends_at: string;
        course: string;
        course_code: string;
        section_code: string;
        instructor: string;
        location: string;
        timetable_timezone: string;
    }[];
    changeHistory: {
        id: number;
        change_type: string;
        occurrence_date: string;
        replacement_date: string | null;
        status: string;
        reason: string;
    }[];
}) {
    return (
        <WorkspaceLayout area="tenant" title="My timetable">
            <main className="mx-auto max-w-5xl space-y-6 pb-12">
                <PageHeader
                    title={kind === 'student' ? 'My class timetable' : 'My teaching timetable'}
                    description={`Published class meetings shown in ${timezone}. Changes follow your current confirmed enrollment or instructor assignments.`}
                />
                <div className="flex gap-2">
                    <a
                        className="border-border focus-ring min-h-10 rounded-lg border px-4 py-2 text-sm"
                        href={`/t/${tenant.slug}/schedule.csv`}
                    >
                        Download CSV
                    </a>
                    <a
                        className="border-border focus-ring min-h-10 rounded-lg border px-4 py-2 text-sm"
                        href={`/t/${tenant.slug}/schedule.ics`}
                    >
                        Add to calendar (.ics)
                    </a>
                </div>
                {scheduleEvents.length ? (
                    <ol className="space-y-3" aria-label="Upcoming class occurrences">
                        {scheduleEvents.map((event) => (
                            <li key={event.id} className="border-border bg-surface rounded-2xl border p-5">
                                <div className="flex flex-wrap items-start justify-between gap-3">
                                    <div>
                                        <h2 className="font-semibold">
                                            {event.course_code} · {event.course}
                                        </h2>
                                        <p className="text-text-secondary mt-1 text-sm">
                                            Section {event.section_code} · {event.date} ·{' '}
                                            {event.timetable_timezone}
                                        </p>
                                    </div>
                                    <span className="bg-brand-blue/10 text-brand-link rounded-full px-3 py-1 text-sm">
                                        {new Intl.DateTimeFormat(undefined, {
                                            hour: 'numeric',
                                            minute: '2-digit',
                                            timeZone: timezone,
                                        }).format(new Date(event.starts_at))}
                                    </span>
                                </div>
                                <p className="text-text-secondary mt-3 text-sm">
                                    Until{' '}
                                    {new Intl.DateTimeFormat(undefined, {
                                        hour: 'numeric',
                                        minute: '2-digit',
                                        timeZone: timezone,
                                    }).format(new Date(event.ends_at))}{' '}
                                    · {event.location} · Instructor: {event.instructor}
                                </p>
                            </li>
                        ))}
                    </ol>
                ) : meetings.length ? (
                    <ol className="space-y-3" aria-label="Published meetings">
                        {meetings.map((meeting) => (
                            <li key={meeting.id} className="border-border bg-surface rounded-2xl border p-5">
                                <div className="flex flex-wrap items-start justify-between gap-3">
                                    <div>
                                        <h2 className="font-semibold">
                                            {meeting.section.offering.course.code} ·{' '}
                                            {meeting.section.offering.course.title}
                                        </h2>
                                        <p className="text-text-secondary mt-1 text-sm">
                                            Section {meeting.section.section_code} ·{' '}
                                            {meeting.weekdays.map((day) => weekday[day - 1]).join(', ')}
                                        </p>
                                    </div>
                                    <span className="bg-brand-blue/10 text-brand-link rounded-full px-3 py-1 text-sm">
                                        {meeting.starts_at_local.slice(0, 5)}
                                    </span>
                                </div>
                                <p className="text-text-secondary mt-3 text-sm">
                                    {meeting.duration_minutes} minutes · {meeting.starts_on} through{' '}
                                    {meeting.ends_on} · {meeting.timetable.timezone}
                                </p>
                                <p className="mt-2 text-sm">
                                    {meeting.room?.name ??
                                        (meeting.delivery_mode === 'online'
                                            ? 'Online'
                                            : 'Location pending')}{' '}
                                    · Instructor: {meeting.instructor.name}
                                </p>
                                {meeting.online_location?.startsWith('https://') ? (
                                    <a
                                        className="text-brand-link focus-ring mt-2 inline-block text-sm underline"
                                        href={meeting.online_location}
                                        rel="noreferrer"
                                    >
                                        Open online classroom
                                    </a>
                                ) : null}
                            </li>
                        ))}
                    </ol>
                ) : (
                    <div className="border-border bg-surface rounded-2xl border p-6">
                        <p className="font-medium">No published timetable meetings are available.</p>
                        <p className="text-text-secondary mt-1 text-sm">
                            This view updates when a timetable is published and your enrollment or instructor
                            assignment is active.
                        </p>
                    </div>
                )}
                {changeHistory.length ? (
                    <section
                        className="border-border bg-surface rounded-2xl border p-5"
                        aria-labelledby="schedule-change-history"
                    >
                        <h2 id="schedule-change-history" className="font-semibold">
                            Recent schedule changes
                        </h2>
                        <ul className="mt-3 space-y-2">
                            {changeHistory.map((change) => (
                                <li key={change.id} className="bg-surface-muted rounded-lg p-3 text-sm">
                                    <strong className="capitalize">{change.change_type}</strong> ·{' '}
                                    {change.occurrence_date}
                                    {change.replacement_date ? ` → ${change.replacement_date}` : ''} ·{' '}
                                    {change.status}
                                    <p className="text-text-secondary mt-1">{change.reason}</p>
                                </li>
                            ))}
                        </ul>
                    </section>
                ) : null}
            </main>
        </WorkspaceLayout>
    );
}
