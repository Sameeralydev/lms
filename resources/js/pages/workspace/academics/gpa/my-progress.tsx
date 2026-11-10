import { Head } from '@inertiajs/react';
import { PageHeader } from '@/components/ui/page-header';
import { WorkspaceLayout } from '@/layouts/workspace-layout';

type Snapshot = {
    id: number;
    policy: string;
    calculation_type: string;
    academic_period_id: number | null;
    gpa: string;
    quality_points: string;
    attempted_credits: string;
    academic_standing: string | null;
    snapshot_number: number;
    calculated_at: string;
};
export default function MyProgress({ snapshots }: { tenant: { slug: string }; snapshots: Snapshot[] }) {
    return (
        <WorkspaceLayout area="tenant" title="My academic progress">
            <Head title="My academic progress" />
            <PageHeader
                title="My academic progress"
                description="Official progress snapshots calculated from published grade points and confirmed enrollment credits."
            />
            {snapshots.length ? (
                <div className="grid gap-4 md:grid-cols-2">
                    {snapshots.map((item) => (
                        <section
                            key={item.id}
                            aria-label={`${item.calculation_type} GPA snapshot`}
                            className="border-border bg-surface rounded-2xl border p-5 sm:p-7"
                        >
                            <p className="text-text-secondary text-sm">
                                {item.policy} ·{' '}
                                {item.calculation_type === 'term'
                                    ? `period ${item.academic_period_id}`
                                    : 'cumulative'}
                            </p>
                            <p className="mt-2 text-4xl font-semibold">{item.gpa}</p>
                            <p className="mt-1 font-medium">
                                {item.academic_standing ?? 'Standing not assigned'}
                            </p>
                            <dl className="text-text-secondary mt-4 grid grid-cols-2 gap-2 text-sm">
                                <dt>Quality points</dt>
                                <dd>{item.quality_points}</dd>
                                <dt>Attempted credits</dt>
                                <dd>{item.attempted_credits}</dd>
                                <dt>Snapshot version</dt>
                                <dd>{item.snapshot_number}</dd>
                                <dt>Calculated</dt>
                                <dd>{new Date(item.calculated_at).toLocaleDateString()}</dd>
                            </dl>
                        </section>
                    ))}
                </div>
            ) : (
                <section
                    aria-label="Academic progress empty state"
                    className="border-border bg-surface rounded-2xl border p-6"
                >
                    <p className="font-medium">No GPA snapshot is available yet.</p>
                    <p className="text-text-secondary mt-1 text-sm">
                        Your institution will publish a progress calculation after eligible grades are
                        released.
                    </p>
                </section>
            )}
        </WorkspaceLayout>
    );
}
