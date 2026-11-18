import { Link } from '@inertiajs/react';
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

export default function BillingReconciliation({
    tenant,
    filters,
    campuses,
    payment_totals,
    refund_totals,
    payments,
    refunds,
    export_url,
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
}) {
    const base = `/t/${tenant.slug}/billing`;
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
            </div>

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
