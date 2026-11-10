import { router, useForm } from '@inertiajs/react';
import { useState, type FormEvent } from 'react';
import { FormErrors } from '@/components/ui/form-errors';
import { PageHeader } from '@/components/ui/page-header';
import { WorkspaceLayout } from '@/layouts/workspace-layout';
import { ResourceForms } from './timetables/resources-forms';

type IdName = { id: number; name: string; code?: string };
type Period = { id: number; academic_year_id: number; name: string; starts_on: string; ends_on: string };
type Section = {
    id: number;
    section_code: string;
    capacity: number;
    campus_id: number | null;
    academic_period_id: number;
    course_title: string;
    course_code: string;
    instructor_user_id: number;
    instructor_name: string;
};
type Meeting = {
    id: number;
    section_id: number;
    instructor_user_id: number;
    room_id: number | null;
    starts_on: string;
    ends_on: string;
    weekdays: number[];
    starts_at_local: string;
    duration_minutes: number;
    delivery_mode: string;
    online_location: string | null;
};
type Timetable = {
    id: number;
    name: string;
    version: number;
    status: string;
    academic_year_id: number;
    academic_period_id: number;
    campus_id: number | null;
    timezone: string;
    meetings: Meeting[];
    meetings_count: number;
};
type ScheduleChange = {
    id: number;
    change_type: string;
    occurrence_date: string;
    replacement_date: string | null;
    reason: string;
    status: string;
    meeting: {
        id: number;
        section: { section_code: string; offering: { course: { code: string; title: string } } };
    };
};
type Props = {
    tenant: { slug: string };
    institution: { name: string; timezone: string };
    canManage: boolean;
    canApprove: boolean;
    campusScope: number | null;
    campuses: IdName[];
    years: { id: number; name: string; starts_on: string; ends_on: string }[];
    periods: Period[];
    sections: Section[];
    buildings: (IdName & { campus_id: number })[];
    rooms: (IdName & { campus_id: number; capacity: number; room_type: string })[];
    settings: null | {
        teaching_days: number[];
        opens_at: string;
        closes_at: string;
        slot_minutes: number;
        max_weekly_teaching_minutes: number;
    };
    closures: { id: number; name: string; starts_on: string; ends_on: string; exception_type: string }[];
    availability: {
        id: number;
        user_id: number;
        weekdays: number[];
        starts_at: string;
        ends_at: string;
        availability_type: string;
    }[];
    roomBlocks: {
        id: number;
        room_id: number;
        starts_at_utc: string;
        ends_at_utc: string;
        block_type: string;
        reason: string;
        room: IdName | null;
    }[];
    timetables: { data: Timetable[] };
    changes: ScheduleChange[];
};

const dayOptions = [
    [1, 'Mon'],
    [2, 'Tue'],
    [3, 'Wed'],
    [4, 'Thu'],
    [5, 'Fri'],
    [6, 'Sat'],
    [7, 'Sun'],
] as const;
const inputClass =
    'border-border bg-surface text-text-primary focus-ring min-h-10 w-full rounded-lg border px-3 text-sm';
const labelClass = 'text-text-secondary block text-sm font-medium';

function DayPicker({ value, onChange }: { value: number[]; onChange: (days: number[]) => void }) {
    return (
        <fieldset className="flex flex-wrap gap-2">
            <legend className="text-text-secondary mb-2 text-sm">Repeats on</legend>
            {dayOptions.map(([day, label]) => (
                <label
                    key={day}
                    className="border-border inline-flex min-h-10 cursor-pointer items-center gap-2 rounded-lg border px-3 text-sm"
                >
                    <input
                        type="checkbox"
                        checked={value.includes(day)}
                        onChange={(event) =>
                            onChange(
                                event.target.checked
                                    ? [...value, day].sort()
                                    : value.filter((item) => item !== day),
                            )
                        }
                    />
                    {label}
                </label>
            ))}
        </fieldset>
    );
}

function SettingsForm({
    tenant,
    settings,
    campuses,
    campusScope,
}: Pick<Props, 'tenant' | 'settings' | 'campuses' | 'campusScope'>) {
    const form = useForm({
        campus_id: campusScope ?? '',
        teaching_days: settings?.teaching_days ?? [1, 2, 3, 4, 5],
        opens_at: settings?.opens_at?.slice(0, 5) ?? '08:00',
        closes_at: settings?.closes_at?.slice(0, 5) ?? '17:00',
        slot_minutes: settings?.slot_minutes ?? 50,
        max_weekly_teaching_minutes: settings?.max_weekly_teaching_minutes ?? 2400,
    });
    const save = (event: FormEvent) => {
        event.preventDefault();
        form.put(`/t/${tenant.slug}/academics/timetables/settings`, { onSuccess: () => form.clearErrors() });
    };
    return (
        <form
            onSubmit={save}
            className="border-border bg-surface grid gap-4 rounded-2xl border p-5 md:grid-cols-2"
        >
            <div className="md:col-span-2">
                <h2 className="font-semibold">Operating rules</h2>
                <p className="text-text-secondary mt-1 text-sm">
                    Use campus-specific settings to override institution hours.
                </p>
            </div>
            <label className={labelClass}>
                Campus scope
                <select
                    className={`${inputClass} mt-1`}
                    value={form.data.campus_id}
                    onChange={(e) => form.setData('campus_id', e.target.value ? Number(e.target.value) : '')}
                >
                    <option value="">Institution default</option>
                    {campuses.map((c) => (
                        <option key={c.id} value={c.id}>
                            {c.name}
                        </option>
                    ))}
                </select>
            </label>
            <div className="grid grid-cols-3 gap-3">
                <label className={labelClass}>
                    Opens
                    <input
                        type="time"
                        className={`${inputClass} mt-1`}
                        value={form.data.opens_at}
                        onChange={(e) => form.setData('opens_at', e.target.value)}
                    />
                </label>
                <label className={labelClass}>
                    Closes
                    <input
                        type="time"
                        className={`${inputClass} mt-1`}
                        value={form.data.closes_at}
                        onChange={(e) => form.setData('closes_at', e.target.value)}
                    />
                </label>
                <label className={labelClass}>
                    Slot minutes
                    <input
                        type="number"
                        min="15"
                        max="240"
                        className={`${inputClass} mt-1`}
                        value={form.data.slot_minutes}
                        onChange={(e) => form.setData('slot_minutes', Number(e.target.value))}
                    />
                </label>
            </div>
            <div className="md:col-span-2">
                <DayPicker
                    value={form.data.teaching_days}
                    onChange={(days) => form.setData('teaching_days', days)}
                />
            </div>
            <label className={labelClass}>
                Weekly instructor limit (minutes)
                <input
                    type="number"
                    min="60"
                    max="10080"
                    className={`${inputClass} mt-1`}
                    value={form.data.max_weekly_teaching_minutes}
                    onChange={(e) => form.setData('max_weekly_teaching_minutes', Number(e.target.value))}
                />
            </label>
            <div className="flex items-end">
                <button
                    className="bg-brand-action focus-ring min-h-10 rounded-lg px-4 text-sm font-medium text-white"
                    disabled={form.processing}
                >
                    Save operating rules
                </button>
            </div>
            <FormErrors errors={form.errors} />
        </form>
    );
}

