import { Link } from '@inertiajs/react';
import { MetricCard } from '@/components/ui/metric-card';
import { PageHeader } from '@/components/ui/page-header';
import { WorkspaceLayout } from '@/layouts/workspace-layout';

type Event = {
    id: number;
    event_type: string;
    occurred_at: string;
    tenant?: { name: string } | null;
    actor?: { name: string } | null;
};
export default function PlatformDashboard({
    metrics,
    recentEvents,
}: {
    metrics: Record<string, number>;
    recentEvents: Event[];
}) {
    return (
        <WorkspaceLayout area="platform" title="Overview">
            <PageHeader
                title="Platform overview"
                description="Live counts from the platform database. These describe provisioned records, not production activity or commercial performance."
                actions={
                    <Link
                        className="bg-brand-action inline-flex min-h-11 items-center rounded-lg px-4 text-sm font-semibold text-white"
                        href="/platform/tenants/create"
                    >
                        Create institution
                    </Link>
                }
            />
            <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
                {[
                    ['Institutions', metrics.tenants, 'Provisioned tenant records'],
                    ['Active tenants', metrics.activeTenants, 'Currently available workspaces'],
                    ['Suspended tenants', metrics.suspendedTenants, 'Access paused by platform operations'],
                    ['Campuses', metrics.campuses, 'Registered campus records'],
                    [
                        'Active memberships',
                        metrics.activeMemberships,
                        'Current institution access assignments',
                    ],
                ].map(([label, value, detail]) => (
                    <MetricCard
                        key={label}
                        label={String(label)}
                        value={Number(value)}
                        detail={String(detail)}
                    />
                ))}
            </div>
            <section className="border-border bg-surface mt-8 overflow-hidden rounded-2xl border">
                <div className="border-border flex items-center justify-between border-b px-5 py-4">
                    <div>
                        <h2 className="text-text-primary font-semibold">Recent administrative activity</h2>
                        <p className="text-text-muted mt-1 text-xs">Audit event records, newest first.</p>
                    </div>
                    <Link href="/platform/audit" className="text-brand-link text-sm font-semibold">
                        View history
                    </Link>
                </div>
                {recentEvents.length ? (
                    <div className="divide-border divide-y">
                        {recentEvents.map((event) => (
                            <div
                                key={event.id}
                                className="flex flex-wrap items-center justify-between gap-2 px-5 py-4"
                            >
                                <div>
                                    <p className="text-text-primary text-sm font-medium">
                                        {event.event_type.replaceAll('.', ' ')}
                                    </p>
                                    <p className="text-text-secondary mt-1 text-xs">
                                        {event.actor?.name ?? 'System'} · {event.tenant?.name ?? 'Platform'}
                                    </p>
                                </div>
                                <time className="text-text-muted text-xs" dateTime={event.occurred_at}>
                                    {new Date(event.occurred_at).toLocaleString()}
                                </time>
                            </div>
                        ))}
                    </div>
                ) : (
                    <p className="text-text-secondary px-5 py-9 text-center text-sm">
                        No administrative events have been recorded yet.
                    </p>
                )}
            </section>
        </WorkspaceLayout>
    );
}
