import { Link, router, useForm } from '@inertiajs/react';
import { useMemo, useState } from 'react';
import { EmptyState } from '@/components/ui/empty-state';
import { MetricCard } from '@/components/ui/metric-card';
import { PageHeader } from '@/components/ui/page-header';
import { WorkspaceLayout } from '@/layouts/workspace-layout';

type FeeHead = { id: number; code: string; name: string; category: string; status: string };
type Student = {
    id: number;
    student_number: string;
    legal_first_name: string;
    legal_last_name: string;
    home_campus_id: number | null;
};
type Structure = {
    id: number;
    campus_id: number | null;
    code: string;
    name: string;
    currency: string;
    frequency: string;
    effective_from: string;
    effective_until: string | null;
    status: string;
    items_count: number;
    items: { fee_head_id: number; unit_amount: string; quantity: string }[];
};
type Invoice = {
    id: number;
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

export default function BillingIndex({
    tenant,
    institution,
    summary,
    fee_heads,
    structures,
    students,
    campuses,
    invoices,
    permissions,
}: {
    tenant: { slug: string };
    institution: { name: string };
    summary: { issued_count: number; outstanding_totals: { currency: string; amount: string }[] };
    fee_heads: FeeHead[];
    structures: Structure[];
    students: Student[];
    campuses: { id: number; name: string }[];
    invoices: { data: Invoice[]; links: PageLink[] };
    permissions: {
        manage_fees: boolean;
        issue_invoices: boolean;
        cancel_invoices: boolean;
        view_reports: boolean;
        view_payments: boolean;
    };
}) {
    const base = `/t/${tenant.slug}/billing`;
    const headForm = useForm({ code: '', name: '', category: 'tuition' });
    const structureForm = useForm({
        campus_id: '',
        code: '',
        name: '',
        currency: '',
        frequency: 'monthly',
        effective_from: new Date().toISOString().slice(0, 10),
        effective_until: '',
        items: [{ fee_head_id: '', unit_amount: '', quantity: '1' }],
    });
    const invoiceForm = useForm({
        student_id: '',
        fee_structure_id: '',
        period_start: '',
        period_end: '',
        due_on: '',
        idempotency_key: '',
    });
    const currencyOptions = useMemo(
        () => [...new Set(structures.map((structure) => structure.currency))],
        [structures],
    );
    const [editingStructureId, setEditingStructureId] = useState<number | null>(null);
    const saveStructure = (event: React.FormEvent<HTMLFormElement>) => {
        event.preventDefault();
        const onSuccess = () => {
            setEditingStructureId(null);
            structureForm.reset();
        };
        if (editingStructureId === null) {
            structureForm.post(`${base}/structures`, { onSuccess });
        } else {
            structureForm.put(`${base}/structures/${editingStructureId}`, { onSuccess });
        }
    };

    return (
        <WorkspaceLayout area="tenant" title="Billing & invoices">
            <PageHeader
                title="Billing & invoices"
                description={`${institution.name} · Configure fee structures and issue auditable student invoices.`}
            />
            {permissions.view_reports && (
                <div className="mb-4">
                    <Link
                        className="border-border text-text-secondary rounded-lg border px-4 py-2 font-medium"
                        href={`${base}/reports`}
                    >
                        Billing reports
                    </Link>
                </div>
            )}
            {permissions.view_payments && (
                <div className="mb-4">
                    <Link
                        className="bg-brand inline-flex rounded-lg px-4 py-2 font-medium text-white"
                        href={`${base}/payments`}
                    >
                        Payments & collections
                    </Link>
                </div>
            )}
            <div className="grid gap-4 sm:grid-cols-2">
                <MetricCard
                    label="Issued invoices"
                    value={summary.issued_count.toLocaleString()}
                    detail="Currently issued, unpaid billing documents"
                />
                <MetricCard
                    label="Outstanding balance"
                    value={
                        summary.outstanding_totals
                            .map((row) => `${row.currency} ${row.amount}`)
                            .join(' · ') || '0'
                    }
                    detail="Current open invoice balances grouped by currency"
                />
            </div>

            {permissions.manage_fees && (
                <section className="bg-surface border-border mt-6 grid gap-6 rounded-2xl border p-5 xl:grid-cols-2">
                    <div>
                        <h2 className="text-text-primary text-lg font-semibold">Fee heads</h2>
                        <p className="text-text-secondary mt-1 text-sm">
                            Reusable charge labels. Issued invoices keep a copy of each label and amount.
                        </p>
                        <form
                            className="mt-4 grid gap-3 sm:grid-cols-2"
                            onSubmit={(event) => {
                                event.preventDefault();
                                headForm.post(`${base}/fee-heads`, { onSuccess: () => headForm.reset() });
                            }}
                        >
                            <label className="text-text-secondary text-sm">
                                Code
                                <input
                                    className="border-border bg-canvas text-text-primary mt-1 w-full rounded-lg border p-2"
                                    value={headForm.data.code}
                                    onChange={(event) => headForm.setData('code', event.currentTarget.value)}
                                    required
                                    maxLength={40}
                                />
                            </label>
                            <label className="text-text-secondary text-sm">
                                Name
                                <input
                                    className="border-border bg-canvas text-text-primary mt-1 w-full rounded-lg border p-2"
                                    value={headForm.data.name}
                                    onChange={(event) => headForm.setData('name', event.currentTarget.value)}
                                    required
                                    maxLength={140}
                                />
                            </label>
                            <label className="text-text-secondary text-sm">
                                Category
                                <select
                                    className="border-border bg-canvas text-text-primary mt-1 w-full rounded-lg border p-2"
                                    value={headForm.data.category}
                                    onChange={(event) =>
                                        headForm.setData('category', event.currentTarget.value)
                                    }
                                >
                                    {[
                                        'registration',
                                        'admission',
                                        'tuition',
                                        'examination',
                                        'certification',
                                        'materials',
                                        'transport',
                                        'hostel',
                                        'other',
                                    ].map((category) => (
                                        <option key={category}>{category}</option>
                                    ))}
                                </select>
                            </label>
                            <button
                                className="bg-brand mt-5 rounded-lg px-4 py-2 font-semibold text-white disabled:opacity-50"
                                disabled={headForm.processing}
                            >
                                Add fee head
                            </button>
                        </form>
                        <div className="divide-border mt-4 divide-y">
                            {fee_heads.map((head) => (
                                <div
                                    key={head.id}
                                    className="flex items-center justify-between gap-3 py-2 text-sm"
                                >
                                    <span className="text-text-primary">
                                        {head.code} · {head.name}
                                        <span className="text-text-muted ml-2 capitalize">
                                            {head.category}
                                        </span>
                                    </span>
                                    <button
                                        className="text-brand underline"
                                        onClick={() =>
                                            router.put(`${base}/fee-heads/${head.id}`, {
                                                name: head.name,
                                                status: head.status === 'active' ? 'inactive' : 'active',
                                            })
                                        }
                                    >
                                        {head.status === 'active' ? 'Deactivate' : 'Activate'}
                                    </button>
                                </div>
                            ))}
                        </div>
                    </div>

                    <div>
                        <h2 className="text-text-primary text-lg font-semibold">Fee structure</h2>
                        <p className="text-text-secondary mt-1 text-sm">
                            Create as a draft, review its lines and activate it before invoicing.
                        </p>
                        <form className="mt-4 grid gap-3 sm:grid-cols-2" onSubmit={saveStructure}>
                            <label className="text-text-secondary text-sm">
                                Code
                                <input
                                    className="border-border bg-canvas text-text-primary mt-1 w-full rounded-lg border p-2"
                                    value={structureForm.data.code}
                                    onChange={(event) =>
                                        structureForm.setData('code', event.currentTarget.value)
                                    }
                                    required
                                    maxLength={40}
                                    disabled={editingStructureId !== null}
                                />
                            </label>
                            <label className="text-text-secondary text-sm">
                                Name
                                <input
                                    className="border-border bg-canvas text-text-primary mt-1 w-full rounded-lg border p-2"
                                    value={structureForm.data.name}
                                    onChange={(event) =>
                                        structureForm.setData('name', event.currentTarget.value)
                                    }
                                    required
                                    maxLength={160}
                                />
                            </label>
                            <label className="text-text-secondary text-sm">
                                Currency (ISO 4217)
                                <input
                                    className="border-border bg-canvas text-text-primary mt-1 w-full rounded-lg border p-2 uppercase"
                                    value={structureForm.data.currency}
                                    onChange={(event) =>
                                        structureForm.setData(
                                            'currency',
                                            event.currentTarget.value.toUpperCase(),
                                        )
                                    }
                                    required
                                    minLength={3}
                                    maxLength={3}
                                    placeholder={currencyOptions[0] ?? 'USD'}
                                />
                            </label>
                            <label className="text-text-secondary text-sm">
                                Billing frequency
                                <select
                                    className="border-border bg-canvas text-text-primary mt-1 w-full rounded-lg border p-2"
                                    value={structureForm.data.frequency}
                                    onChange={(event) =>
                                        structureForm.setData('frequency', event.currentTarget.value)
                                    }
                                >
                                    {[
                                        ['one_time', 'One-time'],
                                        ['monthly', 'Monthly'],
                                        ['term', 'Term'],
                                        ['annual', 'Annual'],
                                    ].map(([value, label]) => (
                                        <option key={value} value={value}>
                                            {label}
                                        </option>
                                    ))}
                                </select>
                            </label>
                            <label className="text-text-secondary text-sm">
                                Campus scope
                                <select
                                    className="border-border bg-canvas text-text-primary mt-1 w-full rounded-lg border p-2"
                                    value={structureForm.data.campus_id}
                                    onChange={(event) =>
                                        structureForm.setData('campus_id', event.currentTarget.value)
                                    }
                                >
                                    <option value="">Institution-wide</option>
                                    {campuses.map((campus) => (
                                        <option key={campus.id} value={campus.id}>
                                            {campus.name}
                                        </option>
                                    ))}
                                </select>
                            </label>
                            <label className="text-text-secondary text-sm">
                                Effective from
                                <input
                                    type="date"
                                    className="border-border bg-canvas text-text-primary mt-1 w-full rounded-lg border p-2"
                                    value={structureForm.data.effective_from}
                                    onChange={(event) =>
                                        structureForm.setData('effective_from', event.currentTarget.value)
                                    }
                                    required
                                />
                            </label>
                            <label className="text-text-secondary text-sm sm:col-span-2">
                                Effective until (optional)
                                <input
                                    type="date"
                                    className="border-border bg-canvas text-text-primary mt-1 w-full rounded-lg border p-2"
                                    value={structureForm.data.effective_until}
                                    onChange={(event) =>
                                        structureForm.setData('effective_until', event.currentTarget.value)
                                    }
                                />
                            </label>
                            <div className="sm:col-span-2">
                                <h3 className="text-text-primary font-medium">Charge lines</h3>
                                {structureForm.data.items.map((item, index) => (
                                    <div key={index} className="mt-2 grid gap-2 sm:grid-cols-[1fr_1fr_7rem]">
                                        <select
                                            aria-label={`Fee head for line ${index + 1}`}
                                            className="border-border bg-canvas text-text-primary rounded-lg border p-2"
                                            value={item.fee_head_id}
                                            onChange={(event) =>
                                                structureForm.setData(
                                                    'items',
                                                    structureForm.data.items.map((row, rowIndex) =>
                                                        rowIndex === index
                                                            ? {
                                                                  ...row,
                                                                  fee_head_id: event.currentTarget.value,
                                                              }
                                                            : row,
                                                    ),
                                                )
                                            }
                                            required
                                        >
                                            <option value="">Select fee head</option>
                                            {fee_heads
                                                .filter((head) => head.status === 'active')
                                                .map((head) => (
                                                    <option key={head.id} value={head.id}>
                                                        {head.code} · {head.name}
                                                    </option>
                                                ))}
                                        </select>
                                        <input
                                            aria-label={`Unit amount for line ${index + 1}`}
                                            inputMode="decimal"
                                            className="border-border bg-canvas text-text-primary rounded-lg border p-2"
                                            placeholder="Amount"
                                            value={item.unit_amount}
                                            onChange={(event) =>
                                                structureForm.setData(
                                                    'items',
                                                    structureForm.data.items.map((row, rowIndex) =>
                                                        rowIndex === index
                                                            ? {
                                                                  ...row,
                                                                  unit_amount: event.currentTarget.value,
                                                              }
                                                            : row,
                                                    ),
                                                )
                                            }
                                            required
                                        />
                                        <input
                                            aria-label={`Quantity for line ${index + 1}`}
                                            type="number"
                                            min="1"
                                            max="1000"
                                            className="border-border bg-canvas text-text-primary rounded-lg border p-2"
                                            value={item.quantity}
                                            onChange={(event) =>
                                                structureForm.setData(
                                                    'items',
                                                    structureForm.data.items.map((row, rowIndex) =>
                                                        rowIndex === index
                                                            ? { ...row, quantity: event.currentTarget.value }
                                                            : row,
                                                    ),
                                                )
                                            }
                                            required
                                        />
                                    </div>
                                ))}
                                <button
                                    type="button"
                                    className="text-brand mt-2 text-sm underline"
                                    onClick={() =>
                                        structureForm.setData('items', [
                                            ...structureForm.data.items,
                                            { fee_head_id: '', unit_amount: '', quantity: '1' },
                                        ])
                                    }
                                >
                                    Add charge line
                                </button>
                            </div>
                            <div className="flex flex-wrap gap-2 sm:col-span-2">
                                <button
                                    className="bg-brand rounded-lg px-4 py-2 font-semibold text-white disabled:opacity-50"
                                    disabled={structureForm.processing || fee_heads.length === 0}
                                >
                                    {editingStructureId === null
                                        ? 'Save draft structure'
                                        : 'Update draft structure'}
                                </button>
                                {editingStructureId !== null && (
                                    <button
                                        type="button"
                                        className="border-border text-text-secondary rounded-lg border px-4 py-2"
                                        onClick={() => {
                                            setEditingStructureId(null);
                                            structureForm.reset();
                                        }}
                                    >
                                        Stop editing
                                    </button>
                                )}
                            </div>
                        </form>
                        <div className="divide-border mt-4 divide-y">
                            {structures.map((structure) => (
                                <div
                                    key={structure.id}
                                    className="flex flex-wrap items-center justify-between gap-3 py-2 text-sm"
                                >
                                    <span className="text-text-primary">
                                        {structure.code} · {structure.name} · {structure.currency}
                                        <span className="text-text-muted ml-2 capitalize">
                                            {structure.status}, {structure.items_count} lines
                                        </span>
                                    </span>
                                    {structure.status === 'draft' && (
                                        <div className="flex gap-3">
                                            <button
                                                className="text-brand underline"
                                                onClick={() => {
                                                    setEditingStructureId(structure.id);
                                                    structureForm.setData({
                                                        campus_id: structure.campus_id?.toString() ?? '',
                                                        code: structure.code,
                                                        name: structure.name,
                                                        currency: structure.currency,
                                                        frequency: structure.frequency,
                                                        effective_from: structure.effective_from,
                                                        effective_until: structure.effective_until ?? '',
                                                        items: structure.items.map((item) => ({
                                                            ...item,
                                                            fee_head_id: String(item.fee_head_id),
                                                        })),
                                                    });
                                                }}
                                            >
                                                Edit draft
                                            </button>
                                            <button
                                                className="text-brand underline"
                                                onClick={() =>
                                                    router.post(`${base}/structures/${structure.id}/activate`)
                                                }
                                            >
                                                Activate
                                            </button>
                                        </div>
                                    )}
                                </div>
                            ))}
                        </div>
                    </div>
                </section>
            )}

            {permissions.issue_invoices && (
                <section className="bg-surface border-border mt-6 rounded-2xl border p-5">
                    <h2 className="text-text-primary text-lg font-semibold">Issue invoice</h2>
                    <p className="text-text-secondary mt-1 text-sm">
                        Invoice totals are calculated server-side. Authorized payments are recorded from the
                        dedicated collection workspace.
                    </p>
                    <form
                        className="mt-4 grid gap-3 sm:grid-cols-2 xl:grid-cols-3"
                        onSubmit={(event) => {
                            event.preventDefault();
                            invoiceForm.setData(
                                'idempotency_key',
                                invoiceForm.data.idempotency_key || crypto.randomUUID(),
                            );
                            invoiceForm.post(`${base}/invoices`);
                        }}
                    >
                        <label className="text-text-secondary text-sm">
                            Learner
                            <select
                                className="border-border bg-canvas text-text-primary mt-1 w-full rounded-lg border p-2"
                                value={invoiceForm.data.student_id}
                                onChange={(event) =>
                                    invoiceForm.setData('student_id', event.currentTarget.value)
                                }
                                required
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
                            Active fee structure
                            <select
                                className="border-border bg-canvas text-text-primary mt-1 w-full rounded-lg border p-2"
                                value={invoiceForm.data.fee_structure_id}
                                onChange={(event) =>
                                    invoiceForm.setData('fee_structure_id', event.currentTarget.value)
                                }
                                required
                            >
                                <option value="">Select structure</option>
                                {structures
                                    .filter((structure) => structure.status === 'active')
                                    .map((structure) => (
                                        <option key={structure.id} value={structure.id}>
                                            {structure.code} · {structure.name} ({structure.currency})
                                        </option>
                                    ))}
                            </select>
                        </label>
                        <label className="text-text-secondary text-sm">
                            Period starts
                            <input
                                type="date"
                                className="border-border bg-canvas text-text-primary mt-1 w-full rounded-lg border p-2"
                                value={invoiceForm.data.period_start}
                                onChange={(event) =>
                                    invoiceForm.setData('period_start', event.currentTarget.value)
                                }
                                required
                            />
                        </label>
                        <label className="text-text-secondary text-sm">
                            Period ends
                            <input
                                type="date"
                                className="border-border bg-canvas text-text-primary mt-1 w-full rounded-lg border p-2"
                                value={invoiceForm.data.period_end}
                                onChange={(event) =>
                                    invoiceForm.setData('period_end', event.currentTarget.value)
                                }
                                required
                            />
                        </label>
                        <label className="text-text-secondary text-sm">
                            Due date
                            <input
                                type="date"
                                className="border-border bg-canvas text-text-primary mt-1 w-full rounded-lg border p-2"
                                value={invoiceForm.data.due_on}
                                onChange={(event) => invoiceForm.setData('due_on', event.currentTarget.value)}
                                required
                            />
                        </label>
                        <button
                            className="bg-brand self-end rounded-lg px-4 py-2 font-semibold text-white disabled:opacity-50"
                            disabled={
                                invoiceForm.processing ||
                                students.length === 0 ||
                                structures.every((structure) => structure.status !== 'active')
                            }
                        >
                            Issue invoice
                        </button>
                    </form>
                </section>
            )}

            <section className="bg-surface border-border mt-6 rounded-2xl border p-5">
                <h2 className="text-text-primary text-lg font-semibold">Recent invoices</h2>
                {invoices.data.length === 0 ? (
                    <EmptyState
                        title="No invoices yet"
                        description="Invoices appear here after an authorized finance operator issues them from an active fee structure."
                    />
                ) : (
                    <div className="mt-4 overflow-x-auto">
                        <table className="w-full min-w-[720px] text-left text-sm">
                            <thead className="text-text-muted border-border border-b">
                                <tr>
                                    <th className="p-3">Invoice</th>
                                    <th className="p-3">Learner</th>
                                    <th className="p-3">Period</th>
                                    <th className="p-3">Due</th>
                                    <th className="p-3">Amount</th>
                                    <th className="p-3">Status</th>
                                </tr>
                            </thead>
                            <tbody className="divide-border divide-y">
                                {invoices.data.map((invoice) => (
                                    <tr key={invoice.id}>
                                        <td className="p-3">
                                            <Link
                                                className="text-brand font-medium underline"
                                                href={`${base}/invoices/${invoice.id}`}
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
                                        <td className="p-3 capitalize">{invoice.status}</td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                        <nav aria-label="Invoice pages" className="mt-4 flex flex-wrap gap-2">
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
            </section>
        </WorkspaceLayout>
    );
}
