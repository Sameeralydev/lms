import { Head } from '@inertiajs/react';
import { PageHeader } from '@/components/ui/page-header';
import { WorkspaceLayout } from '@/layouts/workspace-layout';

type RecordEntry = {
    id: number;
    record_type: string;
    issue_number: number;
    status: string;
    issued_at: string | null;
    verification_code: string;
};
export default function MyAcademicRecords({
    tenant,
    records,
}: {
    tenant: { slug: string };
    records: RecordEntry[];
}) {
    return (
        <WorkspaceLayout area="tenant" title="My academic records">
            <Head title="My academic records" />
            <PageHeader
                title="My academic records"
                description="Download only records issued to you or to a learner under your verified guardian authority."
            />
            {records.length ? (
                <ul className="border-border bg-surface divide-border divide-y rounded-2xl border px-5">
                    {records.map((record) => (
                        <li
                            key={record.id}
                            className="flex flex-wrap items-center justify-between gap-3 py-4"
                        >
                            <div>
                                <p className="font-medium">
                                    {record.record_type.replaceAll('_', ' ')} · issue {record.issue_number}
                                </p>
                                <p className="text-text-secondary text-sm">
                                    Issued{' '}
                                    {record.issued_at
                                        ? new Date(record.issued_at).toLocaleDateString()
                                        : 'date unavailable'}
                                </p>
                            </div>
                            <div className="flex gap-3">
                                <a
                                    className="text-text-primary focus-ring rounded underline"
                                    href={`/t/${tenant.slug}/academic-records/${record.id}/download`}
                                >
                                    Download secure PDF
                                </a>
                                {record.verification_code && (
                                    <a
                                        className="text-brand-link focus-ring rounded underline"
                                        href={`/academic-records/verify/${record.verification_code}`}
                                    >
                                        Verify certificate
                                    </a>
                                )}
                            </div>
                        </li>
                    ))}
                </ul>
            ) : (
                <section
                    aria-label="No issued records"
                    className="border-border bg-surface rounded-2xl border p-6"
                >
                    <p className="font-medium">No academic records have been issued to you.</p>
                </section>
            )}
        </WorkspaceLayout>
    );
}
