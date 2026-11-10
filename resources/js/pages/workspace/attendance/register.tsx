import { Head, Link, router, useForm } from '@inertiajs/react';
import { WorkspaceLayout } from '@/layouts/workspace-layout';

type Entry = {
    id: number;
    student_id: number;
    student: string;
    student_number: string;
    status_code: string | null;
    reason_code: string | null;
    minutes_attended: number | null;
};
type Status = { id: number; code: string; label: string; color: string; requires_reason: boolean };
type Props = {
    tenant: { slug: string };
    register: {
        id: number;
        date: string;
        status: string;
        version: number;
        expected_count: number;
        entries: Entry[];
    };
    statuses: Status[];
    permissions: { mark: boolean; submit: boolean; override: boolean };
};

export default function AttendanceRegisterPage({ tenant, register, statuses, permissions }: Props) {
    const form = useForm({
        version: register.version,
        entries: register.entries.map((entry) => ({
            entry_id: entry.id,
            status_code: entry.status_code ?? '',
            reason_code: entry.reason_code ?? '',
            minutes_attended: entry.minutes_attended?.toString() ?? '',
        })),
    });
    const submitForm = useForm({ version: register.version });
    const setStatus = (index: number, code: string) =>
        form.setData(
            'entries',
            form.data.entries.map((entry, entryIndex) =>
                entryIndex === index ? { ...entry, status_code: code } : entry,
            ),
        );
    const setReason = (index: number, reason: string) =>
        form.setData(
            'entries',
            form.data.entries.map((entry, entryIndex) =>
                entryIndex === index ? { ...entry, reason_code: reason } : entry,
            ),
        );
    const setMinutes = (index: number, minutes: string) =>
        form.setData(
            'entries',
            form.data.entries.map((entry, entryIndex) =>
                entryIndex === index ? { ...entry, minutes_attended: minutes } : entry,
            ),
        );
    const editable = ['draft', 'reopened'].includes(register.status);
    const allPresent = () =>
        form.setData(
            'entries',
            form.data.entries.map((entry) => ({ ...entry, status_code: 'PRESENT' })),
        );

    return (
        <WorkspaceLayout title="Attendance register" area="tenant">
            <Head title={`Attendance register · ${register.date}`} />
            <main id="main-content" className="mx-auto max-w-6xl space-y-6 px-4 py-8 sm:px-6 lg:px-8">
                <header className="flex flex-wrap items-start justify-between gap-4">
                    <div>
                        <Link
                            href={`/t/${tenant.slug}/attendance`}
                            className="text-brand-link text-sm focus-visible:outline-2"
                        >
                            ← Attendance
                        </Link>
                        <p className="text-brand-link mt-4 text-sm font-semibold uppercase">Class register</p>
                        <h1 className="text-text-primary mt-1 text-3xl font-semibold">
                            Attendance · {register.date}
                        </h1>
                        <p className="text-text-secondary mt-2">
                            {register.expected_count} expected learners · {register.status} · Revision{' '}
                            {register.version}
                        </p>
                    </div>
                    {permissions.mark && editable && (
                        <button
                            type="button"
                            onClick={allPresent}
                            className="rounded-lg border px-4 py-2 text-sm font-medium focus-visible:outline-2"
                        >
                            Mark all present
                        </button>
                    )}
                </header>

                <form
                    onSubmit={(event) => {
                        event.preventDefault();
                        form.transform((data) => ({
                            ...data,
                            entries: data.entries
                                .filter((entry) => entry.status_code !== '')
                                .map((entry) => ({
                                    ...entry,
                                    minutes_attended:
                                        entry.minutes_attended === '' ? null : Number(entry.minutes_attended),
                                    reason_code: entry.reason_code || null,
                                })),
                        }));
                        form.patch(`/t/${tenant.slug}/attendance/registers/${register.id}/entries`, {
                            onSuccess: () => {
                                const nextVersion = register.version + 1;
                                form.setData('version', nextVersion);
                                submitForm.setData('version', nextVersion);
                            },
                        });
                    }}
                    className="surface overflow-hidden rounded-2xl border"
                >
                    <div className="border-border flex flex-wrap items-center justify-between gap-3 border-b p-4 sm:p-5">
                        <div>
                            <h2 className="text-text-primary font-semibold">Expected roster</h2>
                            <p className="text-text-secondary text-sm">
                                Unmarked stays distinct from absent. Only statuses selected below will be
                                saved.
                            </p>
                        </div>
                        {permissions.mark && (
                            <button
                                disabled={form.processing || !editable}
                                className="bg-brand-action rounded-lg px-4 py-2 text-sm font-medium text-white disabled:opacity-50"
                                type="submit"
                            >
                                {form.processing ? 'Saving…' : 'Save attendance'}
                            </button>
                        )}
                    </div>
                    {form.errors.entries && (
                        <p role="alert" className="bg-danger/10 text-danger p-4">
                            {form.errors.entries}
                        </p>
                    )}
                    <ul className="divide-border divide-y">
                        {register.entries.map((entry, index) => {
                            const selected = statuses.find(
                                (status) => status.code === form.data.entries[index]?.status_code,
                            );
                            return (
                                <li
                                    key={entry.id}
                                    className="grid gap-4 p-4 sm:grid-cols-[minmax(12rem,1fr)_minmax(12rem,0.8fr)_minmax(10rem,0.7fr)] sm:items-center sm:p-5"
                                >
                                    <div>
                                        <h3 className="text-text-primary font-medium">{entry.student}</h3>
                                        <p className="text-text-secondary text-sm">{entry.student_number}</p>
                                    </div>
                                    <label className="text-sm font-medium">
                                        Attendance status
                                        <select
                                            disabled={!permissions.mark || !editable}
                                            className="mt-1 w-full rounded-lg border px-3 py-2"
                                            value={form.data.entries[index]?.status_code}
                                            onChange={(event) => setStatus(index, event.target.value)}
                                        >
                                            <option value="">Not marked</option>
                                            {statuses.map((status) => (
                                                <option key={status.id} value={status.code}>
                                                    {status.label}
                                                </option>
                                            ))}
                                        </select>
                                    </label>
                                    {selected?.requires_reason && (
                                        <label className="text-sm font-medium">
                                            Reason code
                                            <input
                                                required
                                                disabled={!permissions.mark || !editable}
                                                maxLength={64}
                                                className="mt-1 w-full rounded-lg border px-3 py-2"
                                                value={form.data.entries[index]?.reason_code}
                                                onChange={(event) => setReason(index, event.target.value)}
                                            />
                                        </label>
                                    )}
                                    {selected?.code === 'PARTIAL' && (
                                        <label className="text-sm font-medium">
                                            Minutes attended
                                            <input
                                                required
                                                type="number"
                                                min="1"
                                                max="1440"
                                                className="mt-1 w-full rounded-lg border px-3 py-2"
                                                value={form.data.entries[index]?.minutes_attended}
                                                onChange={(event) => setMinutes(index, event.target.value)}
                                            />
                                        </label>
                                    )}
                                    <p className="text-text-secondary text-xs sm:col-span-full">
                                        Current: {selected?.label ?? 'Not marked'}
                                    </p>
                                </li>
                            );
                        })}
                        {register.entries.length === 0 && (
                            <li className="text-text-secondary p-8 text-center">
                                No confirmed learners were on the roster at this occurrence. No attendance
                                will be inferred.
                            </li>
                        )}
                    </ul>
                    {permissions.submit && (
                        <div className="border-border flex flex-wrap items-center justify-between gap-3 border-t p-4 sm:p-5">
                            <p className="text-text-secondary max-w-xl text-sm">
                                Submission is blocked until every expected learner has an explicit status. It
                                does not convert blanks to absences.
                            </p>
                            <button
                                disabled={submitForm.processing || form.processing || !editable}
                                type="button"
                                className="rounded-lg border px-4 py-2 text-sm font-medium disabled:opacity-50"
                                onClick={() =>
                                    submitForm.post(
                                        `/t/${tenant.slug}/attendance/registers/${register.id}/submit`,
                                    )
                                }
                            >
                                Submit register
                            </button>
                        </div>
                    )}
                    {permissions.override && register.status === 'submitted' && (
                        <div className="border-border flex flex-wrap items-center justify-between gap-3 border-t p-4 sm:p-5">
                            <p className="text-text-secondary text-sm">
                                Finalization preserves the submitted attendance and may lock this register
                                according to its policy.
                            </p>
                            <button
                                type="button"
                                className="bg-brand-action rounded-lg px-4 py-2 text-sm font-medium text-white"
                                onClick={() =>
                                    router.post(
                                        `/t/${tenant.slug}/attendance/registers/${register.id}/finalize`,
                                        { version: register.version },
                                    )
                                }
                            >
                                Finalize register
                            </button>
                        </div>
                    )}
                    {permissions.override && ['locked', 'finalized'].includes(register.status) && (
                        <form
                            className="border-border flex flex-col gap-3 border-t p-4 sm:flex-row sm:items-end sm:p-5"
                            onSubmit={(event) => {
                                event.preventDefault();
                                const formData = new FormData(event.currentTarget);
                                router.post(`/t/${tenant.slug}/attendance/registers/${register.id}/reopen`, {
                                    reason_code: formData.get('reason_code'),
                                });
                            }}
                        >
                            <label className="flex-1 text-sm font-medium">
                                Reopen reason code
                                <input
                                    name="reason_code"
                                    required
                                    minLength={8}
                                    maxLength={64}
                                    className="mt-1 w-full rounded-lg border px-3 py-2"
                                />
                            </label>
                            <button className="rounded-lg border px-4 py-2 text-sm font-medium" type="submit">
                                Reopen register
                            </button>
                        </form>
                    )}
                </form>
            </main>
        </WorkspaceLayout>
    );
}
