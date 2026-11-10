import { Head, useForm } from '@inertiajs/react';
import { WorkspaceLayout } from '@/layouts/workspace-layout';
import { Button } from '@/components/ui/button';

type Review = { revision_number: number };
type ResponseRow = {
    id: number;
    points_awarded: string | null;
    grading_reviews: Review[];
    attempt: {
        id: number;
        student: { legal_first_name: string; legal_last_name: string };
        quiz_version: { title: string };
    };
    question_version: {
        content: { prompt?: string; passage?: string };
        default_points: string;
        question_type: string;
    };
};
type Props = { tenant: { slug: string }; responses: { data: ResponseRow[] } };

export default function AssessmentGrading({ tenant, responses }: Props) {
    const base = `/t/${tenant.slug}/assessments`;
    return (
        <WorkspaceLayout title="Assessment grading" area="tenant">
            <main id="main-content" className="mx-auto max-w-6xl space-y-6 px-4 py-8 sm:px-6">
                <Head title="Assessment grading" />
                <header>
                    <h1 className="text-2xl font-semibold">Manual grading queue</h1>
                    <p className="text-text-muted mt-1 text-sm">
                        Submitted responses requiring instructor review. Saved marks are versioned and
                        audited.
                    </p>
                </header>
                <nav aria-label="Assessment workspace" className="flex flex-wrap gap-4 text-sm">
                    <a className="text-primary underline" href={`${base}/`}>
                        Assessments
                    </a>
                    <a className="text-primary underline" href={`${base}/analytics`}>
                        Analytics
                    </a>
                    <a className="text-primary underline" href={`${base}/regrades`}>
                        Regrade requests
                    </a>
                </nav>
                <section className="space-y-4">
                    {responses.data.map((row) => (
                        <GradeCard key={row.id} row={row} base={base} />
                    ))}
                    {responses.data.length === 0 && (
                        <p className="border-border rounded-xl border border-dashed p-6">
                            No submitted responses are waiting for review.
                        </p>
                    )}
                </section>
            </main>
        </WorkspaceLayout>
    );
}

function GradeCard({ row, base }: { row: ResponseRow; base: string }) {
    const revision = Math.max(0, ...row.grading_reviews.map((review) => review.revision_number));
    const form = useForm({
        points: row.points_awarded ?? '0.00',
        feedback: '',
        private_notes: '',
        expected_revision: revision,
    });
    return (
        <article className="border-border bg-surface space-y-4 rounded-2xl border p-5">
            <header>
                <p className="text-text-muted text-sm">
                    {row.attempt.quiz_version.title} · {row.attempt.student.legal_first_name}{' '}
                    {row.attempt.student.legal_last_name}
                </p>
                <h2 className="mt-1 font-semibold">
                    {String(row.question_version.content.prompt ?? 'Written response')}
                </h2>
                {Boolean(row.question_version.content.passage) && (
                    <p className="bg-surface-soft mt-2 rounded-lg p-3 text-sm whitespace-pre-wrap">
                        {row.question_version.content.passage}
                    </p>
                )}
            </header>
            <p className="border-border rounded-lg border p-4 whitespace-pre-wrap">
                {String(
                    (row as unknown as { response_payload?: { text?: string } }).response_payload?.text ??
                        'Response file or structured response is not available in this review surface.',
                )}
            </p>
            <form
                className="grid gap-3 md:grid-cols-2"
                onSubmit={(event) => {
                    event.preventDefault();
                    form.post(`${base}/responses/${row.id}/grade`, { preserveScroll: true });
                }}
            >
                <label className="text-sm">
                    Points (maximum {row.question_version.default_points})
                    <input
                        className="border-border bg-canvas mt-1 min-h-11 w-full rounded-lg border px-3"
                        type="number"
                        min="0"
                        max={row.question_version.default_points}
                        step="0.01"
                        value={form.data.points}
                        onChange={(event) => form.setData('points', event.target.value)}
                        required
                    />
                </label>
                <label className="text-sm">
                    Feedback for learner
                    <textarea
                        className="border-border bg-canvas mt-1 min-h-20 w-full rounded-lg border p-3"
                        maxLength={4000}
                        value={form.data.feedback}
                        onChange={(event) => form.setData('feedback', event.target.value)}
                    />
                </label>
                <label className="text-sm md:col-span-2">
                    Private moderation note
                    <textarea
                        className="border-border bg-canvas mt-1 min-h-16 w-full rounded-lg border p-3"
                        maxLength={4000}
                        value={form.data.private_notes}
                        onChange={(event) => form.setData('private_notes', event.target.value)}
                    />
                </label>
                <div className="md:col-span-2">
                    <Button type="submit" disabled={form.processing}>
                        Save grade revision
                    </Button>
                </div>
            </form>
        </article>
    );
}
