import { Link, router, useForm } from '@inertiajs/react';
import { EmptyState } from '@/components/ui/empty-state';
import { PageHeader } from '@/components/ui/page-header';
import { WorkspaceLayout } from '@/layouts/workspace-layout';

type Page<T> = { data: T[]; links: { url: string | null; label: string; active: boolean }[] };
type Payment = {
    payment_number: string;
    student_number: string;
    student_name: string;
    method: string;
    status: string;
    currency: string;
    amount: string;
    unapplied_amount: string;
    recorded_at: string;
    allocations: { invoice_number: string; amount: string }[];
};
type Refund = {
    refund_number: string;
    payment_number: string;
    student_number: string;
    currency: string;
    amount: string;
    payout_reference: string | null;
    executed_at: string | null;
};
type StatementLine = {
    id: number;
    row_number: number;
    date: string;
    reference: string;
    direction: 'credit' | 'debit';
    amount: string;
    currency: string;
    match_status: string;
    suggested_payment_id: number | null;
    suggested_refund_id: number | null;
};
type StatementBatch = {
    id: number;
    number: string;
    account: string;
    currency: string;
    period_start: string;
    period_end: string;
    status: string;
    line_count: number;
    lines: StatementLine[];
};

const field = 'border-border bg-canvas text-text-primary mt-1 w-full rounded-lg border p-2';
const button = 'bg-brand rounded-lg px-3 py-2 font-medium text-white disabled:opacity-50';

function StatementLineReview({ line, base, open }: { line: StatementLine; base: string; open: boolean }) {
    const match = useForm({
        payment_id: line.suggested_payment_id?.toString() ?? '',
        refund_id: line.suggested_refund_id?.toString() ?? '',
    });
    const exclusion = useForm({ reason: '' });
    if (!open || ['matched', 'excluded'].includes(line.match_status)) return null;
    return (
        <div className="flex min-w-64 flex-wrap items-end gap-2">
            <form
                className="flex flex-wrap items-end gap-2"
                onSubmit={(event) => {
                    event.preventDefault();
                    match.post(`${base}/reconciliation/lines/${line.id}/match`);
                }}
            >
                {line.direction === 'credit' ? (
                    <label className="text-xs">
                        Payment ID
                        <input
                            aria-label={`Payment record ID for statement row ${line.row_number}`}
                            className={field}
                            required
                            type="number"
                            min="1"
                            value={match.data.payment_id}
                            onChange={(e) => match.setData({ payment_id: e.target.value, refund_id: '' })}
                        />
                    </label>
                ) : (
                    <label className="text-xs">
                        Executed refund ID
                        <input
                            aria-label={`Refund record ID for statement row ${line.row_number}`}
                            className={field}
                            required
                            type="number"
                            min="1"
                            value={match.data.refund_id}
                            onChange={(e) => match.setData({ refund_id: e.target.value, payment_id: '' })}
                        />
                    </label>
                )}
                <button className={button} disabled={match.processing}>
                    Confirm exact match
                </button>
            </form>
            <form
                className="flex flex-wrap items-end gap-2"
                onSubmit={(event) => {
                    event.preventDefault();
                    exclusion.post(`${base}/reconciliation/lines/${line.id}/exclude`);
                }}
            >
                <label className="text-xs">
                    Exclusion reason
                    <input
                        aria-label={`Exclusion reason for statement row ${line.row_number}`}
                        className={field}
                        required
                        minLength={5}
                        value={exclusion.data.reason}
                        onChange={(e) => exclusion.setData('reason', e.target.value)}
                    />
                </label>
                <button
                    className="border-border rounded-lg border px-3 py-2 text-sm"
                    disabled={exclusion.processing}
                >
                    Exclude
                </button>
            </form>
            {(Object.values(match.errors).length > 0 || Object.values(exclusion.errors).length > 0) && (
                <p role="alert" className="w-full text-sm text-red-700">
                    {Object.values(match.errors).concat(Object.values(exclusion.errors)).join(' ')}
                </p>
            )}
        </div>
    );
}

