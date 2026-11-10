import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { Head, router, useForm } from '@inertiajs/react';
import { WorkspaceLayout } from '@/layouts/workspace-layout';
import { AssessmentTimer } from '@/components/assessment-timer';
import { Button } from '@/components/ui/button';

type SnapshotQuestion = {
    question_version_id: number;
    question_type: string;
    position: number;
    points: string;
    content: Record<string, unknown>;
};
type AnswerValue = string | number | boolean | null | AnswerValue[] | { [key: string]: AnswerValue };
type AttemptResponse = {
    id: number;
    question_version_id: number;
    response_payload: Record<string, AnswerValue> | null;
    lock_version: number;
    marked_for_review: boolean;
    saved_at: string | null;
    file_artifact?: { name: string; url: string };
    points_awarded?: string | null;
    grading_reviews?: { learner_feedback: string | null }[];
    question_version: { question_type: string; content: Record<string, unknown> };
};
type Attempt = {
    id: number;
    status: string;
    started_at: string;
    expires_at: string | null;
    submitted_at: string | null;
    result_released_at?: string | null;
    receipt_reference?: string | null;
    score_points?: string;
    maximum_points?: string;
    percentage?: string;
    question_snapshot: SnapshotQuestion[];
    responses: AttemptResponse[];
    regrade_requests?: { status: string; reason: string }[];
    quiz_version: {
        title: string;
        instructions: string;
        settings: { navigation?: string; result_visibility?: string };
    };
};
type Props = {
    tenant: { slug: string };
    attempt: Attempt;
    exam?: {
        title: string;
        course: string;
        instructions: string | null;
        endsAt: string;
        receiptReference: string | null;
    } | null;
    questionAssets: Record<string, { id: number; name: string; url: string }[]>;
};

