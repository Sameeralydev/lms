import { Head, Link, router, useForm } from '@inertiajs/react';
import { WorkspaceLayout } from '@/layouts/workspace-layout';

type Occurrence = {
    meeting_id: number;
    date: string;
    starts_at: string;
    course: string;
    section: string;
    instructor: string;
    register_id: number | null;
};

type Props = {
    tenant: { slug: string };
    metrics: {
        registers_today: number;
        missing_registers: number;
        unsubmitted: number;
        pending_corrections: number;
    };
    occurrences: Occurrence[];
    policies: {
        id: number;
        name: string;
        campus_id: number | null;
        version: number;
        statuses: { code: string; label: string; color: string }[];
        settings: {
            minimum_attendance_percent?: number | null;
            grace_period_minutes?: number;
            late_after_minutes?: number;
            marking_opens_before_minutes?: number;
            marking_closes_after_minutes?: number;
            correction_deadline_days?: number;
            retroactive_marking?: boolean;
            independent_correction_review?: boolean;
            lock_on_finalize?: boolean;
        };
    }[];
    permissions: { manage_policy: boolean; mark: boolean; view_reports: boolean };
    institutionId: number;
    campuses: { id: number; name: string }[];
};

function PolicyRevisionForm({ tenant, policy }: { tenant: string; policy: Props['policies'][number] }) {
    const form = useForm({
        name: policy.name,
        minimum_attendance_percent: policy.settings.minimum_attendance_percent?.toString() ?? '',
        grace_period_minutes: (policy.settings.grace_period_minutes ?? 0).toString(),
        late_after_minutes: (policy.settings.late_after_minutes ?? 10).toString(),
        marking_opens_before_minutes: (policy.settings.marking_opens_before_minutes ?? 1440).toString(),
        marking_closes_after_minutes: (policy.settings.marking_closes_after_minutes ?? 10080).toString(),
        correction_deadline_days: (policy.settings.correction_deadline_days ?? 7).toString(),
        retroactive_marking: Boolean(policy.settings.retroactive_marking),
        independent_correction_review: policy.settings.independent_correction_review ?? true,
        lock_on_finalize: policy.settings.lock_on_finalize ?? true,
        statuses: Object.fromEntries(
            policy.statuses.map((status) => [status.code, { label: status.label, color: status.color }]),
        ) as Record<string, { label: string; color: string }>,
    });
    return (
        <details className="mt-4">
            <summary className="text-brand-link cursor-pointer text-sm font-medium">
                Revise policy settings
            </summary>
            <form
                className="mt-3 grid gap-3 sm:grid-cols-2"
                onSubmit={(event) => {
                    event.preventDefault();
                    form.patch(`/t/${tenant}/attendance/policies/${policy.id}`);
                }}
            >
                <label className="text-sm">
                    Policy name
                    <input
                        required
                        maxLength={120}
                        className="mt-1 w-full rounded-lg border px-3 py-2"
                        value={form.data.name}
                        onChange={(event) => form.setData('name', event.target.value)}
                    />
                </label>
                <label className="text-sm">
                    Minimum attendance (%)
                    <input
                        type="number"
                        min="0"
                        max="100"
                        step="0.01"
                        className="mt-1 w-full rounded-lg border px-3 py-2"
                        value={form.data.minimum_attendance_percent}
                        onChange={(event) => form.setData('minimum_attendance_percent', event.target.value)}
                    />
                </label>
                <label className="text-sm">
                    Grace (minutes)
                    <input
                        type="number"
                        min="0"
                        max="240"
                        className="mt-1 w-full rounded-lg border px-3 py-2"
                        value={form.data.grace_period_minutes}
                        onChange={(event) => form.setData('grace_period_minutes', event.target.value)}
                    />
                </label>
                <label className="text-sm">
                    Late after (minutes)
                    <input
                        type="number"
                        min="0"
                        max="1440"
                        className="mt-1 w-full rounded-lg border px-3 py-2"
                        value={form.data.late_after_minutes}
                        onChange={(event) => form.setData('late_after_minutes', event.target.value)}
                    />
                </label>
                <label className="text-sm">
                    Correction deadline (days)
                    <input
                        type="number"
                        min="0"
                        max="365"
                        className="mt-1 w-full rounded-lg border px-3 py-2"
                        value={form.data.correction_deadline_days}
                        onChange={(event) => form.setData('correction_deadline_days', event.target.value)}
                    />
                </label>
                <div className="space-y-2 text-sm sm:col-span-2">
                    {[
                        ['retroactive_marking', 'Allow retroactive marking'],
                        ['independent_correction_review', 'Require independent review'],
                        ['lock_on_finalize', 'Lock register after finalization'],
                    ].map(([key, label]) => (
                        <label key={key} className="flex items-center gap-2">
                            <input
                                type="checkbox"
                                checked={form.data[key as keyof typeof form.data] as boolean}
                                onChange={(event) =>
                                    form.setData(
                                        key as
                                            | 'retroactive_marking'
                                            | 'independent_correction_review'
                                            | 'lock_on_finalize',
                                        event.target.checked,
                                    )
                                }
                            />
                            {label}
                        </label>
                    ))}
                </div>
                {form.errors.name && (
                    <p role="alert" className="text-danger sm:col-span-2">
                        {form.errors.name}
                    </p>
                )}
                <button
                    disabled={form.processing}
                    className="bg-brand-action rounded-lg px-4 py-2 text-sm font-medium text-white disabled:opacity-60 sm:col-span-2"
                    type="submit"
                >
                    {form.processing ? 'Saving…' : 'Save as new policy version'}
                </button>
            </form>
        </details>
    );
}