function MeetingForm({
    tenant,
    timetable,
    sections,
    rooms,
    existing,
}: {
    tenant: Props['tenant'];
    timetable: Timetable;
    sections: Section[];
    rooms: Props['rooms'];
    existing?: Meeting;
}) {
    const matchingSections = sections.filter(
        (section) =>
            section.academic_period_id === timetable.academic_period_id &&
            (!timetable.campus_id || section.campus_id === timetable.campus_id),
    );
    const selectableSections = Array.from(
        new Map(matchingSections.map((section) => [section.id, section])).values(),
    );
    const [conflicts, setConflicts] = useState<{ type: string; date?: string }[] | null>(null);
    const form = useForm({
        section_id: existing?.section_id ?? matchingSections[0]?.id ?? '',
        instructor_user_id: existing?.instructor_user_id ?? matchingSections[0]?.instructor_user_id ?? '',
        room_id: existing?.room_id ?? '',
        starts_on: existing?.starts_on.slice(0, 10) ?? '',
        ends_on: existing?.ends_on.slice(0, 10) ?? '',
        weekdays: existing?.weekdays ?? [1, 3, 5],
        starts_at_local: existing?.starts_at_local.slice(0, 5) ?? '09:00',
        duration_minutes: existing?.duration_minutes ?? 50,
        delivery_mode: existing?.delivery_mode ?? 'in_person',
        online_location: existing?.online_location ?? '',
    });
    const endpoint = `/t/${tenant.slug}/academics/timetables/${timetable.id}`;
    const preview = async () => {
        const response = await fetch(`${endpoint}/conflicts`, {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/json',
                Accept: 'application/json',
                'X-CSRF-TOKEN':
                    document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content ?? '',
            },
            body: JSON.stringify({ ...form.data, meeting_id: existing?.id }),
        });
        if (response.ok)
            setConflicts(
                ((await response.json()) as { conflicts: { type: string; date?: string }[] }).conflicts,
            );
        else setConflicts([{ type: 'preview_failed' }]);
    };
    const save = (event: FormEvent) => {
        event.preventDefault();
        const submit = existing ? form.put : form.post;
        const url = existing
            ? `/t/${tenant.slug}/academics/timetable-meetings/${existing.id}`
            : `${endpoint}/meetings`;
        submit(url, {
            onSuccess: () => {
                setConflicts(null);
                form.reset('starts_on', 'ends_on');
            },
        });
    };
    return (
        <form
            id={existing ? `meeting-edit-${existing.id}` : 'timetable-create-meeting'}
            onSubmit={save}
            className="border-border mt-4 grid gap-3 rounded-xl border p-4 sm:grid-cols-2"
        >
            <h3 className="font-medium sm:col-span-2">
                {existing ? 'Edit recurring series in this draft' : 'Add weekly class meeting'}
            </h3>
            <label className={labelClass}>
                Section
                <select
                    className={`${inputClass} mt-1`}
                    value={form.data.section_id}
                    onChange={(e) => {
                        const id = Number(e.target.value);
                        form.setData({
                            ...form.data,
                            section_id: id,
                            instructor_user_id:
                                matchingSections.find((s) => s.id === id)?.instructor_user_id ?? 0,
                        });
                    }}
                    required
                >
                    {selectableSections.map((s) => (
                        <option key={s.id} value={s.id}>
                            {s.course_code} · {s.section_code} · {s.instructor_name}
                        </option>
                    ))}
                </select>
            </label>
            <label className={labelClass}>
                Assigned instructor
                <select
                    className={`${inputClass} mt-1`}
                    value={form.data.instructor_user_id}
                    onChange={(e) => form.setData('instructor_user_id', Number(e.target.value))}
                    required
                >
                    {matchingSections
                        .filter((s) => s.id === Number(form.data.section_id))
                        .map((s) => (
                            <option key={s.instructor_user_id} value={s.instructor_user_id}>
                                {s.instructor_name}
                            </option>
                        ))}
                </select>
            </label>
            <label className={labelClass}>
                Room
                <select
                    className={`${inputClass} mt-1`}
                    value={form.data.room_id}
                    onChange={(e) => form.setData('room_id', e.target.value)}
                    required={form.data.delivery_mode === 'in_person'}
                >
                    <option value="">Select room</option>
                    {rooms
                        .filter((r) => !timetable.campus_id || r.campus_id === timetable.campus_id)
                        .map((r) => (
                            <option key={r.id} value={r.id}>
                                {r.name} · {r.capacity} seats
                            </option>
                        ))}
                </select>
            </label>
            <label className={labelClass}>
                Delivery mode
                <select
                    className={`${inputClass} mt-1`}
                    value={form.data.delivery_mode}
                    onChange={(e) => form.setData('delivery_mode', e.target.value)}
                >
                    <option value="in_person">In person</option>
                    <option value="online">Online</option>
                    <option value="hybrid">Hybrid</option>
                </select>
            </label>
            {form.data.delivery_mode !== 'in_person' ? (
                <label className={`${labelClass} sm:col-span-2`}>
                    Secure online location
                    <input
                        type="url"
                        className={`${inputClass} mt-1`}
                        value={form.data.online_location}
                        onChange={(e) => form.setData('online_location', e.target.value)}
                        placeholder="https://…"
                    />
                </label>
            ) : null}
            <label className={labelClass}>
                First occurrence
                <input
                    type="date"
                    className={`${inputClass} mt-1`}
                    value={form.data.starts_on}
                    onChange={(e) => form.setData('starts_on', e.target.value)}
                    required
                />
            </label>
            <label className={labelClass}>
                Last occurrence
                <input
                    type="date"
                    className={`${inputClass} mt-1`}
                    value={form.data.ends_on}
                    onChange={(e) => form.setData('ends_on', e.target.value)}
                    required
                />
            </label>
            <label className={labelClass}>
                Local start time
                <input
                    type="time"
                    className={`${inputClass} mt-1`}
                    value={form.data.starts_at_local}
                    onChange={(e) => form.setData('starts_at_local', e.target.value)}
                    required
                />
            </label>
            <label className={labelClass}>
                Duration (minutes)
                <input
                    type="number"
                    min="10"
                    max="480"
                    className={`${inputClass} mt-1`}
                    value={form.data.duration_minutes}
                    onChange={(e) => form.setData('duration_minutes', Number(e.target.value))}
                />
            </label>
            <div className="sm:col-span-2">
                <DayPicker value={form.data.weekdays} onChange={(days) => form.setData('weekdays', days)} />
            </div>
            {conflicts ? (
                <div
                    role={conflicts.length ? 'alert' : 'status'}
                    className="bg-surface-muted rounded-lg p-3 text-sm sm:col-span-2"
                >
                    {conflicts.length
                        ? `Conflicts: ${conflicts.map((c) => `${c.type}${c.date ? ` on ${c.date}` : ''}`).join(', ')}`
                        : 'No conflicts found for the selected occurrences.'}
                </div>
            ) : null}
            <FormErrors errors={form.errors} />
            <div className="flex flex-wrap gap-2 sm:col-span-2">
                <button
                    type="button"
                    className="border-border focus-ring min-h-10 rounded-lg border px-4 text-sm"
                    onClick={() => void preview()}
                >
                    Preview conflicts
                </button>
                <button
                    className="bg-brand-action focus-ring min-h-10 rounded-lg px-4 text-sm font-medium text-white"
                    disabled={form.processing || matchingSections.length === 0}
                >
                    {existing ? 'Save series changes' : 'Save meeting'}
                </button>
            </div>
        </form>
    );
}

