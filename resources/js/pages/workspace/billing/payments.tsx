import { Link, useForm } from '@inertiajs/react';
import { useMemo, useRef, useState } from 'react';
import { EmptyState } from '@/components/ui/empty-state';
import { MetricCard } from '@/components/ui/metric-card';
import { PageHeader } from '@/components/ui/page-header';
import { WorkspaceLayout } from '@/layouts/workspace-layout';

type Student = { id: number; student_number: string; legal_first_name: string; legal_last_name: string };
type Invoice = {
    id: number;
    student_id: number;
    invoice_number: string;
    student_number: string;
    currency: string;
    outstanding_amount: string;
    due_on: string;
};
type Payment = {
    id: number;
    student_id: number;
    payment_number: string;
    student_number: string;
    student_name: string;
    method: string;
    status: string;
    currency: string;
    amount: string;
    unapplied_amount: string;
    reference: string | null;
    recorded_at: string;
    receipt_number: string | null;
    allocations: { invoice_number: string; amount: string }[];
    refunds: {
        id: number;
        refund_number: string;
        amount: string;
        currency: string;
        status: string;
        reason: string;
        payout_reference: string | null;
    }[];
    reversals: {
        id: number;
        reversal_number: string;
        status: string;
        reason: string;
        review_note: string | null;
    }[];
};
type PageLink = { url: string | null; label: string; active: boolean };

