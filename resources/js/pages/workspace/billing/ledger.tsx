import { Link } from '@inertiajs/react';
import { PageHeader } from '@/components/ui/page-header';
import { WorkspaceLayout } from '@/layouts/workspace-layout';

type LedgerEntry = {
    date: string;
    source: string;
    reference: string;
    type: string;
    debit: string;
    credit: string;
    currency: string;
    description: string;
    balance: string;
};

export default function BillingLedger({
    tenant,
    student,
    currency_totals,
    entries,
}: {
    tenant: { slug: string };
    student: { id: number; number: string; name: string };
    currency_totals: { currency: string; debit: string; credit: string; balance: string }[];
    entries: LedgerEntry[];
}) {
    return (
        <WorkspaceLayout area="tenant" title="Student financial ledger">
            <PageHeader
                title="Student financial ledger"
                description={`${student.name} · ${student.number}. Charges, payment allocations, executed refund reversals, and posted adjustments in your authorized campus scope.`}
                actions={
                    <Link
                        className="border-border rounded-lg border px-4 py-2"
                        href={`/t/${tenant.slug}/billing/lifecycle`}
                    >
                        Billing lifecycle
                    </Link>
                }
            />
            <div className="grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
                {currency_totals.map((total) => (
                    <section className="bg-surface border-border rounded-2xl border p-4" key={total.currency}>
                        <h2 className="text-text-primary font-semibold">{total.currency} account balance</h2>
                        <p className="text-text-primary mt-2 text-2xl font-semibold">{total.balance}</p>
                        <p className="text-text-secondary mt-1 text-sm">
                            Charges {total.debit} · credits {total.credit}
                        </p>
                    </section>
                ))}
            </div>
            <section className="bg-surface border-border mt-5 overflow-x-auto rounded-2xl border">
                <table className="w-full min-w-[860px] text-left text-sm">
                    <caption className="text-text-secondary p-4 text-left">
                        Financial ledger entries for {student.number}
                    </caption>
                    <thead className="bg-canvas text-text-secondary">
                        <tr>
                            <th scope="col" className="p-3">
                                Date
                            </th>
                            <th scope="col" className="p-3">
                                Reference
                            </th>
                            <th scope="col" className="p-3">
                                Entry
                            </th>
                            <th scope="col" className="p-3">
                                Description
                            </th>
                            <th scope="col" className="p-3">
                                Debit
                            </th>
                            <th scope="col" className="p-3">
                                Credit
                            </th>
                            <th scope="col" className="p-3">
                                Balance
                            </th>
                        </tr>
                    </thead>
                    <tbody>
                        {entries.length === 0 ? (
                            <tr>
                                <td className="text-text-secondary p-6" colSpan={7}>
                                    No posted charges or payment activity is recorded.
                                </td>
                            </tr>
                        ) : (
                            entries.map((entry, index) => (
                                <tr
                                    className="border-border border-t"
                                    key={`${entry.source}-${entry.reference}-${index}`}
                                >
                                    <td className="p-3">{new Date(entry.date).toLocaleDateString()}</td>
                                    <td className="p-3">{entry.reference}</td>
                                    <td className="p-3">{entry.type.replaceAll('_', ' ')}</td>
                                    <td className="p-3">{entry.description}</td>
                                    <td className="p-3">
                                        {entry.debit === '0.00' ? '—' : `${entry.currency} ${entry.debit}`}
                                    </td>
                                    <td className="p-3">
                                        {entry.credit === '0.00' ? '—' : `${entry.currency} ${entry.credit}`}
                                    </td>
                                    <td className="p-3">
                                        {entry.currency} {entry.balance}
                                    </td>
                                </tr>
                            ))
                        )}
                    </tbody>
                </table>
            </section>
        </WorkspaceLayout>
    );
}