function CancelSeriesForm({ tenant, meeting }: { tenant: Props['tenant']; meeting: Meeting }) {
    const form = useForm({ reason: '' });
    return (
        <form
            className="border-border mt-3 grid gap-2 rounded-lg border p-3 sm:grid-cols-[1fr_auto]"
            onSubmit={(event) => {
                event.preventDefault();
                form.patch(`/t/${tenant.slug}/academics/timetable-meetings/${meeting.id}/series/cancel`);
            }}
        >
            <label className={labelClass}>
                Cancellation reason
                <input
                    className={`${inputClass} mt-1`}
                    value={form.data.reason}
                    onChange={(event) => form.setData('reason', event.currentTarget.value)}
                    minLength={8}
                    maxLength={1000}
                    required
                />
            </label>
            <button
                className="border-danger text-danger focus-ring min-h-10 self-end rounded-lg border px-3 text-sm"
                disabled={form.processing}
            >
                Cancel series in draft
            </button>
            <FormErrors errors={form.errors} />
        </form>
    );
}

function OccurrenceChangeForm({
    tenant,
    meeting,
    section,
    rooms,
    instructors,
}: {
    tenant: Props['tenant'];
    meeting: Meeting;
    section?: Section;
    rooms: Props['rooms'];
    instructors: Section[];
}) {
    const form = useForm({
        change_type: 'reschedule',
        occurrence_date: meeting.starts_on.slice(0, 10),
        replacement_date: meeting.starts_on.slice(0, 10),
        starts_at_local: meeting.starts_at_local.slice(0, 5),
        duration_minutes: meeting.duration_minutes,
        instructor_user_id: meeting.instructor_user_id,
        room_id: meeting.room_id ?? '',
        reason: '',
    });
    const change = (event: FormEvent) => {
        event.preventDefault();
        form.post(`/t/${tenant.slug}/academics/timetable-meetings/${meeting.id}/changes`, {
            onSuccess: () => form.reset('reason'),
        });
    };
    return (
        <form
            onSubmit={change}
            className="border-border mt-3 grid gap-3 rounded-lg border p-3 sm:grid-cols-2"
            aria-label={`Request a change for ${section?.course_code ?? 'class'} ${section?.section_code ?? ''}`}
        >
            <h4 className="font-medium sm:col-span-2">Request occurrence change</h4>
            <label className={labelClass}>
                Change type
                <select
                    className={`${inputClass} mt-1`}
                    value={form.data.change_type}
                    onChange={(e) => form.setData('change_type', e.target.value)}
                >
                    <option value="reschedule">Reschedule</option>
                    <option value="substitution">Instructor substitution</option>
                    <option value="cancellation">Cancel occurrence</option>
                    <option value="makeup">Add makeup class</option>
                </select>
            </label>
            <label className={labelClass}>
                Original occurrence
                <input
                    type="date"
                    required
                    className={`${inputClass} mt-1`}
                    value={form.data.occurrence_date}
                    onChange={(e) => form.setData('occurrence_date', e.target.value)}
                />
            </label>
            {form.data.change_type !== 'cancellation' ? (
                <>
                    <label className={labelClass}>
                        {form.data.change_type === 'makeup' ? 'Makeup date' : 'New date'}
                        <input
                            type="date"
                            required
                            className={`${inputClass} mt-1`}
                            value={form.data.replacement_date}
                            onChange={(e) => form.setData('replacement_date', e.target.value)}
                        />
                    </label>
                    <label className={labelClass}>
                        Local start time
                        <input
                            type="time"
                            className={`${inputClass} mt-1`}
                            value={form.data.starts_at_local}
                            onChange={(e) => form.setData('starts_at_local', e.target.value)}
                        />
                    </label>
                    <label className={labelClass}>
                        Instructor
                        <select
                            className={`${inputClass} mt-1`}
                            value={form.data.instructor_user_id}
                            onChange={(e) => form.setData('instructor_user_id', Number(e.target.value))}
                        >
                            {instructors.map((item) => (
                                <option key={item.instructor_user_id} value={item.instructor_user_id}>
                                    {item.instructor_name}
                                </option>
                            ))}
                        </select>
                    </label>
                    <label className={labelClass}>
                        Room
                        <select
                            className={`${inputClass} mt-1`}
                            value={form.data.room_id}
                            onChange={(e) =>
                                form.setData('room_id', e.target.value ? Number(e.target.value) : '')
                            }
                        >
                            <option value="">No room</option>
                            {rooms.map((room) => (
                                <option key={room.id} value={room.id}>
                                    {room.name}
                                </option>
                            ))}
                        </select>
                    </label>
                </>
            ) : null}
            <label className={`${labelClass} sm:col-span-2`}>
                Reason
                <textarea
                    required
                    minLength={8}
                    maxLength={1000}
                    className={`${inputClass} mt-1 min-h-20`}
                    value={form.data.reason}
                    onChange={(e) => form.setData('reason', e.target.value)}
                />
            </label>
            <FormErrors errors={form.errors} />
            <button
                disabled={form.processing}
                className="bg-brand-action focus-ring min-h-10 rounded-lg px-4 text-sm font-medium text-white sm:col-span-2"
            >
                Submit for independent approval
            </button>
        </form>
    );
}