export default function BillingPayments({
    tenant,
    summary,
    students,
    open_invoices,
    payments,
    permissions,
}: {
    tenant: { slug: string };
    summary: {
        payment_count: number;
        confirmed_totals: { currency: string; amount: string }[];
        pending_totals: { currency: string; amount: string }[];
        refunded_totals: { currency: string; amount: string }[];
    };
    students: Student[];
    open_invoices: Invoice[];
    payments: { data: Payment[]; links: PageLink[] };
    permissions: {
        collect: boolean;
        verify: boolean;
        allocate: boolean;
        request_refund: boolean;
        approve_refund: boolean;
        execute_refund: boolean;
        view_refunds: boolean;
        request_reversal: boolean;
        approve_reversal: boolean;
        execute_reversal: boolean;
    };
}) {
    const base = `/t/${tenant.slug}/billing`;
    const [selectedStudent, setSelectedStudent] = useState('');
    const [allocationRows, setAllocationRows] = useState([{ invoice_id: '', amount: '' }]);
    const collectionRequestKey = useRef('');
    const collection = useForm({
        student_id: '',
        method: 'cash',
        amount: '',
        currency: 'USD',
        reference: '',
        idempotency_key: '',
        allocations: [{ invoice_id: '', amount: '' }],
    });
    const verificationForms = useMemo(
        () =>
            open_invoices.reduce(
                (grouped, invoice) => {
                    (grouped[invoice.student_id] ??= []).push(invoice);
                    return grouped;
                },
                {} as Record<number, Invoice[]>,
            ),
        [open_invoices],
    );
    const availableInvoices = open_invoices.filter(
        (invoice) => invoice.student_id === Number(selectedStudent),
    );
    const submitCollection = (event: React.FormEvent<HTMLFormElement>) => {
        event.preventDefault();
        collectionRequestKey.current ||= crypto.randomUUID();
        collection.transform((data) => ({
            ...data,
            idempotency_key: collectionRequestKey.current,
            allocations: allocationRows.filter((row) => row.invoice_id && row.amount),
        }));
        collection.post(`${base}/payments`, {
            onSuccess: () => {
                collectionRequestKey.current = '';
                collection.reset();
                setSelectedStudent('');
                setAllocationRows([{ invoice_id: '', amount: '' }]);
            },
        });
    };

    return (
        <WorkspaceLayout area="tenant" title="Payments & collections">
            <PageHeader
                title="Payments & collections"
                description="Record authorized collections, review bank transfers, allocate confirmed balances and reprint receipts."
            />
            <div className="mb-4">
                <Link href={base} className="text-brand underline">
                    Back to billing
                </Link>
                {' · '}
                <Link href={`${base}/reconciliation`} className="text-brand underline">
                    Collection reconciliation
                </Link>
            </div>
            <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                <MetricCard
                    label="Payment records"
                    value={summary.payment_count.toLocaleString()}
                    detail="Within your institution and campus access"
                />
                <MetricCard
                    label="Confirmed collections (gross)"
                    value={
                        summary.confirmed_totals.map((row) => `${row.currency} ${row.amount}`).join(' · ') ||
                        '0'
                    }
                    detail="Grouped by currency; confirmed payment records only"
                />
                <MetricCard
                    label="Awaiting verification"
                    value={
                        summary.pending_totals.map((row) => `${row.currency} ${row.amount}`).join(' · ') ||
                        '0'
                    }
                    detail="Grouped by currency; these transfers are not applied to invoices"
                />
                <MetricCard
                    label="Refunds executed"
                    value={
                        summary.refunded_totals.map((row) => `${row.currency} ${row.amount}`).join(' · ') ||
                        '0'
                    }
                    detail="Recorded manual refund payouts, grouped by currency"
                />
            </div>

            {permissions.collect && (
                <section className="bg-surface border-border mt-6 rounded-2xl border p-5">
                    <h2 className="text-text-primary text-lg font-semibold">Record a payment</h2>
                    <p className="text-text-secondary mt-1 text-sm">
                        Cash is confirmed at collection. Bank transfers remain pending until a different
                        authorized operator verifies them.
                    </p>
                    <form
                        className="mt-4 grid gap-3 sm:grid-cols-2 xl:grid-cols-3"
                        onSubmit={submitCollection}
                    >
                        <label className="text-text-secondary text-sm">
                            Learner
                            <select
                                className="border-border bg-canvas text-text-primary mt-1 w-full rounded-lg border p-2"
                                value={selectedStudent}
                                required
                                onChange={(event) => {
                                    setSelectedStudent(event.currentTarget.value);
                                    collection.setData('student_id', event.currentTarget.value);
                                    setAllocationRows([{ invoice_id: '', amount: '' }]);
                                }}
                            >
                                <option value="">Select learner</option>
                                {students.map((student) => (
                                    <option key={student.id} value={student.id}>
                                        {student.student_number} · {student.legal_first_name}{' '}
                                        {student.legal_last_name}
                                    </option>
                                ))}
                            </select>
                        </label>
                        <label className="text-text-secondary text-sm">
                            Method
                            <select
                                className="border-border bg-canvas text-text-primary mt-1 w-full rounded-lg border p-2"
                                value={collection.data.method}
                                onChange={(event) => collection.setData('method', event.currentTarget.value)}
                            >
                                <option value="cash">Cash</option>
                                <option value="bank_transfer">Bank transfer (pending verification)</option>
                            </select>
                        </label>
                        <label className="text-text-secondary text-sm">
                            Amount
                            <input
                                className="border-border bg-canvas text-text-primary mt-1 w-full rounded-lg border p-2"
                                inputMode="decimal"
                                pattern="(?:0|[1-9][0-9]{0,11})(?:\.[0-9]{1,2})?"
                                required
                                value={collection.data.amount}
                                onChange={(event) => collection.setData('amount', event.currentTarget.value)}
                            />
                        </label>
                        <label className="text-text-secondary text-sm">
                            Currency
                            <input
                                className="border-border bg-canvas text-text-primary mt-1 w-full rounded-lg border p-2 uppercase"
                                minLength={3}
                                maxLength={3}
                                pattern="[A-Z]{3}"
                                required
                                value={collection.data.currency}
                                onChange={(event) =>
                                    collection.setData('currency', event.currentTarget.value.toUpperCase())
                                }
                            />
                        </label>
                        <label className="text-text-secondary text-sm sm:col-span-2">
                            Reference{' '}
                            {collection.data.method === 'bank_transfer' ? '(required)' : '(optional)'}
                            <input
                                className="border-border bg-canvas text-text-primary mt-1 w-full rounded-lg border p-2"
                                maxLength={120}
                                required={collection.data.method === 'bank_transfer'}
                                value={collection.data.reference}
                                onChange={(event) =>
                                    collection.setData('reference', event.currentTarget.value)
                                }
                            />
                        </label>
                        {collection.data.method === 'cash' && (
                            <div className="sm:col-span-2 xl:col-span-3">
                                <div className="mb-2 flex items-center justify-between">
                                    <h3 className="text-text-primary font-medium">
                                        Invoice allocation (optional; remaining funds stay as learner credit)
                                    </h3>
                                    <button
                                        type="button"
                                        className="text-brand underline"
                                        onClick={() =>
                                            setAllocationRows([
                                                ...allocationRows,
                                                { invoice_id: '', amount: '' },
                                            ])
                                        }
                                    >
                                        Add another invoice
                                    </button>
                                </div>
                                {allocationRows.map((row, index) => (
                                    <div
                                        key={index}
                                        className="mb-2 grid gap-2 sm:grid-cols-[1fr_10rem_auto]"
                                    >
                                        <label className="sr-only" htmlFor={`collection-invoice-${index}`}>
                                            Invoice {index + 1}
                                        </label>
                                        <select
                                            id={`collection-invoice-${index}`}
                                            className="border-border bg-canvas text-text-primary rounded-lg border p-2"
                                            value={row.invoice_id}
                                            onChange={(event) => {
                                                const next = [...allocationRows];
                                                next[index] = {
                                                    ...row,
                                                    invoice_id: event.currentTarget.value,
                                                };
                                                setAllocationRows(next);
                                                collection.setData(
                                                    'currency',
                                                    availableInvoices.find(
                                                        (invoice) =>
                                                            String(invoice.id) === event.currentTarget.value,
                                                    )?.currency ?? collection.data.currency,
                                                );
                                            }}
                                        >
                                            <option value="">Do not allocate</option>
                                            {availableInvoices.map((invoice) => (
                                                <option key={invoice.id} value={invoice.id}>
                                                    {invoice.invoice_number} · due {invoice.due_on} ·{' '}
                                                    {invoice.currency} {invoice.outstanding_amount}
                                                </option>
                                            ))}
                                        </select>
                                        <label className="sr-only" htmlFor={`collection-allocation-${index}`}>
                                            Amount to allocate for row {index + 1}
                                        </label>
                                        <input
                                            id={`collection-allocation-${index}`}
                                            className="border-border bg-canvas text-text-primary rounded-lg border p-2"
                                            aria-label={`Allocation amount ${index + 1}`}
                                            inputMode="decimal"
                                            placeholder="Amount"
                                            value={row.amount}
                                            onChange={(event) => {
                                                const next = [...allocationRows];
                                                next[index] = { ...row, amount: event.currentTarget.value };
                                                setAllocationRows(next);
                                            }}
                                        />
                                        {allocationRows.length > 1 && (
                                            <button
                                                type="button"
                                                className="text-danger underline"
                                                onClick={() =>
                                                    setAllocationRows(
                                                        allocationRows.filter(
                                                            (_, rowIndex) => rowIndex !== index,
                                                        ),
                                                    )
                                                }
                                            >
                                                Remove
                                            </button>
                                        )}
                                    </div>
                                ))}
                            </div>
                        )}
                        {collection.errors && Object.keys(collection.errors).length > 0 && (
                            <p role="alert" className="text-danger sm:col-span-2 xl:col-span-3">
                                Review the highlighted payment information and try again.
                            </p>
                        )}
                        <button
                            className="bg-brand rounded-lg px-4 py-2 font-semibold text-white disabled:opacity-50"
                            disabled={collection.processing || students.length === 0}
                        >
                            Record payment
                        </button>
                    </form>
                </section>
            )}

            <section className="bg-surface border-border mt-6 rounded-2xl border p-5">
                <h2 className="text-text-primary text-lg font-semibold">Payment history</h2>
                {payments.data.length === 0 ? (
                    <EmptyState
                        title="No payments recorded"
                        description="Authorized collections and bank submissions will appear here."
                    />
                ) : (
                    <div className="mt-4 space-y-4">
                        {payments.data.map((payment) => (
                            <article key={payment.id} className="border-border rounded-xl border p-4">
                                <div className="flex flex-wrap items-start justify-between gap-3">
                                    <div>
                                        <h3 className="text-text-primary font-semibold">
                                            {payment.payment_number} · {payment.student_number} ·{' '}
                                            {payment.student_name}
                                        </h3>
                                        <p className="text-text-secondary mt-1 text-sm">
                                            {payment.method.replace('_', ' ')} ·{' '}
                                            {payment.status.replace('_', ' ')} · {payment.recorded_at}
                                        </p>
                                    </div>
                                    <div className="text-right">
                                        <p className="text-text-primary font-semibold">
                                            {payment.currency} {payment.amount}
                                        </p>
                                        <p className="text-text-secondary text-sm">
                                            Unapplied {payment.currency} {payment.unapplied_amount}
                                        </p>
                                    </div>
                                </div>
                                {payment.reference && (
                                    <p className="text-text-secondary mt-2 text-sm">
                                        Reference: {payment.reference}
                                    </p>
                                )}
                                {payment.allocations.length > 0 && (
                                    <ul className="text-text-secondary mt-2 text-sm">
                                        {payment.allocations.map((allocation) => (
                                            <li key={allocation.invoice_number}>
                                                {allocation.invoice_number}: {payment.currency}{' '}
                                                {allocation.amount}
                                            </li>
                                        ))}
                                    </ul>
                                )}
                                {payment.reversals.length > 0 && (
                                    <div className="border-border mt-3 space-y-2 border-t pt-3">
                                        <h4 className="text-text-primary text-sm font-semibold">
                                            Payment reversal history
                                        </h4>
                                        {payment.reversals.map((reversal) => (
                                            <div key={reversal.id} className="bg-canvas rounded-lg p-3">
                                                <p className="text-text-primary text-sm font-medium">
                                                    {reversal.reversal_number} ·{' '}
                                                    {reversal.status.replace('_', ' ')}
                                                </p>
                                                <p className="text-text-secondary mt-1 text-sm">
                                                    {reversal.reason}
                                                </p>
                                                {reversal.review_note && (
                                                    <p className="text-text-secondary mt-1 text-sm">
                                                        Review: {reversal.review_note}
                                                    </p>
                                                )}
                                                <PaymentReversalDecision
                                                    reversal={reversal}
                                                    base={base}
                                                    permissions={permissions}
                                                />
                                            </div>
                                        ))}
                                    </div>
                                )}
                                {permissions.request_reversal &&
                                    payment.status === 'confirmed' &&
                                    !payment.reversals.some((reversal) =>
                                        ['pending_approval', 'approved', 'reversed'].includes(
                                            reversal.status,
                                        ),
                                    ) && <PaymentReversalRequest payment={payment} base={base} />}
                                <div className="mt-3 flex flex-wrap gap-3">
                                    {payment.receipt_number && (
                                        <a
                                            className="text-brand underline"
                                            href={`${base}/payments/${payment.id}/receipt`}
                                            target="_blank"
                                            rel="noreferrer"
                                        >
                                            Receipt {payment.receipt_number}
                                        </a>
                                    )}
                                    {permissions.verify && payment.status === 'pending_verification' && (
                                        <BankReview
                                            payment={payment}
                                            base={base}
                                            openInvoices={verificationForms[payment.student_id] ?? []}
                                        />
                                    )}
                                    {permissions.allocate &&
                                        payment.status === 'confirmed' &&
                                        Number(payment.unapplied_amount) > 0 && (
                                            <AllocationForm
                                                payment={payment}
                                                base={base}
                                                invoices={verificationForms[payment.student_id] ?? []}
                                            />
                                        )}
                                </div>
                                {permissions.view_refunds && payment.refunds.length > 0 && (
                                    <div className="border-border mt-3 space-y-2 border-t pt-3">
                                        <h4 className="text-text-primary text-sm font-semibold">
                                            Refund history
                                        </h4>
                                        {payment.refunds.map((refund) => (
                                            <div key={refund.id} className="bg-canvas rounded-lg p-3">
                                                <p className="text-text-primary text-sm font-medium">
                                                    {refund.refund_number} · {refund.currency} {refund.amount}{' '}
                                                    · {refund.status.replace('_', ' ')}
                                                </p>
                                                <p className="text-text-secondary mt-1 text-sm">
                                                    {refund.reason}
                                                    {refund.payout_reference
                                                        ? ` · Payout: ${refund.payout_reference}`
                                                        : ''}
                                                </p>
                                                {(permissions.approve_refund ||
                                                    permissions.execute_refund) && (
                                                    <RefundDecision
                                                        refund={refund}
                                                        base={base}
                                                        permissions={permissions}
                                                    />
                                                )}
                                            </div>
                                        ))}
                                    </div>
                                )}
                                {permissions.request_refund && payment.status === 'confirmed' && (
                                    <RefundRequest payment={payment} base={base} />
                                )}
                            </article>
                        ))}
                        <nav aria-label="Payment pages" className="flex flex-wrap gap-2">
                            {payments.links.map((link, index) =>
                                link.url ? (
                                    <Link
                                        key={index}
                                        href={link.url}
                                        preserveScroll
                                        className={`rounded border px-3 py-1 ${link.active ? 'bg-brand text-white' : 'border-border text-text-secondary'}`}
                                        dangerouslySetInnerHTML={{ __html: link.label }}
                                    />
                                ) : (
                                    <span
                                        key={index}
                                        className="text-text-muted rounded border px-3 py-1"
                                        dangerouslySetInnerHTML={{ __html: link.label }}
                                    />
                                ),
                            )}
                        </nav>
                    </div>
                )}
            </section>
        </WorkspaceLayout>
    );
}