export default function BillingReconciliation({
    tenant,
    filters,
    campuses,
    payment_totals,
    refund_totals,
    payments,
    refunds,
    export_url,
    bank_accounts,
    statement_batches,
    reconciliation_permissions,
}: {
    tenant: { slug: string };
    filters: { from: string; to: string; campus_id: number | null };
    campuses: { id: number; name: string }[];
    payment_totals: {
        method: string;
        status: string;
        currency: string;
        record_count: number;
        amount: string;
        unapplied_amount: string;
    }[];
    refund_totals: { currency: string; refund_count: number; amount: string }[];
    payments: Page<Payment>;
    refunds: Page<Refund>;
    export_url: string;
    bank_accounts: {
        id: number;
        account_code: string;
        display_name: string;
        currency: string;
        campus_id: number | null;
    }[];
    statement_batches: StatementBatch[];
    reconciliation_permissions: { manage: boolean; review: boolean };
}) {
    const base = `/t/${tenant.slug}/billing`;
    const accountForm = useForm({ account_code: '', display_name: '', currency: 'USD', campus_id: '' });
    const statementForm = useForm<{ bank_account_id: string; statement: File | null }>({
        bank_account_id: '',
        statement: null,
    });
    return (
        <WorkspaceLayout area="tenant" title="Collection reconciliation">
            <PageHeader
                title="Collection reconciliation"
                description="Compare recorded collections and executed manual refunds for an authorized institution period."
            />
            <div className="my-4 flex flex-wrap gap-4">
                <Link href={`${base}/payments`} className="text-brand underline">
                    Payments
                </Link>
                <Link href={base} className="text-brand underline">
                    Billing
                </Link>
                <a href={export_url} className="text-brand underline">
                    Export CSV
                </a>
                {reconciliation_permissions.manage && (
                    <a href={`${base}/reconciliation/template.csv`} className="text-brand underline">
                        Download statement CSV template
                    </a>
                )}
            </div>

            {reconciliation_permissions.manage && (
                <section className="bg-surface border-border mb-6 rounded-xl border p-4">
                    <h2 className="text-text-primary text-lg font-semibold">Bank statement import</h2>
                    <p className="text-text-secondary mt-1 text-sm">
                        Use the provided CSV template. Imports create a review batch and exact-match
                        suggestions only. They never confirm a pending payment or create collections.
                    </p>
                    <form
                        className="mt-4 grid gap-3 sm:grid-cols-2 lg:grid-cols-4"
                        onSubmit={(event) => {
                            event.preventDefault();
                            statementForm.post(`${base}/reconciliation/statements`, { forceFormData: true });
                        }}
                    >
                        <label className="text-text-secondary text-sm">
                            Account
                            <select
                                className={field}
                                required
                                value={statementForm.data.bank_account_id}
                                onChange={(e) => statementForm.setData('bank_account_id', e.target.value)}
                            >
                                <option value="">Select account</option>
                                {bank_accounts.map((account) => (
                                    <option key={account.id} value={account.id}>
                                        {account.display_name} · {account.currency}
                                    </option>
                                ))}
                            </select>
                        </label>
                        <label className="text-text-secondary text-sm">
                            CSV statement
                            <input
                                className={field}
                                required
                                type="file"
                                accept=".csv,text/csv"
                                onChange={(e) =>
                                    statementForm.setData('statement', e.target.files?.[0] ?? null)
                                }
                            />
                        </label>
                        <button className={`${button} self-end`} disabled={statementForm.processing}>
                            Import for review
                        </button>
                    </form>
                    <form
                        className="mt-5 grid gap-3 sm:grid-cols-2 lg:grid-cols-4"
                        onSubmit={(event) => {
                            event.preventDefault();
                            accountForm.post(`${base}/reconciliation/accounts`);
                        }}
                    >
                        <label className="text-text-secondary text-sm">
                            Account code
                            <input
                                className={field}
                                required
                                value={accountForm.data.account_code}
                                onChange={(e) => accountForm.setData('account_code', e.target.value)}
                            />
                        </label>
                        <label className="text-text-secondary text-sm">
                            Display name
                            <input
                                className={field}
                                required
                                value={accountForm.data.display_name}
                                onChange={(e) => accountForm.setData('display_name', e.target.value)}
                            />
                        </label>
                        <label className="text-text-secondary text-sm">
                            Currency
                            <input
                                className={field}
                                required
                                maxLength={3}
                                value={accountForm.data.currency}
                                onChange={(e) =>
                                    accountForm.setData('currency', e.target.value.toUpperCase())
                                }
                            />
                        </label>
                        {campuses.length > 0 && (
                            <label className="text-text-secondary text-sm">
                                Campus scope
                                <select
                                    className={field}
                                    value={accountForm.data.campus_id}
                                    onChange={(e) => accountForm.setData('campus_id', e.target.value)}
                                >
                                    <option value="">Institution-wide</option>
                                    {campuses.map((campus) => (
                                        <option key={campus.id} value={campus.id}>
                                            {campus.name}
                                        </option>
                                    ))}
                                </select>
                            </label>
                        )}
                        <button className={`${button} self-end`} disabled={accountForm.processing}>
                            Add bank account
                        </button>
                    </form>
                    {(Object.values(accountForm.errors).length > 0 ||
                        Object.values(statementForm.errors).length > 0) && (
                        <p role="alert" className="mt-3 text-sm text-red-700">
                            {Object.values(accountForm.errors)
                                .concat(Object.values(statementForm.errors))
                                .join(' ')}
                        </p>
                    )}
                </section>
            )}

            {reconciliation_permissions.review && (
                <section className="bg-surface border-border mb-6 rounded-xl border p-4">
                    <h2 className="text-text-primary text-lg font-semibold">Imported statement batches</h2>
                    {statement_batches.length === 0 ? (
                        <p className="text-text-secondary mt-2 text-sm">
                            No CSV statement batches have been imported.
                        </p>
                    ) : (
                        <ul className="mt-3 space-y-4">
                            {statement_batches.map((batch) => (
                                <li key={batch.id} className="border-border rounded-lg border p-3">
                                    <div className="flex flex-wrap items-center justify-between gap-2">
                                        <div>
                                            <strong>{batch.number}</strong> · {batch.account} ·{' '}
                                            {batch.currency} · {batch.period_start} to {batch.period_end} ·{' '}
                                            {batch.status} · {batch.line_count} rows
                                        </div>
                                        {batch.status === 'review' && (
                                            <button
                                                className={button}
                                                onClick={() =>
                                                    window.confirm('Close this reviewed batch?') &&
                                                    router.post(
                                                        `${base}/reconciliation/batches/${batch.id}/close`,
                                                    )
                                                }
                                            >
                                                Close batch
                                            </button>
                                        )}
                                    </div>
                                    <div className="mt-3 overflow-x-auto">
                                        <table className="w-full min-w-[760px] text-left text-sm">
                                            <thead>
                                                <tr>
                                                    <th className="p-2">Row</th>
                                                    <th className="p-2">Date</th>
                                                    <th className="p-2">Reference</th>
                                                    <th className="p-2">Direction</th>
                                                    <th className="p-2">Amount</th>
                                                    <th className="p-2">Status</th>
                                                    <th className="p-2">Review action</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                {batch.lines.map((line) => (
                                                    <tr
                                                        className="border-border border-t align-top"
                                                        key={line.id}
                                                    >
                                                        <td className="p-2">{line.row_number}</td>
                                                        <td className="p-2">{line.date}</td>
                                                        <td className="p-2">{line.reference}</td>
                                                        <td className="p-2">{line.direction}</td>
                                                        <td className="p-2">
                                                            {line.currency} {line.amount}
                                                        </td>
                                                        <td className="p-2">{line.match_status}</td>
                                                        <td className="p-2">
                                                            <StatementLineReview
                                                                line={line}
                                                                base={base}
                                                                open={batch.status === 'review'}
                                                            />
                                                        </td>
                                                    </tr>
                                                ))}
                                            </tbody>
                                        </table>
                                    </div>
                                </li>
                            ))}
                        </ul>
                    )}
                </section>
            )}

            <form
                method="get"
                className="bg-surface border-border mb-6 grid gap-3 rounded-xl border p-4 sm:grid-cols-2 xl:grid-cols-4"
            >
                <label className="text-text-secondary text-sm">
                    From
                    <input
                        type="date"
                        name="from"
                        defaultValue={filters.from}
                        required
                        className="border-border bg-canvas text-text-primary mt-1 w-full rounded-lg border p-2"
                    />
                </label>
                <label className="text-text-secondary text-sm">
                    To
                    <input
                        type="date"
                        name="to"
                        defaultValue={filters.to}
                        required
                        className="border-border bg-canvas text-text-primary mt-1 w-full rounded-lg border p-2"
                    />
                </label>
                {campuses.length > 1 && (
                    <label className="text-text-secondary text-sm">
                        Campus
                        <select
                            name="campus_id"
                            defaultValue={filters.campus_id ?? ''}
                            className="border-border bg-canvas text-text-primary mt-1 w-full rounded-lg border p-2"
                        >
                            <option value="">All authorized campuses</option>
                            {campuses.map((campus) => (
                                <option key={campus.id} value={campus.id}>
                                    {campus.name}
                                </option>
                            ))}
                        </select>
                    </label>
                )}
                <div className="flex items-end">
                    <button
                        type="submit"
                        className="bg-brand text-brand-contrast min-h-10 rounded-lg px-4 font-medium"
                    >
                        Apply period
                    </button>
                </div>
            </form>

            <section className="bg-surface border-border rounded-xl border p-4">
                <h2 className="text-text-primary text-lg font-semibold">Payments recorded in period</h2>
                <p className="text-text-secondary mt-1 text-sm">
                    Totals remain separated by currency and payment state. Pending transfers are shown but do
                    not count as settled funds.
                </p>
                {payment_totals.length === 0 ? (
                    <EmptyState
                        title="No payment activity"
                        description="No recorded payments match this period and campus scope."
                    />
                ) : (
                    <div className="mt-4 overflow-x-auto">
                        <table className="w-full min-w-[720px] text-left text-sm">
                            <thead className="text-text-secondary border-border border-b">
                                <tr>
                                    <th className="p-2">Currency</th>
                                    <th className="p-2">Method</th>
                                    <th className="p-2">State</th>
                                    <th className="p-2">Records</th>
                                    <th className="p-2">Amount</th>
                                    <th className="p-2">Unapplied now</th>
                                </tr>
                            </thead>
                            <tbody>
                                {payment_totals.map((row) => (
                                    <tr
                                        key={`${row.currency}-${row.method}-${row.status}`}
                                        className="border-border border-b last:border-0"
                                    >
                                        <td className="p-2 font-medium">{row.currency}</td>
                                        <td className="p-2">{row.method.replaceAll('_', ' ')}</td>
                                        <td className="p-2">{row.status.replaceAll('_', ' ')}</td>
                                        <td className="p-2">{row.record_count}</td>
                                        <td className="p-2">
                                            {row.currency} {row.amount}
                                        </td>
                                        <td className="p-2">
                                            {row.currency} {row.unapplied_amount}
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                )}
            </section>

            <section className="bg-surface border-border mt-6 rounded-xl border p-4">
                <h2 className="text-text-primary text-lg font-semibold">Refunds executed in period</h2>
                {refund_totals.length === 0 ? (
                    <p className="text-text-secondary mt-3 text-sm">No executed refunds match this period.</p>
                ) : (
                    <ul className="mt-3 flex flex-wrap gap-3">
                        {refund_totals.map((row) => (
                            <li key={row.currency} className="bg-canvas rounded-lg px-3 py-2 text-sm">
                                {row.currency}: {row.amount} ({row.refund_count} refunds)
                            </li>
                        ))}
                    </ul>
                )}
                <p className="text-text-secondary mt-3 text-xs">
                    Refunds are classified by execution date; they may relate to payments recorded in an
                    earlier period.
                </p>
            </section>

            <section className="bg-surface border-border mt-6 rounded-xl border p-4">
                <h2 className="text-text-primary text-lg font-semibold">Payment records</h2>
                {payments.data.length === 0 ? (
                    <EmptyState
                        title="No payment records"
                        description="Payment details for this period will appear here."
                    />
                ) : (
                    <div className="mt-3 space-y-3">
                        {payments.data.map((payment) => (
                            <article
                                key={payment.payment_number}
                                className="border-border rounded-lg border p-3"
                            >
                                <div className="flex flex-wrap justify-between gap-2">
                                    <div>
                                        <h3 className="text-text-primary font-medium">
                                            {payment.payment_number} · {payment.student_number}
                                        </h3>
                                        <p className="text-text-secondary text-sm">
                                            {payment.student_name} · {payment.method.replaceAll('_', ' ')} ·{' '}
                                            {payment.status.replaceAll('_', ' ')}
                                        </p>
                                    </div>
                                    <p className="text-text-primary font-semibold">
                                        {payment.currency} {payment.amount}
                                    </p>
                                </div>
                                <p className="text-text-secondary mt-2 text-xs">
                                    Recorded {new Date(payment.recorded_at).toLocaleString()}
                                </p>
                                {payment.allocations.length > 0 && (
                                    <p className="text-text-secondary mt-2 text-sm">
                                        Allocations:{' '}
                                        {payment.allocations
                                            .map(
                                                (item) =>
                                                    `${item.invoice_number}: ${payment.currency} ${item.amount}`,
                                            )
                                            .join(' · ')}
                                    </p>
                                )}
                            </article>
                        ))}
                    </div>
                )}
                <Pagination links={payments.links} />
            </section>

            <section className="bg-surface border-border mt-6 rounded-xl border p-4">
                <h2 className="text-text-primary text-lg font-semibold">Executed refund records</h2>
                {refunds.data.length === 0 ? (
                    <p className="text-text-secondary mt-3 text-sm">No refund details in this period.</p>
                ) : (
                    <div className="mt-3 space-y-3">
                        {refunds.data.map((refund) => (
                            <article
                                key={refund.refund_number}
                                className="border-border rounded-lg border p-3"
                            >
                                <div className="flex flex-wrap justify-between gap-2">
                                    <p className="text-text-primary font-medium">
                                        {refund.refund_number} · {refund.payment_number} ·{' '}
                                        {refund.student_number}
                                    </p>
                                    <p className="font-semibold">
                                        {refund.currency} {refund.amount}
                                    </p>
                                </div>
                                <p className="text-text-secondary mt-2 text-sm">
                                    Executed{' '}
                                    {refund.executed_at
                                        ? new Date(refund.executed_at).toLocaleString()
                                        : 'date unavailable'}{' '}
                                    · payout reference {refund.payout_reference ?? 'unrecorded'}
                                </p>
                            </article>
                        ))}
                    </div>
                )}
                <Pagination links={refunds.links} />
            </section>
        </WorkspaceLayout>
    );
}

function Pagination({ links }: { links: Page<unknown>['links'] }) {
    return (
        <nav aria-label="Report pages" className="mt-4 flex flex-wrap gap-2">
            {links.map((link, index) =>
                link.url ? (
                    <Link
                        key={`${link.label}-${index}`}
                        href={link.url}
                        preserveScroll
                        aria-current={link.active ? 'page' : undefined}
                        className={`border-border rounded border px-3 py-1 text-sm ${link.active ? 'bg-brand text-brand-contrast' : 'text-text-secondary'}`}
                    >
                        {link.label.replace('&laquo;', '‹').replace('&raquo;', '›')}
                    </Link>
                ) : null,
            )}
        </nav>
    );
}