export default function TimetablesPage(props: Props) {
    const base = `/t/${props.tenant.slug}/academics/timetables`;
    const [openId, setOpenId] = useState<number | null>(null);
    const [editingMeetingId, setEditingMeetingId] = useState<number | null>(null);
    const [calendarView, setCalendarView] = useState<'week' | 'day'>('week');
    const [selectedDay, setSelectedDay] = useState(1);
    const [groupBy, setGroupBy] = useState<'section' | 'instructor' | 'room'>('section');
    const buildingForm = useForm({ campus_id: props.campuses[0]?.id ?? '', code: '', name: '', address: '' });
    const roomForm = useForm({
        campus_id: props.campuses[0]?.id ?? '',
        building_id: '',
        code: '',
        name: '',
        room_type: 'classroom',
        capacity: 30,
        features: [] as string[],
    });
    const timetableForm = useForm({
        academic_year_id: props.years[0]?.id ?? '',
        academic_period_id: props.periods[0]?.id ?? '',
        campus_id: props.campusScope ?? '',
        name: '',
        timezone: props.institution.timezone || 'UTC',
        independent_review_required: true,
    });
    const closureForm = useForm({
        campus_id: props.campusScope ?? '',
        name: '',
        exception_type: 'closure',
        starts_on: '',
        ends_on: '',
    });
    const submit = (post: (url: string) => void, url: string) => (event: FormEvent) => {
        event.preventDefault();
        post(url);
    };
    return (
        <WorkspaceLayout area="tenant" title="Academic timetables">
            <main className="mx-auto max-w-7xl space-y-7 pb-12">
                <PageHeader
                    title="Academic timetables"
                    description={`${props.institution.name} · weekly schedules, facilities, availability, and controlled publication`}
                />
                <div className="flex flex-wrap gap-2">
                    <a
                        className="border-border focus-ring min-h-10 rounded-lg border px-4 py-2 text-sm"
                        href={`/t/${props.tenant.slug}/schedule.csv`}
                    >
                        Export CSV
                    </a>
                    <a
                        className="border-border focus-ring min-h-10 rounded-lg border px-4 py-2 text-sm"
                        href={`/t/${props.tenant.slug}/schedule.ics`}
                    >
                        Export iCalendar
                    </a>
                    <a
                        className="border-border focus-ring min-h-10 rounded-lg border px-4 py-2 text-sm"
                        href={`/t/${props.tenant.slug}/schedule`}
                    >
                        My schedule
                    </a>
                    <a
                        className="border-border focus-ring min-h-10 rounded-lg border px-4 py-2 text-sm"
                        href={`${base}/analytics`}
                    >
                        Scheduling analytics
                    </a>
                </div>
                {props.canManage ? (
                    <>
                        <SettingsForm
                            tenant={props.tenant}
                            settings={props.settings}
                            campuses={props.campuses}
                            campusScope={props.campusScope}
                        />
                        <section className="grid gap-4 xl:grid-cols-2">
                            <form
                                onSubmit={submit(buildingForm.post, `${base}/buildings`)}
                                className="border-border bg-surface grid gap-3 rounded-2xl border p-5 sm:grid-cols-2"
                            >
                                <h2 className="font-semibold sm:col-span-2">Campus buildings</h2>
                                <label className={labelClass}>
                                    Campus
                                    <select
                                        className={`${inputClass} mt-1`}
                                        value={buildingForm.data.campus_id}
                                        onChange={(e) =>
                                            buildingForm.setData('campus_id', Number(e.target.value))
                                        }
                                    >
                                        {props.campuses.map((c) => (
                                            <option key={c.id} value={c.id}>
                                                {c.name}
                                            </option>
                                        ))}
                                    </select>
                                </label>
                                <label className={labelClass}>
                                    Building code
                                    <input
                                        className={`${inputClass} mt-1`}
                                        value={buildingForm.data.code}
                                        onChange={(e) => buildingForm.setData('code', e.target.value)}
                                        required
                                    />
                                </label>
                                <label className={`${labelClass} sm:col-span-2`}>
                                    Building name
                                    <input
                                        className={`${inputClass} mt-1`}
                                        value={buildingForm.data.name}
                                        onChange={(e) => buildingForm.setData('name', e.target.value)}
                                        required
                                    />
                                </label>
                                <label className={`${labelClass} sm:col-span-2`}>
                                    Address
                                    <input
                                        className={`${inputClass} mt-1`}
                                        value={buildingForm.data.address}
                                        onChange={(e) => buildingForm.setData('address', e.target.value)}
                                    />
                                </label>
                                <button className="bg-brand-action focus-ring min-h-10 rounded-lg px-4 text-sm text-white">
                                    Add building
                                </button>
                                <FormErrors errors={buildingForm.errors} />
                                {props.buildings.map((b) => (
                                    <p key={b.id} className="text-text-secondary text-sm">
                                        {b.code} · {b.name}
                                    </p>
                                ))}
                            </form>
                            <form
                                onSubmit={submit(roomForm.post, `${base}/rooms`)}
                                className="border-border bg-surface grid gap-3 rounded-2xl border p-5 sm:grid-cols-2"
                            >
                                <h2 className="font-semibold sm:col-span-2">Room directory</h2>
                                <label className={labelClass}>
                                    Campus
                                    <select
                                        className={`${inputClass} mt-1`}
                                        value={roomForm.data.campus_id}
                                        onChange={(e) =>
                                            roomForm.setData('campus_id', Number(e.target.value))
                                        }
                                    >
                                        {props.campuses.map((c) => (
                                            <option key={c.id} value={c.id}>
                                                {c.name}
                                            </option>
                                        ))}
                                    </select>
                                </label>
                                <label className={labelClass}>
                                    Building
                                    <select
                                        className={`${inputClass} mt-1`}
                                        value={roomForm.data.building_id}
                                        onChange={(e) => roomForm.setData('building_id', e.target.value)}
                                    >
                                        <option value="">No building</option>
                                        {props.buildings
                                            .filter((b) => b.campus_id === Number(roomForm.data.campus_id))
                                            .map((b) => (
                                                <option key={b.id} value={b.id}>
                                                    {b.name}
                                                </option>
                                            ))}
                                    </select>
                                </label>
                                <label className={labelClass}>
                                    Room code
                                    <input
                                        className={`${inputClass} mt-1`}
                                        value={roomForm.data.code}
                                        onChange={(e) => roomForm.setData('code', e.target.value)}
                                        required
                                    />
                                </label>
                                <label className={labelClass}>
                                    Room name
                                    <input
                                        className={`${inputClass} mt-1`}
                                        value={roomForm.data.name}
                                        onChange={(e) => roomForm.setData('name', e.target.value)}
                                        required
                                    />
                                </label>
                                <label className={labelClass}>
                                    Type
                                    <select
                                        className={`${inputClass} mt-1`}
                                        value={roomForm.data.room_type}
                                        onChange={(e) => roomForm.setData('room_type', e.target.value)}
                                    >
                                        <option value="classroom">Classroom</option>
                                        <option value="lecture_hall">Lecture hall</option>
                                        <option value="laboratory">Laboratory</option>
                                        <option value="seminar">Seminar room</option>
                                        <option value="examination">Examination room</option>
                                    </select>
                                </label>
                                <label className={labelClass}>
                                    Seats
                                    <input
                                        type="number"
                                        min="1"
                                        max="5000"
                                        className={`${inputClass} mt-1`}
                                        value={roomForm.data.capacity}
                                        onChange={(e) => roomForm.setData('capacity', Number(e.target.value))}
                                    />
                                </label>
                                <button className="bg-brand-action focus-ring min-h-10 rounded-lg px-4 text-sm text-white">
                                    Add room
                                </button>
                                <FormErrors errors={roomForm.errors} />
                                {props.rooms.map((r) => (
                                    <p key={r.id} className="text-text-secondary text-sm">
                                        {r.code} · {r.name} · {r.capacity} seats
                                    </p>
                                ))}
                            </form>
                        </section>
                        <form
                            onSubmit={submit((url) => closureForm.post(url), `${base}/closures`)}
                            className="border-border bg-surface grid gap-3 rounded-2xl border p-5 sm:grid-cols-2"
                        >
                            <h2 className="font-semibold sm:col-span-2">Closures and holidays</h2>
                            <label className={labelClass}>
                                Name
                                <input
                                    className={`${inputClass} mt-1`}
                                    value={closureForm.data.name}
                                    onChange={(e) => closureForm.setData('name', e.target.value)}
                                    required
                                />
                            </label>
                            <label className={labelClass}>
                                Type
                                <select
                                    className={`${inputClass} mt-1`}
                                    value={closureForm.data.exception_type}
                                    onChange={(e) => closureForm.setData('exception_type', e.target.value)}
                                >
                                    <option value="closure">Closure</option>
                                    <option value="holiday">Holiday</option>
                                    <option value="break">Break</option>
                                </select>
                            </label>
                            <label className={labelClass}>
                                Starts
                                <input
                                    type="date"
                                    className={`${inputClass} mt-1`}
                                    value={closureForm.data.starts_on}
                                    onChange={(e) => closureForm.setData('starts_on', e.target.value)}
                                    required
                                />
                            </label>
                            <label className={labelClass}>
                                Ends
                                <input
                                    type="date"
                                    className={`${inputClass} mt-1`}
                                    value={closureForm.data.ends_on}
                                    onChange={(e) => closureForm.setData('ends_on', e.target.value)}
                                    required
                                />
                            </label>
                            <button className="bg-brand-action focus-ring min-h-10 rounded-lg px-4 text-sm text-white">
                                Add closure
                            </button>
                            <FormErrors errors={closureForm.errors} />
                        </form>
                        <ResourceForms
                            endpoint={base}
                            campusScope={props.campusScope}
                            rooms={props.rooms.map((room) => ({
                                id: room.id,
                                name: room.name,
                                code: room.code ?? '',
                            }))}
                            instructors={Array.from(
                                new Map(
                                    props.sections.map((section) => [
                                        section.instructor_user_id,
                                        section.instructor_name,
                                    ]),
                                ).entries(),
                            ).map(([id, name]) => ({ id, name }))}
                            availability={props.availability}
                            roomBlocks={props.roomBlocks}
                        />
                        <form
                            onSubmit={submit((url) => timetableForm.post(url), base)}
                            className="border-border bg-surface grid gap-3 rounded-2xl border p-5 sm:grid-cols-2"
                        >
                            <h2 className="font-semibold sm:col-span-2">Create a timetable draft</h2>
                            <label className={labelClass}>
                                Academic year
                                <select
                                    className={`${inputClass} mt-1`}
                                    value={timetableForm.data.academic_year_id}
                                    onChange={(e) =>
                                        timetableForm.setData('academic_year_id', Number(e.target.value))
                                    }
                                >
                                    {props.years.map((y) => (
                                        <option key={y.id} value={y.id}>
                                            {y.name}
                                        </option>
                                    ))}
                                </select>
                            </label>
                            <label className={labelClass}>
                                Academic period
                                <select
                                    className={`${inputClass} mt-1`}
                                    value={timetableForm.data.academic_period_id}
                                    onChange={(e) =>
                                        timetableForm.setData('academic_period_id', Number(e.target.value))
                                    }
                                >
                                    {props.periods.map((p) => (
                                        <option key={p.id} value={p.id}>
                                            {p.name}
                                        </option>
                                    ))}
                                </select>
                            </label>
                            <label className={labelClass}>
                                Campus
                                <select
                                    className={`${inputClass} mt-1`}
                                    value={timetableForm.data.campus_id}
                                    onChange={(e) =>
                                        timetableForm.setData(
                                            'campus_id',
                                            e.target.value ? Number(e.target.value) : '',
                                        )
                                    }
                                >
                                    <option value="">Institution-wide</option>
                                    {props.campuses.map((c) => (
                                        <option key={c.id} value={c.id}>
                                            {c.name}
                                        </option>
                                    ))}
                                </select>
                            </label>
                            <label className={labelClass}>
                                Timetable name
                                <input
                                    className={`${inputClass} mt-1`}
                                    value={timetableForm.data.name}
                                    onChange={(e) => timetableForm.setData('name', e.target.value)}
                                    required
                                />
                            </label>
                            <label className={labelClass}>
                                Time zone
                                <input
                                    className={`${inputClass} mt-1`}
                                    value={timetableForm.data.timezone}
                                    onChange={(e) => timetableForm.setData('timezone', e.target.value)}
                                    required
                                />
                            </label>
                            <label className="flex items-center gap-2 text-sm">
                                <input
                                    type="checkbox"
                                    checked={timetableForm.data.independent_review_required}
                                    onChange={(e) =>
                                        timetableForm.setData('independent_review_required', e.target.checked)
                                    }
                                />{' '}
                                Require independent review
                            </label>
                            <button
                                className="bg-brand-action focus-ring min-h-10 rounded-lg px-4 text-sm text-white"
                                disabled={timetableForm.processing}
                            >
                                Create draft
                            </button>
                            <FormErrors errors={timetableForm.errors} />
                        </form>
                    </>
                ) : null}
                <section className="space-y-4" aria-label="Timetable versions">
                    <h2 className="text-xl font-semibold">Timetable versions</h2>
                    <div className="border-border bg-surface flex flex-wrap items-end gap-3 rounded-xl border p-4">
                        <div className="flex gap-2" role="group" aria-label="Calendar view">
                            <button
                                type="button"
                                aria-pressed={calendarView === 'week'}
                                className="border-border focus-ring min-h-10 rounded-lg border px-3 text-sm"
                                onClick={() => setCalendarView('week')}
                            >
                                Week view
                            </button>
                            <button
                                type="button"
                                aria-pressed={calendarView === 'day'}
                                className="border-border focus-ring min-h-10 rounded-lg border px-3 text-sm"
                                onClick={() => setCalendarView('day')}
                            >
                                Day view
                            </button>
                        </div>
                        {calendarView === 'day' ? (
                            <label className={labelClass}>
                                Day
                                <select
                                    className={`${inputClass} mt-1`}
                                    value={selectedDay}
                                    onChange={(event) => setSelectedDay(Number(event.target.value))}
                                >
                                    {dayOptions.map(([number, label]) => (
                                        <option key={number} value={number}>
                                            {label}
                                        </option>
                                    ))}
                                </select>
                            </label>
                        ) : null}
                        <label className={labelClass}>
                            View by
                            <select
                                className={`${inputClass} mt-1`}
                                value={groupBy}
                                onChange={(event) =>
                                    setGroupBy(event.target.value as 'section' | 'instructor' | 'room')
                                }
                            >
                                <option value="section">Section</option>
                                <option value="instructor">Instructor</option>
                                <option value="room">Room</option>
                            </select>
                        </label>
                        <p className="text-text-secondary text-sm">
                            Accessible timetable grid; use the meeting editor to add or revise recurring
                            entries.
                        </p>
                    </div>
                    {props.timetables.data.length ? (
                        props.timetables.data.map((timetable) => (
                            <article
                                id={`timetable-${timetable.id}`}
                                key={timetable.id}
                                className="border-border bg-surface rounded-2xl border p-5"
                            >
                                <div className="flex flex-wrap items-start justify-between gap-4">
                                    <div>
                                        <h3 className="font-semibold">
                                            {timetable.name}{' '}
                                            <span className="text-text-muted text-sm">
                                                v{timetable.version}
                                            </span>
                                        </h3>
                                        <p className="text-text-secondary text-sm">
                                            {timetable.status} · {timetable.timezone} ·{' '}
                                            {timetable.meetings_count} meetings
                                        </p>
                                    </div>
                                    <div className="flex flex-wrap gap-2">
                                        {timetable.status === 'draft' ? (
                                            <>
                                                <button
                                                    className="border-border focus-ring min-h-10 rounded-lg border px-3 text-sm"
                                                    onClick={() =>
                                                        setOpenId(
                                                            openId === timetable.id ? null : timetable.id,
                                                        )
                                                    }
                                                >
                                                    {openId === timetable.id ? 'Close editor' : 'Edit draft'}
                                                </button>
                                                <button
                                                    className="bg-brand-action focus-ring min-h-10 rounded-lg px-3 text-sm text-white"
                                                    onClick={() =>
                                                        router.post(`${base}/${timetable.id}/submit`)
                                                    }
                                                >
                                                    Submit for review
                                                </button>
                                            </>
                                        ) : null}
                                        {timetable.status === 'review' && props.canApprove ? (
                                            <button
                                                className="bg-brand-action focus-ring min-h-10 rounded-lg px-3 text-sm text-white"
                                                onClick={() =>
                                                    router.post(`${base}/${timetable.id}/review`, {
                                                        decision: 'approve',
                                                        note: 'Reviewed scheduling conflicts, capacity, and academic period scope.',
                                                    })
                                                }
                                            >
                                                Approve
                                            </button>
                                        ) : null}
                                        {timetable.status === 'approved' && props.canApprove ? (
                                            <button
                                                className="bg-brand-action focus-ring min-h-10 rounded-lg px-3 text-sm text-white"
                                                onClick={() => router.post(`${base}/${timetable.id}/publish`)}
                                            >
                                                Publish
                                            </button>
                                        ) : null}
                                        {timetable.status === 'published' && props.canManage ? (
                                            <button
                                                className="border-border focus-ring min-h-10 rounded-lg border px-3 text-sm"
                                                onClick={() => router.post(`${base}/${timetable.id}/revise`)}
                                            >
                                                Create next draft version
                                            </button>
                                        ) : null}
                                    </div>
                                </div>
                                {timetable.meetings.map((meeting) => {
                                    const section = props.sections.find(
                                        (item) => item.id === meeting.section_id,
                                    );
                                    return (
                                        <div
                                            key={meeting.id}
                                            className="border-border mt-3 rounded-lg border p-3 text-sm"
                                        >
                                            <strong>
                                                {section?.course_code ?? 'Course'} ·{' '}
                                                {section?.section_code ?? `Section ${meeting.section_id}`}
                                            </strong>
                                            <p className="text-text-secondary mt-1">
                                                {meeting.weekdays
                                                    .map(
                                                        (day) =>
                                                            dayOptions.find(
                                                                ([number]) => number === day,
                                                            )?.[1],
                                                    )
                                                    .join(', ')}{' '}
                                                · {meeting.starts_at_local.slice(0, 5)} ·{' '}
                                                {meeting.duration_minutes} min · {meeting.starts_on} to{' '}
                                                {meeting.ends_on}
                                            </p>
                                            {timetable.status === 'draft' && props.canManage ? (
                                                <div className="mt-3 flex flex-wrap gap-2">
                                                    <button
                                                        type="button"
                                                        className="border-border focus-ring min-h-10 rounded-lg border px-3 text-sm"
                                                        aria-expanded={editingMeetingId === meeting.id}
                                                        onClick={() =>
                                                            setEditingMeetingId((current) =>
                                                                current === meeting.id ? null : meeting.id,
                                                            )
                                                        }
                                                    >
                                                        {editingMeetingId === meeting.id
                                                            ? 'Close series editor'
                                                            : 'Edit recurring series'}
                                                    </button>
                                                </div>
                                            ) : null}
                                            {timetable.status === 'draft' &&
                                            props.canManage &&
                                            editingMeetingId === meeting.id ? (
                                                <MeetingForm
                                                    tenant={props.tenant}
                                                    timetable={timetable}
                                                    sections={props.sections}
                                                    rooms={props.rooms}
                                                    existing={meeting}
                                                />
                                            ) : null}
                                            {timetable.status === 'draft' && props.canManage ? (
                                                <CancelSeriesForm tenant={props.tenant} meeting={meeting} />
                                            ) : null}
                                            {timetable.status === 'published' && props.canManage ? (
                                                <OccurrenceChangeForm
                                                    tenant={props.tenant}
                                                    meeting={meeting}
                                                    section={section}
                                                    rooms={props.rooms.filter(
                                                        (room) =>
                                                            !timetable.campus_id ||
                                                            room.campus_id === timetable.campus_id,
                                                    )}
                                                    instructors={props.sections.filter(
                                                        (item) => item.id === meeting.section_id,
                                                    )}
                                                />
                                            ) : null}
                                        </div>
                                    );
                                })}
                                <div
                                    className="border-border mt-4 overflow-x-auto rounded-xl border"
                                    role="region"
                                    aria-label={`${timetable.name} ${calendarView} view`}
                                    tabIndex={0}
                                >
                                    <div
                                        className={`grid ${calendarView === 'week' ? 'min-w-[840px] grid-cols-7' : 'grid-cols-1'}`}
                                    >
                                        {(calendarView === 'week'
                                            ? dayOptions
                                            : dayOptions.filter(([day]) => day === selectedDay)
                                        ).map(([day, label]) => {
                                            const entries = timetable.meetings.filter((meeting) =>
                                                meeting.weekdays.includes(day),
                                            );
                                            return (
                                                <section
                                                    key={day}
                                                    className="border-border min-h-28 border-r p-3 last:border-r-0"
                                                    aria-label={`${label} scheduled classes`}
                                                >
                                                    <h4 className="text-text-secondary text-xs font-semibold tracking-wide uppercase">
                                                        {label}
                                                    </h4>
                                                    <ul className="mt-2 space-y-2">
                                                        {entries.map((meeting) => {
                                                            const section = props.sections.find(
                                                                (item) => item.id === meeting.section_id,
                                                            );
                                                            const room = props.rooms.find(
                                                                (item) => item.id === meeting.room_id,
                                                            );
                                                            const groupLabel =
                                                                groupBy === 'section'
                                                                    ? `${section?.course_code ?? 'Course'} · ${section?.section_code ?? `Section ${meeting.section_id}`}`
                                                                    : groupBy === 'instructor'
                                                                      ? (section?.instructor_name ??
                                                                        'Unassigned instructor')
                                                                      : (room?.name ?? 'Online / no room');
                                                            return (
                                                                <li
                                                                    key={meeting.id}
                                                                    className="bg-brand-blue/10 text-text-primary rounded-lg p-2 text-xs"
                                                                >
                                                                    <strong>
                                                                        {meeting.starts_at_local.slice(0, 5)}
                                                                    </strong>
                                                                    <br />
                                                                    {groupLabel}
                                                                    <br />
                                                                    {meeting.duration_minutes} min
                                                                </li>
                                                            );
                                                        })}
                                                    </ul>
                                                </section>
                                            );
                                        })}
                                    </div>
                                </div>
                                {openId === timetable.id && timetable.status === 'draft' ? (
                                    <MeetingForm
                                        tenant={props.tenant}
                                        timetable={timetable}
                                        sections={props.sections}
                                        rooms={props.rooms}
                                    />
                                ) : null}
                                {timetable.status === 'review' ? (
                                    <p className="text-text-muted mt-3 text-sm">
                                        Review requires `academic.policy.approve`. The submitter cannot
                                        approve this version.
                                    </p>
                                ) : null}
                            </article>
                        ))
                    ) : (
                        <div className="border-border bg-surface rounded-2xl border p-6 text-sm">
                            <p className="font-medium">No timetable versions yet.</p>
                            <p className="text-text-secondary mt-1">
                                Create a draft for an active academic period to begin scheduling.
                            </p>
                        </div>
                    )}
                </section>
                {props.changes.length ? (
                    <section
                        className="border-border bg-surface rounded-2xl border p-5"
                        aria-labelledby="schedule-changes-heading"
                    >
                        <h2 id="schedule-changes-heading" className="font-semibold">
                            Occurrence change history
                        </h2>
                        <ul className="mt-3 space-y-3">
                            {props.changes.map((change) => (
                                <li key={change.id} className="border-border rounded-lg border p-3">
                                    <div className="flex flex-wrap items-center justify-between gap-3">
                                        <div>
                                            <p className="font-medium capitalize">
                                                {change.change_type} ·{' '}
                                                {change.meeting.section.offering.course.code} /{' '}
                                                {change.meeting.section.section_code}
                                            </p>
                                            <p className="text-text-secondary text-sm">
                                                {change.occurrence_date}
                                                {change.replacement_date
                                                    ? ` → ${change.replacement_date}`
                                                    : ''}{' '}
                                                · {change.status}
                                            </p>
                                            <p className="text-text-secondary mt-1 text-sm">
                                                {change.reason}
                                            </p>
                                        </div>
                                        {change.status === 'pending' && props.canApprove ? (
                                            <div className="flex gap-2">
                                                <button
                                                    type="button"
                                                    className="bg-brand-action focus-ring min-h-10 rounded-lg px-3 text-sm text-white"
                                                    onClick={() =>
                                                        router.post(
                                                            `/t/${props.tenant.slug}/academics/timetable-changes/${change.id}/decision`,
                                                            { decision: 'approve' },
                                                        )
                                                    }
                                                >
                                                    Approve
                                                </button>
                                                <button
                                                    type="button"
                                                    className="border-border focus-ring min-h-10 rounded-lg border px-3 text-sm"
                                                    onClick={() =>
                                                        router.post(
                                                            `/t/${props.tenant.slug}/academics/timetable-changes/${change.id}/decision`,
                                                            { decision: 'reject' },
                                                        )
                                                    }
                                                >
                                                    Reject
                                                </button>
                                            </div>
                                        ) : null}
                                    </div>
                                </li>
                            ))}
                        </ul>
                    </section>
                ) : null}
            </main>
        </WorkspaceLayout>
    );
}