function RefundRequest({ payment, base }: { payment: Payment; base: string }) {
    const form = useForm({ amount: '', reason: '', request_key: '' });
    const requestKey = useRef('');
    return (
        <form
            className="border-border mt-3 grid gap-2 rounded-lg border p-3 sm:grid-cols-[10rem_1fr_auto]"
            onSubmit={(event) => {
                event.preventDefault();
                requestKey.current ||= crypto.randomUUID();
                form.transform((data) => ({ ...data, request_key: requestKey.current }));
                form.post(`${base}/payments/${payment.id}/refunds`, {
                    onSuccess: () => {
                        requestKey.current = '';
                        form.reset();
                    },
                });
            }}
        >
            <label className="text-text-secondary text-sm">
                Refund amount ({payment.currency})
                <input
                    className="border-border bg-canvas text-text-primary mt-1 w-full rounded-lg border p-2"
                    inputMode="decimal"
                    required
                    value={form.data.amount}
                    onChange={(event) => form.setData('amount', event.currentTarget.value)}
                />
            </label>
            <label className="text-text-secondary text-sm">
                Reason
                <input
                    className="border-border bg-canvas text-text-primary mt-1 w-full rounded-lg border p-2"
                    minLength={8}
                    maxLength={1000}
                    required
                    value={form.data.reason}
                    onChange={(event) => form.setData('reason', event.currentTarget.value)}
                />
            </label>
            <button
                className="border-border self-end rounded-lg border px-3 py-2 text-sm font-medium"
                disabled={form.processing}
            >
                Request refund
            </button>
        </form>
    );
}

