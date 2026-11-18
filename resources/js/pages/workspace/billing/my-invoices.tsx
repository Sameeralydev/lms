import { Link } from '@inertiajs/react';
import { EmptyState } from '@/components/ui/empty-state';
import { PageHeader } from '@/components/ui/page-header';
import { WorkspaceLayout } from '@/layouts/workspace-layout';

type Invoice = {
    id: number;
    student_id: number;
    invoice_number: string;
    student_number: string | null;
    student_name: string;
    period_start: string | null;
    period_end: string | null;
    due_on: string | null;
    currency: string;
    total: string;
    outstanding_amount: string;
    status: string;
};
type PageLink = { url: string | null; label: string; active: boolean };
type Payment = {
    id: number;
    payment_number: string;
    receipt_number: string | null;
    status: string;
    method: string;
    currency: string;
    amount: string;
    recorded_at: string;
    allocations: { invoice_number: string; amount: string }[];
};

export default function MyBilling({
    tenant,
    invoices,
    payments,
}: {
    tenant: { slug: string };
    invoices: { data: Invoice[]; links: PageLink[] };
    payments: Payment[];
}) {
    return (
        <WorkspaceLayout area="tenant" title="My fees">
            <PageHeader
                title="My fees & invoices"
                description="Billing records you are authorized to view as the signed-in learner or a verified guardian."
            />
            {invoices.data.length === 0 ? (
                <EmptyState
                    title="No billing records"
                    description="There are no invoices for your account or for learners whose verified guardian profile access is active."
                />
            ) : (
                <div className="bg-surface border-border overflow-hidden rounded-2xl border">
                    <div className="overflow-x-auto">
                        <table className="w-full min-w-[680px] text-left text-sm">
                            <thead className="text-text-muted border-border border-b">
                                <tr>
                                    <th className="p-3">Invoice</th>
                                    <th className="p-3">Learner</th>
                                    <th className="p-3">Period</th>
                                    <th className="p-3">Due</th>
                                    <th className="p-3">Total</th>
                                    <th className="p-3">Outstanding</th>
                                    <th className="p-3">Status</th>
                                    <th className="p-3">Statement</th>
                                </tr>
                            </thead>
                            <tbody className="divide-border divide-y">
                                {invoices.data.map((invoice) => (
                                    <tr key={invoice.id}>
                                        <td className="p-3">
                                            <Link
                                                className="text-brand underline"
                                                href={`/t/${tenant.slug}/billing/my/${invoice.id}`}
                                            >
                                                {invoice.invoice_number}
                                            </Link>
                                        </td>
                                        <td className="text-text-primary p-3">
                                            {invoice.student_number} · {invoice.student_name}
                                        </td>
                                        <td className="p-3">
                                            {invoice.period_start} – {invoice.period_end}
                                        </td>
                                        <td className="p-3">{invoice.due_on}</td>
                                        <td className="p-3">
                                            {invoice.currency} {invoice.total}
                                        </td>
                                        <td className="p-3">
                                            {invoice.currency} {invoice.outstanding_amount}
                                        </td>
                                        <td className="p-3 capitalize">{invoice.status}</td>
                                        <td className="p-3">
                                            <Link
                                                className="text-brand underline"
                                                href={`/t/${tenant.slug}/billing/my-ledger/${invoice.student_id}`}
                                            >
                                                Statement
                                            </Link>
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                    <nav aria-label="Invoice pages" className="flex flex-wrap gap-2 p-4">
                        {invoices.links.map((link, index) =>
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
            <section className="bg-surface border-border mt-6 rounded-2xl border p-5">
                <h2 className="text-text-primary text-lg font-semibold">Payment history</h2>
                {payments.length === 0 ? (
                    <p className="text-text-secondary mt-2 text-sm">
                        No payment records are available for your authorized learner accounts.
                    </p>
                ) : (
                    <div className="mt-3 overflow-x-auto">
                        <table className="w-full min-w-[640px] text-left text-sm">
                            <thead className="text-text-muted border-border border-b">
                                <tr>
                                    <th className="p-3">Payment</th>
                                    <th className="p-3">Date</th>
                                    <th className="p-3">Method / status</th>
                                    <th className="p-3">Amount</th>
                                    <th className="p-3">Allocation</th>
                                    <th className="p-3">Receipt</th>
                                </tr>
                            </thead>
                            <tbody className="divide-border divide-y">
                                {payments.map((payment) => (
                                    <tr key={payment.id}>
                                        <td className="p-3">{payment.payment_number}</td>
                                        <td className="p-3">{payment.recorded_at}</td>
                                        <td className="p-3 capitalize">
                                            {payment.method.replace('_', ' ')} ·{' '}
                                            {payment.status.replace('_', ' ')}
                                        </td>
                                        <td className="p-3">
                                            {payment.currency} {payment.amount}
                                        </td>
                                        <td className="p-3">
                                            {payment.allocations
                                                .map(
                                                    (row) =>
                                                        `${row.invoice_number}: ${payment.currency} ${row.amount}`,
                                                )
                                                .join(', ') || 'Unapplied'}
                                        </td>
                                        <td className="p-3">
                                            {payment.receipt_number && payment.status === 'confirmed' ? (
                                                <a
                                                    className="text-brand underline"
                                                    href={`/t/${tenant.slug}/billing/my/payments/${payment.id}/receipt`}
                                                    target="_blank"
                                                    rel="noreferrer"
                                                >
                                                    Print receipt
                                                </a>
                                            ) : (
                                                '—'
                                            )}
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                )}
            </section>
        </WorkspaceLayout>
    );
}