export default function AssessmentAttempt({ tenant, attempt, questionAssets, exam }: Props) {
    const responseByQuestion = useMemo(
        () => new Map(attempt.responses.map((response) => [response.question_version_id, response])),
        [attempt.responses],
    );
    const [answers, setAnswers] = useState<Record<number, Record<string, AnswerValue>>>(() =>
        Object.fromEntries(
            attempt.responses.map((response) => [
                response.question_version_id,
                response.response_payload ?? {},
            ]),
        ),
    );
    const [saved, setSaved] = useState<Record<number, boolean>>({});
    const [dirty, setDirty] = useState<Set<number>>(() => new Set());
    const [error, setError] = useState('');
    const [uploadingQuestion, setUploadingQuestion] = useState<number | null>(null);
    const answersRef = useRef(answers);
    const responseVersions = useRef(
        new Map(attempt.responses.map((response) => [response.question_version_id, response.lock_version])),
    );
    const savingQuestions = useRef(new Set<number>());
    const retryAfterSave = useRef(new Set<number>());
    const saveRef = useRef<(questionId: number) => void>(() => undefined);
    const [remaining, setRemaining] = useState(() =>
        attempt.expires_at
            ? Math.max(0, Math.floor((new Date(attempt.expires_at).getTime() - Date.now()) / 1000))
            : null,
    );
    const autoSubmitStarted = useRef(false);
    const regradeForm = useForm({ reason: '' });
    const base = `/t/${tenant.slug}/assessments`;

    useEffect(() => {
        if (!attempt.expires_at || attempt.status !== 'in_progress') return;
        const timer = window.setInterval(
            () =>
                setRemaining(
                    Math.max(
                        0,
                        Math.floor((new Date(attempt.expires_at as string).getTime() - Date.now()) / 1000),
                    ),
                ),
            1000,
        );
        return () => window.clearInterval(timer);
    }, [attempt.expires_at, attempt.status]);

    useEffect(() => {
        if (remaining !== 0 || attempt.status !== 'in_progress' || autoSubmitStarted.current) return;
        autoSubmitStarted.current = true;
        setError('Time expired. The server is finalizing your saved responses.');
        router.post(
            `${base}/attempts/${attempt.id}/submit`,
            {},
            { onError: (errors) => setError(Object.values(errors).join(' ')) },
        );
    }, [attempt.id, attempt.status, base, remaining]);

    function update(questionId: number, next: Record<string, AnswerValue>) {
        answersRef.current = { ...answersRef.current, [questionId]: next };
        setAnswers((current) => ({ ...current, [questionId]: next }));
        setSaved((current) => ({ ...current, [questionId]: false }));
        setDirty((current) => new Set(current).add(questionId));
    }

    const save = useCallback(
        (questionId: number) => {
            const response = responseByQuestion.get(questionId);
            if (!response || savingQuestions.current.has(questionId)) return;
            const payload = answersRef.current[questionId] ?? {};
            const expectedVersion = responseVersions.current.get(questionId) ?? response.lock_version;
            savingQuestions.current.add(questionId);
            retryAfterSave.current.delete(questionId);
            let saveSucceeded = false;
            setError('');
            router.patch(
                `${base}/attempts/${attempt.id}/questions/${questionId}`,
                { expected_version: expectedVersion, response: payload },
                {
                    preserveScroll: true,
                    onSuccess: () => {
                        saveSucceeded = true;
                        responseVersions.current.set(questionId, expectedVersion + 1);
                        if (
                            JSON.stringify(answersRef.current[questionId] ?? {}) === JSON.stringify(payload)
                        ) {
                            setSaved((current) => ({ ...current, [questionId]: true }));
                            setDirty((current) => {
                                const next = new Set(current);
                                next.delete(questionId);
                                return next;
                            });
                        } else {
                            retryAfterSave.current.add(questionId);
                        }
                    },
                    onError: (errors) => setError(Object.values(errors).join(' ')),
                    onFinish: () => {
                        savingQuestions.current.delete(questionId);
                        if (
                            saveSucceeded &&
                            (retryAfterSave.current.has(questionId) ||
                                JSON.stringify(answersRef.current[questionId] ?? {}) !==
                                    JSON.stringify(payload))
                        ) {
                            retryAfterSave.current.delete(questionId);
                            window.setTimeout(() => saveRef.current(questionId), 0);
                        }
                    },
                },
            );
        },
        [attempt.id, base, responseByQuestion],
    );

    useEffect(() => {
        saveRef.current = save;
    }, [save]);

    useEffect(() => {
        if (dirty.size === 0 || attempt.status !== 'in_progress') return;
        const timeout = window.setTimeout(() => dirty.forEach((questionId) => save(questionId)), 800);
        return () => window.clearTimeout(timeout);
    }, [answers, attempt.status, dirty, save]);

    function submit(confirm = true) {
        if (
            confirm &&
            !window.confirm('Submit this assessment? You cannot change responses after submission.')
        )
            return;
        router.post(
            `${base}/attempts/${attempt.id}/submit`,
            {},
            { onError: (errors) => setError(Object.values(errors).join(' ')) },
        );
    }

    function responseControl(question: SnapshotQuestion) {
        const answer = answers[question.question_version_id] ?? {};
        const response = responseByQuestion.get(question.question_version_id);
        const set = (key: string, value: AnswerValue) =>
            update(question.question_version_id, { ...answer, [key]: value });
        const content = question.content;
        const options = Array.isArray(content.options)
            ? (content.options as { id: string; text: string }[])
            : [];
        if (['single_choice', 'multiple_choice', 'image_based'].includes(question.question_type)) {
            const selected = Array.isArray(answer.selected_ids) ? answer.selected_ids.map(String) : [];
            return (
                <fieldset className="space-y-2">
                    <legend className="sr-only">Answer options</legend>
                    {options.map((option) => (
                        <label
                            key={option.id}
                            className="border-border flex min-h-11 items-center gap-3 rounded-lg border p-3"
                        >
                            <input
                                type={question.question_type === 'single_choice' ? 'radio' : 'checkbox'}
                                name={`question-${question.question_version_id}`}
                                checked={selected.includes(option.id)}
                                onChange={(event) =>
                                    set(
                                        'selected_ids',
                                        question.question_type === 'single_choice'
                                            ? [option.id]
                                            : event.target.checked
                                              ? [...selected, option.id]
                                              : selected.filter((id) => id !== option.id),
                                    )
                                }
                            />
                            <span>{option.text}</span>
                        </label>
                    ))}
                </fieldset>
            );
        }
        if (question.question_type === 'true_false')
            return (
                <fieldset className="flex gap-5">
                    <legend className="sr-only">True or false</legend>
                    {[true, false].map((value) => (
                        <label key={String(value)} className="flex min-h-11 items-center gap-2">
                            <input
                                type="radio"
                                name={`question-${question.question_version_id}`}
                                checked={answer.value === value}
                                onChange={() => set('value', value)}
                            />
                            {value ? 'True' : 'False'}
                        </label>
                    ))}
                </fieldset>
            );
        if (question.question_type === 'matching') {
            const pairs = Array.isArray(content.pairs)
                ? (content.pairs as { id: string; left: string; right: string }[])
                : [];
            const matches = (answer.matches ?? {}) as Record<string, string>;
            return (
                <div className="space-y-3">
                    {pairs.map((pair) => (
                        <label key={pair.id} className="grid gap-2 sm:grid-cols-2">
                            <span className="self-center">{pair.left}</span>
                            <select
                                className="border-border bg-canvas min-h-11 rounded-lg border px-3"
                                value={matches[pair.id] ?? ''}
                                onChange={(event) =>
                                    set('matches', { ...matches, [pair.id]: event.target.value })
                                }
                            >
                                <option value="">Choose a match</option>
                                {pairs.map((item) => (
                                    <option key={item.id} value={item.id}>
                                        {item.right}
                                    </option>
                                ))}
                            </select>
                        </label>
                    ))}
                </div>
            );
        }
        if (question.question_type === 'ordering') {
            const items = Array.isArray(content.items)
                ? (content.items as { id: string; text: string }[])
                : [];
            const order = Array.isArray(answer.order)
                ? answer.order.map(String)
                : items.map((item) => item.id);
            return (
                <ol className="space-y-2">
                    {order.map((id, index) => {
                        const item = items.find((candidate) => candidate.id === id);
                        return (
                            <li
                                key={id}
                                className="border-border flex min-h-11 items-center gap-2 rounded-lg border p-2"
                            >
                                <span className="flex-1">{item?.text}</span>
                                <button
                                    type="button"
                                    className="focus-visible:outline-focus border-border min-h-9 rounded border px-3"
                                    aria-label={`Move item ${index + 1} up`}
                                    disabled={index === 0}
                                    onClick={() => {
                                        const next = [...order];
                                        [next[index - 1], next[index]] = [next[index], next[index - 1]];
                                        set('order', next);
                                    }}
                                >
                                    ↑
                                </button>
                                <button
                                    type="button"
                                    className="focus-visible:outline-focus border-border min-h-9 rounded border px-3"
                                    aria-label={`Move item ${index + 1} down`}
                                    disabled={index === order.length - 1}
                                    onClick={() => {
                                        const next = [...order];
                                        [next[index + 1], next[index]] = [next[index], next[index + 1]];
                                        set('order', next);
                                    }}
                                >
                                    ↓
                                </button>
                            </li>
                        );
                    })}
                </ol>
            );
        }
        if (['fill_blank', 'multiple_blanks'].includes(question.question_type)) {
            const keys = Array.isArray(content.blank_keys) ? content.blank_keys.map(String) : [];
            const values = (answer.values ?? {}) as Record<string, string>;
            return (
                <div className="space-y-3">
                    {keys.map((key) => (
                        <label key={key} className="block text-sm font-medium">
                            {key}
                            <input
                                className="border-border bg-canvas mt-1 min-h-11 w-full rounded-lg border px-3"
                                value={values[key] ?? ''}
                                onChange={(event) => set('values', { ...values, [key]: event.target.value })}
                            />
                        </label>
                    ))}
                </div>
            );
        }
        if (question.question_type === 'numeric')
            return (
                <label className="block max-w-sm text-sm font-medium">
                    Your answer
                    <input
                        inputMode="decimal"
                        className="border-border bg-canvas mt-1 min-h-11 w-full rounded-lg border px-3"
                        value={String(answer.value ?? '')}
                        onChange={(event) => set('value', event.target.value)}
                    />
                </label>
            );
        if (question.question_type === 'file_response')
            return (
                <div className="space-y-3 rounded-lg border p-4">
                    <label className="block text-sm font-medium">
                        Upload your response file (PDF, image, or document; maximum 10 MB)
                        <input
                            className="border-border mt-2 block min-h-11 w-full rounded-lg border p-2"
                            type="file"
                            accept=".pdf,.jpg,.jpeg,.png,.webp,.txt,.doc,.docx,.odt"
                            disabled={
                                attempt.status !== 'in_progress' ||
                                uploadingQuestion === question.question_version_id
                            }
                            onChange={(event) => {
                                const file = event.currentTarget.files?.[0];
                                if (!file || !response) return;
                                setUploadingQuestion(question.question_version_id);
                                setError('');
                                router.post(
                                    `${base}/attempts/${attempt.id}/questions/${question.question_version_id}/file`,
                                    { file, expected_version: response.lock_version },
                                    {
                                        forceFormData: true,
                                        preserveScroll: true,
                                        onError: (errors) => setError(Object.values(errors).join(' ')),
                                        onFinish: () => setUploadingQuestion(null),
                                        onSuccess: () =>
                                            router.reload({ only: ['attempt', 'questionAssets'] }),
                                    },
                                );
                                event.currentTarget.value = '';
                            }}
                        />
                    </label>
                    <p className="text-text-muted text-sm" role="status">
                        {uploadingQuestion === question.question_version_id
                            ? 'Uploading and scanning privately…'
                            : 'The file remains private until the scanner approves it.'}
                    </p>
                    {response?.file_artifact && (
                        <p className="text-sm">
                            Saved response:{' '}
                            <a className="text-primary underline" href={response.file_artifact.url}>
                                {response.file_artifact.name}
                            </a>
                        </p>
                    )}
                </div>
            );
        return (
            <label className="block text-sm font-medium">
                Your response
                <textarea
                    className="border-border bg-canvas mt-1 min-h-32 w-full rounded-lg border p-3"
                    maxLength={question.question_type === 'short_text' ? 4000 : 30000}
                    value={String(answer.text ?? '')}
                    onChange={(event) => set('text', event.target.value)}
                />
            </label>
        );
    }

    return (
        <WorkspaceLayout title={attempt.quiz_version.title} area="tenant">
            <Head title={attempt.quiz_version.title} />
            <main id="main-content" className="mx-auto max-w-4xl space-y-6 px-4 py-8 sm:px-6">
                <header className="border-border bg-surface rounded-2xl border p-5">
                    <p className="text-text-muted text-sm">
                        {exam ? 'Formal examination' : 'Assessment attempt'}
                    </p>
                    <h1 className="mt-1 text-2xl font-semibold">
                        {exam?.title ?? attempt.quiz_version.title}
                    </h1>
                    {exam ? (
                        <p className="text-text-muted mt-1 text-sm">
                            {exam.course} · Official session closes {new Date(exam.endsAt).toLocaleString()}
                        </p>
                    ) : null}
                    <p className="text-text-muted mt-2 text-sm whitespace-pre-wrap">
                        {exam?.instructions ?? attempt.quiz_version.instructions}
                    </p>
                    <AssessmentTimer remaining={remaining} />
                </header>
                {error && (
                    <p role="alert" className="rounded-xl border border-red-500/50 bg-red-500/5 p-4">
                        {error}
                    </p>
                )}
                {attempt.status !== 'in_progress' && (
                    <section
                        className="border-border bg-surface rounded-xl border p-4"
                        aria-label="Submission receipt"
                    >
                        <h2 className="font-semibold">Submission receipt</h2>
                        <p className="mt-1">This attempt is {attempt.status.replaceAll('_', ' ')}.</p>
                        {attempt.receipt_reference && (
                            <p className="text-text-muted mt-1 text-sm">
                                Receipt reference: {attempt.receipt_reference}
                            </p>
                        )}
                        {!attempt.result_released_at && (
                            <p className="text-text-muted mt-2 text-sm">
                                Your score and feedback remain hidden until the instructor releases your
                                result.
                            </p>
                        )}
                    </section>
                )}
                <nav
                    aria-label="Question progress"
                    className="border-border bg-surface flex flex-wrap gap-2 rounded-xl border p-3"
                >
                    {attempt.question_snapshot.map((question, index) => {
                        const response = responseByQuestion.get(question.question_version_id);
                        return (
                            <a
                                key={question.question_version_id}
                                href={`#question-${question.question_version_id}`}
                                className="border-border focus-visible:outline-focus min-h-10 rounded-lg border px-3 py-2 text-sm"
                                aria-label={`Question ${index + 1}${response?.marked_for_review ? ' · Review' : ''}${response?.saved_at ? ' · Saved' : ''}`}
                            >
                                Question {index + 1}
                                {response?.marked_for_review ? ' · Review' : ''}
                                {response?.saved_at ? ' · Saved' : ''}
                            </a>
                        );
                    })}
                </nav>
                {attempt.question_snapshot.map((question, index) => {
                    const response = responseByQuestion.get(question.question_version_id);
                    const savedState = saved[question.question_version_id] ?? Boolean(response?.saved_at);
                    return (
                        <section
                            key={question.question_version_id}
                            id={`question-${question.question_version_id}`}
                            aria-labelledby={`question-heading-${question.question_version_id}`}
                            className="border-border bg-surface scroll-mt-6 space-y-4 rounded-2xl border p-5"
                        >
                            <div className="flex justify-between gap-3">
                                <h2
                                    id={`question-heading-${question.question_version_id}`}
                                    className="font-semibold"
                                >
                                    Question {index + 1}
                                </h2>
                                <span className="text-text-muted text-sm">{question.points} points</span>
                            </div>
                            {Boolean(question.content.passage) && (
                                <div className="bg-surface-soft rounded-xl p-4">
                                    <h3 className="font-medium">Case passage</h3>
                                    <p className="mt-2 whitespace-pre-wrap">
                                        {String(question.content.passage)}
                                    </p>
                                </div>
                            )}
                            <p className="whitespace-pre-wrap">{String(question.content.prompt ?? '')}</p>
                            {(questionAssets[String(question.question_version_id)] ?? []).map((asset) => (
                                <figure key={asset.id} className="space-y-2">
                                    {['jpg', 'png', 'webp'].includes(
                                        asset.name.split('.').pop()?.toLowerCase() ?? '',
                                    ) ? (
                                        <img
                                            className="max-h-96 rounded-xl object-contain"
                                            src={asset.url}
                                            alt={String(question.content.image_alt ?? asset.name)}
                                        />
                                    ) : (
                                        <a className="text-primary underline" href={asset.url}>
                                            {asset.name}
                                        </a>
                                    )}
                                    <figcaption className="text-text-muted text-sm">{asset.name}</figcaption>
                                </figure>
                            ))}
                            {responseControl(question)}
                            {attempt.status === 'in_progress' && (
                                <div className="flex flex-wrap items-center gap-3">
                                    <Button
                                        variant="secondary"
                                        onClick={() => save(question.question_version_id)}
                                        disabled={remaining === 0 || !dirty.has(question.question_version_id)}
                                    >
                                        Save response
                                    </Button>
                                    <Button
                                        variant="quiet"
                                        aria-pressed={Boolean(response?.marked_for_review)}
                                        onClick={() =>
                                            router.put(
                                                `${base}/attempts/${attempt.id}/questions/${question.question_version_id}/review-mark`,
                                                { marked: !response?.marked_for_review },
                                                { preserveScroll: true },
                                            )
                                        }
                                    >
                                        {response?.marked_for_review
                                            ? 'Remove review mark'
                                            : 'Mark for review'}
                                    </Button>
                                    {savedState && (
                                        <span role="status" className="text-text-muted text-sm">
                                            Saved on server
                                        </span>
                                    )}
                                </div>
                            )}
                            {attempt.result_released_at &&
                                attempt.quiz_version.settings.result_visibility === 'score_and_feedback' &&
                                response?.grading_reviews?.[0]?.learner_feedback && (
                                    <p className="bg-surface-soft rounded-lg p-3 text-sm">
                                        Instructor feedback: {response.grading_reviews[0].learner_feedback}
                                    </p>
                                )}
                        </section>
                    );
                })}
                {attempt.result_released_at && (
                    <section
                        aria-labelledby="released-result-heading"
                        className="border-border bg-surface space-y-3 rounded-2xl border p-5"
                    >
                        <h2 id="released-result-heading" className="text-lg font-semibold">
                            Released result
                        </h2>
                        <p className="text-xl font-semibold">
                            {attempt.score_points} / {attempt.maximum_points} points · {attempt.percentage}%
                        </p>
                        <p className="text-text-muted text-sm">Receipt: {attempt.receipt_reference}</p>
                        {attempt.regrade_requests?.map((request, index) => (
                            <p
                                key={`${request.status}-${index}`}
                                role="status"
                                className="bg-surface-soft rounded-lg p-3 text-sm"
                            >
                                Regrade request: {request.status.replaceAll('_', ' ')}
                            </p>
                        ))}
                        {!attempt.regrade_requests?.some((request) =>
                            ['open', 'approved'].includes(request.status),
                        ) && (
                            <form
                                className="border-border space-y-3 border-t pt-4"
                                onSubmit={(event) => {
                                    event.preventDefault();
                                    regradeForm.post(`${base}/attempts/${attempt.id}/regrade`, {
                                        preserveScroll: true,
                                    });
                                }}
                            >
                                <label className="block text-sm font-medium">
                                    Request a regrade
                                    <textarea
                                        className="border-border bg-canvas mt-1 min-h-24 w-full rounded-lg border p-3"
                                        minLength={10}
                                        maxLength={2000}
                                        value={regradeForm.data.reason}
                                        onChange={(event) =>
                                            regradeForm.setData('reason', event.target.value)
                                        }
                                        required
                                    />
                                </label>
                                <Button type="submit" variant="secondary" disabled={regradeForm.processing}>
                                    Submit regrade request
                                </Button>
                            </form>
                        )}
                    </section>
                )}
                {attempt.status === 'in_progress' && (
                    <div className="flex flex-wrap justify-end gap-3">
                        <Button
                            variant="secondary"
                            onClick={() =>
                                router.visit(
                                    exam ? `/t/${tenant.slug}/exams` : `/t/${tenant.slug}/assessments`,
                                )
                            }
                        >
                            {exam ? 'Return to examinations' : 'Return to assessments'}
                        </Button>
                        <Button onClick={() => submit()} disabled={dirty.size > 0}>
                            Submit assessment
                        </Button>
                        {dirty.size > 0 && (
                            <span role="status" className="text-text-muted self-center text-sm">
                                Saving responses…
                            </span>
                        )}
                    </div>
                )}
            </main>
        </WorkspaceLayout>
    );
}