function PaymentReversalRequest({ payment, base }: { payment: Payment; base: string }) {
    const form = useForm({ reason: '', request_key: '' });
    const requestKey = useRef('');
    return (
        <form
            className="border-border mt-3 grid gap-2 rounded-lg border p-3 sm:grid-cols-[1fr_auto]"
            onSubmit={(event) => {
                event.preventDefault();
                requestKey.current ||= crypto.randomUUID();
                form.transform((data) => ({ ...data, request_key: requestKey.current }));
                form.post(`${base}/payments/${payment.id}/reversals`, {
                    onSuccess: () => {
                        requestKey.current = '';
                        form.reset();
                    },
                });
            }}
        >
            <label className="text-text-secondary text-sm">
                Payment reversal reason
                <input
                    className="border-border bg-canvas text-text-primary mt-1 w-full rounded-lg border p-2"
                    minLength={8}
                    maxLength={1000}
                    required
                    value={form.data.reason}
                    onChange={(event) => form.setData('reason', event.currentTarget.value)}
                />
            </label>
            <button
                className="border-border self-end rounded-lg border px-3 py-2 text-sm font-medium"
                disabled={form.processing}
            >
                Request reversal
            </button>
        </form>
    );
}

function PaymentReversalDecision({
    reversal,
    base,
    permissions,
}: {
    reversal: Payment['reversals'][number];
    base: string;
    permissions: { approve_reversal: boolean; execute_reversal: boolean };
}) {
    const review = useForm({ decision: 'approve', note: '' });
    const submitReview = (event: React.FormEvent<HTMLFormElement>) => {
        event.preventDefault();
        review.post(`${base}/payment-reversals/${reversal.id}/review`);
    };
    return (
        <div className="mt-2 flex flex-wrap items-end gap-2">
            {permissions.approve_reversal && reversal.status === 'pending_approval' && (
                <form className="flex flex-wrap items-end gap-2" onSubmit={submitReview}>
                    <label className="text-text-secondary text-xs">
                        Decision
                        <select
                            className="border-border bg-surface text-text-primary mt-1 rounded-lg border p-2"
                            value={review.data.decision}
                            onChange={(event) => review.setData('decision', event.currentTarget.value)}
                        >
                            <option value="approve">Approve</option>
                            <option value="reject">Reject</option>
                        </select>
                    </label>
                    <label className="text-text-secondary text-xs">
                        Review note
                        <input
                            className="border-border bg-surface text-text-primary mt-1 rounded-lg border p-2"
                            minLength={3}
                            maxLength={500}
                            required
                            value={review.data.note}
                            onChange={(event) => review.setData('note', event.currentTarget.value)}
                        />
                    </label>
                    <button
                        className="bg-brand rounded-lg px-3 py-2 text-sm font-semibold text-white"
                        disabled={review.processing}
                    >
                        Save decision
                    </button>
                </form>
            )}
            {permissions.execute_reversal && reversal.status === 'approved' && (
                <form
                    onSubmit={(event) => {
                        event.preventDefault();
                        review.post(`${base}/payment-reversals/${reversal.id}/execute`);
                    }}
                >
                    <button
                        className="border-border rounded-lg border px-3 py-2 text-sm font-medium"
                        disabled={review.processing}
                    >
                        Execute approved reversal
                    </button>
                </form>
            )}
        </div>
    );
}

