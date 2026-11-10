import { Head, router, useForm } from '@inertiajs/react';
import { FormErrors } from '@/components/ui/form-errors';
import { PageHeader } from '@/components/ui/page-header';
import { WorkspaceLayout } from '@/layouts/workspace-layout';

type Option = { id: number; name: string; code?: string };
type Offering = {
    id: number;
    code: string;
    status: string;
    courseId: number;
    course: string;
    period: string;
    policyId: number;
    campusId: number | null;
    deliveryMode: 'in_person' | 'online' | 'hybrid';
    registrationOpensAt: string | null;
    registrationClosesAt: string | null;
    sections: {
        id: number;
        code: string;
        capacity: number;
        reserved: number;
        confirmed: number;
        waitlist: number;
        campusId: number | null;
        status: 'open' | 'closed';
    }[];
};
type Props = {
    tenant: { slug: string };
    analytics: {
        total: number;
        confirmed: number;
        waitlisted: number;
        pending: number;
        pendingWithdrawals: number;
        dropped: number;
        withdrawn: number;
        seatCapacity: number;
        openConfirmed: number;
        seatUtilizationPercent: number;
    };
    filters: { campus_id?: number; course_id?: number; status?: string };
    offerings: Offering[];
    courses: { id: number; code: string; title: string }[];
    levels: { id: number; code: string; name: string }[];
    years: Option[];
    periods: (Option & { academic_year_id: number })[];
    policies: {
        id: number;
        name: string;
        minimum_courses: number;
        maximum_courses: number;
        maximum_credits: number | null;
        requires_approval: boolean;
        waitlist_enabled: boolean;
        waitlist_offer_hours: number;
        require_prerequisites: boolean;
        require_withdrawal_approval: boolean;
        allow_section_transfer: boolean;
        allow_late_registration: boolean;
        status: 'active' | 'inactive';
        academic_level_ids: string[];
    }[];
    campuses: Option[];
    instructors: { user_id: number; name: string; email: string }[];
    students: { id: number; student_number: string; name: string }[];
    activeHolds: {
        id: number;
        student_id: number;
        student_number: string;
        student_name: string;
        type: string;
        reason_code: string;
        reason: string;
        effective_at: string;
        expires_at: string | null;
    }[];
    pending: { id: number; studentNumber: string; studentName: string; course: string; section: string }[];
    pendingWithdrawals: {
        id: number;
        student: string;
        number: string;
        course: string;
        reason: string | null;
    }[];
    pendingTransfers: {
        id: number;
        student_number: string;
        legal_first_name: string;
        legal_last_name: string;
        section_code: string;
        reason: string;
    }[];
    pendingExceptions: {
        id: number;
        exception_type: string;
        reason: string;
        evidence_reference: string | null;
        requested_by: number;
        student_number: string;
        legal_first_name: string;
        legal_last_name: string;
        title: string;
    }[];
    bulkPreview: {
        id: number;
        status: string;
        row_count: number;
        valid_count: number;
        error_count: number;
    } | null;
    bulkPreviewRows: {
        row_number: number;
        student_number: string;
        offering_code: string;
        section_code: string;
        status: string;
        error_code: string | null;
    }[];
    waitlistEntries: {
        id: number;
        position: number;
        status: string;
        student_number: string;
        student_name: string;
        offering_code: string;
        course: string;
        section: string;
        offer_expires_at: string | null;
    }[];
};

const field = 'min-h-11 w-full rounded-lg border border-border bg-canvas px-3 text-sm text-text-primary';

