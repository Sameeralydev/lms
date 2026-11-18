import { Link, router, useForm } from '@inertiajs/react';
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
    status: string;
    installments: { sequence: number; due_on: string; amount: string }[];
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

const field = 'border-border bg-canvas text-text-primary mt-1 w-full rounded-lg border p-2';
const button = 'bg-brand rounded-lg px-4 py-2 font-semibold text-white disabled:opacity-50';

export default function BillingLifecycle({
    tenant,
    students,
    campuses,
    structures,
    invoices,
    assignments,
    concessions,
    installment_plans,
    adjustments,
    permissions,
}: {
    tenant: { slug: string };
    students: Student[];
    campuses: { id: number; name: string }[];
    structures: {
        id: number;
        name: string;
        currency: string;
        effective_from: string;
        effective_until: string | null;
    }[];
    invoices: Invoice[];
    assignments: Assignment[];
    concessions: Concession[];
    installment_plans: InstallmentPlan[];
    adjustments: Adjustment[];
    permissions: {
        assign: boolean;
        concession_request: boolean;
        concession_review: boolean;
        installments: boolean;
        adjustment_request: boolean;
        adjustment_review: boolean;
        adjustment_post: boolean;
        ledger: boolean;
    };
}) {
    const base = `/t/${tenant.slug}/billing`;
    const assignment = useForm({
        student_id: '',
        fee_structure_id: '',
        period_start: '',
        period_end: '',
        effective_from: '',
        effective_until: '',
        idempotency_key: crypto.randomUUID(),
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
                                    {index === 0 ? 'First' : 'Second'} due date
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
                                    {index === 0 ? 'First' : 'Second'} amount
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
                            </div>
                        ))}
                        <button className={button} disabled={installment.processing}>
                            Save two-part draft
                        </button>
                    </form>
                    {installment_plans.length > 0 && (
                        <ul className="mt-4 space-y-2">
                            {installment_plans.map((plan) => (
                                <li
                                    className="border-border flex flex-wrap items-center justify-between gap-3 border-t pt-3"
                                    key={plan.id}
                                >
                                    <span>
                                        {plan.invoice}:{' '}
                                        {plan.installments
                                            .map((row) => `${row.due_on} (${row.amount})`)
                                            .join(' · ')}{' '}
                                        — {plan.status}
                                    </span>
                                    {plan.status === 'draft' && (
                                        <button
                                            className="border-border rounded border px-3 py-1"
                                            onClick={() =>
                                                router.post(`${base}/installment-plans/${plan.id}/publish`)
                                            }
                                        >
                                            Publish plan
                                        </button>
                                    )}
                                </li>
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
