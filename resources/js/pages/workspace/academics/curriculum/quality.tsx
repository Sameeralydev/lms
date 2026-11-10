import { Head, Link } from '@inertiajs/react';
import { PageHeader } from '@/components/ui/page-header';
import { WorkspaceLayout } from '@/layouts/workspace-layout';

type Props = {
    tenantSlug: string;
    summary: Record<string, number>;
    findings: {
        curriculum_id: number;
        curriculum_title: string;
        version_number: number | null;
        coverage_percent: number;
        completeness_percent: number;
        lesson_objective_coverage_percent?: number;
        code: string;
        severity: string;
        title: string;
        detail: string;
        count: number;
    }[];
    curricula: {
        links: { url: string | null; label: string; active: boolean }[];
        current_page: number;
        last_page: number;
    };
};

export default function CurriculumQuality({ tenantSlug, summary, findings, curricula }: Props) {
    const base = `/t/${tenantSlug}/academics/curriculum`;
    return (
        <WorkspaceLayout title="Curriculum quality" area="tenant">
            <Head title="Curriculum quality" />
            <div className="grid gap-6">
                <PageHeader
                    title="Curriculum quality"
                    description="Actionable findings are calculated from saved syllabus, outcome, competency, workload, and review data. Findings link to their source curriculum version."
                    actions={
                        <Link
                            href={base}
                            className="border-border bg-surface inline-flex min-h-11 items-center rounded-lg border px-4 text-sm font-semibold"
                        >
                            Curriculum workspace
                        </Link>
                    }
                />
                <section aria-label="Quality overview" className="grid gap-3 sm:grid-cols-2 xl:grid-cols-5">
                    {[
                        ['Curricula', summary.curricula],
                        ['Pending approvals', summary.pending_approvals],
                        ['Published versions', summary.published],
                        ['Draft versions', summary.drafts],
                        ['Findings on this page', summary.findings_on_page],
                    ].map(([label, value]) => (
                        <article
                            key={label}
                            className="border-border bg-surface rounded-2xl border p-4 shadow-sm"
                        >
                            <p className="text-text-muted text-xs font-semibold tracking-wide uppercase">
                                {label}
                            </p>
                            <p className="mt-2 text-2xl font-semibold tabular-nums">{value}</p>
                        </article>
                    ))}
                </section>
                <section
                    className="border-border bg-surface overflow-hidden rounded-2xl border shadow-sm"
                    aria-labelledby="findings-title"
                >
                    <div className="border-border border-b p-5">
                        <h2 id="findings-title" className="text-lg font-semibold">
                            Review findings
                        </h2>
                        <p className="text-text-secondary mt-1 text-sm">
                            Page {curricula.current_page} of {curricula.last_page}; findings are scoped to the
                            active institution.
                        </p>
                    </div>
                    {findings.length === 0 ? (
                        <div className="p-8 text-center">
                            <h3 className="font-semibold">No quality gaps on this page</h3>
                            <p className="text-text-secondary mt-1 text-sm">
                                The listed curriculum versions meet the current design checks.
                            </p>
                        </div>
                    ) : (
                        <ul className="divide-border divide-y">
                            {findings.map((finding, index) => (
                                <li
                                    key={`${finding.curriculum_id}-${finding.code}-${index}`}
                                    className="grid gap-3 p-5 md:grid-cols-[minmax(0,1fr)_auto] md:items-center"
                                >
                                    <div>
                                        <div className="flex flex-wrap items-center gap-2">
                                            <span
                                                className={`rounded-full px-2.5 py-1 text-xs font-semibold ${finding.severity === 'high' ? 'bg-danger/10 text-danger' : 'bg-canvas text-text-secondary'}`}
                                            >
                                                {finding.severity}
                                            </span>
                                            <span className="text-text-muted text-xs">
                                                {finding.count} finding{finding.count === 1 ? '' : 's'}
                                            </span>
                                        </div>
                                        <h3 className="mt-2 font-semibold">{finding.title}</h3>
                                        <p className="text-text-secondary mt-1 text-sm">{finding.detail}</p>
                                        <p className="text-text-muted mt-2 text-xs">
                                            {finding.curriculum_title} ·{' '}
                                            {finding.version_number === null
                                                ? 'No version'
                                                : `version ${finding.version_number}`}{' '}
                                            · Outcome coverage {finding.coverage_percent}% · Description
                                            completeness {finding.completeness_percent}% · Objective
                                            completion {finding.lesson_objective_coverage_percent ?? 100}%
                                        </p>
                                    </div>
                                    <Link
                                        href={`${base}/curricula/${finding.curriculum_id}`}
                                        className="text-brand-link inline-flex min-h-11 items-center font-semibold underline underline-offset-4"
                                    >
                                        Review curriculum
                                    </Link>
                                </li>
                            ))}
                        </ul>
                    )}
                    {curricula.links.length > 1 && (
                        <nav
                            aria-label="Quality result pages"
                            className="border-border flex flex-wrap gap-2 border-t p-4"
                        >
                            {curricula.links.map((link, index) =>
                                link.url ? (
                                    <Link
                                        key={index}
                                        href={link.url}
                                        className={`border-border inline-flex min-h-10 min-w-10 items-center justify-center rounded-lg border px-3 text-sm ${link.active ? 'bg-canvas font-semibold' : ''}`}
                                        aria-current={link.active ? 'page' : undefined}
                                    >
                                        {paginationLabel(link.label)}
                                    </Link>
                                ) : (
                                    <span
                                        key={index}
                                        className="border-border inline-flex min-h-10 min-w-10 items-center justify-center rounded-lg border px-3 text-sm opacity-50"
                                    >
                                        {paginationLabel(link.label)}
                                    </span>
                                ),
                            )}
                        </nav>
                    )}
                </section>
            </div>
        </WorkspaceLayout>
    );
}

function paginationLabel(value: string) {
    return value
        .replace(/<[^>]*>/g, '')
        .replaceAll('&laquo;', '«')
        .replaceAll('&raquo;', '»')
        .trim();
}
