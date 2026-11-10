import { Head, Link, router, useForm } from '@inertiajs/react';
import { WorkspaceLayout } from '@/layouts/workspace-layout';

type Props = {
    tenant: { slug: string };
    institution: { name: string; studentLabel: string };
    filters: { from?: string; until?: string; academic_year_id?: string };
    academicOptions: { years: { id: number; name: string }[] };
    registrySettings: {
        studentLabel: string;
        numberPrefix: string;
        numberWidth: number;
        documentRetentionDays: number | null;
        documentArchiveAfterDays: number | null;
        documentPurgeAfterDays: number | null;
        suspendMembershipOnStudentSuspension: boolean;
        promotionRequiresApproval: boolean;
        lifecycleRequiresApproval: boolean;
        hasStudents: boolean;
    };
    stats: {
        total: number;
        admitted: number;
        active: number;
        onLeave: number;
        suspended: number;
        graduated: number;
    };
    analytics: {
        campuses: { name: string; students: number }[];
        programs: { name: string; students: number }[];
        transfers: { pendingReview: number; approved: number; completed: number; rejected: number };
        promotions: number;
        guardians: { pending: number; verified: number; revoked: number };
    };
    recentStudents: {
        id: number;
        studentNumber: string;
        name: string;
        status: string;
        admissionDate: string;
        homeCampus: { name: string } | null;
    }[];
    permissions: { create: boolean; export: boolean; settings: boolean };
    lifecycleApproval: {
        id: number;
        student_id: number;
        student_number: string;
        name: string;
        from_status: string;
        to_status: string;
        reason_code: string;
        reason: string | null;
        requested_at: string;
        requester: string;
    }[];
    canReviewLifecycle: boolean;
};

const metricCards: { key: keyof Props['stats']; label: string; tone: string }[] = [
    { key: 'total', label: 'Registered learners', tone: 'text-brand-link' },
    { key: 'active', label: 'Active', tone: 'text-emerald-700' },
    { key: 'admitted', label: 'Admitted', tone: 'text-cyan-700' },
    { key: 'onLeave', label: 'On leave', tone: 'text-amber-700' },
    { key: 'suspended', label: 'Suspended', tone: 'text-rose-700' },
    { key: 'graduated', label: 'Graduated', tone: 'text-violet-700' },
];

