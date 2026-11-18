import { Link, router, useForm, usePage } from '@inertiajs/react';
import { PageHeader } from '@/components/ui/page-header';
import { WorkspaceLayout } from '@/layouts/workspace-layout';

type Student = { id: number; student_number: string; legal_first_name: string; legal_last_name: string };
type Invoice = {
    id: number;
    number: string;
    student_id: number;
    student: string;
    currency: string;
    amount_due: string;
    outstanding: string;
    status: string;
};
type Assignment = {
    id: number;
    student: string;
    structure: string;
    period_start: string;
    period_end: string;
    source: string;
    status: string;
};
type FeeRule = {
    id: number;
    fee_structure: string;
    currency: string;
    campus_id: number | null;
    academic_program_id: number | null;
    academic_period_id: number | null;
    academic_course_id: number | null;
    academic_class_id: number | null;
    academic_section_id: number | null;
    effective_from: string;
    effective_until: string | null;
    status: string;
};
type Concession = {
    id: number;
    student: string;
    code: string;
    name: string;
    kind: string;
    value: string;
    status: string;
    requested_by: number;
};
type InstallmentPlan = {
    id: number;
    invoice: string;
    currency: string;
    status: string;
    version: number;
    installments: {
        sequence: number;
        due_on: string;
        amount: string;
        paid_amount: string;
        outstanding_amount: string;
        status: string;
    }[];
    history: { event_type: string; version: number; reason: string | null; occurred_at: string | null }[];
};
type Adjustment = {
    id: number;
    number: string;
    invoice: string;
    kind: string;
    amount: string;
    status: string;
    requested_by: number;
    reviewed_by: number | null;
};

type InstallmentEdit = { sequence: number | undefined; due_on: string; amount: string };

const field = 'border-border bg-canvas text-text-primary mt-1 w-full rounded-lg border p-2';
const button = 'bg-brand rounded-lg px-4 py-2 font-semibold text-white disabled:opacity-50';

function InstallmentPlanCard({ plan, base }: { plan: InstallmentPlan; base: string }) {
    const form = useForm<{ installments: InstallmentEdit[]; reason: string; request_key: string }>({
        installments: plan.installments.map(({ sequence, due_on, amount }) => ({ sequence, due_on, amount })),
        reason: '',
        request_key: crypto.randomUUID(),
    });
    const activeRows = form.data.installments;

    return (
        <li className="border-border border-t pt-3" key={plan.id}>
            <div className="flex flex-wrap items-start justify-between gap-3">
                <div>
                    <p className="text-text-primary font-medium">
                        {plan.invoice} · {plan.status} · version {plan.version}
                    </p>
                    <ul className="text-text-secondary mt-2 space-y-1 text-sm">
                        {plan.installments.map((row) => (
                            <li key={row.sequence}>
                                #{row.sequence} · due {row.due_on} · {plan.currency} {row.amount} · paid{' '}
                                {plan.currency} {row.paid_amount} · remaining {plan.currency}{' '}
                                {row.outstanding_amount} · {row.status.replace('_', ' ')}
                            </li>
                        ))}
                    </ul>
                </div>
                {plan.status === 'draft' && (
                    <button
                        className="border-border rounded border px-3 py-1"
                        onClick={() => router.post(`${base}/installment-plans/${plan.id}/publish`)}
                    >
                        Publish plan
                    </button>
                )}
            </div>
            {plan.status === 'published' && (
                <details className="mt-3">
                    <summary className="text-brand cursor-pointer text-sm font-medium">
                        Amend unpaid installments
                    </summary>
                    <form
                        className="mt-3 space-y-3"
                        onSubmit={(event) => {
                            event.preventDefault();
                            form.post(`${base}/installment-plans/${plan.id}/amend`, {
                                onSuccess: () => form.setData('request_key', crypto.randomUUID()),
                            });
                        }}
                    >
                        {activeRows.map((row, index) => {
                            const current = plan.installments.find((item) => item.sequence === row.sequence);
                            const paid = current !== undefined && current.paid_amount !== '0.00';
                            return (
                                <div
                                    key={row.sequence ?? `new-${index}`}
                                    className="grid gap-2 sm:grid-cols-[1fr_1fr_auto]"
                                >
                                    <label className="text-text-secondary text-sm">
                                        Due date
                                        <input
                                            aria-label={`Installment ${row.sequence ?? 'new'} due date`}
                                            className={field}
                                            type="date"
                                            required
                                            disabled={paid}
                                            value={row.due_on}
                                            onChange={(event) =>
                                                form.setData(
                                                    'installments',
                                                    activeRows.map((candidate, candidateIndex) =>
                                                        candidateIndex === index
                                                            ? {
                                                                  ...candidate,
                                                                  due_on: event.currentTarget.value,
                                                              }
                                                            : candidate,
                                                    ),
                                                )
                                            }
                                        />
                                    </label>
                                    <label className="text-text-secondary text-sm">
                                        Amount
                                        <input
                                            aria-label={`Installment ${row.sequence ?? 'new'} amount`}
                                            className={field}
                                            required
                                            inputMode="decimal"
                                            disabled={paid}
                                            value={row.amount}
                                            onChange={(event) =>
                                                form.setData(
                                                    'installments',
                                                    activeRows.map((candidate, candidateIndex) =>
                                                        candidateIndex === index
                                                            ? {
                                                                  ...candidate,
                                                                  amount: event.currentTarget.value,
                                                              }
                                                            : candidate,
                                                    ),
                                                )
                                            }
                                        />
                                    </label>
                                    {!paid && (
                                        <button
                                            type="button"
                                            className="border-border self-end rounded border px-3 py-2"
                                            onClick={() =>
                                                form.setData(
                                                    'installments',
                                                    activeRows.filter(
                                                        (_, candidateIndex) => candidateIndex !== index,
                                                    ),
                                                )
                                            }
                                        >
                                            Remove
                                        </button>
                                    )}
                                </div>
                            );
                        })}
                        <button
                            type="button"
                            className="border-border rounded border px-3 py-2"
                            disabled={activeRows.length >= 36}
                            onClick={() =>
                                form.setData('installments', [
                                    ...activeRows,
                                    { sequence: undefined, due_on: '', amount: '' },
                                ])
                            }
                        >
                            Add installment
                        </button>
                        <label className="text-text-secondary block text-sm">
                            Amendment reason
                            <textarea
                                className={field}
                                minLength={5}
                                maxLength={500}
                                required
                                value={form.data.reason}
                                onChange={(event) => form.setData('reason', event.currentTarget.value)}
                            />
                        </label>
                        {Object.values(form.errors).length > 0 && (
                            <p role="alert" className="text-sm text-red-700">
                                {Object.values(form.errors).join(' ')}
                            </p>
                        )}
                        <button className={button} disabled={form.processing}>
                            Save amendment
                        </button>
                    </form>
                </details>
            )}
            {plan.history.length > 0 && (
                <details className="mt-3">
                    <summary className="text-text-secondary cursor-pointer text-sm">Version history</summary>
                    <ul className="text-text-secondary mt-2 space-y-1 text-xs">
                        {plan.history.map((event, index) => (
                            <li key={`${event.version}-${event.event_type}-${index}`}>
                                Version {event.version}: {event.event_type}
                                {event.reason ? ` — ${event.reason}` : ''}
                                {event.occurred_at
                                    ? ` · ${new Date(event.occurred_at).toLocaleString()}`
                                    : ''}
                            </li>
                        ))}
                    </ul>
                </details>
            )}
        </li>
    );
}

