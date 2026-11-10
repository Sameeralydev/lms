import { Head, router } from '@inertiajs/react';
import { WorkspaceLayout } from '@/layouts/workspace-layout';

type Item = {
    id: number;
    category: string;
    reason: string;
    status: string;
    created_at: string;
    evidence: { id: number; scan_status: string }[];
    entry: {
        student: { legal_first_name: string; legal_last_name: string; student_number: string };
        register: { occurrence_date: string };
        status_definition: { label: string } | null;
    };
};
type Props = { tenant: { slug: string }; items: { data: Item[]; current_page: number; last_page: number } };

export default function AttendanceJustifications({ tenant, items }: Props) {
    return (
        <WorkspaceLayout title="Absence justifications" area="tenant">
            <Head title="Absence justification review" />
            <main id="main-content" className="mx-auto max-w-6xl space-y-6 px-4 py-8 sm:px-6 lg:px-8">
                <header>
                    <p className="text-brand-link text-sm font-semibold uppercase">Registrar review</p>
                    <h1 className="text-text-primary mt-1 text-3xl font-semibold">Absence justifications</h1>
                    <p className="text-text-secondary mt-2">
                        Approval changes only the linked attendance entry and preserves its prior value in
                        revision history.
                    </p>
                </header>
                <section className="surface rounded-2xl border p-4 sm:p-6">
                    <ul className="divide-border divide-y">
                        {items.data.map((item) => (
                            <li
                                key={item.id}
                                className="flex flex-col gap-3 py-4 sm:flex-row sm:items-center sm:justify-between"
                            >
                                <div>
                                    <h2 className="text-text-primary font-medium">
                                        {item.entry.student.legal_first_name}{' '}
                                        {item.entry.student.legal_last_name} ·{' '}
                                        {item.entry.student.student_number}
                                    </h2>
                                    <p className="text-text-secondary mt-1 text-sm">
                                        {item.entry.register.occurrence_date} ·{' '}
                                        {item.entry.status_definition?.label ?? 'Attendance'} ·{' '}
                                        {item.category}
                                    </p>
                                    <p className="mt-2 text-sm">{item.reason}</p>
                                    {item.evidence.map((evidence) => (
                                        <p key={evidence.id} className="mt-2 text-xs">
                                            Evidence scan: {evidence.scan_status}
                                            {evidence.scan_status === 'clean' && (
                                                <a
                                                    className="text-brand-link ml-2 underline"
                                                    href={`/t/${tenant.slug}/attendance/justifications/evidence/${evidence.id}`}
                                                >
                                                    Download verified file
                                                </a>
                                            )}
                                        </p>
                                    ))}
                                </div>
                                <div className="flex gap-2">
                                    <button
                                        className="rounded-lg border px-3 py-2 text-sm focus-visible:outline-2"
                                        onClick={() =>
                                            router.post(
                                                `/t/${tenant.slug}/attendance/justifications/${item.id}/decision`,
                                                { decision: 'reject' },
                                            )
                                        }
                                    >
                                        Reject
                                    </button>
                                    <button
                                        className="bg-brand-action rounded-lg px-3 py-2 text-sm text-white focus-visible:outline-2"
                                        onClick={() =>
                                            router.post(
                                                `/t/${tenant.slug}/attendance/justifications/${item.id}/decision`,
                                                { decision: 'approve' },
                                            )
                                        }
                                    >
                                        Approve
                                    </button>
                                </div>
                            </li>
                        ))}
                        {items.data.length === 0 && (
                            <li className="text-text-secondary p-8 text-center">
                                No absence justifications need review.
                            </li>
                        )}
                    </ul>
                </section>
            </main>
        </WorkspaceLayout>
    );
}
