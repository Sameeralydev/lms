import { Link } from '@inertiajs/react';
import { AdmissionsPublicShell } from '@/components/admissions/public-shell';
import { EmptyState } from '@/components/ui/empty-state';
import { PageHeader } from '@/components/ui/page-header';

type Application = {
    applicationNumber: string;
    status: string;
    intake: string | null;
    intakeCode: string | null;
    deadline: string | null;
    submittedAt: string | null;
    createdAt: string;
};
export default function ApplicantDashboard({
    tenant,
    applications,
}: {
    tenant: { slug: string; name: string };
    applications: { data: Application[]; links: { url: string | null; label: string; active: boolean }[] };
}) {
    return (
        <AdmissionsPublicShell title="My applications" tenant={tenant}>
            <PageHeader
                title="My applications"
                description="Review saved drafts, submissions, and admission decisions for this institution."
                actions={
                    <Link
                        className="bg-brand-action inline-flex min-h-11 items-center rounded-lg px-4 text-sm font-semibold text-white"
                        href={`/admissions/${tenant.slug}`}
                    >
                        Browse open intakes
                    </Link>
                }
            />
            {applications.data.length ? (
                <div className="grid gap-4">
                    {applications.data.map((app) => (
                        <article
                            key={app.applicationNumber}
                            className="border-border bg-surface rounded-2xl border p-5"
                        >
                            <div className="flex flex-wrap items-start justify-between gap-4">
                                <div>
                                    <p className="text-text-muted text-xs">
                                        Reference {app.applicationNumber}
                                    </p>
                                    <h2 className="mt-1 text-lg font-semibold">
                                        {app.intake ?? 'Application'}
                                    </h2>
                                    <p className="text-text-secondary mt-2 text-sm">
                                        Updated {new Date(app.createdAt).toLocaleDateString()}
                                    </p>
                                </div>
                                <span className="bg-surface-soft rounded-full px-3 py-1 text-xs font-semibold capitalize">
                                    {app.status.replaceAll('_', ' ')}
                                </span>
                            </div>
                            <div className="mt-4 flex flex-wrap items-center justify-between gap-3">
                                <p className="text-text-muted text-sm">
                                    {app.submittedAt
                                        ? `Submitted ${new Date(app.submittedAt).toLocaleDateString()}`
                                        : app.deadline
                                          ? `Deadline ${new Date(app.deadline).toLocaleDateString()}`
                                          : 'No deadline published'}
                                </p>
                                <Link
                                    className="text-brand-link text-sm font-semibold"
                                    href={`/admissions/${tenant.slug}/applications/${app.applicationNumber}`}
                                >
                                    {app.status === 'draft' ? 'Continue application' : 'View application'}
                                </Link>
                            </div>
                        </article>
                    ))}
                </div>
            ) : (
                <EmptyState
                    title="No applications yet"
                    description="When you start an application, it will appear here."
                />
            )}
            <nav aria-label="Application pages" className="mt-5 flex flex-wrap gap-2">
                {applications.links.map((link, index) =>
                    link.url ? (
                        <Link
                            key={index}
                            href={link.url}
                            className={`rounded-md px-3 py-2 text-xs ${link.active ? 'bg-brand-action text-white' : 'border-border border'}`}
                        >
                            {link.label.replace('&laquo;', '‹').replace('&raquo;', '›')}
                        </Link>
                    ) : null,
                )}
            </nav>
        </AdmissionsPublicShell>
    );
}