export default function BillingLifecycle({
    tenant,
    students,
    confirmed_enrollments,
    campuses,
    structures,
    invoices,
    assignments,
    fee_applicability_rules,
    academic_programs,
    academic_periods,
    academic_courses,
    academic_classes,
    academic_sections,
    concessions,
    installment_plans,
    adjustments,
    recurring_plans,
    enrollment_assessment_structure_id,
    proration_policy,
    permissions,
}: {
    tenant: { slug: string };
    students: Student[];
    confirmed_enrollments: { id: number; student_number: string; academic_course_id: number }[];
    campuses: { id: number; name: string }[];
    structures: {
        id: number;
        name: string;
        currency: string;
        frequency: string;
        effective_from: string;
        effective_until: string | null;
    }[];
    invoices: Invoice[];
    assignments: Assignment[];
    fee_applicability_rules: FeeRule[];
    academic_programs: { id: number; title: string; code: string }[];
    academic_periods: { id: number; name: string; code: string; starts_on: string; ends_on: string }[];
    academic_courses: { id: number; title: string; code: string }[];
    academic_classes: { id: number; name: string; code: string }[];
    academic_sections: { id: number; academic_class_id: number; name: string }[];
    concessions: Concession[];
    installment_plans: InstallmentPlan[];
    adjustments: Adjustment[];
    recurring_plans: {
        id: number;
        student: string;
        structure: string;
        frequency: string;
        next_period_start: string;
        next_period_end: string;
        next_run_at: string;
        status: string;
        latest_run: { status: string; attempts: number; failure_code: string | null } | null;
    }[];
    enrollment_assessment_structure_id: number | null;
    proration_policy: {
        enabled: boolean;
        method: string;
        effective_from: string;
        effective_until: string | null;
    };
    permissions: {
        assign: boolean;
        concession_request: boolean;
        concession_review: boolean;
        installments: boolean;
        adjustment_request: boolean;
        adjustment_review: boolean;
        adjustment_post: boolean;
        ledger: boolean;
        issue: boolean;
        recurring: boolean;
        fee_policy: boolean;
    };
}) {
    const base = `/t/${tenant.slug}/billing`;
    const flash = (
        usePage().props as {
            flash?: {
                billingBulkFailedIds?: number[];
                billingProrationPreview?: {
                    method: string;
                    full_amount: string;
                    eligible_days: number;
                    period_days: number;
                    prorated_amount: string;
                    eligible_from: string;
                    eligible_until: string;
                };
                billingApplicabilityPreview?: {
                    eligible: boolean;
                    reason: string;
                    fee_structure_id: number | null;
                    rule_id: number | null;
                };
                billingReassessmentResult?: {
                    outcome: string;
                    assignment_id: number | null;
                    issued_obligation_preserved: boolean;
                };
            };
        }
    ).flash;
    const assignment = useForm({
        student_id: '',
        fee_structure_id: '',
        period_start: '',
        period_end: '',
        effective_from: '',
        effective_until: '',
        idempotency_key: crypto.randomUUID(),
    });
    const feeRule = useForm({
        fee_structure_id: '',
        campus_id: '',
        academic_program_id: '',
        academic_period_id: '',
        academic_course_id: '',
        academic_class_id: '',
        academic_section_id: '',
        effective_from: '',
        effective_until: '',
    });
    const feeRulePreview = useForm({
        student_id: '',
        academic_period_id: '',
        on_date: new Date().toISOString().slice(0, 10),
    });
    const concession = useForm({
        student_id: '',
        code: '',
        name: '',
        kind: 'fixed',
        value: '',
        effective_from: '',
        effective_until: '',
        reason: '',
    });
    const installment = useForm({
        invoice_id: '',
        installments: [
            { due_on: '', amount: '' },
            { due_on: '', amount: '' },
        ],
    });
    const adjustment = useForm({
        invoice_id: '',
        kind: 'credit',
        amount: '',
        currency: '',
        idempotency_key: crypto.randomUUID(),
        reason: '',
    });
    const bulkInvoice = useForm({
        student_ids: [] as string[],
        fee_structure_id: '',
        period_start: '',
        period_end: '',
        due_on: '',
    });
    const recurring = useForm({
        student_id: '',
        fee_structure_id: '',
        frequency: 'monthly',
        period_start: '',
        period_end: '',
        due_days_after_period: '0',
        timezone: Intl.DateTimeFormat().resolvedOptions().timeZone || 'UTC',
    });
    const enrollmentAssessment = useForm({
        fee_structure_id: enrollment_assessment_structure_id?.toString() ?? '',
    });
    const prorationSetting = useForm({
        enabled: proration_policy.enabled,
        method: proration_policy.method,
        effective_from: proration_policy.effective_from,
        effective_until: proration_policy.effective_until ?? '',
    });
    const prorationPreview = useForm({
        amount: '',
        period_start: '',
        period_end: '',
        charge_start: '',
        charge_end: '',
    });

    return (
        <WorkspaceLayout area="tenant" title="Billing lifecycle">
            <PageHeader
                title="Billing lifecycle"
                description="Manage scoped fee assignments, independently reviewed concessions, installment schedules, credit and debit adjustments, and learner account statements."
                actions={
                    <Link
                        href={`${base}`}
                        className="border-border text-text-secondary rounded-lg border px-4 py-2"
                    >
                        Billing overview
                    </Link>
                }
            />
            {permissions.issue && (
                <section className="bg-surface border-border mb-5 rounded-2xl border p-5">
                    <h2 className="text-text-primary text-lg font-semibold">Issue invoices in bulk</h2>
                    <p className="text-text-secondary mt-1 text-sm">
                        Each learner is processed independently with an idempotency key tied to the fee
                        structure and billing period.
                    </p>
                    {(flash?.billingBulkFailedIds?.length ?? 0) > 0 && (
                        <p className="mt-2 rounded-lg border border-red-500 p-3 text-sm" role="alert">
                            Invoice generation needs review for learner IDs:{' '}
                            {flash?.billingBulkFailedIds?.join(', ')}.
                        </p>
                    )}
                    <form
                        className="mt-4 grid gap-3 sm:grid-cols-2 lg:grid-cols-5"
                        onSubmit={(event) => {
                            event.preventDefault();
                            bulkInvoice.post(`${base}/invoices/bulk`);
                        }}
                    >
                        <label className="text-text-secondary text-sm">
                            Fee structure
                            <select
                                required
                                className={field}
                                value={bulkInvoice.data.fee_structure_id}
                                onChange={(e) => bulkInvoice.setData('fee_structure_id', e.target.value)}
                            >
                                <option value="">Select structure</option>
                                {structures.map((item) => (
                                    <option key={item.id} value={item.id}>
                                        {item.name} ({item.currency})
                                    </option>
                                ))}
                            </select>
                        </label>
                        <label className="text-text-secondary text-sm">
                            Period starts
                            <input
                                required
                                type="date"
                                className={field}
                                value={bulkInvoice.data.period_start}
                                onChange={(e) => bulkInvoice.setData('period_start', e.target.value)}
                            />
                        </label>
                        <label className="text-text-secondary text-sm">
                            Period ends
                            <input
                                required
                                type="date"
                                className={field}
                                value={bulkInvoice.data.period_end}
                                onChange={(e) => bulkInvoice.setData('period_end', e.target.value)}
                            />
                        </label>
                        <label className="text-text-secondary text-sm">
                            Due on
                            <input
                                required
                                type="date"
                                className={field}
                                value={bulkInvoice.data.due_on}
                                onChange={(e) => bulkInvoice.setData('due_on', e.target.value)}
                            />
                        </label>
                        <div className="flex items-end">
                            <button
                                disabled={bulkInvoice.processing || bulkInvoice.data.student_ids.length === 0}
                                className={button}
                            >
                                Issue {bulkInvoice.data.student_ids.length} invoice(s)
                            </button>
                        </div>
                        <fieldset className="border-border max-h-48 overflow-y-auto rounded-lg border p-3 sm:col-span-2 lg:col-span-5">
                            <legend className="text-text-secondary px-1 text-sm">Learners (up to 200)</legend>
                            <div className="grid gap-2 sm:grid-cols-2 lg:grid-cols-3">
                                {students.map((student) => {
                                    const selected = bulkInvoice.data.student_ids.includes(
                                        String(student.id),
                                    );
                                    return (
                                        <label
                                            className="text-text-secondary flex items-center gap-2 text-sm"
                                            key={student.id}
                                        >
                                            <input
                                                type="checkbox"
                                                checked={selected}
                                                disabled={
                                                    !selected && bulkInvoice.data.student_ids.length >= 200
                                                }
                                                onChange={(e) =>
                                                    bulkInvoice.setData(
                                                        'student_ids',
                                                        e.target.checked
                                                            ? [
                                                                  ...bulkInvoice.data.student_ids,
                                                                  String(student.id),
                                                              ]
                                                            : bulkInvoice.data.student_ids.filter(
                                                                  (id) => id !== String(student.id),
                                                              ),
                                                    )
                                                }
                                            />
                                            {student.student_number} · {student.legal_first_name}{' '}
                                            {student.legal_last_name}
                                        </label>
                                    );
                                })}
                            </div>
                        </fieldset>
                    </form>
                </section>
            )}
            {permissions.fee_policy && (
                <section className="bg-surface border-border mb-5 rounded-2xl border p-5">
                    <h2 className="text-text-primary text-lg font-semibold">Proration policy and preview</h2>
                    <p className="text-text-secondary mt-1 text-sm">
                        Disabled by default. Calendar-day calculations are a preview only and never change
                        issued invoices. Configure an institution-approved effective window before using
                        previews.
                    </p>
                    <form
                        className="mt-4 grid gap-3 sm:grid-cols-2 lg:grid-cols-4"
                        onSubmit={(event) => {
                            event.preventDefault();
                            prorationSetting.put(`${base}/proration-policy`);
                        }}
                    >
                        <label className="text-text-secondary flex items-center gap-2 text-sm">
                            <input
                                type="checkbox"
                                checked={prorationSetting.data.enabled}
                                onChange={(event) =>
                                    prorationSetting.setData('enabled', event.currentTarget.checked)
                                }
                            />
                            Enable configured preview policy
                        </label>
                        <label className="text-text-secondary text-sm">
                            Calculation method
                            <select
                                className={field}
                                value={prorationSetting.data.method}
                                onChange={(event) =>
                                    prorationSetting.setData('method', event.currentTarget.value)
                                }
                            >
                                <option value="calendar_days">Calendar days</option>
                            </select>
                        </label>
                        <label className="text-text-secondary text-sm">
                            Effective from
                            <input
                                type="date"
                                required
                                className={field}
                                value={prorationSetting.data.effective_from}
                                onChange={(event) =>
                                    prorationSetting.setData('effective_from', event.currentTarget.value)
                                }
                            />
                        </label>
                        <label className="text-text-secondary text-sm">
                            Effective until
                            <input
                                type="date"
                                className={field}
                                value={prorationSetting.data.effective_until}
                                onChange={(event) =>
                                    prorationSetting.setData('effective_until', event.currentTarget.value)
                                }
                            />
                        </label>
                        <button className={button} disabled={prorationSetting.processing}>
                            Save policy
                        </button>
                    </form>
                    <form
                        className="border-border mt-5 grid gap-3 border-t pt-4 sm:grid-cols-2 lg:grid-cols-5"
                        onSubmit={(event) => {
                            event.preventDefault();
                            prorationPreview.post(`${base}/proration-preview`);
                        }}
                    >
                        <label className="text-text-secondary text-sm">
                            Full-period amount
                            <input
                                className={field}
                                inputMode="decimal"
                                required
                                value={prorationPreview.data.amount}
                                onChange={(event) =>
                                    prorationPreview.setData('amount', event.currentTarget.value)
                                }
                            />
                        </label>
                        <label className="text-text-secondary text-sm">
                            Billing starts
                            <input
                                className={field}
                                type="date"
                                required
                                value={prorationPreview.data.period_start}
                                onChange={(event) =>
                                    prorationPreview.setData('period_start', event.currentTarget.value)
                                }
                            />
                        </label>
                        <label className="text-text-secondary text-sm">
                            Billing ends
                            <input
                                className={field}
                                type="date"
                                required
                                value={prorationPreview.data.period_end}
                                onChange={(event) =>
                                    prorationPreview.setData('period_end', event.currentTarget.value)
                                }
                            />
                        </label>
                        <label className="text-text-secondary text-sm">
                            Charge starts
                            <input
                                className={field}
                                type="date"
                                required
                                value={prorationPreview.data.charge_start}
                                onChange={(event) =>
                                    prorationPreview.setData('charge_start', event.currentTarget.value)
                                }
                            />
                        </label>
                        <label className="text-text-secondary text-sm">
                            Charge ends
                            <input
                                className={field}
                                type="date"
                                required
                                value={prorationPreview.data.charge_end}
                                onChange={(event) =>
                                    prorationPreview.setData('charge_end', event.currentTarget.value)
                                }
                            />
                        </label>
                        <button className={button} disabled={prorationPreview.processing}>
                            Preview only
                        </button>
                    </form>
                    {Object.values(prorationSetting.errors).length +
                        Object.values(prorationPreview.errors).length >
                        0 && (
                        <p role="alert" className="mt-3 text-sm text-red-700">
                            {Object.values(prorationSetting.errors)
                                .concat(Object.values(prorationPreview.errors))
                                .join(' ')}
                        </p>
                    )}
                    {flash?.billingProrationPreview && (
                        <p role="status" className="border-border mt-3 rounded-lg border p-3 text-sm">
                            {flash.billingProrationPreview.method}:{' '}
                            {flash.billingProrationPreview.prorated_amount} for{' '}
                            {flash.billingProrationPreview.eligible_days} of{' '}
                            {flash.billingProrationPreview.period_days} days (
                            {flash.billingProrationPreview.eligible_from}–
                            {flash.billingProrationPreview.eligible_until}); full-period amount{' '}
                            {flash.billingProrationPreview.full_amount}.
                        </p>
                    )}
                </section>
            )}
            {permissions.assign && (
                <section className="bg-surface border-border mb-5 rounded-2xl border p-5">
                    <h2 className="text-text-primary text-lg font-semibold">Enrollment billing policy</h2>
                    <p className="text-text-secondary mt-1 text-sm">
                        Optional. Confirmed enrollments create an idempotent one-time fee assignment only;
                        staff still review and issue the invoice separately.
                    </p>
                    <form
                        className="mt-3 flex flex-wrap items-end gap-3"
                        onSubmit={(event) => {
                            event.preventDefault();
                            enrollmentAssessment.put(`${base}/enrollment-assessment`);
                        }}
                    >
                        <label className="text-text-secondary min-w-64 text-sm">
                            Initial enrollment fee
                            <select
                                className={field}
                                value={enrollmentAssessment.data.fee_structure_id}
                                onChange={(e) =>
                                    enrollmentAssessment.setData('fee_structure_id', e.target.value)
                                }
                            >
                                <option value="">Disabled</option>
                                {structures
                                    .filter((s) => s.frequency === 'one_time')
                                    .map((s) => (
                                        <option key={s.id} value={s.id}>
                                            {s.name} ({s.currency})
                                        </option>
                                    ))}
                            </select>
                        </label>
                        <button className={button} disabled={enrollmentAssessment.processing}>
                            Save setting
                        </button>
                    </form>
                    <h3 className="text-text-primary mt-4 font-semibold">Reassess confirmed enrollments</h3>
                    <p className="text-text-secondary text-sm">
                        Reassessment never edits or issues an invoice. If an invoice already exists, finance
                        review is required before a new fee assignment can be created.
                    </p>
                    {flash?.billingReassessmentResult && (
                        <p role="status" className="mt-2 text-sm">
                            Result: {flash.billingReassessmentResult.outcome}. Existing issued obligation
                            preserved:{' '}
                            {flash.billingReassessmentResult.issued_obligation_preserved
                                ? 'yes'
                                : 'not found'}
                            .
                            {flash.billingReassessmentResult.outcome === 'financial_review_required' &&
                                ' Review the existing obligation through the approved adjustment workflow before issuing any additional charge.'}
                        </p>
                    )}
                    <ul className="divide-border mt-2 divide-y">
                        {confirmed_enrollments.map((enrollment) => (
                            <li
                                key={enrollment.id}
                                className="flex flex-wrap items-center justify-between gap-2 py-2 text-sm"
                            >
                                <span>
                                    {enrollment.student_number} · enrollment {enrollment.id}
                                </span>
                                <button
                                    type="button"
                                    className="border-border rounded border px-3 py-1"
                                    onClick={() => {
                                        if (
                                            !window.confirm(
                                                'Recheck fee eligibility for this enrollment? Existing invoices will not be changed.',
                                            )
                                        )
                                            return;
                                        const reason = window.prompt('Reason for reassessment');
                                        if (reason && reason.trim().length >= 5)
                                            router.post(
                                                `${base}/enrollment-assessment/${enrollment.id}/reassess`,
                                                { reason },
                                            );
                                    }}
                                >
                                    Reassess
                                </button>
                            </li>
                        ))}
                    </ul>
                    <h2 className="text-text-primary text-lg font-semibold">Recurring billing plans</h2>
                    <p className="text-text-secondary mt-1 text-sm">
                        Plans are explicit per learner and use the structure’s monthly or annual frequency.
                        Term billing remains institution-calendar dependent and is not automated here.
                    </p>
                    <form
                        className="mt-4 grid gap-3 sm:grid-cols-2 lg:grid-cols-4"
                        onSubmit={(event) => {
                            event.preventDefault();
                            recurring.post(`${base}/recurring-plans`);
                        }}
                    >
                        <label className="text-text-secondary text-sm">
                            Learner
                            <select
                                required
                                className={field}
                                value={recurring.data.student_id}
                                onChange={(e) => recurring.setData('student_id', e.target.value)}
                            >
                                <option value="">Select learner</option>
                                {students.map((s) => (
                                    <option key={s.id} value={s.id}>
                                        {s.student_number} · {s.legal_first_name} {s.legal_last_name}
                                    </option>
                                ))}
                            </select>
                        </label>
                        <label className="text-text-secondary text-sm">
                            Fee structure
                            <select
                                required
                                className={field}
                                value={recurring.data.fee_structure_id}
                                onChange={(e) => recurring.setData('fee_structure_id', e.target.value)}
                            >
                                <option value="">Select structure</option>
                                {structures.map((s) => (
                                    <option key={s.id} value={s.id}>
                                        {s.name} ({s.frequency})
                                    </option>
                                ))}
                            </select>
                        </label>
                        <label className="text-text-secondary text-sm">
                            Frequency
                            <select
                                className={field}
                                value={recurring.data.frequency}
                                onChange={(e) => recurring.setData('frequency', e.target.value)}
                            >
                                <option value="monthly">Monthly</option>
                                <option value="annual">Annual</option>
                            </select>
                        </label>
                        <label className="text-text-secondary text-sm">
                            Timezone
                            <input
                                required
                                className={field}
                                value={recurring.data.timezone}
                                onChange={(e) => recurring.setData('timezone', e.target.value)}
                            />
                        </label>
                        <label className="text-text-secondary text-sm">
                            First period starts
                            <input
                                required
                                type="date"
                                className={field}
                                value={recurring.data.period_start}
                                onChange={(e) => recurring.setData('period_start', e.target.value)}
                            />
                        </label>
                        <label className="text-text-secondary text-sm">
                            First period ends
                            <input
                                required
                                type="date"
                                className={field}
                                value={recurring.data.period_end}
                                onChange={(e) => recurring.setData('period_end', e.target.value)}
                            />
                        </label>
                        <label className="text-text-secondary text-sm">
                            Due days after period
                            <input
                                required
                                min="0"
                                max="90"
                                type="number"
                                className={field}
                                value={recurring.data.due_days_after_period}
                                onChange={(e) => recurring.setData('due_days_after_period', e.target.value)}
                            />
                        </label>
                        <div className="flex items-end">
                            <button className={button} disabled={recurring.processing}>
                                Create plan
                            </button>
                        </div>
                    </form>
                    <div className="mt-4 overflow-x-auto">
                        <table className="w-full min-w-[700px] text-left text-sm">
                            <thead>
                                <tr>
                                    {[
                                        'Learner',
                                        'Structure',
                                        'Frequency',
                                        'Next period',
                                        'Status',
                                        'Latest run',
                                        'Action',
                                    ].map((heading) => (
                                        <th className="p-2" key={heading}>
                                            {heading}
                                        </th>
                                    ))}
                                </tr>
                            </thead>
                            <tbody>
                                {recurring_plans.map((plan) => (
                                    <tr className="border-border border-t" key={plan.id}>
                                        <td className="p-2">{plan.student}</td>
                                        <td>{plan.structure}</td>
                                        <td>{plan.frequency}</td>
                                        <td>
                                            {plan.next_period_start} – {plan.next_period_end}
                                        </td>
                                        <td>{plan.status}</td>
                                        <td className="text-sm">
                                            {plan.latest_run
                                                ? `${plan.latest_run.status} · ${plan.latest_run.attempts} attempt(s)`
                                                : 'Not run'}
                                            {plan.latest_run?.status === 'failed' &&
                                                plan.latest_run.failure_code && (
                                                    <span className="text-text-muted block text-xs">
                                                        Failure reference:{' '}
                                                        {plan.latest_run.failure_code.slice(0, 12)}
                                                    </span>
                                                )}
                                        </td>
                                        <td className="flex gap-2 py-2">
                                            {plan.status !== 'cancelled' && (
                                                <button
                                                    className="border-border rounded border px-2 py-1"
                                                    onClick={() =>
                                                        router.patch(
                                                            `${base}/recurring-plans/${plan.id}/status`,
                                                            {
                                                                status:
                                                                    plan.status === 'active'
                                                                        ? 'paused'
                                                                        : 'active',
                                                            },
                                                        )
                                                    }
                                                >
                                                    {plan.status === 'active' ? 'Pause' : 'Resume'}
                                                </button>
                                            )}
                                            {plan.status === 'active' &&
                                                plan.latest_run?.status === 'failed' && (
                                                    <button
                                                        className="border-border rounded border px-2 py-1"
                                                        onClick={() =>
                                                            router.post(
                                                                `${base}/recurring-plans/${plan.id}/retry`,
                                                            )
                                                        }
                                                    >
                                                        Retry period
                                                    </button>
                                                )}
                                            {plan.status !== 'cancelled' && (
                                                <button
                                                    className="border-border rounded border px-2 py-1"
                                                    onClick={() => {
                                                        if (
                                                            window.confirm(
                                                                'Cancel this recurring plan? Existing invoices are preserved.',
                                                            )
                                                        )
                                                            router.patch(
                                                                `${base}/recurring-plans/${plan.id}/status`,
                                                                { status: 'cancelled' },
                                                            );
                                                    }}
                                                >
                                                    Cancel
                                                </button>
                                            )}
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                </section>
            )}
            {permissions.fee_policy && (
                <section className="bg-surface border-border mb-5 rounded-2xl border p-5">
                    <h2 className="text-text-primary text-lg font-semibold">Academic fee applicability</h2>
                    <p className="text-text-secondary mt-1 text-sm">
                        Rules are created as drafts. Activate only after checking that the academic scope and
                        date range are correct. Equally specific overlapping active rules are rejected.
                    </p>
                    <form
                        className="mt-4 grid gap-3 sm:grid-cols-2 lg:grid-cols-3"
                        onSubmit={(event) => {
                            event.preventDefault();
                            feeRule.post(`${base}/fee-applicability`);
                        }}
                    >
                        <label className="text-text-secondary text-sm">
                            Fee structure
                            <select
                                required
                                className={field}
                                value={feeRule.data.fee_structure_id}
                                onChange={(e) => feeRule.setData('fee_structure_id', e.target.value)}
                            >
                                <option value="">Select</option>
                                {structures.map((row) => (
                                    <option key={row.id} value={row.id}>
                                        {row.name} ({row.currency})
                                    </option>
                                ))}
                            </select>
                        </label>
                        <label className="text-text-secondary text-sm">
                            Campus scope
                            <select
                                className={field}
                                value={feeRule.data.campus_id}
                                onChange={(e) => feeRule.setData('campus_id', e.target.value)}
                            >
                                <option value="">All authorized campuses</option>
                                {campuses.map((row) => (
                                    <option key={row.id} value={row.id}>
                                        {row.name}
                                    </option>
                                ))}
                            </select>
                        </label>
                        <label className="text-text-secondary text-sm">
                            Program scope
                            <select
                                className={field}
                                value={feeRule.data.academic_program_id}
                                onChange={(e) => feeRule.setData('academic_program_id', e.target.value)}
                            >
                                <option value="">All programs</option>
                                {academic_programs.map((row) => (
                                    <option key={row.id} value={row.id}>
                                        {row.title} ({row.code})
                                    </option>
                                ))}
                            </select>
                        </label>
                        <label className="text-text-secondary text-sm">
                            Academic period
                            <select
                                className={field}
                                value={feeRule.data.academic_period_id}
                                onChange={(e) => feeRule.setData('academic_period_id', e.target.value)}
                            >
                                <option value="">Any period</option>
                                {academic_periods.map((row) => (
                                    <option key={row.id} value={row.id}>
                                        {row.name} ({row.starts_on}–{row.ends_on})
                                    </option>
                                ))}
                            </select>
                        </label>
                        <label className="text-text-secondary text-sm">
                            Course scope
                            <select
                                className={field}
                                value={feeRule.data.academic_course_id}
                                onChange={(e) => feeRule.setData('academic_course_id', e.target.value)}
                            >
                                <option value="">All courses</option>
                                {academic_courses.map((row) => (
                                    <option key={row.id} value={row.id}>
                                        {row.title} ({row.code})
                                    </option>
                                ))}
                            </select>
                        </label>
                        <label className="text-text-secondary text-sm">
                            Class scope
                            <select
                                className={field}
                                value={feeRule.data.academic_class_id}
                                onChange={(e) => {
                                    feeRule.setData('academic_class_id', e.target.value);
                                    feeRule.setData('academic_section_id', '');
                                }}
                            >
                                <option value="">All classes</option>
                                {academic_classes.map((row) => (
                                    <option key={row.id} value={row.id}>
                                        {row.name} ({row.code})
                                    </option>
                                ))}
                            </select>
                        </label>
                        <label className="text-text-secondary text-sm">
                            Section scope
                            <select
                                className={field}
                                value={feeRule.data.academic_section_id}
                                onChange={(e) => feeRule.setData('academic_section_id', e.target.value)}
                            >
                                <option value="">All sections</option>
                                {academic_sections
                                    .filter(
                                        (row) =>
                                            feeRule.data.academic_class_id === '' ||
                                            row.academic_class_id === Number(feeRule.data.academic_class_id),
                                    )
                                    .map((row) => (
                                        <option key={row.id} value={row.id}>
                                            {row.name}
                                        </option>
                                    ))}
                            </select>
                        </label>
                        <label className="text-text-secondary text-sm">
                            Effective from
                            <input
                                required
                                type="date"
                                className={field}
                                value={feeRule.data.effective_from}
                                onChange={(e) => feeRule.setData('effective_from', e.target.value)}
                            />
                        </label>
                        <label className="text-text-secondary text-sm">
                            Effective until
                            <input
                                type="date"
                                className={field}
                                value={feeRule.data.effective_until}
                                onChange={(e) => feeRule.setData('effective_until', e.target.value)}
                            />
                        </label>
                        <button className={button} disabled={feeRule.processing}>
                            Create draft rule
                        </button>
                    </form>
                    {Object.values(feeRule.errors).length > 0 && (
                        <p role="alert" className="mt-2 text-sm text-red-700">
                            {Object.values(feeRule.errors).join(' ')}
                        </p>
                    )}
                    <ul className="divide-border mt-5 divide-y">
                        {fee_applicability_rules.map((rule) => (
                            <li
                                key={rule.id}
                                className="flex flex-wrap items-center justify-between gap-2 py-3 text-sm"
                            >
                                <span>
                                    {rule.fee_structure} · {rule.currency} · program{' '}
                                    {academic_programs.find((p) => p.id === rule.academic_program_id)?.code ??
                                        'all'}{' '}
                                    · period{' '}
                                    {academic_periods.find((p) => p.id === rule.academic_period_id)?.name ??
                                        'all'}{' '}
                                    · {rule.effective_from} to {rule.effective_until ?? 'open'} ·{' '}
                                    {rule.status}
                                </span>
                                {rule.status !== 'draft' && (
                                    <button
                                        type="button"
                                        className="border-border rounded border px-3 py-1"
                                        onClick={() =>
                                            router.patch(`${base}/fee-applicability/${rule.id}/status`, {
                                                status: rule.status === 'active' ? 'inactive' : 'active',
                                            })
                                        }
                                    >
                                        {rule.status === 'active' ? 'Deactivate' : 'Activate'}
                                    </button>
                                )}
                                {rule.status === 'draft' && (
                                    <button
                                        type="button"
                                        className="border-border rounded border px-3 py-1"
                                        onClick={() =>
                                            router.patch(`${base}/fee-applicability/${rule.id}/status`, {
                                                status: 'active',
                                            })
                                        }
                                    >
                                        Activate
                                    </button>
                                )}
                            </li>
                        ))}
                    </ul>
                    <form
                        className="mt-5 grid gap-3 sm:grid-cols-2 lg:grid-cols-4"
                        onSubmit={(event) => {
                            event.preventDefault();
                            feeRulePreview.post(`${base}/fee-applicability/preview`);
                        }}
                    >
                        <label className="text-text-secondary text-sm">
                            Preview learner
                            <select
                                required
                                className={field}
                                value={feeRulePreview.data.student_id}
                                onChange={(e) => feeRulePreview.setData('student_id', e.target.value)}
                            >
                                <option value="">Select</option>
                                {students.map((s) => (
                                    <option key={s.id} value={s.id}>
                                        {s.student_number}
                                    </option>
                                ))}
                            </select>
                        </label>
                        <label className="text-text-secondary text-sm">
                            Period
                            <select
                                required
                                className={field}
                                value={feeRulePreview.data.academic_period_id}
                                onChange={(e) => feeRulePreview.setData('academic_period_id', e.target.value)}
                            >
                                <option value="">Select</option>
                                {academic_periods.map((p) => (
                                    <option key={p.id} value={p.id}>
                                        {p.name}
                                    </option>
                                ))}
                            </select>
                        </label>
                        <label className="text-text-secondary text-sm">
                            On date
                            <input
                                required
                                type="date"
                                className={field}
                                value={feeRulePreview.data.on_date}
                                onChange={(e) => feeRulePreview.setData('on_date', e.target.value)}
                            />
                        </label>
                        <button className={button} disabled={feeRulePreview.processing}>
                            Preview eligibility
                        </button>
                    </form>
                    {Object.values(feeRulePreview.errors).length > 0 && (
                        <p role="alert" className="mt-2 text-sm text-red-700">
                            {Object.values(feeRulePreview.errors).join(' ')}
                        </p>
                    )}
                    {flash?.billingApplicabilityPreview && (
                        <p role="status" className="mt-3 text-sm">
                            {flash.billingApplicabilityPreview.eligible
                                ? `Eligible under rule ${flash.billingApplicabilityPreview.rule_id}; fee structure ${flash.billingApplicabilityPreview.fee_structure_id}.`
                                : `No automatic assignment: ${flash.billingApplicabilityPreview.reason}.`}
                        </p>
                    )}
                </section>
            )}
            <div className="grid gap-5 xl:grid-cols-2">
                {permissions.assign && (
                    <section className="bg-surface border-border rounded-2xl border p-5">
                        <h2 className="text-text-primary text-lg font-semibold">Assign a fee structure</h2>
                        <form
                            className="mt-4 grid gap-3 sm:grid-cols-2"
                            onSubmit={(event) => {
                                event.preventDefault();
                                assignment.post(`${base}/assignments`, {
                                    onSuccess: () => {
                                        assignment.reset();
                                        assignment.setData('idempotency_key', crypto.randomUUID());
                                    },
                                });
                            }}
                        >
                            <label className="text-text-secondary text-sm">
                                Learner
                                <select
                                    className={field}
                                    required
                                    value={assignment.data.student_id}
                                    onChange={(e) => assignment.setData('student_id', e.target.value)}
                                >
                                    <option value="">Select learner</option>
                                    {students.map((s) => (
                                        <option key={s.id} value={s.id}>
                                            {s.student_number} · {s.legal_first_name} {s.legal_last_name}
                                        </option>
                                    ))}
                                </select>
                            </label>
                            <label className="text-text-secondary text-sm">
                                Fee structure
                                <select
                                    className={field}
                                    required
                                    value={assignment.data.fee_structure_id}
                                    onChange={(e) => assignment.setData('fee_structure_id', e.target.value)}
                                >
                                    <option value="">Select structure</option>
                                    {structures.map((s) => (
                                        <option key={s.id} value={s.id}>
                                            {s.name} ({s.currency})
                                        </option>
                                    ))}
                                </select>
                            </label>
                            <label className="text-text-secondary text-sm">
                                Billing period starts
                                <input
                                    className={field}
                                    type="date"
                                    required
                                    value={assignment.data.period_start}
                                    onChange={(e) => assignment.setData('period_start', e.target.value)}
                                />
                            </label>
                            <label className="text-text-secondary text-sm">
                                Billing period ends
                                <input
                                    className={field}
                                    type="date"
                                    required
                                    value={assignment.data.period_end}
                                    onChange={(e) => assignment.setData('period_end', e.target.value)}
                                />
                            </label>
                            <label className="text-text-secondary text-sm">
                                Effective from
                                <input
                                    className={field}
                                    type="date"
                                    required
                                    value={assignment.data.effective_from}
                                    onChange={(e) => assignment.setData('effective_from', e.target.value)}
                                />
                            </label>
                            <label className="text-text-secondary text-sm">
                                Effective until (optional)
                                <input
                                    className={field}
                                    type="date"
                                    value={assignment.data.effective_until}
                                    onChange={(e) => assignment.setData('effective_until', e.target.value)}
                                />
                            </label>
                            {assignment.errors.student_id && (
                                <p role="alert" className="text-danger sm:col-span-2">
                                    {assignment.errors.student_id}
                                </p>
                            )}
                            <button className={button} disabled={assignment.processing}>
                                Save assignment
                            </button>
                        </form>
                    </section>
                )}
                {permissions.concession_request && (
                    <section className="bg-surface border-border rounded-2xl border p-5">
                        <h2 className="text-text-primary text-lg font-semibold">Request a concession</h2>
                        <form
                            className="mt-4 grid gap-3 sm:grid-cols-2"
                            onSubmit={(event) => {
                                event.preventDefault();
                                concession.post(`${base}/concessions`, {
                                    onSuccess: () => concession.reset(),
                                });
                            }}
                        >
                            <label className="text-text-secondary text-sm">
                                Learner
                                <select
                                    className={field}
                                    required
                                    value={concession.data.student_id}
                                    onChange={(e) => concession.setData('student_id', e.target.value)}
                                >
                                    <option value="">Select learner</option>
                                    {students.map((s) => (
                                        <option key={s.id} value={s.id}>
                                            {s.student_number} · {s.legal_first_name} {s.legal_last_name}
                                        </option>
                                    ))}
                                </select>
                            </label>
                            <label className="text-text-secondary text-sm">
                                Code
                                <input
                                    className={field}
                                    required
                                    maxLength={40}
                                    value={concession.data.code}
                                    onChange={(e) => concession.setData('code', e.target.value)}
                                />
                            </label>
                            <label className="text-text-secondary text-sm">
                                Name
                                <input
                                    className={field}
                                    required
                                    maxLength={120}
                                    value={concession.data.name}
                                    onChange={(e) => concession.setData('name', e.target.value)}
                                />
                            </label>
                            <label className="text-text-secondary text-sm">
                                Type
                                <select
                                    className={field}
                                    value={concession.data.kind}
                                    onChange={(e) => concession.setData('kind', e.target.value)}
                                >
                                    <option value="fixed">Fixed amount</option>
                                    <option value="percentage">Percentage</option>
                                </select>
                            </label>
                            <label className="text-text-secondary text-sm">
                                Value
                                <input
                                    className={field}
                                    inputMode="decimal"
                                    required
                                    value={concession.data.value}
                                    onChange={(e) => concession.setData('value', e.target.value)}
                                />
                            </label>
                            <label className="text-text-secondary text-sm">
                                Effective from
                                <input
                                    className={field}
                                    type="date"
                                    required
                                    value={concession.data.effective_from}
                                    onChange={(e) => concession.setData('effective_from', e.target.value)}
                                />
                            </label>
                            <label className="text-text-secondary text-sm">
                                Effective until (optional)
                                <input
                                    className={field}
                                    type="date"
                                    value={concession.data.effective_until}
                                    onChange={(e) => concession.setData('effective_until', e.target.value)}
                                />
                            </label>
                            <label className="text-text-secondary text-sm sm:col-span-2">
                                Reason
                                <textarea
                                    className={field}
                                    required
                                    minLength={5}
                                    maxLength={1000}
                                    value={concession.data.reason}
                                    onChange={(e) => concession.setData('reason', e.target.value)}
                                />
                            </label>
                            {concession.errors.value && (
                                <p role="alert" className="text-danger sm:col-span-2">
                                    {concession.errors.value}
                                </p>
                            )}
                            <button className={button} disabled={concession.processing}>
                                Submit for review
                            </button>
                        </form>
                    </section>
                )}
            </div>

            <section className="bg-surface border-border mt-5 overflow-x-auto rounded-2xl border p-5">
                <h2 className="text-text-primary text-lg font-semibold">Fee assignments</h2>
                {assignments.length === 0 ? (
                    <p className="text-text-secondary mt-3">
                        No assignments match this authorized institution and campus scope.
                    </p>
                ) : (
                    <table className="mt-3 w-full min-w-[640px] text-left text-sm">
                        <thead>
                            <tr>
                                <th>Learner</th>
                                <th>Structure</th>
                                <th>Period</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            {assignments.map((row) => (
                                <tr className="border-border border-t" key={row.id}>
                                    <td className="py-3">{row.student}</td>
                                    <td>{row.structure}</td>
                                    <td>
                                        {row.period_start} – {row.period_end}
                                    </td>
                                    <td>{row.status}</td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                )}
            </section>

            <section className="bg-surface border-border mt-5 overflow-x-auto rounded-2xl border p-5">
                <h2 className="text-text-primary text-lg font-semibold">Concessions</h2>
                {concessions.length === 0 ? (
                    <p className="text-text-secondary mt-3">No concession requests are recorded.</p>
                ) : (
                    <table className="mt-3 w-full min-w-[700px] text-left text-sm">
                        <thead>
                            <tr>
                                <th>Learner</th>
                                <th>Concession</th>
                                <th>Value</th>
                                <th>Status</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            {concessions.map((row) => (
                                <tr className="border-border border-t" key={row.id}>
                                    <td className="py-3">{row.student}</td>
                                    <td>
                                        {row.name} ({row.code})
                                    </td>
                                    <td>
                                        {row.value} {row.kind === 'percentage' ? '%' : ''}
                                    </td>
                                    <td>{row.status}</td>
                                    <td className="flex flex-wrap gap-2 py-2">
                                        {permissions.concession_review &&
                                            row.status === 'pending_approval' && (
                                                <>
                                                    <button
                                                        className="border-border rounded border px-2 py-1"
                                                        onClick={() =>
                                                            router.post(
                                                                `${base}/concessions/${row.id}/review`,
                                                                {
                                                                    decision: 'approve',
                                                                    note: 'Reviewed in billing workspace.',
                                                                },
                                                            )
                                                        }
                                                    >
                                                        Approve
                                                    </button>
                                                    <button
                                                        className="border-border rounded border px-2 py-1"
                                                        onClick={() =>
                                                            router.post(
                                                                `${base}/concessions/${row.id}/review`,
                                                                {
                                                                    decision: 'reject',
                                                                    note: 'Does not meet current approval criteria.',
                                                                },
                                                            )
                                                        }
                                                    >
                                                        Reject
                                                    </button>
                                                </>
                                            )}
                                        {permissions.concession_request && row.status === 'approved' && (
                                            <button
                                                className="border-border rounded border px-2 py-1"
                                                onClick={() =>
                                                    router.post(`${base}/concessions/${row.id}/revoke`, {
                                                        reason: 'Revoked by authorized billing operator.',
                                                    })
                                                }
                                            >
                                                Revoke
                                            </button>
                                        )}
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                )}
            </section>

            {permissions.installments && (
                <section className="bg-surface border-border mt-5 rounded-2xl border p-5">
                    <h2 className="text-text-primary text-lg font-semibold">Create an installment plan</h2>
                    <form
                        className="mt-4 grid gap-3 sm:grid-cols-2 lg:grid-cols-5"
                        onSubmit={(event) => {
                            event.preventDefault();
                            installment.post(`${base}/invoices/${installment.data.invoice_id}/installments`, {
                                onSuccess: () => installment.reset(),
                            });
                        }}
                    >
                        <label className="text-text-secondary text-sm lg:col-span-2">
                            Invoice
                            <select
                                className={field}
                                required
                                value={installment.data.invoice_id}
                                onChange={(e) => installment.setData('invoice_id', e.target.value)}
                            >
                                <option value="">Select invoice</option>
                                {invoices.map((i) => (
                                    <option key={i.id} value={i.id}>
                                        {i.number} · {i.currency} {i.outstanding}
                                    </option>
                                ))}
                            </select>
                        </label>
                        {installment.data.installments.map((row, index) => (
                            <div className="contents" key={index}>
                                <label className="text-text-secondary text-sm">
                                    Installment {index + 1} due date
                                    <input
                                        className={field}
                                        type="date"
                                        required
                                        value={row.due_on}
                                        onChange={(e) =>
                                            installment.setData(
                                                'installments',
                                                installment.data.installments.map((item, itemIndex) =>
                                                    itemIndex === index
                                                        ? { ...item, due_on: e.target.value }
                                                        : item,
                                                ),
                                            )
                                        }
                                    />
                                </label>
                                <label className="text-text-secondary text-sm">
                                    Installment {index + 1} amount
                                    <input
                                        className={field}
                                        required
                                        inputMode="decimal"
                                        value={row.amount}
                                        onChange={(e) =>
                                            installment.setData(
                                                'installments',
                                                installment.data.installments.map((item, itemIndex) =>
                                                    itemIndex === index
                                                        ? { ...item, amount: e.target.value }
                                                        : item,
                                                ),
                                            )
                                        }
                                    />
                                </label>
                                <button
                                    type="button"
                                    className="border-border rounded border px-3 py-2 sm:col-span-2 lg:col-span-1"
                                    disabled={installment.data.installments.length <= 1}
                                    onClick={() =>
                                        installment.setData(
                                            'installments',
                                            installment.data.installments.filter(
                                                (_, itemIndex) => itemIndex !== index,
                                            ),
                                        )
                                    }
                                >
                                    Remove installment {index + 1}
                                </button>
                            </div>
                        ))}
                        <button
                            type="button"
                            className="border-border rounded border px-3 py-2"
                            disabled={installment.data.installments.length >= 36}
                            onClick={() =>
                                installment.setData('installments', [
                                    ...installment.data.installments,
                                    { due_on: '', amount: '' },
                                ])
                            }
                        >
                            Add installment
                        </button>
                        <button
                            className={button}
                            disabled={installment.processing || installment.data.installments.length < 1}
                        >
                            Save schedule draft
                        </button>
                    </form>
                    {installment_plans.length > 0 && (
                        <ul className="mt-4 space-y-2">
                            {installment_plans.map((plan) => (
                                <InstallmentPlanCard key={plan.id} plan={plan} base={base} />
                            ))}
                        </ul>
                    )}
                </section>
            )}

            {permissions.adjustment_request && (
                <section className="bg-surface border-border mt-5 rounded-2xl border p-5">
                    <h2 className="text-text-primary text-lg font-semibold">Request an invoice adjustment</h2>
                    <form
                        className="mt-4 grid gap-3 sm:grid-cols-2 lg:grid-cols-4"
                        onSubmit={(event) => {
                            event.preventDefault();
                            adjustment.post(`${base}/adjustments`, {
                                onSuccess: () => {
                                    adjustment.reset();
                                    adjustment.setData('idempotency_key', crypto.randomUUID());
                                },
                            });
                        }}
                    >
                        <label className="text-text-secondary text-sm">
                            Invoice
                            <select
                                className={field}
                                required
                                value={adjustment.data.invoice_id}
                                onChange={(e) => {
                                    const invoice = invoices.find(
                                        (item) => item.id.toString() === e.target.value,
                                    );
                                    adjustment.setData({
                                        ...adjustment.data,
                                        invoice_id: e.target.value,
                                        currency: invoice?.currency ?? '',
                                    });
                                }}
                            >
                                <option value="">Select invoice</option>
                                {invoices.map((i) => (
                                    <option key={i.id} value={i.id}>
                                        {i.number} · {i.outstanding} due
                                    </option>
                                ))}
                            </select>
                        </label>
                        <label className="text-text-secondary text-sm">
                            Adjustment type
                            <select
                                className={field}
                                value={adjustment.data.kind}
                                onChange={(e) => adjustment.setData('kind', e.target.value)}
                            >
                                <option value="credit">Credit note</option>
                                <option value="debit">Debit adjustment</option>
                            </select>
                        </label>
                        <label className="text-text-secondary text-sm">
                            Amount
                            <input
                                className={field}
                                required
                                inputMode="decimal"
                                value={adjustment.data.amount}
                                onChange={(e) => adjustment.setData('amount', e.target.value)}
                            />
                        </label>
                        <label className="text-text-secondary text-sm">
                            Currency
                            <input
                                className={field}
                                required
                                maxLength={3}
                                value={adjustment.data.currency}
                                onChange={(e) => adjustment.setData('currency', e.target.value.toUpperCase())}
                            />
                        </label>
                        <label className="text-text-secondary text-sm sm:col-span-2 lg:col-span-4">
                            Reason
                            <textarea
                                className={field}
                                required
                                minLength={5}
                                maxLength={1000}
                                value={adjustment.data.reason}
                                onChange={(e) => adjustment.setData('reason', e.target.value)}
                            />
                        </label>
                        <button className={button} disabled={adjustment.processing}>
                            Submit for independent approval
                        </button>
                    </form>
                </section>
            )}

            <section className="bg-surface border-border mt-5 overflow-x-auto rounded-2xl border p-5">
                <h2 className="text-text-primary text-lg font-semibold">Adjustments</h2>
                {adjustments.length === 0 ? (
                    <p className="text-text-secondary mt-3">No adjustment requests are recorded.</p>
                ) : (
                    <table className="mt-3 w-full min-w-[680px] text-left text-sm">
                        <thead>
                            <tr>
                                <th>Reference</th>
                                <th>Invoice</th>
                                <th>Type</th>
                                <th>Amount</th>
                                <th>Status</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            {adjustments.map((row) => (
                                <tr className="border-border border-t" key={row.id}>
                                    <td className="py-3">{row.number}</td>
                                    <td>{row.invoice}</td>
                                    <td>{row.kind}</td>
                                    <td>{row.amount}</td>
                                    <td>{row.status}</td>
                                    <td className="flex flex-wrap gap-2 py-2">
                                        {permissions.adjustment_review &&
                                            row.status === 'pending_approval' && (
                                                <>
                                                    <button
                                                        className="border-border rounded border px-2 py-1"
                                                        onClick={() =>
                                                            router.post(
                                                                `${base}/adjustments/${row.id}/review`,
                                                                {
                                                                    decision: 'approve',
                                                                    note: 'Reviewed in billing workspace.',
                                                                },
                                                            )
                                                        }
                                                    >
                                                        Approve
                                                    </button>
                                                    <button
                                                        className="border-border rounded border px-2 py-1"
                                                        onClick={() =>
                                                            router.post(
                                                                `${base}/adjustments/${row.id}/review`,
                                                                {
                                                                    decision: 'reject',
                                                                    note: 'Rejected in billing workspace.',
                                                                },
                                                            )
                                                        }
                                                    >
                                                        Reject
                                                    </button>
                                                </>
                                            )}
                                        {permissions.adjustment_post && row.status === 'approved' && (
                                            <button
                                                className="border-border rounded border px-2 py-1"
                                                onClick={() =>
                                                    router.post(`${base}/adjustments/${row.id}/post`)
                                                }
                                            >
                                                Post approved adjustment
                                            </button>
                                        )}
                                        {permissions.adjustment_request && row.status === 'posted' && (
                                            <button
                                                className="border-border rounded border px-2 py-1"
                                                onClick={() => {
                                                    const reason = window.prompt(
                                                        'Explain why this posted adjustment must be reversed.',
                                                    );
                                                    if (reason?.trim())
                                                        router.post(`${base}/adjustments/${row.id}/reverse`, {
                                                            reason: reason.trim(),
                                                            idempotency_key: crypto.randomUUID(),
                                                        });
                                                }}
                                            >
                                                Request reversal
                                            </button>
                                        )}
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                )}
            </section>

            {permissions.ledger && (
                <section className="bg-surface border-border mt-5 rounded-2xl border p-5">
                    <h2 className="text-text-primary text-lg font-semibold">Student statements</h2>
                    <div className="mt-3 flex flex-wrap gap-2">
                        {students.map((student) => (
                            <Link
                                className="border-border rounded-lg border px-3 py-2 text-sm"
                                key={student.id}
                                href={`${base}/ledger/${student.id}`}
                            >
                                Ledger · {student.student_number}
                            </Link>
                        ))}
                    </div>
                </section>
            )}
            <p className="text-text-secondary mt-4 text-xs">
                Campus options: {campuses.length}. Financial changes preserve original invoice and payment
                records; posted updates are recorded in the audit history.
            </p>
        </WorkspaceLayout>
    );
}
