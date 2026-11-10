import { Link } from '@inertiajs/react';
import { EmptyState } from '@/components/ui/empty-state';
import { MetricCard } from '@/components/ui/metric-card';
import { PageHeader } from '@/components/ui/page-header';
import { WorkspaceLayout } from '@/layouts/workspace-layout';

type Event = { id: number; event_type: string; occurred_at: string; actor?: { name: string } | null };
type Metric = { label: string; value: number; detail: string };
type DashboardCard = { label: string; href: string; detail: string };

export default function WorkspaceDashboard({
    workspace,
    metrics,
    cards,
    recentEvents,
}: {
    workspace: { title: string; description: string; auditHref: string };
    metrics: Metric[];
    cards: DashboardCard[];
    recentEvents: Event[];
}) {
    return (
        <WorkspaceLayout area="tenant" title={workspace.title}>
            <PageHeader title={workspace.title} description={workspace.description} />

            {metrics.length > 0 ? (
                <section
                    aria-label="Current workspace metrics"
                    className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4"
                >
                    {metrics.map((metric) => (
                        <MetricCard
                            key={metric.label}
                            label={metric.label}
                            value={metric.value}
                            detail={metric.detail}
                        />
                    ))}
                </section>
            ) : null}

            <section aria-labelledby="workspace-services-heading" className="mt-8">
                <div className="mb-4">
                    <h2 id="workspace-services-heading" className="text-lg font-semibold">
                        Your workspace
                    </h2>
                    <p className="text-text-secondary mt-1 text-sm">
                        Open a service available to your active role and institution membership.
                    </p>
                </div>
                {cards.length > 0 ? (
                    <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
                        {cards.map((card) => (
                            <Link
                                key={card.href}
                                href={card.href}
                                className="group border-border bg-surface focus-ring rounded-2xl border p-5 shadow-sm transition-shadow hover:shadow-md sm:p-6"
                            >
                                <span
                                    className="text-brand-link bg-brand-blue-soft grid size-10 place-items-center rounded-xl text-lg font-semibold"
                                    aria-hidden="true"
                                >
                                    ↗
                                </span>
                                <h3 className="group-hover:text-brand-link mt-4 font-semibold">
                                    {card.label}
                                </h3>
                                <p className="text-text-secondary mt-2 text-sm leading-6">{card.detail}</p>
                            </Link>
                        ))}
                    </div>
                ) : (
                    <EmptyState
                        title="No workspace services assigned yet"
                        description="Your active membership does not currently include access to a listed module. Contact an institution administrator if you believe this is incorrect."
                    />
                )}
            </section>

            {recentEvents.length > 0 ? (
                <section className="border-border bg-surface mt-8 overflow-hidden rounded-2xl border">
                    <div className="border-border flex flex-wrap items-center justify-between gap-3 border-b px-5 py-4">
                        <div>
                            <h2 className="font-semibold">Recent institution activity</h2>
                            <p className="text-text-muted mt-1 text-xs">
                                Recent administrative changes visible to your role.
                            </p>
                        </div>
                        <Link
                            href={workspace.auditHref}
                            className="text-brand-link focus-ring rounded text-sm font-semibold"
                        >
                            View audit
                        </Link>
                    </div>
                    <div className="divide-border divide-y">
                        {recentEvents.map((event) => (
                            <div key={event.id} className="flex flex-wrap justify-between gap-3 px-5 py-4">
                                <div>
                                    <p className="text-sm font-medium">
                                        {event.event_type.replaceAll('.', ' ')}
                                    </p>
                                    <p className="text-text-secondary mt-1 text-xs">
                                        {event.actor?.name ?? 'System'}
                                    </p>
                                </div>
                                <time className="text-text-muted text-xs" dateTime={event.occurred_at}>
                                    {new Date(event.occurred_at).toLocaleString()}
                                </time>
                            </div>
                        ))}
                    </div>
                </section>
            ) : null}
        </WorkspaceLayout>
    );
}