export default function AttendanceIndex({
    tenant,
    metrics,
    occurrences,
    policies,
    permissions,
    institutionId,
    campuses,
}: Props) {
    const policyForm = useForm({
        institution_id: institutionId,
        campus_id: '',
        name: '',
        minimum_attendance_percent: '75',
        grace_period_minutes: '0',
        late_after_minutes: '10',
        marking_opens_before_minutes: '10080',
        marking_closes_after_minutes: '10080',
        correction_deadline_days: '7',
        retroactive_marking: false,
    });

    return (
        <WorkspaceLayout title="Attendance" area="tenant">
            <Head title="Attendance operations" />
            <main id="main-content" className="mx-auto max-w-7xl space-y-8 px-4 py-8 sm:px-6 lg:px-8">
                <header className="space-y-2">
                    <p className="text-brand-link text-sm font-semibold tracking-wide uppercase">
                        Academic operations
                    </p>
                    <h1 className="text-text-primary text-3xl font-semibold">Attendance</h1>
                    <p className="text-text-secondary max-w-3xl">
                        Registers come from published timetable occurrences and confirmed section enrollment.
                        A blank status remains unmarked until an instructor records it.
                    </p>
                </header>

                <section
                    aria-label="Attendance overview"
                    className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4"
                >
                    {[
                        ['Registers today', metrics.registers_today],
                        ['Classes without a register', metrics.missing_registers],
                        ['Draft registers', metrics.unsubmitted],
                        ['Corrections awaiting review', metrics.pending_corrections],
                    ].map(([label, value]) => (
                        <article key={label} className="surface rounded-2xl border p-5">
                            <h2 className="text-text-secondary text-sm">{label}</h2>
                            <p className="text-text-primary mt-2 text-3xl font-semibold">{value}</p>
                        </article>
                    ))}
                </section>

                <section
                    className="surface rounded-2xl border p-5 sm:p-6"
                    aria-labelledby="occurrences-heading"
                >
                    <div className="mb-5 flex flex-wrap items-start justify-between gap-3">
                        <div>
                            <h2 id="occurrences-heading" className="text-text-primary text-xl font-semibold">
                                Published classes
                            </h2>
                            <p className="text-text-secondary mt-1 text-sm">
                                Upcoming occurrences for the next seven days.
                            </p>
                        </div>
                        <nav aria-label="Attendance operations" className="flex flex-wrap gap-2">
                            {permissions.view_reports && (
                                <>
                                    <Link
                                        className="text-brand-link rounded-lg px-3 py-2 text-sm font-medium focus-visible:outline-2"
                                        href={`/t/${tenant.slug}/attendance/reports`}
                                    >
                                        Reports
                                    </Link>
                                    <Link
                                        className="text-brand-link rounded-lg px-3 py-2 text-sm font-medium focus-visible:outline-2"
                                        href={`/t/${tenant.slug}/attendance/corrections`}
                                    >
                                        Correction queue
                                    </Link>
                                    <Link
                                        className="text-brand-link rounded-lg px-3 py-2 text-sm font-medium focus-visible:outline-2"
                                        href={`/t/${tenant.slug}/attendance/justifications`}
                                    >
                                        Absence justifications
                                    </Link>
                                </>
                            )}
                        </nav>
                    </div>
                    {occurrences.length === 0 ? (
                        <p className="text-text-secondary rounded-xl border border-dashed p-8 text-center">
                            No scheduled classes are available in this period.
                        </p>
                    ) : (
                        <ul className="divide-border divide-y">
                            {occurrences.map((occurrence) => (
                                <li
                                    key={`${occurrence.meeting_id}-${occurrence.date}`}
                                    className="flex flex-col gap-3 py-4 sm:flex-row sm:items-center sm:justify-between"
                                >
                                    <div>
                                        <h3 className="text-text-primary font-medium">
                                            {occurrence.course} · {occurrence.section}
                                        </h3>
                                        <p className="text-text-secondary mt-1 text-sm">
                                            {occurrence.date} ·{' '}
                                            {new Date(occurrence.starts_at).toLocaleTimeString([], {
                                                hour: '2-digit',
                                                minute: '2-digit',
                                            })}{' '}
                                            · {occurrence.instructor}
                                        </p>
                                    </div>
                                    {occurrence.register_id ? (
                                        <Link
                                            className="bg-brand-action rounded-lg px-4 py-2 text-center text-sm font-medium text-white focus-visible:outline-2"
                                            href={`/t/${tenant.slug}/attendance/registers/${occurrence.register_id}`}
                                        >
                                            Open register
                                        </Link>
                                    ) : permissions.mark ? (
                                        <button
                                            className="bg-brand-action rounded-lg px-4 py-2 text-sm font-medium text-white focus-visible:outline-2"
                                            type="button"
                                            onClick={() =>
                                                router.post(`/t/${tenant.slug}/attendance/registers`, {
                                                    meeting_id: occurrence.meeting_id,
                                                    occurrence_date: occurrence.date,
                                                })
                                            }
                                        >
                                            Open attendance register
                                        </button>
                                    ) : (
                                        <span className="text-text-secondary text-sm">No register</span>
                                    )}
                                </li>
                            ))}
                        </ul>
                    )}
                </section>

                {permissions.manage_policy && (
                    <section
                        className="surface grid gap-6 rounded-2xl border p-5 sm:p-6 lg:grid-cols-[minmax(0,1fr)_minmax(18rem,0.8fr)]"
                        aria-labelledby="policy-heading"
                    >
                        <div>
                            <h2 id="policy-heading" className="text-text-primary text-xl font-semibold">
                                Attendance policies
                            </h2>
                            <p className="text-text-secondary mt-1 text-sm">
                                Each new policy creates an immutable version. Existing registers retain their
                                original status semantics.
                            </p>
                            <ul className="mt-5 space-y-3">
                                {policies.map((policy) => (
                                    <li key={policy.id} className="border-border rounded-xl border p-4">
                                        <div className="flex items-center justify-between gap-3">
                                            <h3 className="text-text-primary font-medium">{policy.name}</h3>
                                            <span className="text-text-secondary text-xs">
                                                Version {policy.version}
                                            </span>
                                        </div>
                                        <ul
                                            className="mt-3 flex flex-wrap gap-2"
                                            aria-label={`${policy.name} status meanings`}
                                        >
                                            {policy.statuses.map((status) => (
                                                <li
                                                    key={status.code}
                                                    className="rounded-full border px-2.5 py-1 text-xs"
                                                    style={{ borderColor: status.color }}
                                                >
                                                    {status.label}
                                                </li>
                                            ))}
                                        </ul>
                                        <PolicyRevisionForm tenant={tenant.slug} policy={policy} />
                                    </li>
                                ))}
                                {policies.length === 0 && (
                                    <li className="text-text-secondary rounded-xl border border-dashed p-4">
                                        No attendance policy is configured yet.
                                    </li>
                                )}
                            </ul>
                        </div>
                        <form
                            className="space-y-4"
                            onSubmit={(event) => {
                                event.preventDefault();
                                policyForm.post(`/t/${tenant.slug}/attendance/policies`);
                            }}
                        >
                            <h3 className="text-text-primary font-semibold">Create policy</h3>
                            <label className="block text-sm font-medium">
                                Policy name
                                <input
                                    required
                                    maxLength={120}
                                    className="mt-1 w-full rounded-lg border px-3 py-2"
                                    value={policyForm.data.name}
                                    onChange={(event) => policyForm.setData('name', event.target.value)}
                                />
                            </label>
                            <div className="grid grid-cols-2 gap-3">
                                <label className="block text-sm font-medium">
                                    Open marking (minutes before class)
                                    <input
                                        type="number"
                                        min="0"
                                        max="10080"
                                        className="mt-1 w-full rounded-lg border px-3 py-2"
                                        value={policyForm.data.marking_opens_before_minutes}
                                        onChange={(event) =>
                                            policyForm.setData(
                                                'marking_opens_before_minutes',
                                                event.target.value,
                                            )
                                        }
                                    />
                                </label>
                                <label className="block text-sm font-medium">
                                    Close marking (minutes after class)
                                    <input
                                        type="number"
                                        min="0"
                                        max="43200"
                                        className="mt-1 w-full rounded-lg border px-3 py-2"
                                        value={policyForm.data.marking_closes_after_minutes}
                                        onChange={(event) =>
                                            policyForm.setData(
                                                'marking_closes_after_minutes',
                                                event.target.value,
                                            )
                                        }
                                    />
                                </label>
                            </div>
                            <label className="block text-sm font-medium">
                                Campus scope
                                <select
                                    className="mt-1 w-full rounded-lg border px-3 py-2"
                                    value={policyForm.data.campus_id}
                                    onChange={(event) => policyForm.setData('campus_id', event.target.value)}
                                >
                                    <option value="">Institution-wide</option>
                                    {campuses.map((campus) => (
                                        <option key={campus.id} value={campus.id}>
                                            {campus.name}
                                        </option>
                                    ))}
                                </select>
                            </label>
                            <label className="block text-sm font-medium">
                                Minimum attendance threshold (%)
                                <input
                                    type="number"
                                    min="0"
                                    max="100"
                                    step="0.01"
                                    className="mt-1 w-full rounded-lg border px-3 py-2"
                                    value={policyForm.data.minimum_attendance_percent}
                                    onChange={(event) =>
                                        policyForm.setData('minimum_attendance_percent', event.target.value)
                                    }
                                />
                            </label>
                            <div className="grid grid-cols-2 gap-3">
                                <label className="block text-sm font-medium">
                                    Grace (minutes)
                                    <input
                                        type="number"
                                        min="0"
                                        max="240"
                                        className="mt-1 w-full rounded-lg border px-3 py-2"
                                        value={policyForm.data.grace_period_minutes}
                                        onChange={(event) =>
                                            policyForm.setData('grace_period_minutes', event.target.value)
                                        }
                                    />
                                </label>
                                <label className="block text-sm font-medium">
                                    Late after (minutes)
                                    <input
                                        type="number"
                                        min="0"
                                        max="1440"
                                        className="mt-1 w-full rounded-lg border px-3 py-2"
                                        value={policyForm.data.late_after_minutes}
                                        onChange={(event) =>
                                            policyForm.setData('late_after_minutes', event.target.value)
                                        }
                                    />
                                </label>
                            </div>
                            <label className="flex items-start gap-3 text-sm">
                                <input
                                    type="checkbox"
                                    checked={policyForm.data.retroactive_marking}
                                    onChange={(event) =>
                                        policyForm.setData('retroactive_marking', event.target.checked)
                                    }
                                />
                                <span>Allow retroactive marking subject to the correction workflow</span>
                            </label>
                            {policyForm.errors.name && (
                                <p role="alert" className="text-danger">
                                    {policyForm.errors.name}
                                </p>
                            )}
                            <button
                                disabled={policyForm.processing}
                                className="bg-brand-action rounded-lg px-4 py-2 font-medium text-white disabled:opacity-60"
                                type="submit"
                            >
                                {policyForm.processing ? 'Saving…' : 'Create policy'}
                            </button>
                        </form>
                    </section>
                )}
            </main>
        </WorkspaceLayout>
    );
}
