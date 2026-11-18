import { Link, router, useForm } from '@inertiajs/react';
import { MetricCard } from '@/components/ui/metric-card';
import { PageHeader } from '@/components/ui/page-header';
import { WorkspaceLayout } from '@/layouts/workspace-layout';

type InvoiceRow = {
    id: number;
    invoice_number: string;
    student_number: string | null;
    student_name: string;
    issued_at: string;
    due_on: string;
    currency: string;
    total: string;
    outstanding_amount: string;
    status: string;
};
type PageLink = { url: string | null; label: string; active: boolean };

export default function BillingReports({
    tenant,
    filters,
    summary,
    collections,
    aging,
    campuses,
    invoices,
    export_url,
    can_view_invoice,
}: {
    tenant: { slug: string };
    filters: {
        from: string | null;
        to: string | null;
        status: string | null;
        campus_id: number | null;
        search: string | null;
    };
    summary: {
        invoice_count: number;
        currencies: {
            currency: string;
            invoiced_total: string;
            collected_total: string;
            outstanding_total: string;
            overdue_total: string;
        }[];
    };
    collections: { currency: string; method: string; payment_count: number; net_confirmed_amount: string }[];
    aging: {
        currency: string;
        current: string;
        days_1_30: string;
        days_31_60: string;
        days_61_90: string;
        days_over_90: string;
    }[];
    campuses: { id: number; name: string }[];
    invoices: { data: InvoiceRow[]; links: PageLink[] };
    export_url: string;
    can_view_invoice: boolean;
}) {
    const form = useForm({
        from: filters.from ?? '',
        to: filters.to ?? '',
        status: filters.status ?? '',
        campus_id: filters.campus_id?.toString() ?? '',
        search: filters.search ?? '',
    });
    const base = `/t/${tenant.slug}/billing`;

    return (
        <WorkspaceLayout area="tenant" title="Billing reports">
            <PageHeader
                title="Billing reports"
                description="Review issued invoices and outstanding balances for the institution or your authorized campus scope."
                actions={
                    <a href={export_url} className="bg-brand rounded-lg px-4 py-2 font-semibold text-white">
                        Export CSV
                    </a>
                }
            />
            <form
                className="bg-surface border-border grid gap-3 rounded-2xl border p-4 sm:grid-cols-2 lg:grid-cols-6"
                onSubmit={(event) => {
                    event.preventDefault();
                    router.get(
                        `${base}/reports`,
                        {
                            ...form.data,
                            campus_id: form.data.campus_id || undefined,
                            status: form.data.status || undefined,
                        },
                        { preserveState: true, preserveScroll: true },
                    );
                }}
            >
                <label className="text-text-secondary text-sm">
                    Issued from
                    <input
                        className="border-border bg-canvas text-text-primary mt-1 w-full rounded-lg border p-2"
                        type="date"
                        value={form.data.from}
                        onChange={(event) => form.setData('from', event.currentTarget.value)}
                    />
                </label>
                <label className="text-text-secondary text-sm">
                    Issued to
                    <input
                        className="border-border bg-canvas text-text-primary mt-1 w-full rounded-lg border p-2"
                        type="date"
                        value={form.data.to}
                        onChange={(event) => form.setData('to', event.currentTarget.value)}
                    />
                </label>
                <label className="text-text-secondary text-sm">
                    Status
                    <select
                        className="border-border bg-canvas text-text-primary mt-1 w-full rounded-lg border p-2"
                        value={form.data.status}
                        onChange={(event) => form.setData('status', event.currentTarget.value)}
                    >
                        <option value="">All statuses</option>
                        {['issued', 'partially_paid', 'paid', 'overdue', 'cancelled'].map((status) => (
                            <option key={status} value={status}>
                                {status.replace('_', ' ')}
                            </option>
                        ))}
                    </select>
                </label>
                <label className="text-text-secondary text-sm">
                    Campus
                    <select
                        className="border-border bg-canvas text-text-primary mt-1 w-full rounded-lg border p-2"
                        value={form.data.campus_id}
                        onChange={(event) => form.setData('campus_id', event.currentTarget.value)}
                    >
                        <option value="">All authorized campuses</option>
                        {campuses.map((campus) => (
                            <option key={campus.id} value={campus.id}>
                                {campus.name}
                            </option>
                        ))}
                    </select>
                </label>
                <label className="text-text-secondary text-sm lg:col-span-2">
                    Invoice or learner
                    <input
                        className="border-border bg-canvas text-text-primary mt-1 w-full rounded-lg border p-2"
                        value={form.data.search}
                        onChange={(event) => form.setData('search', event.currentTarget.value)}
                        maxLength={100}
                    />
                </label>
                <div className="flex items-end gap-2 lg:col-span-6">
                    <button
                        className="bg-brand rounded-lg px-4 py-2 font-semibold text-white"
                        disabled={form.processing}
                    >
                        Apply filters
                    </button>
                    <Link
                        className="border-border text-text-secondary rounded-lg border px-4 py-2"
                        href={`${base}/reports`}
                    >
                        Clear
                    </Link>
                </div>
            </form>
            <div className="mt-5 grid gap-3 sm:grid-cols-2 xl:grid-cols-5">
                <MetricCard
                    label="Invoices"
                    value={summary.invoice_count.toLocaleString()}
                    detail="Matching records"
                />
                {summary.currencies.flatMap((row) => [
                    <MetricCard
                        key={`${row.currency}-invoiced`}
                        label={`Invoiced (${row.currency})`}
                        value={row.invoiced_total}
                        detail="Excludes cancelled invoices"
                    />,
                    <MetricCard
                        key={`${row.currency}-collected`}
                        label={`Collected (${row.currency})`}
                        value={row.collected_total}
                        detail="Current confirmed net collections"
                    />,
                    <MetricCard
                        key={`${row.currency}-outstanding`}
                        label={`Outstanding (${row.currency})`}
                        value={row.outstanding_total}
                        detail="Open invoice balance"
                    />,
                    <MetricCard
                        key={`${row.currency}-overdue`}
                        label={`Overdue (${row.currency})`}
                        value={row.overdue_total}
                        detail="Past-due open balance"
                    />,
                ])}
            </div>
            <section className="bg-surface border-border mt-5 overflow-x-auto rounded-2xl border p-4">
                <h2 className="text-text-primary font-semibold">Outstanding balance aging</h2>
                <p className="text-text-secondary mt-1 text-sm">
                    Open invoice balances grouped by days past due, using the current date and selected
                    institution/campus filters.
                </p>
                {aging.length === 0 ? (
                    <p className="text-text-secondary mt-3 text-sm">
                        No outstanding balances match these filters.
                    </p>
                ) : (
                    <table className="mt-3 w-full min-w-[700px] text-left text-sm">
                        <thead>
                            <tr>
                                {[
                                    'Currency',
                                    'Not yet due',
                                    '1–30 days',
                                    '31–60 days',
                                    '61–90 days',
                                    'Over 90 days',
                                ].map((heading) => (
                                    <th className="p-2" key={heading}>
                                        {heading}
                                    </th>
                                ))}
                            </tr>
                        </thead>
                        <tbody>
                            {aging.map((row) => (
                                <tr className="border-border border-t" key={row.currency}>
                                    <td className="p-2">{row.currency}</td>
                                    <td className="p-2">{row.current}</td>
                                    <td className="p-2">{row.days_1_30}</td>
                                    <td className="p-2">{row.days_31_60}</td>
                                    <td className="p-2">{row.days_61_90}</td>
                                    <td className="p-2">{row.days_over_90}</td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                )}
            </section>
            <section className="bg-surface border-border mt-5 overflow-x-auto rounded-2xl border p-4">
                <h2 className="text-text-primary font-semibold">Confirmed collections</h2>
                <p className="text-text-secondary mt-1 text-sm">
                    Confirmed payments less executed refunds. Pending or reversed payments are excluded;
                    currencies remain separate.
                </p>
                {collections.length === 0 ? (
                    <p className="text-text-secondary mt-3 text-sm">
                        No confirmed collections match these filters.
                    </p>
                ) : (
                    <table className="mt-3 w-full min-w-[520px] text-left text-sm">
                        <thead>
                            <tr>
                                {['Currency', 'Method', 'Payments', 'Net collected'].map((heading) => (
                                    <th className="p-2" key={heading}>
                                        {heading}
                                    </th>
                                ))}
                            </tr>
                        </thead>
                        <tbody>
                            {collections.map((row) => (
                                <tr className="border-border border-t" key={`${row.currency}-${row.method}`}>
                                    <td className="p-2">{row.currency}</td>
                                    <td className="p-2 capitalize">{row.method.replaceAll('_', ' ')}</td>
                                    <td className="p-2">{row.payment_count.toLocaleString()}</td>
                                    <td className="p-2">
                                        {row.currency} {row.net_confirmed_amount}
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                )}
            </section>
            <section className="bg-surface border-border mt-5 overflow-hidden rounded-2xl border">
                {invoices.data.length === 0 ? (
                    <div className="text-text-secondary p-8 text-center">
                        No invoices match these filters.
                    </div>
                ) : (
                    <div className="overflow-x-auto">
                        <table className="w-full min-w-[850px] text-left text-sm">
                            <thead className="text-text-muted border-border border-b">
                                <tr>
                                    {[
                                        'Invoice',
                                        'Learner',
                                        'Issued',
                                        'Due',
                                        'Total',
                                        'Outstanding',
                                        'Status',
                                    ].map((heading) => (
                                        <th className="p-3" key={heading}>
                                            {heading}
                                        </th>
                                    ))}
                                </tr>
                            </thead>
                            <tbody className="divide-border divide-y">
                                {invoices.data.map((invoice) => (
                                    <tr key={invoice.id}>
                                        <td className="p-3">
                                            {can_view_invoice ? (
                                                <Link
                                                    className="text-brand underline"
                                                    href={`${base}/invoices/${invoice.id}`}
                                                >
                                                    {invoice.invoice_number}
                                                </Link>
                                            ) : (
                                                invoice.invoice_number
                                            )}
                                        </td>
                                        <td className="text-text-primary p-3">
                                            {invoice.student_number} · {invoice.student_name}
                                        </td>
                                        <td className="p-3">{invoice.issued_at}</td>
                                        <td className="p-3">{invoice.due_on}</td>
                                        <td className="p-3">
                                            {invoice.currency} {invoice.total}
                                        </td>
                                        <td className="p-3">
                                            {invoice.currency} {invoice.outstanding_amount}
                                        </td>
                                        <td className="p-3 capitalize">
                                            {invoice.status === 'issued' &&
                                            invoice.outstanding_amount !== '0.00' &&
                                            invoice.due_on < new Date().toISOString().slice(0, 10)
                                                ? 'overdue'
                                                : invoice.status}
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                )}
                <nav aria-label="Billing report pages" className="flex flex-wrap gap-2 p-4">
                    {invoices.links.map((link, index) =>
                        link.url ? (
                            <Link
                                key={index}
                                href={link.url}
                                preserveScroll
                                aria-current={link.active ? 'page' : undefined}
                                className={`rounded border px-3 py-1 ${link.active ? 'bg-brand text-white' : 'border-border text-text-secondary'}`}
                            >
                                {link.label.replace('&laquo;', '«').replace('&raquo;', '»')}
                            </Link>
                        ) : (
                            <span key={index} className="text-text-muted rounded border px-3 py-1">
                                {link.label.replace('&laquo;', '«').replace('&raquo;', '»')}
                            </span>
                        ),
                    )}
                </nav>
            </section>
        </WorkspaceLayout>
    );
}
