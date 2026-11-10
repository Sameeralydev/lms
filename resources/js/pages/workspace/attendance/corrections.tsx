import { Head, router } from '@inertiajs/react';
import { WorkspaceLayout } from '@/layouts/workspace-layout';

type Item = {
    id: number;
    reason: string;
    status: string;
    created_at: string;
    entry: {
        student: { legal_first_name: string; legal_last_name: string; student_number: string };
        register: { occurrence_date: string };
        status_definition: { label: string } | null;
    };
};
type Props = { tenant: { slug: string }; items: { data: Item[]; current_page: number; last_page: number } };

export default function AttendanceCorrections({ tenant, items }: Props) {
    return (
        <WorkspaceLayout title="Attendance corrections" area="tenant">
            <Head title="Attendance correction review" />
            <main id="main-content" className="mx-auto max-w-6xl space-y-6 px-4 py-8 sm:px-6 lg:px-8">
                <header>
                    <p className="text-brand-link text-sm font-semibold uppercase">Registrar review</p>
                    <h1 className="text-text-primary mt-1 text-3xl font-semibold">Correction requests</h1>
                    <p className="text-text-secondary mt-2">
                        Requesters cannot approve their own changes. Decisions preserve the previous
                        attendance value in revision history.
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
                                        {item.entry.register.occurrence_date} · currently{' '}
                                        {item.entry.status_definition?.label ?? 'Not marked'}
                                    </p>
                                    <p className="mt-2 text-sm">{item.reason}</p>
                                </div>
                                <div className="flex gap-2">
                                    <button
                                        className="rounded-lg border px-3 py-2 text-sm focus-visible:outline-2"
                                        onClick={() =>
                                            router.post(
                                                `/t/${tenant.slug}/attendance/corrections/${item.id}/decision`,
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
                                                `/t/${tenant.slug}/attendance/corrections/${item.id}/decision`,
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
                                No correction requests need review.
                            </li>
                        )}
                    </ul>
                </section>
            </main>
        </WorkspaceLayout>
    );
}