function RefundDecision({
    refund,
    base,
    permissions,
}: {
    refund: Payment['refunds'][number];
    base: string;
    permissions: { approve_refund: boolean; execute_refund: boolean };
}) {
    const review = useForm({ note: '' });
    const payout = useForm({ payout_reference: '' });
    return (
        <div className="mt-2 flex flex-wrap items-end gap-2">
            {permissions.approve_refund && refund.status === 'pending_approval' && (
                <>
                    <form
                        className="flex flex-wrap items-end gap-2"
                        onSubmit={(event) => {
                            event.preventDefault();
                            review.post(`${base}/refunds/${refund.id}/approve`);
                        }}
                    >
                        <label className="text-text-secondary text-xs">
                            Approval note
                            <input
                                className="border-border bg-surface text-text-primary mt-1 rounded-lg border p-2"
                                minLength={3}
                                required
                                value={review.data.note}
                                onChange={(event) => review.setData('note', event.currentTarget.value)}
                            />
                        </label>
                        <button
                            className="bg-brand rounded-lg px-3 py-2 text-xs font-semibold text-white"
                            disabled={review.processing}
                        >
                            Approve
                        </button>
                    </form>
                    <form
                        className="flex flex-wrap items-end gap-2"
                        onSubmit={(event) => {
                            event.preventDefault();
                            review.post(`${base}/refunds/${refund.id}/reject`);
                        }}
                    >
                        <label className="text-text-secondary text-xs">
                            Rejection note
                            <input
                                className="border-border bg-surface text-text-primary mt-1 rounded-lg border p-2"
                                minLength={3}
                                required
                                value={review.data.note}
                                onChange={(event) => review.setData('note', event.currentTarget.value)}
                            />
                        </label>
                        <button
                            className="border-danger text-danger rounded-lg border px-3 py-2 text-xs font-semibold"
                            disabled={review.processing}
                        >
                            Reject
                        </button>
                    </form>
                </>
            )}
            {permissions.execute_refund && refund.status === 'approved' && (
                <form
                    className="flex flex-wrap items-end gap-2"
                    onSubmit={(event) => {
                        event.preventDefault();
                        payout.post(`${base}/refunds/${refund.id}/execute`);
                    }}
                >
                    <label className="text-text-secondary text-xs">
                        Manual payout reference
                        <input
                            className="border-border bg-surface text-text-primary mt-1 rounded-lg border p-2"
                            maxLength={120}
                            required
                            value={payout.data.payout_reference}
                            onChange={(event) =>
                                payout.setData('payout_reference', event.currentTarget.value)
                            }
                        />
                    </label>
                    <button
                        className="bg-brand rounded-lg px-3 py-2 text-xs font-semibold text-white"
                        disabled={payout.processing}
                    >
                        Record executed payout
                    </button>
                </form>
            )}
        </div>
    );
}