export default function EnrollmentRegistrar({
    tenant,
    analytics,
    filters,
    offerings,
    courses,
    levels,
    years,
    periods,
    policies,
    campuses,
    instructors,
    students,
    activeHolds,
    pending,
    pendingWithdrawals,
    pendingTransfers,
    pendingExceptions,
    bulkPreview,
    bulkPreviewRows,
    waitlistEntries,
}: Props) {
    const policyForm = useForm({
        name: '',
        minimum_courses: '0',
        maximum_courses: '8',
        maximum_credits: '',
        requires_approval: false,
        waitlist_enabled: true,
        waitlist_offer_hours: '48',
        require_prerequisites: false,
        require_withdrawal_approval: true,
        allow_section_transfer: true,
        allow_late_registration: false,
        academic_level_ids: [] as string[],
    });
    const offeringForm = useForm({
        offering_code: '',
        academic_year_id: '',
        academic_period_id: '',
        academic_course_id: '',
        academic_course_version_id: '',
        academic_program_id: '',
        campus_id: '',
        policy_id: '',
        delivery_mode: 'in_person',
        registration_opens_at: '',
        registration_closes_at: '',
    });
    const prerequisiteForm = useForm({
        course_id: '',
        prerequisite_course_id: '',
        relation_type: 'prerequisite',
        is_required: true,
    });
    const bulkForm = useForm({ file: null as File | null, idempotency_key: window.crypto.randomUUID() });
    const holdForm = useForm({
        student_id: '',
        hold_type: 'academic',
        reason_code: '',
        reason: '',
        effective_at: '',
        expires_at: '',
    });
    const base = `/t/${tenant.slug}/registrar/enrollment`;

    return (
        <WorkspaceLayout area="tenant" title="Enrollment administration">
            <Head title="Enrollment administration" />
            <div className="space-y-6">
                <PageHeader
                    eyebrow="Registrar workspace"
                    title="Enrollment administration"
                    description="Configure registration policy and offerings, review requests, and monitor section capacity."
                />
                <form
                    method="get"
                    action={`/t/${tenant.slug}/registrar/enrollment`}
                    className="border-border bg-surface grid gap-3 rounded-xl border p-4 sm:grid-cols-3"
                    aria-label="Filter enrollment analytics"
                >
                    <label className="text-text-primary text-sm">
                        Campus
                        <select name="campus_id" defaultValue={filters.campus_id ?? ''} className={field}>
                            <option value="">All campuses</option>
                            {campuses.map((campus) => (
                                <option key={campus.id} value={campus.id}>
                                    {campus.name}
                                </option>
                            ))}
                        </select>
                    </label>
                    <label className="text-text-primary text-sm">
                        Course
                        <select name="course_id" defaultValue={filters.course_id ?? ''} className={field}>
                            <option value="">All courses</option>
                            {courses.map((course) => (
                                <option key={course.id} value={course.id}>
                                    {course.code} · {course.title}
                                </option>
                            ))}
                        </select>
                    </label>
                    <label className="text-text-primary text-sm">
                        Offering status
                        <select name="status" defaultValue={filters.status ?? ''} className={field}>
                            <option value="">All statuses</option>
                            {['draft', 'open', 'closed', 'completed', 'cancelled'].map((status) => (
                                <option key={status} value={status}>
                                    {status}
                                </option>
                            ))}
                        </select>
                    </label>
                    <button className="bg-brand-action min-h-10 rounded-lg px-4 font-semibold text-white sm:col-span-3 sm:justify-self-start">
                        Apply filters
                    </button>
                </form>
                <section className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4" aria-label="Enrollment summary">
                    <article className="border-border bg-surface rounded-xl border p-4">
                        <p className="text-text-muted text-sm">Recorded enrollments</p>
                        <p className="text-text-primary mt-2 text-2xl font-semibold">{analytics.total}</p>
                    </article>
                    <article className="border-border bg-surface rounded-xl border p-4">
                        <p className="text-text-muted text-sm">Confirmed registrations</p>
                        <p className="text-text-primary mt-2 text-2xl font-semibold">{analytics.confirmed}</p>
                    </article>
                    <article className="border-border bg-surface rounded-xl border p-4">
                        <p className="text-text-muted text-sm">Waitlisted · pending approval</p>
                        <p className="text-text-primary mt-2 text-2xl font-semibold">
                            {analytics.waitlisted} · {analytics.pending}
                        </p>
                    </article>
                    <article className="border-border bg-surface rounded-xl border p-4">
                        <p className="text-text-muted text-sm">Open-section seat utilization</p>
                        <p className="text-text-primary mt-2 text-2xl font-semibold">
                            {analytics.seatUtilizationPercent}%
                        </p>
                        <p className="text-text-muted text-xs">
                            {analytics.openConfirmed} confirmed / {analytics.seatCapacity} seats
                        </p>
                    </article>
                </section>
                <section className="grid gap-5 xl:grid-cols-2">
                    <form
                        className="border-border bg-surface grid gap-3 rounded-xl border p-5"
                        onSubmit={(event) => {
                            event.preventDefault();
                            policyForm.post(`${base}/policies`, {
                                preserveScroll: true,
                                onSuccess: () => policyForm.reset(),
                            });
                        }}
                    >
                        <h2 className="text-text-primary text-lg font-semibold">New registration policy</h2>
                        <FormErrors errors={policyForm.errors} />
                        <label className="text-text-primary text-sm font-medium">
                            Policy name
                            <input
                                required
                                maxLength={140}
                                className={field}
                                value={policyForm.data.name}
                                onChange={(e) => policyForm.setData('name', e.target.value)}
                            />
                        </label>
                        <div className="grid gap-3 sm:grid-cols-2">
                            <label className="text-text-primary text-sm">
                                Maximum courses
                                <input
                                    required
                                    type="number"
                                    min="1"
                                    max="40"
                                    className={field}
                                    value={policyForm.data.maximum_courses}
                                    onChange={(e) => policyForm.setData('maximum_courses', e.target.value)}
                                />
                            </label>
                            <label className="text-text-primary text-sm">
                                Maximum credits
                                <input
                                    type="number"
                                    min="0.001"
                                    step="0.001"
                                    className={field}
                                    value={policyForm.data.maximum_credits}
                                    onChange={(e) => policyForm.setData('maximum_credits', e.target.value)}
                                />
                            </label>
                        </div>
                        <label className="text-text-primary flex min-h-11 items-center gap-2 text-sm">
                            <input
                                type="checkbox"
                                checked={policyForm.data.requires_approval}
                                onChange={(e) => policyForm.setData('requires_approval', e.target.checked)}
                            />{' '}
                            Require registrar approval
                        </label>
                        <label className="text-text-primary flex min-h-11 items-center gap-2 text-sm">
                            <input
                                type="checkbox"
                                checked={policyForm.data.waitlist_enabled}
                                onChange={(e) => policyForm.setData('waitlist_enabled', e.target.checked)}
                            />{' '}
                            Enable section waitlists
                        </label>
                        {(
                            [
                                ['require_prerequisites', 'Require verified prerequisite evidence'],
                                ['require_withdrawal_approval', 'Require registrar approval for withdrawal'],
                                ['allow_section_transfer', 'Allow section transfer requests'],
                                ['allow_late_registration', 'Allow late registration window'],
                            ] as const
                        ).map(([key, label]) => (
                            <label
                                key={key}
                                className="text-text-primary flex min-h-11 items-center gap-2 text-sm"
                            >
                                <input
                                    type="checkbox"
                                    checked={policyForm.data[key]}
                                    onChange={(event) => policyForm.setData(key, event.target.checked)}
                                />{' '}
                                {label}
                            </label>
                        ))}
                        <label className="text-text-primary text-sm">
                            Waitlist offer hours
                            <input
                                required
                                type="number"
                                min="1"
                                max="720"
                                className={field}
                                value={policyForm.data.waitlist_offer_hours}
                                onChange={(e) => policyForm.setData('waitlist_offer_hours', e.target.value)}
                            />
                        </label>
                        <label className="text-text-primary text-sm">
                            Eligible academic levels (leave empty for all)
                            <select
                                multiple
                                className={`${field} min-h-28`}
                                value={policyForm.data.academic_level_ids}
                                onChange={(event) =>
                                    policyForm.setData(
                                        'academic_level_ids',
                                        Array.from(event.target.selectedOptions, (option) => option.value),
                                    )
                                }
                            >
                                {levels.map((level) => (
                                    <option key={level.id} value={level.id}>
                                        {level.code} · {level.name}
                                    </option>
                                ))}
                            </select>
                        </label>
                        <button
                            disabled={policyForm.processing}
                            className="bg-brand-action min-h-11 rounded-lg px-4 font-semibold text-white"
                        >
                            Save policy
                        </button>
                    </form>
                    <form
                        className="border-border bg-surface grid gap-3 rounded-xl border p-5"
                        onSubmit={(event) => {
                            event.preventDefault();
                            offeringForm.post(`${base}/offerings`, {
                                preserveScroll: true,
                                onSuccess: () => offeringForm.reset(),
                            });
                        }}
                    >
                        <h2 className="text-text-primary text-lg font-semibold">New course offering</h2>
                        <FormErrors errors={offeringForm.errors} />
                        <label className="text-text-primary text-sm">
                            Offering code
                            <input
                                required
                                className={field}
                                value={offeringForm.data.offering_code}
                                onChange={(e) => offeringForm.setData('offering_code', e.target.value)}
                            />
                        </label>
                        <label className="text-text-primary text-sm">
                            Course
                            <select
                                required
                                className={field}
                                value={offeringForm.data.academic_course_id}
                                onChange={(e) => offeringForm.setData('academic_course_id', e.target.value)}
                            >
                                <option value="">Select course</option>
                                {courses.map((c) => (
                                    <option key={c.id} value={c.id}>
                                        {c.code} — {c.title}
                                    </option>
                                ))}
                            </select>
                        </label>
                        <div className="grid gap-3 sm:grid-cols-2">
                            <label className="text-text-primary text-sm">
                                Academic year
                                <select
                                    required
                                    className={field}
                                    value={offeringForm.data.academic_year_id}
                                    onChange={(e) => offeringForm.setData('academic_year_id', e.target.value)}
                                >
                                    <option value="">Select year</option>
                                    {years.map((y) => (
                                        <option key={y.id} value={y.id}>
                                            {y.name}
                                        </option>
                                    ))}
                                </select>
                            </label>
                            <label className="text-text-primary text-sm">
                                Period
                                <select
                                    required
                                    className={field}
                                    value={offeringForm.data.academic_period_id}
                                    onChange={(e) =>
                                        offeringForm.setData('academic_period_id', e.target.value)
                                    }
                                >
                                    <option value="">Select period</option>
                                    {periods
                                        .filter(
                                            (p) =>
                                                !offeringForm.data.academic_year_id ||
                                                p.academic_year_id ===
                                                    Number(offeringForm.data.academic_year_id),
                                        )
                                        .map((p) => (
                                            <option key={p.id} value={p.id}>
                                                {p.name}
                                            </option>
                                        ))}
                                </select>
                            </label>
                        </div>
                        <div className="grid gap-3 sm:grid-cols-2">
                            <label className="text-text-primary text-sm">
                                Policy
                                <select
                                    required
                                    className={field}
                                    value={offeringForm.data.policy_id}
                                    onChange={(e) => offeringForm.setData('policy_id', e.target.value)}
                                >
                                    <option value="">Select policy</option>
                                    {policies
                                        .filter((p) => p.status === 'active')
                                        .map((p) => (
                                            <option key={p.id} value={p.id}>
                                                {p.name}
                                            </option>
                                        ))}
                                </select>
                            </label>
                            <label className="text-text-primary text-sm">
                                Campus
                                <select
                                    className={field}
                                    value={offeringForm.data.campus_id}
                                    onChange={(e) => offeringForm.setData('campus_id', e.target.value)}
                                >
                                    <option value="">Institution-wide</option>
                                    {campuses.map((c) => (
                                        <option key={c.id} value={c.id}>
                                            {c.name}
                                        </option>
                                    ))}
                                </select>
                            </label>
                        </div>
                        <button
                            disabled={offeringForm.processing || policies.length === 0}
                            className="bg-brand-action min-h-11 rounded-lg px-4 font-semibold text-white"
                        >
                            Create draft offering
                        </button>
                    </form>
                </section>
                <section aria-labelledby="policy-manager" className="space-y-3">
                    <h2 id="policy-manager" className="text-text-primary text-xl font-semibold">
                        Registration policy manager
                    </h2>
                    {policies.length === 0 ? (
                        <p className="border-border bg-surface text-text-muted rounded-xl border p-5">
                            No policies have been created.
                        </p>
                    ) : (
                        policies.map((policy) => (
                            <PolicySettingsForm key={policy.id} base={base} policy={policy} levels={levels} />
                        ))
                    )}
                </section>
                <section
                    className="border-border bg-surface grid gap-4 rounded-xl border p-5 md:grid-cols-2"
                    aria-labelledby="prerequisite-settings"
                >
                    <form
                        className="grid gap-3"
                        onSubmit={(event) => {
                            event.preventDefault();
                            prerequisiteForm.post(`${base}/prerequisites`, {
                                preserveScroll: true,
                                onSuccess: () => prerequisiteForm.reset(),
                            });
                        }}
                    >
                        <h2 id="prerequisite-settings" className="text-text-primary text-lg font-semibold">
                            Course prerequisite rules
                        </h2>
                        <FormErrors errors={prerequisiteForm.errors} />
                        <label className="text-text-primary text-sm">
                            Course
                            <select
                                required
                                className={field}
                                value={prerequisiteForm.data.course_id}
                                onChange={(event) =>
                                    prerequisiteForm.setData('course_id', event.target.value)
                                }
                            >
                                <option value="">Select course</option>
                                {courses.map((course) => (
                                    <option key={course.id} value={course.id}>
                                        {course.code} · {course.title}
                                    </option>
                                ))}
                            </select>
                        </label>
                        <label className="text-text-primary text-sm">
                            Prerequisite or co-requisite
                            <select
                                required
                                className={field}
                                value={prerequisiteForm.data.prerequisite_course_id}
                                onChange={(event) =>
                                    prerequisiteForm.setData('prerequisite_course_id', event.target.value)
                                }
                            >
                                <option value="">Select course</option>
                                {courses.map((course) => (
                                    <option key={course.id} value={course.id}>
                                        {course.code} · {course.title}
                                    </option>
                                ))}
                            </select>
                        </label>
                        <label className="text-text-primary text-sm">
                            Relation
                            <select
                                className={field}
                                value={prerequisiteForm.data.relation_type}
                                onChange={(event) =>
                                    prerequisiteForm.setData('relation_type', event.target.value)
                                }
                            >
                                <option value="prerequisite">Prerequisite</option>
                                <option value="corequisite">Co-requisite</option>
                            </select>
                        </label>
                        <button className="bg-brand-action min-h-11 rounded-lg px-4 font-semibold text-white">
                            Save rule
                        </button>
                    </form>
                    {pendingExceptions.length > 0 && (
                        <div>
                            <h2 className="text-text-primary text-lg font-semibold">
                                Prerequisite evidence review
                            </h2>
                            <ul className="divide-border divide-y">
                                {pendingExceptions.map((item) => (
                                    <li key={item.id} className="py-3">
                                        <p className="text-text-primary font-medium">
                                            {item.legal_first_name} {item.legal_last_name} ·{' '}
                                            {item.student_number} · {item.title}
                                        </p>
                                        <p className="text-text-muted text-sm">
                                            {item.exception_type}: {item.reason} Evidence:{' '}
                                            {item.evidence_reference || 'missing'}
                                        </p>
                                        {item.evidence_reference && (
                                            <DecisionButtons
                                                onDecide={(decision, reason) =>
                                                    router.post(
                                                        `${base}/prerequisite-exceptions/${item.id}/decision`,
                                                        { decision, reason },
                                                        { preserveScroll: true },
                                                    )
                                                }
                                            />
                                        )}
                                    </li>
                                ))}
                            </ul>
                        </div>
                    )}
                </section>
                <section
                    className="border-border bg-surface space-y-4 rounded-xl border p-5"
                    aria-labelledby="bulk-enrollment"
                >
                    <div className="flex flex-wrap items-center justify-between gap-3">
                        <div>
                            <h2 id="bulk-enrollment" className="text-text-primary text-lg font-semibold">
                                Bulk enrollment
                            </h2>
                            <p className="text-text-muted text-sm">
                                Preview eligibility and capacity before importing up to 500 rows.
                            </p>
                        </div>
                        <a
                            download="enrollment-template.csv"
                            href="data:text/csv;charset=utf-8,student_number%2Coffering_code%2Csection_code%0A"
                            className="border-border text-text-primary inline-flex min-h-10 items-center rounded-lg border px-3 text-sm font-semibold"
                        >
                            Download CSV template
                        </a>
                    </div>
                    <FormErrors errors={bulkForm.errors} />
                    <form
                        className="flex flex-wrap items-end gap-3"
                        onSubmit={(event) => {
                            event.preventDefault();
                            bulkForm.post(`${base}/bulk/preview`, {
                                forceFormData: true,
                                preserveScroll: true,
                            });
                        }}
                    >
                        <label className="text-text-primary grid gap-1 text-sm">
                            CSV file
                            <input
                                required
                                type="file"
                                accept=".csv,text/csv"
                                className="max-w-full text-sm"
                                onChange={(event) =>
                                    bulkForm.setData('file', event.target.files?.[0] ?? null)
                                }
                            />
                        </label>
                        <button
                            disabled={bulkForm.processing}
                            className="bg-brand-action min-h-11 rounded-lg px-4 font-semibold text-white"
                        >
                            Preview import
                        </button>
                    </form>
                    {bulkPreview && (
                        <div>
                            <p className="text-text-secondary text-sm">
                                Batch {bulkPreview.id}: {bulkPreview.valid_count} valid ·{' '}
                                {bulkPreview.error_count} errors · {bulkPreview.status}
                            </p>
                            <div className="border-border mt-2 max-h-64 overflow-auto rounded border">
                                <table className="w-full text-left text-sm">
                                    <thead>
                                        <tr>
                                            <th className="p-2">Student number</th>
                                            <th className="p-2">Offering / section</th>
                                            <th className="p-2">Result</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        {bulkPreviewRows.map((row) => (
                                            <tr key={row.row_number} className="border-border border-t">
                                                <td className="p-2">{row.student_number}</td>
                                                <td className="p-2">
                                                    {row.offering_code} · {row.section_code}
                                                </td>
                                                <td className="p-2">
                                                    {row.status === 'error' ? row.error_code : 'Ready'}
                                                </td>
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            </div>
                            <button
                                type="button"
                                disabled={bulkPreview.error_count > 0 || bulkPreview.status !== 'previewed'}
                                onClick={() =>
                                    router.post(
                                        `${base}/bulk/${bulkPreview.id}/confirm`,
                                        {},
                                        { preserveScroll: true },
                                    )
                                }
                                className="bg-brand-action mt-3 min-h-11 rounded-lg px-4 font-semibold text-white disabled:opacity-50"
                            >
                                Confirm import
                            </button>
                        </div>
                    )}
                </section>
                {pending.length > 0 && (
                    <section className="border-border bg-surface rounded-xl border p-5">
                        <h2 className="text-text-primary text-lg font-semibold">
                            Pending registration requests
                        </h2>
                        <ul className="divide-border mt-3 divide-y">
                            {pending.map((item) => (
                                <li
                                    key={item.id}
                                    className="flex flex-wrap items-center justify-between gap-3 py-3"
                                >
                                    <span className="text-text-primary text-sm">
                                        {item.studentName} ({item.studentNumber}) · {item.course} ·{' '}
                                        {item.section}
                                    </span>
                                    <div className="flex gap-2">
                                        <button
                                            className="bg-brand-action min-h-10 rounded-lg px-3 text-sm font-semibold text-white"
                                            onClick={() => {
                                                const reason = window.prompt('Approval reason');
                                                if (reason)
                                                    router.post(
                                                        `${base}/requests/${item.id}/decision`,
                                                        { decision: 'approve', reason },
                                                        { preserveScroll: true },
                                                    );
                                            }}
                                        >
                                            Approve
                                        </button>
                                        <button
                                            className="border-border text-text-primary min-h-10 rounded-lg border px-3 text-sm font-semibold"
                                            onClick={() => {
                                                const reason = window.prompt('Reason for rejection');
                                                if (reason)
                                                    router.post(
                                                        `${base}/requests/${item.id}/decision`,
                                                        { decision: 'reject', reason },
                                                        { preserveScroll: true },
                                                    );
                                            }}
                                        >
                                            Reject
                                        </button>
                                    </div>
                                </li>
                            ))}
                        </ul>
                    </section>
                )}
                {(pendingWithdrawals.length > 0 || pendingTransfers.length > 0) && (
                    <section className="grid gap-4 xl:grid-cols-2" aria-label="Lifecycle review queue">
                        {pendingWithdrawals.length > 0 && (
                            <div className="border-border bg-surface rounded-xl border p-5">
                                <h2 className="text-text-primary text-lg font-semibold">
                                    Pending withdrawals
                                </h2>
                                <ul className="divide-border divide-y">
                                    {pendingWithdrawals.map((item) => (
                                        <li
                                            key={item.id}
                                            className="flex flex-wrap items-center justify-between gap-3 py-3"
                                        >
                                            <div>
                                                <p className="text-text-primary font-medium">
                                                    {item.student} · {item.number}
                                                </p>
                                                <p className="text-text-muted text-sm">
                                                    {item.course} · {item.reason}
                                                </p>
                                            </div>
                                            <DecisionButtons
                                                onDecide={(decision, reason) =>
                                                    router.post(
                                                        `${base}/withdrawals/${item.id}/decision`,
                                                        { decision, reason },
                                                        { preserveScroll: true },
                                                    )
                                                }
                                            />
                                        </li>
                                    ))}
                                </ul>
                            </div>
                        )}
                        {pendingTransfers.length > 0 && (
                            <div className="border-border bg-surface rounded-xl border p-5">
                                <h2 className="text-text-primary text-lg font-semibold">
                                    Pending section transfers
                                </h2>
                                <ul className="divide-border divide-y">
                                    {pendingTransfers.map((item) => (
                                        <li
                                            key={item.id}
                                            className="flex flex-wrap items-center justify-between gap-3 py-3"
                                        >
                                            <div>
                                                <p className="text-text-primary font-medium">
                                                    {item.legal_first_name} {item.legal_last_name} ·{' '}
                                                    {item.student_number}
                                                </p>
                                                <p className="text-text-muted text-sm">
                                                    Destination {item.section_code} · {item.reason}
                                                </p>
                                            </div>
                                            <DecisionButtons
                                                onDecide={(decision, reason) =>
                                                    router.post(
                                                        `${base}/transfers/${item.id}/decision`,
                                                        { decision, reason },
                                                        { preserveScroll: true },
                                                    )
                                                }
                                            />
                                        </li>
                                    ))}
                                </ul>
                            </div>
                        )}
                    </section>
                )}
                <section className="space-y-3">
                    <h2 className="text-text-primary text-xl font-semibold">Offerings and capacity</h2>
                    {offerings.length === 0 ? (
                        <p className="border-border bg-surface text-text-muted rounded-xl border p-5">
                            No offerings have been configured.
                        </p>
                    ) : (
                        offerings.map((offering) => (
                            <article
                                key={offering.id}
                                className="border-border bg-surface rounded-xl border p-5"
                            >
                                <div className="flex flex-wrap items-center justify-between gap-3">
                                    <div>
                                        <h3 className="text-text-primary font-semibold">
                                            {offering.course} · {offering.code}
                                        </h3>
                                        <p className="text-text-muted text-sm">
                                            {offering.period} · {offering.status}
                                        </p>
                                    </div>
                                    {offering.status === 'draft' && offering.sections.length > 0 && (
                                        <button
                                            onClick={() =>
                                                router.patch(
                                                    `${base}/offerings/${offering.id}/status`,
                                                    { status: 'open' },
                                                    { preserveScroll: true },
                                                )
                                            }
                                            className="border-border text-text-primary min-h-10 rounded-lg border px-3 text-sm font-semibold"
                                        >
                                            Open registration
                                        </button>
                                    )}
                                </div>
                                <OfferingSettingsForm
                                    base={base}
                                    offering={offering}
                                    policies={policies}
                                    campuses={campuses}
                                />
                                <ul className="mt-3 grid gap-2 sm:grid-cols-2">
                                    {offering.sections.map((section) => (
                                        <li
                                            key={section.id}
                                            className="bg-canvas text-text-primary rounded-lg p-3 text-sm"
                                        >
                                            Section {section.code}: {section.confirmed}/{section.capacity}{' '}
                                            confirmed · {section.waitlist} waiting · {section.status}
                                            <InstructorForm
                                                base={base}
                                                sectionId={section.id}
                                                instructors={instructors}
                                            />
                                            <SectionSettingsForm
                                                base={base}
                                                section={section}
                                                campuses={campuses}
                                            />
                                        </li>
                                    ))}
                                </ul>
                                <SectionForm base={base} offeringId={offering.id} campuses={campuses} />
                            </article>
                        ))
                    )}
                </section>
                <section aria-labelledby="waitlist-heading" className="space-y-3">
                    <h2 id="waitlist-heading" className="text-text-primary text-xl font-semibold">
                        Waitlist and seat offers
                    </h2>
                    {waitlistEntries.length === 0 ? (
                        <p className="border-border bg-surface text-text-muted rounded-xl border p-5">
                            No active waitlist entries or offers.
                        </p>
                    ) : (
                        <div className="border-border bg-surface overflow-x-auto rounded-xl border">
                            <table className="w-full min-w-[48rem] text-left text-sm">
                                <thead className="bg-surface-soft text-text-secondary">
                                    <tr>
                                        <th className="p-3">Position</th>
                                        <th className="p-3">Student</th>
                                        <th className="p-3">Course / offering</th>
                                        <th className="p-3">Section</th>
                                        <th className="p-3">Status</th>
                                        <th className="p-3">Offer expiry</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {waitlistEntries.map((entry) => (
                                        <tr key={entry.id} className="border-border border-t">
                                            <td className="p-3">{entry.position}</td>
                                            <td className="p-3">
                                                {entry.student_name}{' '}
                                                <span className="text-text-muted">
                                                    {entry.student_number}
                                                </span>
                                            </td>
                                            <td className="p-3">
                                                {entry.course} · {entry.offering_code}
                                            </td>
                                            <td className="p-3">{entry.section}</td>
                                            <td className="p-3">{entry.status}</td>
                                            <td className="p-3">
                                                {entry.offer_expires_at
                                                    ? new Date(entry.offer_expires_at).toLocaleString()
                                                    : '—'}
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    )}
                </section>
                <section className="grid gap-4 xl:grid-cols-2">
                    <form
                        className="border-border bg-surface grid gap-3 rounded-xl border p-5"
                        onSubmit={(event) => {
                            event.preventDefault();
                            holdForm.post(`${base}/holds`, {
                                preserveScroll: true,
                                onSuccess: () => holdForm.reset(),
                            });
                        }}
                    >
                        <h2 className="text-text-primary text-lg font-semibold">Place registration hold</h2>
                        <FormErrors errors={holdForm.errors} />
                        <label className="text-text-primary text-sm">
                            Student
                            <select
                                required
                                className={field}
                                value={holdForm.data.student_id}
                                onChange={(e) => holdForm.setData('student_id', e.target.value)}
                            >
                                <option value="">Choose student</option>
                                {students.map((student) => (
                                    <option key={student.id} value={student.id}>
                                        {student.student_number} · {student.name}
                                    </option>
                                ))}
                            </select>
                        </label>
                        <div className="grid gap-3 sm:grid-cols-2">
                            <label className="text-text-primary text-sm">
                                Hold type
                                <select
                                    className={field}
                                    value={holdForm.data.hold_type}
                                    onChange={(e) => holdForm.setData('hold_type', e.target.value)}
                                >
                                    <option value="academic">Academic</option>
                                    <option value="administrative">Administrative</option>
                                </select>
                            </label>
                            <label className="text-text-primary text-sm">
                                Reason code
                                <input
                                    required
                                    maxLength={48}
                                    className={field}
                                    value={holdForm.data.reason_code}
                                    onChange={(e) => holdForm.setData('reason_code', e.target.value)}
                                />
                            </label>
                        </div>
                        <label className="text-text-primary text-sm">
                            Reason
                            <textarea
                                required
                                minLength={8}
                                maxLength={1000}
                                className={field}
                                value={holdForm.data.reason}
                                onChange={(e) => holdForm.setData('reason', e.target.value)}
                            />
                        </label>
                        <div className="grid gap-3 sm:grid-cols-2">
                            <label className="text-text-primary text-sm">
                                Effective at
                                <input
                                    required
                                    type="datetime-local"
                                    className={field}
                                    value={holdForm.data.effective_at}
                                    onChange={(e) => holdForm.setData('effective_at', e.target.value)}
                                />
                            </label>
                            <label className="text-text-primary text-sm">
                                Expires at
                                <input
                                    type="datetime-local"
                                    className={field}
                                    value={holdForm.data.expires_at}
                                    onChange={(e) => holdForm.setData('expires_at', e.target.value)}
                                />
                            </label>
                        </div>
                        <button className="bg-brand-action min-h-11 rounded-lg px-4 font-semibold text-white">
                            Record hold
                        </button>
                    </form>
                    <section className="border-border bg-surface rounded-xl border p-5">
                        <h2 className="text-text-primary text-lg font-semibold">Active registration holds</h2>
                        {activeHolds.length === 0 ? (
                            <p className="text-text-muted mt-3 text-sm">No active holds.</p>
                        ) : (
                            <ul className="divide-border mt-3 divide-y">
                                {activeHolds.map((hold) => (
                                    <li key={hold.id} className="py-3">
                                        <div className="flex flex-wrap items-start justify-between gap-2">
                                            <div>
                                                <p className="text-text-primary font-semibold">
                                                    {hold.student_name} · {hold.student_number}
                                                </p>
                                                <p className="text-text-secondary text-sm">
                                                    {hold.type} · {hold.reason_code}
                                                </p>
                                                <p className="text-text-muted mt-1 text-sm">{hold.reason}</p>
                                            </div>
                                            <button
                                                onClick={() => {
                                                    const reason = window.prompt(
                                                        'Reason for revoking this hold',
                                                    );
                                                    if (reason)
                                                        router.post(
                                                            `${base}/holds/${hold.id}/revoke`,
                                                            { reason },
                                                            { preserveScroll: true },
                                                        );
                                                }}
                                                className="border-border text-text-primary min-h-10 rounded-lg border px-3 text-sm font-semibold"
                                            >
                                                Revoke
                                            </button>
                                        </div>
                                    </li>
                                ))}
                            </ul>
                        )}
                    </section>
                </section>
            </div>
        </WorkspaceLayout>
    );
}

function SectionForm({
    base,
    offeringId,
    campuses,
}: {
    base: string;
    offeringId: number;
    campuses: Option[];
}) {
    const form = useForm({ section_code: '', capacity: '30', reserved_seats: '0', campus_id: '' });
    return (
        <form
            className="border-border mt-4 grid gap-2 rounded-lg border p-3 sm:grid-cols-4"
            onSubmit={(event) => {
                event.preventDefault();
                form.post(`${base}/offerings/${offeringId}/sections`, {
                    preserveScroll: true,
                    onSuccess: () => form.reset(),
                });
            }}
        >
            <label className="text-text-secondary text-xs">
                Section code
                <input
                    required
                    className={field}
                    value={form.data.section_code}
                    onChange={(e) => form.setData('section_code', e.target.value)}
                />
            </label>
            <label className="text-text-secondary text-xs">
                Capacity
                <input
                    required
                    type="number"
                    min="1"
                    className={field}
                    value={form.data.capacity}
                    onChange={(e) => form.setData('capacity', e.target.value)}
                />
            </label>
            <label className="text-text-secondary text-xs">
                Reserved seats
                <input
                    type="number"
                    min="0"
                    className={field}
                    value={form.data.reserved_seats}
                    onChange={(e) => form.setData('reserved_seats', e.target.value)}
                />
            </label>
            <label className="text-text-secondary text-xs">
                Campus
                <select
                    className={field}
                    value={form.data.campus_id}
                    onChange={(e) => form.setData('campus_id', e.target.value)}
                >
                    <option value="">Offering campus</option>
                    {campuses.map((c) => (
                        <option key={c.id} value={c.id}>
                            {c.name}
                        </option>
                    ))}
                </select>
            </label>
            <button className="bg-brand-action min-h-10 rounded-lg px-3 text-sm font-semibold text-white sm:col-span-4">
                Add section
            </button>
        </form>
    );
}

function PolicySettingsForm({
    base,
    policy,
    levels,
}: {
    base: string;
    policy: Props['policies'][number];
    levels: Props['levels'];
}) {
    const form = useForm({
        name: policy.name,
        minimum_courses: String(policy.minimum_courses),
        maximum_courses: String(policy.maximum_courses),
        maximum_credits: policy.maximum_credits === null ? '' : String(policy.maximum_credits),
        requires_approval: policy.requires_approval,
        waitlist_enabled: policy.waitlist_enabled,
        waitlist_offer_hours: String(policy.waitlist_offer_hours),
        require_prerequisites: policy.require_prerequisites,
        require_withdrawal_approval: policy.require_withdrawal_approval,
        allow_section_transfer: policy.allow_section_transfer,
        allow_late_registration: policy.allow_late_registration,
        waitlist_ordering: 'fifo',
        status: policy.status,
        academic_level_ids: policy.academic_level_ids,
    });

    return (
        <details className="border-border bg-surface rounded-xl border p-4">
            <summary className="text-text-primary min-h-10 cursor-pointer font-semibold">
                {policy.name} · {policy.status}
            </summary>
            <form
                className="mt-3 grid gap-3 sm:grid-cols-2"
                onSubmit={(event) => {
                    event.preventDefault();
                    form.patch(`${base}/policies/${policy.id}`, { preserveScroll: true });
                }}
            >
                <FormErrors errors={form.errors} />
                <label className="text-text-primary text-sm">
                    Policy name
                    <input
                        required
                        maxLength={140}
                        className={field}
                        value={form.data.name}
                        onChange={(event) => form.setData('name', event.target.value)}
                    />
                </label>
                <label className="text-text-primary text-sm">
                    Status
                    <select
                        className={field}
                        value={form.data.status}
                        onChange={(event) =>
                            form.setData('status', event.target.value as typeof form.data.status)
                        }
                    >
                        <option value="active">Active</option>
                        <option value="inactive">Inactive</option>
                    </select>
                </label>
                <label className="text-text-primary text-sm">
                    Minimum courses
                    <input
                        type="number"
                        min="0"
                        max={form.data.maximum_courses}
                        className={field}
                        value={form.data.minimum_courses}
                        onChange={(event) => form.setData('minimum_courses', event.target.value)}
                    />
                </label>
                <label className="text-text-primary text-sm">
                    Maximum courses
                    <input
                        required
                        type="number"
                        min="1"
                        max="40"
                        className={field}
                        value={form.data.maximum_courses}
                        onChange={(event) => form.setData('maximum_courses', event.target.value)}
                    />
                </label>
                <label className="text-text-primary text-sm">
                    Maximum credits
                    <input
                        type="number"
                        min="0.001"
                        step="0.001"
                        className={field}
                        value={form.data.maximum_credits}
                        onChange={(event) => form.setData('maximum_credits', event.target.value)}
                    />
                </label>
                <label className="text-text-primary text-sm">
                    Waitlist offer hours
                    <input
                        required
                        type="number"
                        min="1"
                        max="720"
                        className={field}
                        value={form.data.waitlist_offer_hours}
                        onChange={(event) => form.setData('waitlist_offer_hours', event.target.value)}
                    />
                </label>
                <label className="text-text-primary text-sm sm:col-span-2">
                    Eligible academic levels
                    <select
                        multiple
                        className={`${field} min-h-24`}
                        value={form.data.academic_level_ids}
                        onChange={(event) =>
                            form.setData(
                                'academic_level_ids',
                                Array.from(event.target.selectedOptions, (option) => option.value),
                            )
                        }
                    >
                        <option value="">All levels</option>
                        {levels.map((level) => (
                            <option key={level.id} value={String(level.id)}>
                                {level.code} · {level.name}
                            </option>
                        ))}
                    </select>
                </label>
                {(
                    [
                        ['requires_approval', 'Require registrar approval'],
                        ['waitlist_enabled', 'Enable waitlists'],
                        ['require_prerequisites', 'Require prerequisite evidence'],
                        ['require_withdrawal_approval', 'Require withdrawal approval'],
                        ['allow_section_transfer', 'Allow section transfers'],
                        ['allow_late_registration', 'Allow late registration'],
                    ] as const
                ).map(([key, label]) => (
                    <label key={key} className="text-text-primary flex min-h-10 items-center gap-2 text-sm">
                        <input
                            type="checkbox"
                            checked={form.data[key]}
                            onChange={(event) => form.setData(key, event.target.checked)}
                        />
                        {label}
                    </label>
                ))}
                <button
                    disabled={form.processing}
                    className="bg-brand-action min-h-11 rounded-lg px-4 font-semibold text-white sm:col-span-2"
                >
                    Save policy changes
                </button>
            </form>
        </details>
    );
}

function OfferingSettingsForm({
    base,
    offering,
    policies,
    campuses,
}: {
    base: string;
    offering: Offering;
    policies: Props['policies'];
    campuses: Option[];
}) {
    const form = useForm({
        policy_id: String(offering.policyId),
        campus_id: offering.campusId ? String(offering.campusId) : '',
        delivery_mode: offering.deliveryMode,
        registration_opens_at: offering.registrationOpensAt ?? '',
        registration_closes_at: offering.registrationClosesAt ?? '',
    });

    return (
        <details className="border-border mt-3 rounded-lg border p-3">
            <summary className="text-text-primary min-h-9 cursor-pointer font-medium">
                Edit offering settings
            </summary>
            <form
                className="mt-3 grid gap-3 sm:grid-cols-2"
                onSubmit={(event) => {
                    event.preventDefault();
                    form.patch(`${base}/offerings/${offering.id}`, { preserveScroll: true });
                }}
            >
                <FormErrors errors={form.errors} />
                <label className="text-text-primary text-sm">
                    Registration policy
                    <select
                        className={field}
                        value={form.data.policy_id}
                        onChange={(event) => form.setData('policy_id', event.target.value)}
                    >
                        {policies.map((policy) => (
                            <option key={policy.id} value={policy.id}>
                                {policy.name}
                            </option>
                        ))}
                    </select>
                </label>
                <label className="text-text-primary text-sm">
                    Campus
                    <select
                        className={field}
                        value={form.data.campus_id}
                        onChange={(event) => form.setData('campus_id', event.target.value)}
                    >
                        <option value="">Institution-wide</option>
                        {campuses.map((campus) => (
                            <option key={campus.id} value={campus.id}>
                                {campus.name}
                            </option>
                        ))}
                    </select>
                </label>
                <label className="text-text-primary text-sm">
                    Delivery mode
                    <select
                        className={field}
                        value={form.data.delivery_mode}
                        onChange={(event) =>
                            form.setData(
                                'delivery_mode',
                                event.target.value as typeof form.data.delivery_mode,
                            )
                        }
                    >
                        <option value="in_person">In person</option>
                        <option value="online">Online</option>
                        <option value="hybrid">Hybrid</option>
                    </select>
                </label>
                <label className="text-text-primary text-sm">
                    Registration opens
                    <input
                        type="datetime-local"
                        className={field}
                        value={form.data.registration_opens_at}
                        onChange={(event) => form.setData('registration_opens_at', event.target.value)}
                    />
                </label>
                <label className="text-text-primary text-sm">
                    Registration closes
                    <input
                        type="datetime-local"
                        className={field}
                        value={form.data.registration_closes_at}
                        onChange={(event) => form.setData('registration_closes_at', event.target.value)}
                    />
                </label>
                <button
                    disabled={form.processing}
                    className="bg-brand-action min-h-10 rounded-lg px-3 font-semibold text-white sm:col-span-2"
                >
                    Save offering settings
                </button>
            </form>
        </details>
    );
}

function SectionSettingsForm({
    base,
    section,
    campuses,
}: {
    base: string;
    section: Offering['sections'][number];
    campuses: Option[];
}) {
    const form = useForm({
        capacity: String(section.capacity),
        reserved_seats: String(section.reserved),
        campus_id: section.campusId ? String(section.campusId) : '',
        status: section.status,
    });
    return (
        <details className="mt-2">
            <summary className="text-text-secondary min-h-9 cursor-pointer">
                Edit section capacity and status
            </summary>
            <form
                className="mt-2 grid gap-2"
                onSubmit={(event) => {
                    event.preventDefault();
                    form.patch(
                        `${base.replace('/registrar/enrollment', '/registrar/enrollment')}/sections/${section.id}`,
                        { preserveScroll: true },
                    );
                }}
            >
                <FormErrors errors={form.errors} />
                <label>
                    Capacity
                    <input
                        required
                        type="number"
                        min="1"
                        max="10000"
                        className={field}
                        value={form.data.capacity}
                        onChange={(event) => form.setData('capacity', event.target.value)}
                    />
                </label>
                <label>
                    Reserved seats
                    <input
                        required
                        type="number"
                        min="0"
                        max="10000"
                        className={field}
                        value={form.data.reserved_seats}
                        onChange={(event) => form.setData('reserved_seats', event.target.value)}
                    />
                </label>
                <label>
                    Section campus
                    <select
                        className={field}
                        value={form.data.campus_id}
                        onChange={(event) => form.setData('campus_id', event.target.value)}
                    >
                        <option value="">Use offering campus</option>
                        {campuses.map((campus) => (
                            <option key={campus.id} value={campus.id}>
                                {campus.name}
                            </option>
                        ))}
                    </select>
                </label>
                <label>
                    Status
                    <select
                        className={field}
                        value={form.data.status}
                        onChange={(event) =>
                            form.setData('status', event.target.value as typeof form.data.status)
                        }
                    >
                        <option value="open">Open</option>
                        <option value="closed">Closed</option>
                    </select>
                </label>
                <button
                    disabled={form.processing}
                    className="border-border min-h-10 rounded-lg border px-3 font-semibold"
                >
                    Save section settings
                </button>
            </form>
        </details>
    );
}

function InstructorForm({
    base,
    sectionId,
    instructors,
}: {
    base: string;
    sectionId: number;
    instructors: Props['instructors'];
}) {
    const form = useForm({ user_id: '' });
    if (instructors.length === 0) return null;
    return (
        <form
            className="mt-2 grid gap-2 sm:grid-cols-[2fr_auto]"
            onSubmit={(event) => {
                event.preventDefault();
                form.post(`${base}/sections/${sectionId}/instructors`, {
                    preserveScroll: true,
                    onSuccess: () => form.reset(),
                });
            }}
        >
            <label className="text-text-secondary text-xs">
                Assign instructor
                <select
                    required
                    className={field}
                    value={form.data.user_id}
                    onChange={(e) => form.setData('user_id', e.target.value)}
                >
                    <option value="">Select instructor</option>
                    {instructors.map((instructor) => (
                        <option key={instructor.user_id} value={instructor.user_id}>
                            {instructor.name} · {instructor.email}
                        </option>
                    ))}
                </select>
            </label>
            <button className="border-border text-text-primary min-h-11 self-end rounded-lg border px-3 text-sm font-semibold">
                Assign instructor
            </button>
        </form>
    );
}

function DecisionButtons({
    onDecide,
}: {
    onDecide: (decision: 'approve' | 'reject', reason: string) => void;
}) {
    const decide = (decision: 'approve' | 'reject') => {
        const reason = window.prompt(`Reason to ${decision} this request`);
        if (reason?.trim()) onDecide(decision, reason.trim());
    };
    return (
        <div className="flex gap-2">
            <button
                type="button"
                onClick={() => decide('approve')}
                className="bg-brand-action min-h-10 rounded-lg px-3 text-sm font-semibold text-white"
            >
                Approve
            </button>
            <button
                type="button"
                onClick={() => decide('reject')}
                className="border-border text-text-primary min-h-10 rounded-lg border px-3 text-sm font-semibold"
            >
                Reject
            </button>
        </div>
    );
}
