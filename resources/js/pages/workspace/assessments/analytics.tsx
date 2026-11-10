import { Head, router } from '@inertiajs/react';
import { WorkspaceLayout } from '@/layouts/workspace-layout';
import { Button } from '@/components/ui/button';

type Report = {
    filters: { quizId: number | null; sectionId: number | null };
    sections: { id: number; label: string }[];
    quizzes: { id: number; title: string; status: string }[];
    metrics: {
        quizCount: number;
        attempts: number;
        submitted: number;
        completionRate: number | null;
        pendingManualReview: number;
        releasedCohortSize: number | null;
        suppressedForSmallCohort: boolean;
        averagePercentage: number | null;
        medianPercentage: number | null;
        scoreDistribution: Record<string, number>;
    };
};
export default function AssessmentAnalytics({
    tenant,
    report,
}: {
    tenant: { slug: string };
    report: Report;
}) {
    const base = `/t/${tenant.slug}/assessments`;
    return (
        <WorkspaceLayout title="Assessment analytics" area="tenant">
            <main id="main-content" className="mx-auto max-w-6xl space-y-6 px-4 py-8 sm:px-6">
                <Head title="Assessment analytics" />
                <header>
                    <h1 className="text-2xl font-semibold">Assessment analytics</h1>
                    <p className="text-text-muted mt-1 text-sm">
                        Metrics use persisted attempts and released marks. Cohort score summaries are
                        suppressed below five learners.
                    </p>
                </header>
                <nav aria-label="Assessment workspace" className="flex flex-wrap gap-4 text-sm">
                    <a className="text-primary underline" href={`${base}/`}>
                        Assessments
                    </a>
                    <a className="text-primary underline" href={`${base}/grading`}>
                        Grading queue
                    </a>
                    <a className="text-primary underline" href={`${base}/regrades`}>
                        Regrade requests
                    </a>
                </nav>
                <form
                    className="flex flex-wrap items-end gap-3"
                    onSubmit={(event) => {
                        event.preventDefault();
                        const data = new FormData(event.currentTarget);
                        const params = new URLSearchParams();
                        const quizId = String(data.get('quiz_id') ?? '');
                        const sectionId = String(data.get('section_id') ?? '');
                        if (quizId) params.set('quiz_id', quizId);
                        if (sectionId) params.set('section_id', sectionId);
                        router.get(`${base}/analytics?${params.toString()}`);
                    }}
                >
                    <label className="text-sm">
                        Quiz
                        <select
                            name="quiz_id"
                            defaultValue={report.filters.quizId ?? ''}
                            className="border-border bg-canvas mt-1 min-h-11 rounded-lg border px-3"
                        >
                            <option value="">All accessible quizzes</option>
                            {report.quizzes.map((quiz) => (
                                <option key={quiz.id} value={quiz.id}>
                                    {quiz.title}
                                </option>
                            ))}
                        </select>
                    </label>
                    <label className="text-sm">
                        Section
                        <select
                            name="section_id"
                            defaultValue={report.filters.sectionId ?? ''}
                            className="border-border bg-canvas mt-1 min-h-11 rounded-lg border px-3"
                        >
                            <option value="">All accessible sections</option>
                            {report.sections.map((section) => (
                                <option key={section.id} value={section.id}>
                                    {section.label}
                                </option>
                            ))}
                        </select>
                    </label>
                    <Button variant="secondary">Apply filter</Button>
                </form>
                <section aria-label="Assessment metrics" className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                    {[
                        ['Quizzes', report.metrics.quizCount],
                        ['Attempts', report.metrics.attempts],
                        ['Submitted', report.metrics.submitted],
                        ['Pending review', report.metrics.pendingManualReview],
                    ].map(([label, value]) => (
                        <article
                            key={String(label)}
                            className="border-border bg-surface rounded-xl border p-4"
                        >
                            <p className="text-text-muted text-sm">{label}</p>
                            <p className="mt-2 text-2xl font-semibold">{value}</p>
                        </article>
                    ))}
                </section>
                <section className="border-border bg-surface rounded-2xl border p-5">
                    <h2 className="font-semibold">Completion and released scores</h2>
                    <p className="mt-2 text-sm">
                        Completion rate:{' '}
                        {report.metrics.completionRate === null
                            ? 'No attempts'
                            : `${report.metrics.completionRate}%`}
                    </p>
                    {report.metrics.suppressedForSmallCohort ? (
                        <p className="bg-surface-soft mt-3 rounded-lg p-3 text-sm">
                            Released score summaries are withheld for groups smaller than five.
                        </p>
                    ) : (
                        report.metrics.releasedCohortSize !== null && (
                            <>
                                <p className="mt-3 text-sm">
                                    Average: {report.metrics.averagePercentage}% · Median:{' '}
                                    {report.metrics.medianPercentage}% · Released cohort:{' '}
                                    {report.metrics.releasedCohortSize}
                                </p>
                                <ul className="mt-3 grid gap-2 sm:grid-cols-3">
                                    {Object.entries(report.metrics.scoreDistribution).map(
                                        ([bucket, count]) => (
                                            <li key={bucket} className="border-border rounded-lg border p-3">
                                                {bucket}%: {count}
                                            </li>
                                        ),
                                    )}
                                </ul>
                            </>
                        )
                    )}
                </section>
            </main>
        </WorkspaceLayout>
    );
}