function BankReview({
    payment,
    base,
    openInvoices,
}: {
    payment: Payment;
    base: string;
    openInvoices: Invoice[];
}) {
    const form = useForm({ request_key: '', allocations: [{ invoice_id: '', amount: '' }] });
    const reason = useForm({ request_key: '', reason: '' });
    const verifyKey = useRef('');
    const rejectKey = useRef('');
    return (
        <div className="border-border mt-3 grid w-full gap-3 rounded-lg border p-3 sm:grid-cols-2">
            <form
                className="flex flex-wrap items-end gap-2"
                onSubmit={(event) => {
                    event.preventDefault();
                    verifyKey.current ||= crypto.randomUUID();
                    form.transform((data) => ({
                        ...data,
                        request_key: verifyKey.current,
                        allocations: data.allocations.filter((row) => row.invoice_id && row.amount),
                    }));
                    form.post(`${base}/payments/${payment.id}/verify`, {
                        onSuccess: () => (verifyKey.current = ''),
                    });
                }}
            >
                <span className="text-text-primary w-full text-sm font-medium">
                    Independent bank review and optional allocation
                </span>
                <label className="text-text-secondary text-sm">
                    Invoice
                    <select
                        className="border-border bg-canvas text-text-primary mt-1 rounded-lg border p-2"
                        value={form.data.allocations[0]?.invoice_id ?? ''}
                        onChange={(event) =>
                            form.setData('allocations', [
                                { ...form.data.allocations[0], invoice_id: event.currentTarget.value },
                            ])
                        }
                    >
                        <option value="">Keep as unapplied payment</option>
                        {openInvoices
                            .filter((invoice) => invoice.currency === payment.currency)
                            .map((invoice) => (
                                <option key={invoice.id} value={invoice.id}>
                                    {invoice.invoice_number} · {invoice.currency} {invoice.outstanding_amount}
                                </option>
                            ))}
                    </select>
                </label>
                <label className="text-text-secondary text-sm">
                    Allocation amount
                    <input
                        className="border-border bg-canvas text-text-primary mt-1 w-32 rounded-lg border p-2"
                        value={form.data.allocations[0]?.amount ?? ''}
                        onChange={(event) =>
                            form.setData('allocations', [
                                { ...form.data.allocations[0], amount: event.currentTarget.value },
                            ])
                        }
                    />
                </label>
                <button
                    className="bg-brand rounded-lg px-3 py-2 text-sm font-semibold text-white"
                    disabled={form.processing}
                >
                    Verify transfer
                </button>
            </form>
            <form
                className="flex flex-wrap items-end gap-2"
                onSubmit={(event) => {
                    event.preventDefault();
                    rejectKey.current ||= crypto.randomUUID();
                    reason.transform((data) => ({
                        ...data,
                        request_key: rejectKey.current,
                    }));
                    reason.post(`${base}/payments/${payment.id}/reject`, {
                        onSuccess: () => (rejectKey.current = ''),
                    });
                }}
            >
                <span className="text-text-primary w-full text-sm font-medium">Reject with reason</span>
                <label className="text-text-secondary flex-1 text-sm">
                    Reason
                    <input
                        className="border-border bg-canvas text-text-primary mt-1 w-full rounded-lg border p-2"
                        minLength={8}
                        maxLength={500}
                        required
                        value={reason.data.reason}
                        onChange={(event) => reason.setData('reason', event.currentTarget.value)}
                    />
                </label>
                <button
                    className="border-danger text-danger rounded-lg border px-3 py-2 text-sm font-semibold"
                    disabled={reason.processing}
                >
                    Reject
                </button>
            </form>
        </div>
    );
}

