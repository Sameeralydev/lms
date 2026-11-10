import { Head, router } from '@inertiajs/react';
import { useState } from 'react';
import { PageHeader } from '@/components/ui/page-header';
import { WorkspaceLayout } from '@/layouts/workspace-layout';

type Exam = {
    sessionId: number;
    title: string;
    course: string;
    paperTitle: string;
    instructions: string | null;
    startsAt: string;
    endsAt: string;
    durationMinutes: number;
    canStart: boolean;
    attemptId: number | null;
    attemptStatus: string | null;
    receiptReference: string | null;
};

export default function AvailableExaminations({
    tenant,
    exams,
}: {
    tenant: { slug: string };
    exams: Exam[];
}) {
    const [starting, setStarting] = useState<number | null>(null);
    const base = `/t/${tenant.slug}`;

    return (
        <WorkspaceLayout area="tenant" title="Online examinations">
            <Head title="Online examinations" />
            <PageHeader
                title="Online examinations"
                description="Published formal examinations registered to you."
            />
            {exams.length === 0 ? (
                <div className="bg-surface border-border rounded-xl border p-6" role="status">
                    No registered online examinations are available.
                </div>
            ) : (
                <ul className="grid gap-4 lg:grid-cols-2">
                    {exams.map((exam) => (
                        <li key={exam.sessionId} className="bg-surface border-border rounded-xl border p-5">
                            <h2 className="text-lg font-semibold">{exam.title}</h2>
                            <p className="text-text-secondary mt-1">
                                {exam.course} · {exam.paperTitle}
                            </p>
                            {exam.instructions ? (
                                <p className="mt-4 whitespace-pre-wrap">{exam.instructions}</p>
                            ) : null}
                            <dl className="mt-4 grid grid-cols-2 gap-3 text-sm">
                                <div>
                                    <dt className="text-text-secondary">Opens</dt>
                                    <dd>{new Date(exam.startsAt).toLocaleString()}</dd>
                                </div>
                                <div>
                                    <dt className="text-text-secondary">Closes</dt>
                                    <dd>{new Date(exam.endsAt).toLocaleString()}</dd>
                                </div>
                                <div>
                                    <dt className="text-text-secondary">Duration</dt>
                                    <dd>
                                        {exam.durationMinutes} minutes, adjusted by approved accommodation
                                    </dd>
                                </div>
                                <div>
                                    <dt className="text-text-secondary">Status</dt>
                                    <dd>
                                        {exam.attemptStatus ??
                                            (exam.canStart ? 'Ready to start' : 'Not yet available')}
                                    </dd>
                                </div>
                            </dl>
                            {exam.receiptReference ? (
                                <p className="bg-canvas mt-4 rounded-lg p-3 text-sm">
                                    Submission receipt: <strong>{exam.receiptReference}</strong>
                                </p>
                            ) : null}
                            {exam.attemptId && !exam.receiptReference ? (
                                <a
                                    className="focus-ring mt-4 inline-flex min-h-11 items-center rounded-lg border px-4"
                                    href={`${base}/assessments/attempts/${exam.attemptId}`}
                                >
                                    Resume examination
                                </a>
                            ) : null}
                            {!exam.attemptId && exam.canStart ? (
                                <button
                                    type="button"
                                    disabled={starting === exam.sessionId}
                                    onClick={() => {
                                        setStarting(exam.sessionId);
                                        router.post(
                                            `${base}/exams/sessions/${exam.sessionId}/start`,
                                            {},
                                            { onFinish: () => setStarting(null) },
                                        );
                                    }}
                                    className="focus-ring bg-primary text-primary-foreground mt-4 min-h-11 rounded-lg px-5 font-medium disabled:opacity-60"
                                >
                                    {starting === exam.sessionId ? 'Starting…' : 'Start examination'}
                                </button>
                            ) : null}
                        </li>
                    ))}
                </ul>
            )}
        </WorkspaceLayout>
    );
}