export default function SisDashboard({
    tenant,
    institution,
    filters,
    academicOptions,
    registrySettings,
    stats,
    analytics,
    recentStudents,
    permissions,
    lifecycleApproval,
    canReviewLifecycle,
}: Props) {
    const settingsForm = useForm({
        student_label: registrySettings.studentLabel,
        number_prefix: registrySettings.numberPrefix,
        number_width: registrySettings.numberWidth.toString(),
        document_retention_days: registrySettings.documentRetentionDays?.toString() ?? '',
        document_archive_after_days: registrySettings.documentArchiveAfterDays?.toString() ?? '',
        document_purge_after_days: registrySettings.documentPurgeAfterDays?.toString() ?? '',
        suspend_membership_on_student_suspension: registrySettings.suspendMembershipOnStudentSuspension,
        promotion_requires_approval: registrySettings.promotionRequiresApproval,
        lifecycle_requires_approval: registrySettings.lifecycleRequiresApproval,
    });

    return (
        <WorkspaceLayout area="tenant" title="Student information system">
            <Head title="Student information system" />
            <div className="space-y-7">
                <section className="bg-surface border-border flex flex-col gap-5 rounded-2xl border p-6 sm:flex-row sm:items-end sm:justify-between">
                    <div>
                        <p className="text-brand-link text-xs font-bold tracking-[0.16em] uppercase">
                            Student information system
                        </p>
                        <h1 className="text-text-primary mt-2 text-3xl font-semibold tracking-tight">
                            {institution.name}
                        </h1>
                        <p className="text-text-muted mt-2 max-w-2xl text-sm">
                            Manage institutional records, academic placements, guardian authority and student
                            history from one secure registry.
                        </p>
                    </div>
                    <div className="flex flex-wrap gap-3">
                        <Link
                            href={`/t/${tenant.slug}/sis/students`}
                            className="border-border bg-surface text-text-primary inline-flex min-h-11 items-center rounded-lg border px-4 text-sm font-semibold"
                        >
                            Browse registry
                        </Link>
                        {permissions.create && (
                            <Link
                                href={`/t/${tenant.slug}/sis/students`}
                                className="bg-brand-action inline-flex min-h-11 items-center rounded-lg px-4 text-sm font-semibold text-white"
                            >
                                Register student
                            </Link>
                        )}
                    </div>
                </section>

                <section
                    aria-label="Student registry counts"
                    className="grid grid-cols-2 gap-3 sm:grid-cols-3 xl:grid-cols-6"
                >
                    {metricCards.map((card) => (
                        <article key={card.key} className="bg-surface border-border rounded-xl border p-4">
                            <p className="text-text-muted text-xs font-medium">
                                {card.key === 'total'
                                    ? `Total ${institution.studentLabel.toLocaleLowerCase()} records`
                                    : card.label}
                            </p>
                            <p className={`mt-3 text-2xl font-semibold ${card.tone}`}>
                                {stats[card.key].toLocaleString()}
                            </p>
                        </article>
                    ))}
                </section>

                <form
                    method="get"
                    action={`/t/${tenant.slug}/sis`}
                    className="bg-surface border-border grid gap-3 rounded-xl border p-4 sm:grid-cols-2 xl:grid-cols-4 xl:items-end"
                >
                    <label className="text-text-primary text-sm font-medium">
                        Admission date from
                        <input
                            className="border-border bg-surface mt-1 min-h-11 w-full rounded-lg border px-3 text-sm"
                            type="date"
                            name="from"
                            defaultValue={filters.from ?? ''}
                        />
                    </label>
                    <label className="text-text-primary text-sm font-medium">
                        Admission date through
                        <input
                            className="border-border bg-surface mt-1 min-h-11 w-full rounded-lg border px-3 text-sm"
                            type="date"
                            name="until"
                            defaultValue={filters.until ?? ''}
                        />
                    </label>
                    <label className="text-text-primary text-sm font-medium">
                        Academic year
                        <select
                            className="border-border bg-surface mt-1 min-h-11 w-full rounded-lg border px-3 text-sm"
                            name="academic_year_id"
                            defaultValue={filters.academic_year_id ?? ''}
                        >
                            <option value="">All academic years</option>
                            {academicOptions.years.map((year) => (
                                <option key={year.id} value={year.id}>
                                    {year.name}
                                </option>
                            ))}
                        </select>
                    </label>
                    <div className="flex flex-wrap gap-2">
                        <button className="bg-navy-900 min-h-11 rounded-lg px-5 text-sm font-semibold text-white">
                            Apply filters
                        </button>
                        <a
                            href={`/t/${tenant.slug}/sis`}
                            className="border-border text-text-primary inline-flex min-h-11 items-center rounded-lg border px-4 text-sm font-semibold"
                        >
                            Clear
                        </a>
                    </div>
                </form>

                <section aria-labelledby="sis-analytics-heading" className="space-y-3">
                    <div>
                        <h2 id="sis-analytics-heading" className="text-text-primary text-lg font-semibold">
                            Lifecycle analytics
                        </h2>
                        <p className="text-text-muted mt-1 text-sm">
                            Institution-scoped counts use the selected admission window and academic year.
                        </p>
                    </div>
                    <div className="grid gap-3 sm:grid-cols-2 2xl:grid-cols-4">
                        <article className="bg-surface border-border rounded-xl border p-4">
                            <h3 className="text-text-primary font-semibold">Students by campus</h3>
                            {analytics.campuses.length === 0 ? (
                                <p className="text-text-muted mt-3 text-sm">
                                    No student records in this view.
                                </p>
                            ) : (
                                <ul className="mt-3 space-y-2">
                                    {analytics.campuses.map((campus) => (
                                        <li key={campus.name} className="flex justify-between gap-3 text-sm">
                                            <span className="text-text-muted">{campus.name}</span>
                                            <span className="text-text-primary font-semibold">
                                                {campus.students.toLocaleString()}
                                            </span>
                                        </li>
                                    ))}
                                </ul>
                            )}
                        </article>
                        <article className="bg-surface border-border rounded-xl border p-4">
                            <h3 className="text-text-primary font-semibold">Students by program</h3>
                            {analytics.programs.length === 0 ? (
                                <p className="text-text-muted mt-3 text-sm">
                                    No current program placements in this view.
                                </p>
                            ) : (
                                <ul className="mt-3 space-y-2">
                                    {analytics.programs.map((program) => (
                                        <li key={program.name} className="flex justify-between gap-3 text-sm">
                                            <span className="text-text-muted">{program.name}</span>
                                            <span className="text-text-primary font-semibold">
                                                {program.students.toLocaleString()}
                                            </span>
                                        </li>
                                    ))}
                                </ul>
                            )}
                        </article>
                        <article className="bg-surface border-border rounded-xl border p-4">
                            <h3 className="text-text-primary font-semibold">Transfers</h3>
                            <dl className="mt-3 space-y-2">
                                {[
                                    ['Pending review', analytics.transfers.pendingReview],
                                    ['Approved', analytics.transfers.approved],
                                    ['Completed', analytics.transfers.completed],
                                    ['Rejected', analytics.transfers.rejected],
                                ].map(([label, count]) => (
                                    <div key={label} className="flex justify-between gap-3 text-sm">
                                        <dt className="text-text-muted">{label}</dt>
                                        <dd className="text-text-primary font-semibold">
                                            {Number(count).toLocaleString()}
                                        </dd>
                                    </div>
                                ))}
                                <div className="border-border flex justify-between gap-3 border-t pt-2 text-sm">
                                    <dt className="text-text-muted">Promotions recorded</dt>
                                    <dd className="text-text-primary font-semibold">
                                        {analytics.promotions.toLocaleString()}
                                    </dd>
                                </div>
                            </dl>
                        </article>
                        <article className="bg-surface border-border rounded-xl border p-4">
                            <h3 className="text-text-primary font-semibold">Guardian authority</h3>
                            <dl className="mt-3 space-y-2">
                                {[
                                    ['Pending', analytics.guardians.pending],
                                    ['Verified', analytics.guardians.verified],
                                    ['Revoked', analytics.guardians.revoked],
                                ].map(([label, count]) => (
                                    <div key={label} className="flex justify-between gap-3 text-sm">
                                        <dt className="text-text-muted">{label}</dt>
                                        <dd className="text-text-primary font-semibold">
                                            {Number(count).toLocaleString()}
                                        </dd>
                                    </div>
                                ))}
                            </dl>
                        </article>
                    </div>
                </section>

                {permissions.settings && (
                    <section className="bg-surface border-border rounded-2xl border p-5 sm:p-6">
                        <h2 className="text-text-primary text-lg font-semibold">
                            Registry identity and numbering
                        </h2>
                        <p className="text-text-muted mt-1 text-sm">
                            Set institution terminology and the number format used for new student records.
                        </p>
                        <form
                            className="mt-4 grid gap-4 sm:grid-cols-2 xl:grid-cols-4"
                            onSubmit={(event) => {
                                event.preventDefault();
                                settingsForm.put(`/t/${tenant.slug}/sis/settings`);
                            }}
                        >
                            <label className="text-text-primary text-sm font-medium">
                                Student label
                                <input
                                    className="border-border bg-surface mt-1 min-h-11 w-full rounded-lg border px-3 text-sm"
                                    maxLength={32}
                                    value={settingsForm.data.student_label}
                                    onChange={(event) =>
                                        settingsForm.setData('student_label', event.target.value)
                                    }
                                    required
                                />
                            </label>
                            <label className="text-text-primary text-sm font-medium">
                                Number prefix
                                <input
                                    className="border-border bg-surface mt-1 min-h-11 w-full rounded-lg border px-3 text-sm uppercase"
                                    maxLength={16}
                                    value={settingsForm.data.number_prefix}
                                    onChange={(event) =>
                                        settingsForm.setData('number_prefix', event.target.value)
                                    }
                                    disabled={registrySettings.hasStudents}
                                    required
                                />
                            </label>
                            <label className="text-text-primary text-sm font-medium">
                                Number digits
                                <input
                                    className="border-border bg-surface mt-1 min-h-11 w-full rounded-lg border px-3 text-sm"
                                    type="number"
                                    min={4}
                                    max={12}
                                    value={settingsForm.data.number_width}
                                    onChange={(event) =>
                                        settingsForm.setData('number_width', event.target.value)
                                    }
                                    disabled={registrySettings.hasStudents}
                                    required
                                />
                            </label>
                            <label className="text-text-primary text-sm font-medium">
                                Private SIS document retention (days)
                                <input
                                    className="border-border bg-surface mt-1 min-h-11 w-full rounded-lg border px-3 text-sm"
                                    type="number"
                                    min={1}
                                    max={36500}
                                    value={settingsForm.data.document_retention_days}
                                    onChange={(event) =>
                                        settingsForm.setData('document_retention_days', event.target.value)
                                    }
                                    aria-describedby="sis-retention-help"
                                />
                                <span
                                    id="sis-retention-help"
                                    className="text-text-muted mt-1 block text-xs font-normal"
                                >
                                    Blank means no expiry. This policy applies to new uploads; expired files
                                    remain private and cannot be downloaded.
                                </span>
                            </label>
                            <label className="text-text-primary text-sm font-medium">
                                Archive documents this many days after expiry
                                <input
                                    className="border-border bg-surface mt-1 min-h-11 w-full rounded-lg border px-3 text-sm"
                                    type="number"
                                    min={1}
                                    max={36500}
                                    value={settingsForm.data.document_archive_after_days}
                                    onChange={(event) =>
                                        settingsForm.setData(
                                            'document_archive_after_days',
                                            event.target.value,
                                        )
                                    }
                                    aria-describedby="sis-archive-help"
                                />
                                <span
                                    id="sis-archive-help"
                                    className="text-text-muted mt-1 block text-xs font-normal"
                                >
                                    Blank disables automatic archival. This policy applies only after a
                                    document has an expiry date; archived files remain private until the
                                    configured purge date.
                                </span>
                            </label>
                            <label className="text-text-primary text-sm font-medium">
                                Purge documents this many days after expiry
                                <input
                                    className="border-border bg-surface mt-1 min-h-11 w-full rounded-lg border px-3 text-sm"
                                    type="number"
                                    min={1}
                                    max={36500}
                                    value={settingsForm.data.document_purge_after_days}
                                    onChange={(event) =>
                                        settingsForm.setData('document_purge_after_days', event.target.value)
                                    }
                                    aria-describedby="sis-purge-help"
                                />
                                <span
                                    id="sis-purge-help"
                                    className="text-text-muted mt-1 block text-xs font-normal"
                                >
                                    Blank disables deletion. Purge timing cannot precede archival and only
                                    applies to expired, previously archived files.
                                </span>
                            </label>
                            <label className="text-text-primary flex items-start gap-3 text-sm font-medium sm:col-span-2 xl:col-span-4">
                                <input
                                    type="checkbox"
                                    className="accent-brand-blue mt-1 size-4"
                                    checked={settingsForm.data.suspend_membership_on_student_suspension}
                                    onChange={(event) =>
                                        settingsForm.setData(
                                            'suspend_membership_on_student_suspension',
                                            event.currentTarget.checked,
                                        )
                                    }
                                />
                                <span>
                                    Suspend a student-only institution membership when the linked student is
                                    suspended
                                    <span className="text-text-muted mt-1 block text-xs font-normal">
                                        Staff or administrator memberships are left unchanged. Reactivation
                                        restores access only when the account still has the student role and
                                        its membership was suspended by this policy.
                                    </span>
                                </span>
                            </label>
                            <label className="text-text-primary flex items-start gap-3 text-sm font-medium sm:col-span-2 xl:col-span-4">
                                <input
                                    type="checkbox"
                                    className="accent-brand-blue mt-1 size-4"
                                    checked={settingsForm.data.lifecycle_requires_approval}
                                    onChange={(event) =>
                                        settingsForm.setData(
                                            'lifecycle_requires_approval',
                                            event.currentTarget.checked,
                                        )
                                    }
                                />
                                <span>
                                    Require a different authorized administrator to approve student status
                                    changes
                                    <span className="text-text-muted mt-1 block text-xs font-normal">
                                        Requests remain pending until an independent reviewer approves or
                                        rejects them. The requester cannot approve their own change.
                                    </span>
                                </span>
                            </label>
                            <label className="text-text-primary flex items-start gap-3 text-sm font-medium sm:col-span-2 xl:col-span-4">
                                <input
                                    type="checkbox"
                                    className="accent-brand-blue mt-1 size-4"
                                    checked={settingsForm.data.promotion_requires_approval}
                                    onChange={(event) =>
                                        settingsForm.setData(
                                            'promotion_requires_approval',
                                            event.currentTarget.checked,
                                        )
                                    }
                                />
                                <span>
                                    Require independent approval for bulk promotions
                                    <span className="text-text-muted mt-1 block text-xs font-normal">
                                        Promotion requests remain pending until a different authorized
                                        institution administrator reviews them. Capacity and eligibility are
                                        checked again at approval.
                                    </span>
                                </span>
                            </label>
                            <div className="xl:col-span-4">
                                <p className="text-text-muted text-xs">
                                    Preview: {settingsForm.data.number_prefix.toUpperCase()}
                                    {'0'.repeat(Math.max(0, Number(settingsForm.data.number_width) - 1))}1
                                </p>
                                <button
                                    disabled={settingsForm.processing}
                                    className="bg-brand-action mt-3 min-h-11 rounded-lg px-4 text-sm font-semibold text-white"
                                >
                                    Save registry settings
                                </button>
                                {Object.values(settingsForm.errors).length > 0 && (
                                    <p role="alert" className="mt-2 text-sm text-rose-700">
                                        {Object.values(settingsForm.errors).join(' ')}
                                    </p>
                                )}
                            </div>
                        </form>
                    </section>
                )}

                {canReviewLifecycle && (
                    <section
                        className="bg-surface border-border rounded-2xl border p-5"
                        aria-labelledby="sis-lifecycle-approval-title"
                    >
                        <h2 id="sis-lifecycle-approval-title" className="text-text-primary font-semibold">
                            Lifecycle approvals
                        </h2>
                        <p className="text-text-muted mt-1 text-sm">
                            Independent review for student status changes.
                        </p>
                        {lifecycleApproval.length === 0 ? (
                            <p className="text-text-muted mt-5 rounded-xl border border-dashed p-6 text-center text-sm">
                                No student lifecycle requests are waiting for review.
                            </p>
                        ) : (
                            <ul className="divide-border mt-4 divide-y">
                                {lifecycleApproval.map((item) => (
                                    <li
                                        key={item.id}
                                        className="flex flex-col gap-4 py-4 sm:flex-row sm:items-center sm:justify-between"
                                    >
                                        <div>
                                            <p className="text-text-primary font-medium">
                                                {item.name}{' '}
                                                <span className="text-text-muted font-normal">
                                                    ({item.student_number})
                                                </span>
                                            </p>
                                            <p className="text-text-muted mt-1 text-sm">
                                                {item.from_status.replaceAll('_', ' ')} to{' '}
                                                {item.to_status.replaceAll('_', ' ')} /{' '}
                                                {item.reason_code.replaceAll('_', ' ')}
                                            </p>
                                            {item.reason && (
                                                <p className="text-text-muted mt-1 text-sm">{item.reason}</p>
                                            )}
                                            <p className="text-text-muted mt-1 text-xs">
                                                Requested by {item.requester}
                                            </p>
                                        </div>
                                        <div className="flex gap-2">
                                            <button
                                                type="button"
                                                className="bg-brand-action min-h-11 rounded-lg px-4 text-sm font-semibold text-white focus-visible:outline-2"
                                                onClick={() =>
                                                    router.post(
                                                        `/t/${tenant.slug}/sis/lifecycle-requests/${item.id}/review`,
                                                        { decision: 'approve' },
                                                    )
                                                }
                                            >
                                                Approve
                                            </button>
                                            <button
                                                type="button"
                                                className="border-border min-h-11 rounded-lg border px-4 text-sm font-semibold focus-visible:outline-2"
                                                onClick={() =>
                                                    router.post(
                                                        `/t/${tenant.slug}/sis/lifecycle-requests/${item.id}/review`,
                                                        { decision: 'reject' },
                                                    )
                                                }
                                            >
                                                Reject
                                            </button>
                                        </div>
                                    </li>
                                ))}
                            </ul>
                        )}
                    </section>
                )}

                <section className="bg-surface border-border overflow-hidden rounded-2xl border">
                    <div className="border-border flex flex-wrap items-center justify-between gap-3 border-b px-5 py-4">
                        <div>
                            <h2 className="text-text-primary font-semibold">Recently registered</h2>
                            <p className="text-text-muted mt-1 text-sm">
                                Latest records visible to your institution role.
                            </p>
                        </div>
                        <Link
                            href={`/t/${tenant.slug}/sis/students`}
                            className="text-brand-link rounded px-2 py-2 text-sm font-semibold focus-visible:outline-2"
                        >
                            View registry
                        </Link>
                    </div>
                    {recentStudents.length === 0 ? (
                        <div className="px-5 py-12 text-center">
                            <p className="text-text-primary font-semibold">The registry is ready</p>
                            <p className="text-text-muted mt-2 text-sm">
                                No student records are registered yet.
                            </p>
                            {permissions.create && (
                                <Link
                                    href={`/t/${tenant.slug}/sis/students`}
                                    className="text-brand-link mt-4 inline-block text-sm font-semibold"
                                >
                                    Register the first student
                                </Link>
                            )}
                        </div>
                    ) : (
                        <ul className="divide-border divide-y">
                            {recentStudents.map((student) => (
                                <li key={student.id}>
                                    <Link
                                        href={`/t/${tenant.slug}/sis/students/${student.id}`}
                                        className="hover:bg-canvas focus-visible:outline-brand-blue flex flex-wrap items-center justify-between gap-3 px-5 py-4 focus-visible:outline-2 focus-visible:outline-offset-[-2px]"
                                    >
                                        <span className="min-w-0">
                                            <span className="text-text-primary block truncate font-semibold">
                                                {student.name}
                                            </span>
                                            <span className="text-text-muted mt-1 block text-xs">
                                                {student.studentNumber} ·{' '}
                                                {student.homeCampus?.name ?? 'Institution wide'}
                                            </span>
                                        </span>
                                        <span className="flex items-center gap-4 text-xs">
                                            <span className="text-text-muted">
                                                Admitted {student.admissionDate}
                                            </span>
                                            <span className="bg-canvas text-text-primary rounded-full px-3 py-1 font-medium">
                                                {student.status.replaceAll('_', ' ')}
                                            </span>
                                        </span>
                                    </Link>
                                </li>
                            ))}
                        </ul>
                    )}
                </section>
            </div>
        </WorkspaceLayout>
    );
}