function AllocationForm({
    payment,
    base,
    invoices,
}: {
    payment: Payment;
    base: string;
    invoices: Invoice[];
}) {
    const form = useForm({ request_key: '', allocations: [{ invoice_id: '', amount: '' }] });
    const requestKey = useRef('');
    return (
        <form
            className="flex flex-wrap items-end gap-2"
            onSubmit={(event) => {
                event.preventDefault();
                requestKey.current ||= crypto.randomUUID();
                form.transform((data) => ({
                    ...data,
                    request_key: requestKey.current,
                }));
                form.post(`${base}/payments/${payment.id}/allocate`, {
                    onSuccess: () => (requestKey.current = ''),
                });
            }}
        >
            <label className="text-text-secondary text-sm">
                Allocate credit
                <select
                    className="border-border bg-canvas text-text-primary mt-1 block rounded-lg border p-2"
                    required
                    value={form.data.allocations[0]?.invoice_id ?? ''}
                    onChange={(event) =>
                        form.setData('allocations', [
                            { ...form.data.allocations[0], invoice_id: event.currentTarget.value },
                        ])
                    }
                >
                    <option value="">Select invoice</option>
                    {invoices
                        .filter((invoice) => invoice.currency === payment.currency)
                        .map((invoice) => (
                            <option key={invoice.id} value={invoice.id}>
                                {invoice.invoice_number} · {invoice.currency} {invoice.outstanding_amount}
                            </option>
                        ))}
                </select>
            </label>
            <label className="text-text-secondary text-sm">
                Amount
                <input
                    className="border-border bg-canvas text-text-primary mt-1 block w-32 rounded-lg border p-2"
                    required
                    value={form.data.allocations[0]?.amount ?? ''}
                    onChange={(event) =>
                        form.setData('allocations', [
                            { ...form.data.allocations[0], amount: event.currentTarget.value },
                        ])
                    }
                />
            </label>
            <button
                className="bg-brand rounded-lg px-3 py-2 text-sm font-semibold text-white"
                disabled={form.processing}
            >
                Allocate
            </button>
        </form>
    );
}
