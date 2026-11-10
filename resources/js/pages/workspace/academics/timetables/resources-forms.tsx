import { useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';
import { FormErrors } from '@/components/ui/form-errors';

type Room = { id: number; code?: string; name: string };
type Instructor = { id: number; name: string };
type Props = {
    endpoint: string;
    campusScope: number | null;
    rooms: Room[];
    instructors: Instructor[];
    roomBlocks: { id: number; room_id: number; block_type: string; reason: string; room: Room | null }[];
    availability: {
        id: number;
        user_id: number;
        availability_type: string;
        starts_at: string;
        ends_at: string;
    }[];
};

const control =
    'border-border bg-surface text-text-primary focus-ring min-h-10 w-full rounded-lg border px-3 text-sm';
const label = 'text-text-secondary block text-sm font-medium';

export function ResourceForms({
    endpoint,
    campusScope,
    rooms,
    instructors,
    roomBlocks,
    availability,
}: Props) {
    const block = useForm({
        room_id: rooms[0]?.id ?? '',
        starts_at_utc: '',
        ends_at_utc: '',
        block_type: 'maintenance',
        reason: '',
    });
    const available = useForm({
        campus_id: campusScope ?? '',
        user_id: instructors[0]?.id ?? '',
        weekdays: [1, 2, 3, 4, 5],
        effective_from: '',
        effective_until: '',
        starts_at: '08:00',
        ends_at: '17:00',
        availability_type: 'unavailable',
    });
    const saveBlock = (event: FormEvent) => {
        event.preventDefault();
        const utc = (value: string) => `${new Date(value).toISOString().slice(0, 19)}+00:00`;
        block.transform((data) => ({
            ...data,
            starts_at_utc: utc(data.starts_at_utc),
            ends_at_utc: utc(data.ends_at_utc),
        }));
        block.post(`${endpoint}/room-blocks`);
    };
    const saveAvailability = (event: FormEvent) => {
        event.preventDefault();
        available.post(`${endpoint}/availability`);
    };

    return (
        <section className="grid gap-4 xl:grid-cols-2" aria-label="Availability and room maintenance">
            <form
                onSubmit={saveAvailability}
                className="border-border bg-surface grid gap-3 rounded-2xl border p-5 sm:grid-cols-2"
            >
                <h2 className="font-semibold sm:col-span-2">Instructor availability</h2>
                <label className={label}>
                    Instructor
                    <select
                        className={`${control} mt-1`}
                        value={available.data.user_id}
                        onChange={(event) => available.setData('user_id', Number(event.target.value))}
                        required
                    >
                        {instructors.map((person) => (
                            <option key={person.id} value={person.id}>
                                {person.name}
                            </option>
                        ))}
                    </select>
                </label>
                <label className={label}>
                    Rule
                    <select
                        className={`${control} mt-1`}
                        value={available.data.availability_type}
                        onChange={(event) => available.setData('availability_type', event.target.value)}
                    >
                        <option value="unavailable">Unavailable during these hours</option>
                        <option value="available">Available only during these hours</option>
                    </select>
                </label>
                <fieldset className="sm:col-span-2">
                    <legend className="text-text-secondary mb-2 text-sm">Weekdays</legend>
                    <div className="flex flex-wrap gap-3">
                        {['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'].map((day, index) => (
                            <label key={day} className="inline-flex min-h-10 items-center gap-2 text-sm">
                                <input
                                    type="checkbox"
                                    checked={available.data.weekdays.includes(index + 1)}
                                    onChange={(event) =>
                                        available.setData(
                                            'weekdays',
                                            event.target.checked
                                                ? [...available.data.weekdays, index + 1].sort()
                                                : available.data.weekdays.filter(
                                                      (value) => value !== index + 1,
                                                  ),
                                        )
                                    }
                                />
                                {day}
                            </label>
                        ))}
                    </div>
                </fieldset>
                <label className={label}>
                    Effective from
                    <input
                        type="date"
                        className={`${control} mt-1`}
                        value={available.data.effective_from}
                        onChange={(event) => available.setData('effective_from', event.target.value)}
                        required
                    />
                </label>
                <label className={label}>
                    Through
                    <input
                        type="date"
                        className={`${control} mt-1`}
                        value={available.data.effective_until}
                        onChange={(event) => available.setData('effective_until', event.target.value)}
                        required
                    />
                </label>
                <label className={label}>
                    From
                    <input
                        type="time"
                        className={`${control} mt-1`}
                        value={available.data.starts_at}
                        onChange={(event) => available.setData('starts_at', event.target.value)}
                        required
                    />
                </label>
                <label className={label}>
                    Until
                    <input
                        type="time"
                        className={`${control} mt-1`}
                        value={available.data.ends_at}
                        onChange={(event) => available.setData('ends_at', event.target.value)}
                        required
                    />
                </label>
                <button
                    className="bg-brand-action focus-ring min-h-10 rounded-lg px-4 text-sm text-white"
                    disabled={available.processing || instructors.length === 0}
                >
                    Save availability
                </button>
                <FormErrors errors={available.errors} />
                <ul className="text-text-secondary space-y-1 text-sm sm:col-span-2">
                    {availability.map((rule) => (
                        <li key={rule.id}>
                            Instructor {rule.user_id} · {rule.availability_type} ·{' '}
                            {rule.starts_at.slice(0, 5)}–{rule.ends_at.slice(0, 5)}
                        </li>
                    ))}
                </ul>
            </form>
            <form
                onSubmit={saveBlock}
                className="border-border bg-surface grid gap-3 rounded-2xl border p-5 sm:grid-cols-2"
            >
                <h2 className="font-semibold sm:col-span-2">Room unavailability</h2>
                <p className="text-text-secondary text-sm sm:col-span-2">
                    Choose times in your device time zone; the server stores UTC instants.
                </p>
                <label className={label}>
                    Room
                    <select
                        className={`${control} mt-1`}
                        value={block.data.room_id}
                        onChange={(event) => block.setData('room_id', Number(event.target.value))}
                        required
                    >
                        {rooms.map((room) => (
                            <option key={room.id} value={room.id}>
                                {room.name} · {room.code}
                            </option>
                        ))}
                    </select>
                </label>
                <label className={label}>
                    Reason
                    <input
                        className={`${control} mt-1`}
                        value={block.data.reason}
                        onChange={(event) => block.setData('reason', event.target.value)}
                        maxLength={500}
                        required
                    />
                </label>
                <label className={label}>
                    Starts
                    <input
                        type="datetime-local"
                        className={`${control} mt-1`}
                        value={block.data.starts_at_utc}
                        onChange={(event) => block.setData('starts_at_utc', event.target.value)}
                        required
                    />
                </label>
                <label className={label}>
                    Ends
                    <input
                        type="datetime-local"
                        className={`${control} mt-1`}
                        value={block.data.ends_at_utc}
                        onChange={(event) => block.setData('ends_at_utc', event.target.value)}
                        required
                    />
                </label>
                <label className={label}>
                    Type
                    <select
                        className={`${control} mt-1`}
                        value={block.data.block_type}
                        onChange={(event) => block.setData('block_type', event.target.value)}
                    >
                        <option value="maintenance">Maintenance</option>
                        <option value="closure">Closure</option>
                        <option value="reservation">Reservation</option>
                    </select>
                </label>
                <button
                    className="bg-brand-action focus-ring min-h-10 rounded-lg px-4 text-sm text-white"
                    disabled={block.processing || rooms.length === 0}
                >
                    Block room
                </button>
                <FormErrors errors={block.errors} />
                <ul className="text-text-secondary space-y-1 text-sm sm:col-span-2">
                    {roomBlocks.map((item) => (
                        <li key={item.id}>
                            {item.room?.name ?? `Room ${item.room_id}`} · {item.block_type} · {item.reason}
                        </li>
                    ))}
                </ul>
            </form>
        </section>
    );
}
