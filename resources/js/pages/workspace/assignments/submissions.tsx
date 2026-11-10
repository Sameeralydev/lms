import { Head, router, useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';
import { EmptyState } from '@/components/ui/empty-state';
import { FormErrors } from '@/components/ui/form-errors';
import { PageHeader } from '@/components/ui/page-header';
import { WorkspaceLayout } from '@/layouts/workspace-layout';

type Artifact = { name: string; scan_status: string; download_url: string | null };
type Criterion = { id: number; title: string; maximum_points: number };
type IntegrityCase = {
    id: number;
    status: string;
    case_type: string;
    concern_summary: string;
    learner_response: string | null;
    disposition: string | null;
};
type Grade = {
    id: number;
    status: string;
    lock_version: number;
    score_points: number | string | null;
    criterion_scores: Record<string, number | string> | null;
    learner_feedback: string | null;
    private_notes: string | null;
    marked_by: number;
} | null;
type Attempt = {
    id: number;
    number: number;
    status: string;
    submitted_at: string | null;
    late: boolean;
    response_text: string | null;
    response_url: string | null;
    artifacts: Artifact[];
    grade: Grade;
    integrity_cases: IntegrityCase[];
};
type Submission = {
    id: number;
    status: string;
    student: string | null;
    student_number: string | null;
    attempts: Attempt[];
};
type Props = {
    tenant: { slug: string };
    assignment: {
        id: number;
        title: string;
        course: string;
        maximum_points: number | string | null;
        rubric_criteria: Criterion[];
    };
    permissions: {
        grade: boolean;
        release: boolean;
        integrity_report: boolean;
        integrity_manage: boolean;
        actor_id: number;
    };
    submissions: { data: Submission[]; links: { url: string | null; label: string; active: boolean }[] };
};

function IntegrityCaseCard({
    integrityCase,
    tenant,
    permissions,
}: {
    integrityCase: IntegrityCase;
    tenant: Props['tenant'];
    permissions: Props['permissions'];
}) {
    const form = useForm({ disposition: 'cleared', review_notes: '' });
    const decide = (event: FormEvent) => {
        event.preventDefault();
        form.post(`/t/${tenant.slug}/assignments/integrity-cases/${integrityCase.id}/decision`, {
            preserveScroll: true,
        });
    };
    return (
        <article className="border-border bg-surface-soft grid gap-2 rounded-lg border p-3">
            <h4 className="font-semibold">Integrity case · {integrityCase.case_type.replaceAll('_', ' ')}</h4>
            <p className="text-sm">{integrityCase.concern_summary}</p>
            {integrityCase.learner_response && (
                <p className="bg-surface mt-1 rounded-lg p-3 text-sm">
                    <strong>Learner response: </strong>
                    {integrityCase.learner_response}
                </p>
            )}
            <p className="text-text-muted text-xs">
                Status: {integrityCase.status.replaceAll('_', ' ')}
                {integrityCase.disposition
                    ? ` · Decision: ${integrityCase.disposition.replaceAll('_', ' ')}`
                    : ''}
            </p>
            {permissions.integrity_manage &&
                ['awaiting_learner', 'under_review'].includes(integrityCase.status) && (
                    <form onSubmit={decide} className="grid gap-2">
                        <FormErrors errors={form.errors} />
                        <label className="grid gap-1 text-sm">
                            Disposition
                            <select
                                value={form.data.disposition}
                                onChange={(event) => form.setData('disposition', event.currentTarget.value)}
                                className="border-border min-h-10 rounded-lg border px-3"
                            >
                                <option value="cleared">Concern not substantiated</option>
                                <option value="substantiated">Concern substantiated</option>
                                <option value="no_action">No action</option>
                            </select>
                        </label>
                        <label className="grid gap-1 text-sm">
                            Reasoned review notes
                            <textarea
                                required
                                minLength={12}
                                rows={3}
                                value={form.data.review_notes}
                                onChange={(event) => form.setData('review_notes', event.currentTarget.value)}
                                className="border-border rounded-lg border p-3"
                            />
                        </label>
                        <button
                            disabled={form.processing}
                            className="border-border focus-ring min-h-10 justify-self-start rounded-lg border px-3 text-sm font-semibold"
                        >
                            Record decision
                        </button>
                    </form>
                )}
        </article>
    );
}

function AttemptReview({
    attempt,
    tenant,
    maximumPoints,
    criteria,
    permissions,
}: {
    attempt: Attempt;
    tenant: Props['tenant'];
    maximumPoints: number | string | null;
    criteria: Criterion[];
    permissions: Props['permissions'];
}) {
    const gradeForm = useForm({
        expected_version: attempt.grade?.lock_version ?? 0,
        score_points: attempt.grade?.score_points?.toString() ?? '',
        criterion_scores: Object.fromEntries(
            criteria.map((criterion) => [
                String(criterion.id),
                attempt.grade?.criterion_scores?.[String(criterion.id)]?.toString() ?? '',
            ]),
        ),
        learner_feedback: attempt.grade?.learner_feedback ?? '',
        private_notes: attempt.grade?.private_notes ?? '',
    });
    const returnForm = useForm({ learner_feedback: '' });
    const integrityForm = useForm({ case_type: 'authorship', concern_summary: '' });
    const grade = (event: FormEvent) => {
        event.preventDefault();
        gradeForm.post(`/t/${tenant.slug}/assignments/attempts/${attempt.id}/grade`, {
            preserveScroll: true,
        });
    };
    const returnForRevision = (event: FormEvent) => {
        event.preventDefault();
        returnForm.post(`/t/${tenant.slug}/assignments/attempts/${attempt.id}/return`, {
            preserveScroll: true,
        });
    };
    const reportIntegrity = (event: FormEvent) => {
        event.preventDefault();
        integrityForm.post(`/t/${tenant.slug}/assignments/attempts/${attempt.id}/integrity-cases`, {
            preserveScroll: true,
            onSuccess: () => integrityForm.reset('concern_summary'),
        });
    };
    const release = () => {
        if (attempt.grade)
            router.post(
                `/t/${tenant.slug}/assignments/grades/${attempt.grade.id}/release`,
                {},
                { preserveScroll: true },
            );
    };
    const criterionTotal = Object.values(gradeForm.data.criterion_scores).reduce(
        (sum, score) => sum + (Number(score) || 0),
        0,
    );

    return (
        <li className="border-border rounded-xl border p-4">
            <div className="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <h3 className="font-semibold">
                        Attempt {attempt.number}
                        {attempt.late ? ' · Late' : ''}
                    </h3>
                    <p className="text-text-muted mt-1 text-xs">
                        {attempt.submitted_at
                            ? new Date(attempt.submitted_at).toLocaleString()
                            : attempt.status.replaceAll('_', ' ')}
                    </p>
                </div>
                <span className="text-text-muted text-sm capitalize">
                    {attempt.grade?.status ?? 'Awaiting review'}
                </span>
            </div>
            {attempt.response_text && (
                <div className="bg-surface-soft mt-3 rounded-lg p-3">
                    <h4 className="text-xs font-semibold uppercase">Learner response</h4>
                    <p className="mt-2 text-sm whitespace-pre-wrap">{attempt.response_text}</p>
                </div>
            )}
            {attempt.response_url && (
                <p className="mt-3 text-sm">
                    Reference:{' '}
                    <a
                        className="text-brand-700 focus-ring rounded underline"
                        href={attempt.response_url}
                        target="_blank"
                        rel="noreferrer"
                    >
                        Open learner reference
                    </a>
                </p>
            )}
            {attempt.artifacts.length > 0 && (
                <ul className="mt-3 grid gap-2" aria-label="Submission files">
                    {attempt.artifacts.map((artifact) => (
                        <li
                            key={artifact.name}
                            className="flex flex-wrap items-center justify-between gap-2 text-sm"
                        >
                            <span>{artifact.name}</span>
                            <span className="text-text-muted">{artifact.scan_status}</span>
                            {artifact.download_url && (
                                <a
                                    className="text-brand-700 focus-ring rounded underline"
                                    href={artifact.download_url}
                                >
                                    Download
                                </a>
                            )}
                        </li>
                    ))}
                </ul>
            )}
            {attempt.grade?.private_notes && (
                <p className="mt-3 rounded-lg bg-amber-50 p-3 text-sm text-amber-950">
                    <strong>Private instructor notes: </strong>
                    {attempt.grade.private_notes}
                </p>
            )}
            {attempt.status === 'submitted' && permissions.grade && (
                <div className="mt-4 grid gap-4 lg:grid-cols-2">
                    <form onSubmit={grade} className="border-border grid gap-3 rounded-lg border p-3">
                        <h4 className="font-semibold">Save draft grade</h4>
                        <FormErrors errors={gradeForm.errors} />
                        {criteria.length > 0 ? (
                            <fieldset className="grid gap-3">
                                <legend className="text-sm font-semibold">Published rubric criteria</legend>
                                {criteria.map((criterion) => (
                                    <label key={criterion.id} className="grid gap-1 text-sm">
                                        {criterion.title} (max {criterion.maximum_points})
                                        <input
                                            aria-label={`${criterion.title} points`}
                                            type="number"
                                            min="0"
                                            max={criterion.maximum_points}
                                            step="0.01"
                                            required
                                            value={gradeForm.data.criterion_scores[String(criterion.id)]}
                                            onChange={(event) =>
                                                gradeForm.setData('criterion_scores', {
                                                    ...gradeForm.data.criterion_scores,
                                                    [String(criterion.id)]: event.currentTarget.value,
                                                })
                                            }
                                            className="border-border min-h-10 rounded-lg border px-3"
                                        />
                                    </label>
                                ))}
                                <p className="text-text-muted text-sm">
                                    Calculated total: {criterionTotal.toFixed(2)} / {maximumPoints ?? 0}
                                </p>
                            </fieldset>
                        ) : (
                            <label className="grid gap-1 text-sm">
                                Points
                                <input
                                    aria-label="Points"
                                    type="number"
                                    min="0"
                                    max={maximumPoints ?? 999999.99}
                                    step="0.01"
                                    required
                                    value={gradeForm.data.score_points}
                                    onChange={(event) =>
                                        gradeForm.setData('score_points', event.currentTarget.value)
                                    }
                                    className="border-border min-h-10 rounded-lg border px-3"
                                />
                            </label>
                        )}
                        <label className="grid gap-1 text-sm">
                            Learner feedback
                            <textarea
                                required
                                rows={3}
                                value={gradeForm.data.learner_feedback}
                                onChange={(event) =>
                                    gradeForm.setData('learner_feedback', event.currentTarget.value)
                                }
                                className="border-border rounded-lg border p-3"
                            />
                        </label>
                        <label className="grid gap-1 text-sm">
                            Private instructor notes
                            <textarea
                                rows={2}
                                value={gradeForm.data.private_notes}
                                onChange={(event) =>
                                    gradeForm.setData('private_notes', event.currentTarget.value)
                                }
                                className="border-border rounded-lg border p-3"
                            />
                        </label>
                        <button
                            type="submit"
                            disabled={gradeForm.processing}
                            className="bg-brand-600 focus-ring min-h-10 rounded-lg px-3 text-sm font-semibold text-white"
                        >
                            Save grade privately
                        </button>
                    </form>
                    <form
                        onSubmit={returnForRevision}
                        className="border-border grid content-start gap-3 rounded-lg border p-3"
                    >
                        <h4 className="font-semibold">Return for revision</h4>
                        <FormErrors errors={returnForm.errors} />
                        <label className="grid gap-1 text-sm">
                            Revision feedback
                            <textarea
                                required
                                minLength={8}
                                rows={5}
                                value={returnForm.data.learner_feedback}
                                onChange={(event) =>
                                    returnForm.setData('learner_feedback', event.currentTarget.value)
                                }
                                className="border-border rounded-lg border p-3"
                            />
                        </label>
                        <button
                            type="submit"
                            disabled={returnForm.processing}
                            className="border-border focus-ring min-h-10 rounded-lg border px-3 text-sm font-semibold"
                        >
                            Request a revision
                        </button>
                    </form>
                </div>
            )}
            {attempt.grade?.status === 'draft' && permissions.release && (
                <div className="mt-3">
                    <button
                        type="button"
                        onClick={release}
                        disabled={attempt.grade.marked_by === permissions.actor_id}
                        className="border-border focus-ring min-h-10 rounded-lg border px-3 text-sm font-semibold disabled:opacity-50"
                    >
                        {attempt.grade.marked_by === permissions.actor_id
                            ? 'A separate reviewer must release this grade'
                            : 'Release grade and feedback'}
                    </button>
                </div>
            )}
            {attempt.integrity_cases.length > 0 && (
                <section className="mt-4 grid gap-3" aria-label="Integrity review cases">
                    {attempt.integrity_cases.map((integrityCase) => (
                        <IntegrityCaseCard
                            key={integrityCase.id}
                            integrityCase={integrityCase}
                            tenant={tenant}
                            permissions={permissions}
                        />
                    ))}
                </section>
            )}
            {attempt.status === 'submitted' && permissions.integrity_report && (
                <form
                    onSubmit={reportIntegrity}
                    className="border-border mt-4 grid gap-3 rounded-lg border p-3"
                >
                    <h4 className="font-semibold">Request an integrity review</h4>
                    <p className="text-text-muted text-sm">
                        A human reviewer will assess this concern. Automated similarity analysis is not
                        configured.
                    </p>
                    <FormErrors errors={integrityForm.errors} />
                    <label className="grid gap-1 text-sm">
                        Concern type
                        <select
                            value={integrityForm.data.case_type}
                            onChange={(event) =>
                                integrityForm.setData('case_type', event.currentTarget.value)
                            }
                            className="border-border min-h-10 rounded-lg border px-3"
                        >
                            <option value="authorship">Authorship concern</option>
                            <option value="collaboration">Unauthorized collaboration</option>
                            <option value="source_use">Source attribution</option>
                            <option value="assessment_conduct">Assessment conduct</option>
                            <option value="other">Other</option>
                        </select>
                    </label>
                    <label className="grid gap-1 text-sm">
                        Evidence-based summary
                        <textarea
                            required
                            minLength={12}
                            maxLength={5000}
                            rows={3}
                            value={integrityForm.data.concern_summary}
                            onChange={(event) =>
                                integrityForm.setData('concern_summary', event.currentTarget.value)
                            }
                            className="border-border rounded-lg border p-3"
                        />
                    </label>
                    <button
                        disabled={integrityForm.processing}
                        className="border-border focus-ring min-h-10 justify-self-start rounded-lg border px-3 text-sm font-semibold"
                    >
                        Open review case
                    </button>
                </form>
            )}
        </li>
    );
}

export default function AssignmentSubmissions({ tenant, assignment, permissions, submissions }: Props) {
    return (
        <WorkspaceLayout title="Submission inbox" area="tenant">
            <Head title={`Submissions · ${assignment.title}`} />
            <PageHeader
                eyebrow={assignment.course}
                title="Submission inbox"
                description={`Review learner work for ${assignment.title}. Private notes stay instructor-only; grades require an independent release.`}
            />
            <section
                className="border-border bg-surface mt-6 rounded-2xl border p-4 sm:p-6"
                aria-label="Learner submissions"
            >
                {submissions.data.length === 0 ? (
                    <EmptyState
                        title="No submissions yet"
                        description="Submitted work from eligible section members will appear here."
                    />
                ) : (
                    <div className="grid gap-4">
                        {submissions.data.map((submission) => (
                            <article key={submission.id} className="border-border rounded-xl border p-4">
                                <h2 className="font-semibold">{submission.student || 'Learner'}</h2>
                                <p className="text-text-muted mt-1 text-xs">
                                    {submission.student_number ?? ''} ·{' '}
                                    {submission.status.replaceAll('_', ' ')}
                                </p>
                                {submission.attempts.length === 0 ? (
                                    <p className="text-text-muted mt-3 text-sm">No attempt submitted.</p>
                                ) : (
                                    <ol className="mt-4 grid gap-3">
                                        {submission.attempts.map((attempt) => (
                                            <AttemptReview
                                                key={attempt.id}
                                                attempt={attempt}
                                                tenant={tenant}
                                                maximumPoints={assignment.maximum_points}
                                                criteria={assignment.rubric_criteria}
                                                permissions={permissions}
                                            />
                                        ))}
                                    </ol>
                                )}
                            </article>
                        ))}
                    </div>
                )}
            </section>
        </WorkspaceLayout>
    );
}
