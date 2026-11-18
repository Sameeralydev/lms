import { Link, useForm } from '@inertiajs/react';
import { PageHeader } from '@/components/ui/page-header';
import { WorkspaceLayout } from '@/layouts/workspace-layout';

type Invoice = {
    id: number;
    invoice_number: string;
    institution: string;
    campus: string | null;
    student_number: string;
    student_name: string;
    period_start: string;
    period_end: string;
    due_on: string;
    currency: string;
    subtotal: string;
    discount_total: string;
    total: string;
    paid_amount: string;
    outstanding_amount: string;
    status: string;
    issued_at: string;
    items: {
        fee_head_code: string;
        fee_head_name: string;
        quantity: number;
        unit_amount: string;
        line_total: string;
    }[];
};

export default function BillingInvoicePage({
    tenant,
    invoice,
    can_cancel,
    print_url,
    back_url,
}: {
    tenant: { slug: string };
    invoice: Invoice;
    can_cancel: boolean;
    print_url: string;
    back_url: string;
}) {
    const form = useForm({ reason: '' });
    const base = `/t/${tenant.slug}/billing/invoices/${invoice.id}`;
    return (
        <WorkspaceLayout area="tenant" title={invoice.invoice_number}>
            <PageHeader
                title={`Invoice ${invoice.invoice_number}`}
                description={`${invoice.institution}${invoice.campus ? ` · ${invoice.campus}` : ''} · Issued ${new Date(invoice.issued_at).toLocaleString()}`}
            />
            <div className="bg-surface border-border rounded-2xl border p-5">
                <dl className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                    {[
                        ['Learner', `${invoice.student_number} · ${invoice.student_name}`],
                        ['Billing period', `${invoice.period_start} – ${invoice.period_end}`],
                        ['Due date', invoice.due_on],
                        ['Status', invoice.status],
                        ['Total', `${invoice.currency} ${invoice.total}`],
                        ['Outstanding', `${invoice.currency} ${invoice.outstanding_amount}`],
                    ].map(([label, value]) => (
                        <div key={label}>
                            <dt className="text-text-muted text-sm">{label}</dt>
                            <dd className="text-text-primary mt-1 font-semibold capitalize">{value}</dd>
                        </div>
                    ))}
                </dl>
                <div className="mt-6 overflow-x-auto">
                    <table className="w-full min-w-[500px] text-left text-sm">
                        <thead className="text-text-muted border-border border-b">
                            <tr>
                                <th className="p-3">Charge</th>
                                <th className="p-3">Qty</th>
                                <th className="p-3">Unit</th>
                                <th className="p-3">Line total</th>
                            </tr>
                        </thead>
                        <tbody className="divide-border divide-y">
                            {invoice.items.map((item, index) => (
                                <tr key={`${item.fee_head_code}-${index}`}>
                                    <td className="text-text-primary p-3">
                                        {item.fee_head_code} · {item.fee_head_name}
                                    </td>
                                    <td className="p-3">{item.quantity}</td>
                                    <td className="p-3">
                                        {invoice.currency} {item.unit_amount}
                                    </td>
                                    <td className="text-text-primary p-3 font-medium">
                                        {invoice.currency} {item.line_total}
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                        <tfoot className="border-border border-t">
                            <tr>
                                <th colSpan={3} className="p-3 text-right">
                                    Subtotal
                                </th>
                                <td className="p-3">
                                    {invoice.currency} {invoice.subtotal}
                                </td>
                            </tr>
                            <tr>
                                <th colSpan={3} className="p-3 text-right">
                                    Discounts
                                </th>
                                <td className="p-3">
                                    {invoice.currency} {invoice.discount_total}
                                </td>
                            </tr>
                            <tr>
                                <th colSpan={3} className="p-3 text-right">
                                    Total
                                </th>
                                <td className="text-text-primary p-3 font-bold">
                                    {invoice.currency} {invoice.total}
                                </td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
                <div className="mt-5 flex flex-wrap gap-3">
                    <a
                        className="bg-brand rounded-lg px-4 py-2 font-semibold text-white"
                        href={print_url}
                        target="_blank"
                        rel="noreferrer"
                    >
                        Print / download PDF
                    </a>
                    <Link
                        className="border-border text-text-secondary rounded-lg border px-4 py-2"
                        href={back_url}
                    >
                        Back to billing
                    </Link>
                </div>
            </div>
            {can_cancel && (
                <form
                    className="bg-surface border-border mt-5 rounded-2xl border p-5"
                    onSubmit={(event) => {
                        event.preventDefault();
                        form.post(`${base}/cancel`);
                    }}
                >
                    <h2 className="text-text-primary font-semibold">Cancel unpaid invoice</h2>
                    <p className="text-text-secondary mt-1 text-sm">
                        Cancellation preserves the original invoice and line snapshots. Paid invoices require
                        a compensating Phase 18/approved adjustment workflow.
                    </p>
                    <label className="text-text-secondary mt-3 block text-sm">
                        Reason
                        <textarea
                            className="border-border bg-canvas text-text-primary mt-1 w-full rounded-lg border p-2"
                            value={form.data.reason}
                            onChange={(event) => form.setData('reason', event.currentTarget.value)}
                            minLength={8}
                            maxLength={500}
                            required
                        />
                    </label>
                    <button
                        className="border-danger text-danger mt-3 rounded-lg border px-4 py-2 font-medium"
                        disabled={form.processing}
                    >
                        Cancel invoice
                    </button>
                </form>
            )}
        </WorkspaceLayout>
    );
}
