import { useState } from 'react';
import type { FormEvent } from 'react';
import { Link, router, useForm } from '@inertiajs/react';
import type { FormDataConvertible } from '@inertiajs/core';
import { PageHeader } from '@/components/ui/page-header';
import { WorkspaceLayout } from '@/layouts/workspace-layout';
import { Button } from '@/components/ui/button';
import {
    QuestionDefinitionEditor,
    type QuestionAnswerKey,
    type QuestionContent,
    type QuestionScoringRules,
} from '@/pages/workspace/assessments/question-definition-editor';

type Section = { id: number; code: string; course: string | null; offeringId: number };
type Bank = {
    id: number;
    name: string;
    questions: {
        id: number;
        questionId: number;
        status: string;
        type: string;
        prompt: string;
        content: Record<string, JsonValue>;
        answerKey: QuestionAnswerKey | null;
        scoringRules: QuestionScoringRules;
        difficulty: string;
        defaultPoints: string;
        estimatedSeconds: number;
        latestStatus: string;
        latestVersionId: number;
        latestContent: Record<string, JsonValue>;
        latestPrompt: string;
        latestType: string;
        hasPendingRevision: boolean;
    }[];
};
type QuizRow = {
    id: number;
    status: string;
    versions: {
        title: string;
        total_points: string;
        settings: { available_at?: string | null; closes_at?: string | null };
    }[];
    offering?: { course?: { title?: string } };
};
type QuizCard = {
    id: number;
    title: string;
    course: string;
    mode: string;
    availableAt: string | null;
    closesAt: string | null;
    timeLimitSeconds: number;
    maximumAttempts: number;
    maximumPoints: string;
    canStart: boolean;
    hasAttempt: boolean;
    activeAttemptId: number | null;
    attemptsRemaining: number;
};
type Props = {
    mode: 'student' | 'instructor';
    tenant: { slug: string };
    upcoming?: QuizCard[];
    available?: QuizCard[];
    completed?: {
        data: {
            id: number;
            title: string;
            status: string;
            released: boolean;
            scorePoints?: string;
            maximumPoints?: string;
            percentage?: string;
        }[];
    };
    sections?: Section[];
    quizzes?: { data: QuizRow[] };
    banks?: Bank[];
    policy?: { settings: Record<string, unknown>; lockVersion: number; campusId: number | null };
    permissions?: {
        manage: boolean;
        manageAll: boolean;
        bankManage: boolean;
        bankShare?: boolean;
        policyManage: boolean;
        bankReview?: boolean;
        quizReview?: boolean;
        publish?: boolean;
        grade?: boolean;
        release?: boolean;
    };
};
type JsonValue = string | number | boolean | null | JsonValue[] | { [key: string]: JsonValue };

const questionTypes = [
    'single_choice',
    'multiple_choice',
    'true_false',
    'matching',
    'ordering',
    'fill_blank',
    'short_text',
    'long_response',
    'numeric',
    'multiple_blanks',
    'case_passage',
    'image_based',
    'file_response',
    'instructor_review',
];
const exampleContent: Record<string, unknown> = {
    single_choice: {
        prompt: 'Question prompt',
        options: [
            { id: 'a', text: 'First option' },
            { id: 'b', text: 'Second option' },
        ],
    },
    multiple_choice: {
        prompt: 'Select all that apply',
        options: [
            { id: 'a', text: 'First option' },
            { id: 'b', text: 'Second option' },
        ],
    },
    true_false: { prompt: 'Statement to evaluate' },
    matching: {
        prompt: 'Match each pair',
        pairs: [
            { id: 'one', left: 'Left item', right: 'Right item' },
            { id: 'two', left: 'Second left', right: 'Second right' },
        ],
    },
    ordering: {
        prompt: 'Order the following',
        items: [
            { id: 'one', text: 'First item' },
            { id: 'two', text: 'Second item' },
        ],
    },
    fill_blank: { prompt: 'Complete the answer', blank_keys: ['answer'] },
    multiple_blanks: { prompt: 'Complete each blank', blank_keys: ['first', 'second'] },
    short_text: { prompt: 'Enter a short response' },
    long_response: { prompt: 'Explain your reasoning' },
    numeric: { prompt: 'Calculate the result' },
    case_passage: { prompt: '', passage: 'Case or reading passage' },
    image_based: {
        prompt: 'Answer using the image',
        image_alt: 'Describe the image',
        options: [
            { id: 'a', text: 'First option' },
            { id: 'b', text: 'Second option' },
        ],
    },
    file_response: { prompt: 'Upload a response' },
    instructor_review: { prompt: 'Respond for instructor review' },
};

function defaultAnswerKey(type: string): Record<string, unknown> {
    if (type === 'true_false') return { value: true };
    if (type === 'matching') return { matches: { one: 'one', two: 'two' } };
    if (type === 'ordering') return { order: ['one', 'two'] };
    if (type === 'fill_blank') return { values: { answer: [''] } };
    if (type === 'multiple_blanks') return { values: { first: [''], second: [''] } };
    if (type === 'numeric') return { value: '' };
    if (type === 'short_text') return { accepted_answers: [] };
    if (['long_response', 'file_response', 'instructor_review', 'case_passage'].includes(type)) return {};

    return { correct_option_ids: ['a'] };
}

function parseObject(value: string): Record<string, unknown> {
    try {
        const parsed: unknown = JSON.parse(value);
        return parsed !== null && typeof parsed === 'object' && !Array.isArray(parsed)
            ? (parsed as Record<string, unknown>)
            : {};
    } catch {
        return {};
    }
}

function AssessmentIndex({
    mode,
    tenant,
    upcoming = [],
    available = [],
    completed,
    sections = [],
    quizzes = { data: [] },
    banks = [],
    policy,
    permissions,
}: Props) {
    const base = `/t/${tenant.slug}/assessments`;
    const [questionType, setQuestionType] = useState('single_choice');
    const [questionContent, setQuestionContent] = useState(
        JSON.stringify(exampleContent.single_choice, null, 2),
    );
    const [answerKey, setAnswerKey] = useState('{"correct_option_ids":["a"]}');
    const [scoringRules, setScoringRules] = useState('{}');
    const [revisionQuestion, setRevisionQuestion] = useState<{
        bankId: number;
        questionId: number;
        type: string;
        content: QuestionContent;
        answerKey: QuestionAnswerKey;
        scoringRules: QuestionScoringRules;
    } | null>(null);
    const [selectedBank, setSelectedBank] = useState<number | null>(null);
    const [selectedQuestions, setSelectedQuestions] = useState<number[]>([]);
    const [selectedSection, setSelectedSection] = useState<number | null>(sections[0]?.id ?? null);
    const [poolQuizId, setPoolQuizId] = useState<number | null>(null);
    const [poolQuestionIds, setPoolQuestionIds] = useState<number[]>([]);
    const [poolKey, setPoolKey] = useState('randomized-set');
    const [poolCount, setPoolCount] = useState(1);
    const [poolPoints, setPoolPoints] = useState('1.00');
    const [poolDifficulty, setPoolDifficulty] = useState('');
    const [poolTopic, setPoolTopic] = useState('');
    const [error, setError] = useState('');
    const bankForm = useForm({
        code: '',
        name: '',
        description: '',
        sharing_scope: 'campus' as 'campus' | 'institution',
    });
    const quizForm = useForm({
        title: '',
        instructions: '',
        offering_id: sections[0]?.offeringId ?? 0,
        section_ids: sections[0] ? [sections[0].id] : [],
        questions: [] as { question_version_id: number; points: string }[],
        mode: 'graded',
        category: 'formative',
        description: '',
        maximum_attempts: 1,
        time_limit_minutes: 30,
        cooldown_minutes: 0,
        grading_policy: 'best',
        feedback_release: 'manual',
        result_visibility: 'score_only',
        navigation: 'free',
        available_at: '',
        closes_at: '',
        pass_threshold: '',
        shuffle_questions: false,
        shuffle_options: false,
    });
    const policyForm = useForm({
        expected_version: policy?.lockVersion ?? 0,
        campus_id: policy?.campusId ?? null,
        settings: {
            maximum_attempts: Number(policy?.settings.maximum_attempts ?? 3),
            maximum_cooldown_minutes: Number(policy?.settings.maximum_cooldown_minutes ?? 0),
            require_independent_review: Boolean(policy?.settings.require_independent_review ?? true),
            require_independent_publisher: Boolean(policy?.settings.require_independent_publisher ?? true),
            default_feedback_release: String(policy?.settings.default_feedback_release ?? 'manual'),
            default_result_visibility: String(policy?.settings.default_result_visibility ?? 'score_only'),
        },
    });

    function submit(
        event: FormEvent,
        url: string,
        data: Record<string, FormDataConvertible>,
        onSuccess?: () => void,
    ) {
        event.preventDefault();
        setError('');
        router.post(url, data, {
            preserveScroll: true,
            onSuccess,
            onError: (errors) => setError(Object.values(errors).join(' ')),
        });
    }

    function createQuestion(event: FormEvent, bankId: number) {
        event.preventDefault();
        setError('');
        try {
            const content = JSON.parse(questionContent) as Record<string, JsonValue>;
            const key = answerKey.trim() ? (JSON.parse(answerKey) as Record<string, JsonValue>) : null;
            const rules = JSON.parse(scoringRules) as Record<string, JsonValue>;
            router.post(
                `${base}/banks/${bankId}/questions`,
                {
                    question_type: questionType,
                    content,
                    answer_key: key,
                    scoring_rules: rules,
                    default_points: '1.00',
                    difficulty: 'moderate',
                    estimated_seconds: 60,
                },
                { preserveScroll: true, onError: (errors) => setError(Object.values(errors).join(' ')) },
            );
        } catch {
            setError('Enter valid JSON for the selected question content and answer key.');
        }
    }

    function reviseQuestion(event: FormEvent) {
        event.preventDefault();
        if (!revisionQuestion) return;
        try {
            router.put(
                `${base}/questions/${revisionQuestion.questionId}`,
                {
                    question_type: revisionQuestion.type,
                    content: revisionQuestion.content as unknown as Record<string, FormDataConvertible>,
                    answer_key: revisionQuestion.answerKey as unknown as Record<string, FormDataConvertible>,
                    scoring_rules: revisionQuestion.scoringRules as unknown as Record<
                        string,
                        FormDataConvertible
                    >,
                },
                {
                    preserveScroll: true,
                    onError: (errors) => setError(Object.values(errors).join(' ')),
                    onSuccess: () => setRevisionQuestion(null),
                },
            );
        } catch {
            setError('The revised question could not be submitted. Check the question fields and try again.');
        }
    }

    function createQuiz(event: FormEvent) {
        event.preventDefault();
        setError('');
        const questionRows = selectedQuestions.map((id) => ({ question_version_id: id, points: '1.00' }));
        quizForm.transform((data) => ({
            ...data,
            section_ids: selectedSection ? [selectedSection] : [],
            offering_id:
                sections.find((section) => section.id === selectedSection)?.offeringId ?? data.offering_id,
            questions: questionRows,
            available_at: data.available_at || null,
            closes_at: data.closes_at || null,
            pass_threshold: data.pass_threshold === '' ? null : Number(data.pass_threshold),
        }));
        quizForm.post(base + '/quizzes', {
            preserveScroll: true,
            onError: (errors) => setError(Object.values(errors).join(' ')),
        });
    }

    function saveQuestionPool(event: FormEvent) {
        event.preventDefault();
        setError('');
        if (!poolQuizId) return;
        router.post(
            `${base}/quizzes/${poolQuizId}/pools`,
            {
                pool_key: poolKey,
                question_version_ids: poolQuestionIds,
                select_count: poolCount,
                points_per_question: poolPoints,
                difficulty: poolDifficulty || null,
                topic: poolTopic || null,
                required: true,
            },
            {
                preserveScroll: true,
                onError: (errors) => setError(Object.values(errors).join(' ')),
                onSuccess: () => setPoolQuestionIds([]),
            },
        );
    }

    function startQuiz(quizId: number) {
        router.post(
            `${base}/quizzes/${quizId}/attempts`,
            { idempotency_key: crypto.randomUUID() },
            { onError: (errors) => setError(Object.values(errors).join(' ')) },
        );
    }

    if (mode === 'student') {
        return (
            <WorkspaceLayout title="Assessments" area="tenant">
                <main id="main-content" className="mx-auto max-w-6xl space-y-8 px-4 py-8 sm:px-6">
                    <PageHeader
                        title="Assessments"
                        description="Your available quizzes, upcoming assessments, attempt history, and released results."
                    />
                    {error && (
                        <p
                            role="alert"
                            className="rounded-xl border border-red-500/50 bg-red-500/5 p-4 text-sm"
                        >
                            {error}
                        </p>
                    )}
                    <section aria-labelledby="available-heading" className="space-y-4">
                        <h2 id="available-heading" className="text-xl font-semibold">
                            Available now
                        </h2>
                        <div className="grid gap-4 md:grid-cols-2">
                            {available.map((quiz) => (
                                <article
                                    key={quiz.id}
                                    className="border-border bg-surface rounded-2xl border p-5 shadow-sm"
                                >
                                    <h3 className="font-semibold">{quiz.title}</h3>
                                    <p className="text-text-muted mt-1 text-sm">
                                        {quiz.course} · {quiz.mode.replaceAll('_', ' ')} ·{' '}
                                        {quiz.maximumPoints} points
                                    </p>
                                    <p className="mt-2 text-sm">
                                        {quiz.timeLimitSeconds
                                            ? `${Math.ceil(quiz.timeLimitSeconds / 60)} minutes`
                                            : 'No time limit'}{' '}
                                        · Up to {quiz.maximumAttempts} attempts
                                    </p>
                                    <Button
                                        className="mt-4"
                                        onClick={() =>
                                            quiz.activeAttemptId
                                                ? router.visit(`${base}/attempts/${quiz.activeAttemptId}`)
                                                : startQuiz(quiz.id)
                                        }
                                    >
                                        {quiz.activeAttemptId ? 'Continue saved attempt' : 'Start assessment'}
                                    </Button>
                                </article>
                            ))}
                        </div>
                        {available.length === 0 && (
                            <p className="border-border text-text-muted rounded-xl border border-dashed p-6">
                                No assessments are available right now.
                            </p>
                        )}
                    </section>
                    <section aria-labelledby="upcoming-heading" className="space-y-4">
                        <h2 id="upcoming-heading" className="text-xl font-semibold">
                            Upcoming and closed
                        </h2>
                        <div className="divide-border border-border bg-surface divide-y rounded-2xl border">
                            {upcoming.map((quiz) => (
                                <div key={quiz.id} className="p-4">
                                    <p className="font-medium">{quiz.title}</p>
                                    <p className="text-text-muted text-sm">
                                        {quiz.course} ·{' '}
                                        {quiz.availableAt
                                            ? new Date(quiz.availableAt).toLocaleString()
                                            : 'Not currently open'}
                                    </p>
                                </div>
                            ))}
                            {upcoming.length === 0 && (
                                <p className="text-text-muted p-5">Nothing is scheduled.</p>
                            )}
                        </div>
                    </section>
                    <section aria-labelledby="results-heading" className="space-y-4">
                        <h2 id="results-heading" className="text-xl font-semibold">
                            Attempt history
                        </h2>
                        <div className="divide-border border-border bg-surface divide-y rounded-2xl border">
                            {(completed?.data ?? []).map((attempt) => (
                                <div key={attempt.id} className="flex flex-wrap justify-between gap-2 p-4">
                                    <span>
                                        {attempt.title} · {attempt.status.replaceAll('_', ' ')}
                                    </span>
                                    <span>
                                        {attempt.released
                                            ? `${attempt.scorePoints} / ${attempt.maximumPoints} · ${attempt.percentage}%`
                                            : 'Result not released'}
                                    </span>
                                </div>
                            ))}
                            {(completed?.data ?? []).length === 0 && (
                                <p className="text-text-muted p-5">Your attempt history will appear here.</p>
                            )}
                        </div>
                        <Link
                            href={`${base}/notifications`}
                            className="text-primary inline-flex text-sm font-medium underline"
                        >
                            Assessment notifications
                        </Link>
                    </section>
                </main>
            </WorkspaceLayout>
        );
    }

    return (
        <WorkspaceLayout title="Assessment administration" area="tenant">
            <main id="main-content" className="mx-auto max-w-7xl space-y-8 px-4 py-8 sm:px-6">
                <PageHeader
                    title="Assessment administration"
                    description="Build reviewed question banks, publish course quizzes, and manage institution policy."
                />
                <nav aria-label="Assessment workspace" className="flex flex-wrap gap-4 text-sm">
                    <Link className="text-primary underline" href={`${base}/grading`}>
                        Manual grading queue
                    </Link>
                    <Link className="text-primary underline" href={`${base}/regrades`}>
                        Regrade requests
                    </Link>
                    <Link className="text-primary underline" href={`${base}/analytics`}>
                        Analytics
                    </Link>
                    <Link className="text-primary underline" href={`${base}/notifications`}>
                        Notifications
                    </Link>
                </nav>
                {error && (
                    <p role="alert" className="rounded-xl border border-red-500/50 bg-red-500/5 p-4 text-sm">
                        {error}
                    </p>
                )}
                <section id="quiz-directory" className="space-y-4">
                    <div className="flex flex-wrap items-end justify-between gap-3">
                        <div>
                            <h2 className="text-xl font-semibold">Course assessments</h2>
                            <p className="text-text-muted text-sm">
                                Drafts are reviewed independently before they can be published.
                            </p>
                        </div>
                        <Link
                            className="text-primary text-sm font-medium underline-offset-4 hover:underline"
                            href={`/t/${tenant.slug}/learning`}
                        >
                            Open course delivery
                        </Link>
                    </div>
                    <div className="bg-surface overflow-hidden rounded-2xl border">
                        <div className="divide-y">
                            {quizzes.data.map((quiz) => {
                                const version = quiz.versions[0];
                                return (
                                    <article
                                        key={quiz.id}
                                        className="flex flex-wrap items-center justify-between gap-4 p-4"
                                    >
                                        <div>
                                            <h3 className="font-semibold">
                                                {version?.title ?? 'Untitled quiz'}
                                            </h3>
                                            <p className="text-text-muted text-sm">
                                                {quiz.offering?.course?.title ?? 'Course'} Ã‚Â· {quiz.status}{' '}
                                                Ã‚Â· {version?.total_points ?? '0.00'} points
                                            </p>
                                        </div>
                                        <div className="flex flex-wrap gap-2">
                                            {quiz.status === 'draft' && permissions?.manage && (
                                                <Button
                                                    variant="secondary"
                                                    onClick={() =>
                                                        router.post(
                                                            `${base}/quizzes/${quiz.id}/submit-review`,
                                                            {},
                                                            { preserveScroll: true },
                                                        )
                                                    }
                                                >
                                                    Request review
                                                </Button>
                                            )}
                                            {quiz.status === 'under_review' && permissions?.quizReview && (
                                                <>
                                                    <Button
                                                        variant="secondary"
                                                        onClick={() =>
                                                            router.post(
                                                                `${base}/quizzes/${quiz.id}/review`,
                                                                { decision: 'approve' },
                                                                { preserveScroll: true },
                                                            )
                                                        }
                                                    >
                                                        Approve
                                                    </Button>
                                                    <Button
                                                        variant="secondary"
                                                        onClick={() =>
                                                            router.post(
                                                                `${base}/quizzes/${quiz.id}/review`,
                                                                { decision: 'reject' },
                                                                { preserveScroll: true },
                                                            )
                                                        }
                                                    >
                                                        Return for changes
                                                    </Button>
                                                </>
                                            )}
                                            {quiz.status === 'approved' && permissions?.publish && (
                                                <Button
                                                    onClick={() =>
                                                        router.post(
                                                            `${base}/quizzes/${quiz.id}/publish`,
                                                            {},
                                                            { preserveScroll: true },
                                                        )
                                                    }
                                                >
                                                    Publish
                                                </Button>
                                            )}
                                            {['published', 'scheduled'].includes(quiz.status) &&
                                                permissions?.publish && (
                                                    <Button
                                                        variant="secondary"
                                                        onClick={() =>
                                                            router.post(
                                                                `${base}/quizzes/${quiz.id}/close`,
                                                                {},
                                                                {
                                                                    preserveScroll: true,
                                                                    onError: (errors) =>
                                                                        setError(
                                                                            Object.values(errors).join(' '),
                                                                        ),
                                                                },
                                                            )
                                                        }
                                                    >
                                                        Close quiz
                                                    </Button>
                                                )}
                                            {quiz.status === 'closed' && permissions?.publish && (
                                                <Button
                                                    variant="secondary"
                                                    onClick={() =>
                                                        router.post(
                                                            `${base}/quizzes/${quiz.id}/archive`,
                                                            {},
                                                            {
                                                                preserveScroll: true,
                                                                onError: (errors) =>
                                                                    setError(Object.values(errors).join(' ')),
                                                            },
                                                        )
                                                    }
                                                >
                                                    Archive quiz
                                                </Button>
                                            )}
                                            {['published', 'closed'].includes(quiz.status) &&
                                                permissions?.release && (
                                                    <Button
                                                        variant="secondary"
                                                        onClick={() =>
                                                            router.post(
                                                                `${base}/quizzes/${quiz.id}/release-results`,
                                                                {},
                                                                { preserveScroll: true },
                                                            )
                                                        }
                                                    >
                                                        Release results
                                                    </Button>
                                                )}
                                        </div>
                                    </article>
                                );
                            })}
                            {quizzes.data.length === 0 && (
                                <p className="text-text-muted p-6">
                                    No quizzes yet. Create a question bank and assessment draft to begin.
                                </p>
                            )}
                        </div>
                    </div>
                </section>
                {permissions?.manage && quizzes.data.some((quiz) => quiz.status === 'draft') && (
                    <section className="border-border bg-surface space-y-4 rounded-2xl border p-5">
                        <div>
                            <h2 className="text-lg font-semibold">Random question pool</h2>
                            <p className="text-text-muted mt-1 text-sm">
                                Choose approved candidates. The server assembles and freezes a secure random
                                selection for each attempt.
                            </p>
                        </div>
                        <form className="grid gap-4 md:grid-cols-2" onSubmit={saveQuestionPool}>
                            <label className="text-sm">
                                Draft quiz
                                <select
                                    className="border-border bg-canvas mt-1 min-h-11 w-full rounded-lg border px-3"
                                    value={poolQuizId ?? ''}
                                    onChange={(event) => setPoolQuizId(Number(event.target.value) || null)}
                                    required
                                >
                                    <option value="">Choose quiz</option>
                                    {quizzes.data
                                        .filter((quiz) => quiz.status === 'draft')
                                        .map((quiz) => (
                                            <option key={quiz.id} value={quiz.id}>
                                                {quiz.versions[0]?.title ?? `Quiz ${quiz.id}`}
                                            </option>
                                        ))}
                                </select>
                            </label>
                            <label className="text-sm">
                                Pool name
                                <input
                                    className="border-border bg-canvas mt-1 min-h-11 w-full rounded-lg border px-3"
                                    value={poolKey}
                                    onChange={(event) => setPoolKey(event.target.value)}
                                    maxLength={64}
                                    required
                                />
                            </label>
                            <label className="text-sm">
                                Questions to draw
                                <input
                                    className="border-border bg-canvas mt-1 min-h-11 w-full rounded-lg border px-3"
                                    type="number"
                                    min={1}
                                    max={50}
                                    value={poolCount}
                                    onChange={(event) => setPoolCount(Number(event.target.value))}
                                    required
                                />
                            </label>
                            <label className="text-sm">
                                Points per selected question
                                <input
                                    className="border-border bg-canvas mt-1 min-h-11 w-full rounded-lg border px-3"
                                    type="number"
                                    min={0.01}
                                    step="0.01"
                                    value={poolPoints}
                                    onChange={(event) => setPoolPoints(event.target.value)}
                                    required
                                />
                            </label>
                            <label className="text-sm">
                                Difficulty balance
                                <select
                                    className="border-border bg-canvas mt-1 min-h-11 w-full rounded-lg border px-3"
                                    value={poolDifficulty}
                                    onChange={(event) => setPoolDifficulty(event.target.value)}
                                >
                                    <option value="">Any approved difficulty</option>
                                    <option value="easy">Easy</option>
                                    <option value="moderate">Moderate</option>
                                    <option value="challenging">Challenging</option>
                                </select>
                            </label>
                            <label className="text-sm">
                                Topic filter
                                <input
                                    className="border-border bg-canvas mt-1 min-h-11 w-full rounded-lg border px-3"
                                    value={poolTopic}
                                    onChange={(event) => setPoolTopic(event.target.value)}
                                    maxLength={120}
                                />
                            </label>
                            <fieldset className="space-y-2 md:col-span-2">
                                <legend className="text-sm font-medium">
                                    Approved candidates ({poolQuestionIds.length} selected)
                                </legend>
                                {banks
                                    .flatMap((bank) =>
                                        bank.questions
                                            .filter((question) => question.status === 'active')
                                            .map((question) => ({ ...question, bankName: bank.name })),
                                    )
                                    .map((question) => (
                                        <label
                                            key={question.id}
                                            className="border-border flex min-h-11 items-center gap-3 rounded-lg border p-3 text-sm"
                                        >
                                            <input
                                                type="checkbox"
                                                checked={poolQuestionIds.includes(question.id)}
                                                onChange={(event) =>
                                                    setPoolQuestionIds((current) =>
                                                        event.target.checked
                                                            ? [...current, question.id]
                                                            : current.filter((id) => id !== question.id),
                                                    )
                                                }
                                            />
                                            <span>{question.prompt}</span>
                                            <span className="text-text-muted ml-auto">
                                                {question.bankName} · {question.type.replaceAll('_', ' ')}
                                            </span>
                                        </label>
                                    ))}
                                {banks.every((bank) =>
                                    bank.questions.every((question) => question.status !== 'active'),
                                ) && (
                                    <p className="border-border rounded-lg border border-dashed p-4 text-sm">
                                        Approve questions before adding them to a pool.
                                    </p>
                                )}
                            </fieldset>
                            <Button
                                type="submit"
                                className="md:col-span-2"
                                disabled={!poolQuizId || poolQuestionIds.length < poolCount}
                            >
                                Save randomized pool
                            </Button>
                        </form>
                    </section>
                )}
                {permissions?.bankReview && (
                    <section aria-labelledby="question-review-heading" className="space-y-3">
                        <h2 id="question-review-heading" className="text-xl font-semibold">
                            Question review queue
                        </h2>
                        {banks
                            .flatMap((bank) =>
                                bank.questions
                                    .filter((question) => question.latestStatus === 'under_review')
                                    .map((question) => ({ ...question, bankName: bank.name })),
                            )
                            .map((question) => (
                                <article
                                    key={question.questionId}
                                    className="border-border bg-surface flex flex-wrap items-center justify-between gap-3 rounded-xl border p-4"
                                >
                                    <div>
                                        <p className="font-medium">{question.latestPrompt}</p>
                                        <p className="text-text-muted text-sm">
                                            {question.bankName} / {question.latestType.replaceAll('_', ' ')}
                                        </p>
                                    </div>
                                    <div className="flex gap-2">
                                        <Button
                                            variant="secondary"
                                            onClick={() =>
                                                router.post(
                                                    `${base}/questions/${question.questionId}/review`,
                                                    { decision: 'approve' },
                                                    { preserveScroll: true },
                                                )
                                            }
                                        >
                                            Approve
                                        </Button>
                                        <Button
                                            variant="secondary"
                                            onClick={() =>
                                                router.post(
                                                    `${base}/questions/${question.questionId}/review`,
                                                    { decision: 'reject' },
                                                    { preserveScroll: true },
                                                )
                                            }
                                        >
                                            Return for changes
                                        </Button>
                                    </div>
                                </article>
                            ))}
                        {banks.every((bank) =>
                            bank.questions.every((question) => question.latestStatus !== 'under_review'),
                        ) && (
                            <p className="border-border text-text-muted rounded-xl border border-dashed p-5">
                                No questions are waiting for review.
                            </p>
                        )}
                    </section>
                )}
                {permissions?.bankManage && (
                    <section className="grid gap-6 xl:grid-cols-2">
                        <article className="bg-surface rounded-2xl border p-5">
                            <h2 className="text-lg font-semibold">Question banks</h2>
                            <div className="mt-4 space-y-4">
                                {banks.map((bank) => (
                                    <div key={bank.id} className="rounded-xl border p-4">
                                        <h3 className="font-medium">{bank.name}</h3>
                                        <p className="text-text-muted text-sm">
                                            {bank.questions.length} question version(s)
                                        </p>
                                        <div className="mt-3 space-y-2">
                                            {bank.questions.map((question) => (
                                                <div
                                                    key={question.id}
                                                    className="flex flex-wrap items-center justify-between gap-3 text-sm"
                                                >
                                                    <span className="line-clamp-1">{question.prompt}</span>
                                                    <span className="text-text-muted shrink-0">
                                                        {question.type.replaceAll('_', ' ')} /{' '}
                                                        {question.status}
                                                    </span>
                                                    {(() => {
                                                        const draftVersionId =
                                                            question.status === 'draft'
                                                                ? question.id
                                                                : question.latestStatus === 'draft'
                                                                  ? question.latestVersionId
                                                                  : null;
                                                        const draftContent =
                                                            question.status === 'draft'
                                                                ? question.content
                                                                : question.latestContent;
                                                        const assetCount = Array.isArray(
                                                            draftContent.asset_ids,
                                                        )
                                                            ? draftContent.asset_ids.length
                                                            : 0;
                                                        return draftVersionId === null ? null : (
                                                            <label className="text-text-muted block min-w-44 text-xs">
                                                                Attach a scanned question file
                                                                <input
                                                                    className="mt-1 block w-full"
                                                                    type="file"
                                                                    accept=".pdf,.jpg,.jpeg,.png,.webp,.txt,.doc,.docx,.odt"
                                                                    onChange={(event) => {
                                                                        const file =
                                                                            event.currentTarget.files?.[0];
                                                                        if (!file) return;
                                                                        router.post(
                                                                            `${base}/question-versions/${draftVersionId}/assets`,
                                                                            { file },
                                                                            {
                                                                                forceFormData: true,
                                                                                preserveScroll: true,
                                                                                onError: (errors) =>
                                                                                    setError(
                                                                                        Object.values(
                                                                                            errors,
                                                                                        ).join(' '),
                                                                                    ),
                                                                                onSuccess: () =>
                                                                                    router.reload({
                                                                                        only: ['banks'],
                                                                                    }),
                                                                            },
                                                                        );
                                                                        event.currentTarget.value = '';
                                                                    }}
                                                                />
                                                                <span className="mt-1 block" role="status">
                                                                    {assetCount} clean asset(s) attached
                                                                </span>
                                                            </label>
                                                        );
                                                    })()}
                                                    {question.status === 'draft' && (
                                                        <Button
                                                            variant="quiet"
                                                            onClick={() =>
                                                                router.post(
                                                                    `${base}/questions/${question.questionId}/submit-review`,
                                                                    {},
                                                                    { preserveScroll: true },
                                                                )
                                                            }
                                                        >
                                                            Request review
                                                        </Button>
                                                    )}
                                                    {question.status === 'active' && (
                                                        <Button
                                                            variant="quiet"
                                                            onClick={() => {
                                                                setRevisionQuestion({
                                                                    bankId: bank.id,
                                                                    questionId: question.questionId,
                                                                    type: question.type,
                                                                    content:
                                                                        question.content as QuestionContent,
                                                                    answerKey: question.answerKey ?? {},
                                                                    scoringRules: question.scoringRules,
                                                                });
                                                            }}
                                                        >
                                                            Revise
                                                        </Button>
                                                    )}
                                                    {question.status === 'active' &&
                                                        question.latestStatus === 'draft' && (
                                                            <Button
                                                                variant="quiet"
                                                                onClick={() =>
                                                                    router.post(
                                                                        `${base}/questions/${question.questionId}/submit-review`,
                                                                        {},
                                                                        {
                                                                            preserveScroll: true,
                                                                            onError: (errors) =>
                                                                                setError(
                                                                                    Object.values(
                                                                                        errors,
                                                                                    ).join(' '),
                                                                                ),
                                                                        },
                                                                    )
                                                                }
                                                            >
                                                                Request revision review
                                                            </Button>
                                                        )}
                                                </div>
                                            ))}
                                        </div>
                                        {revisionQuestion?.bankId === bank.id && (
                                            <form
                                                onSubmit={reviseQuestion}
                                                className="mt-4 space-y-3 border-t pt-4"
                                            >
                                                <h4 className="font-medium">Create immutable revision</h4>
                                                <p className="text-text-muted text-xs">
                                                    The active version remains unchanged until independent
                                                    review.
                                                </p>
                                                {revisionQuestion && (
                                                    <>
                                                        <label className="block text-sm font-medium">
                                                            Revised question type
                                                            <select
                                                                className="bg-canvas mt-1 min-h-11 w-full rounded-lg border px-3"
                                                                value={revisionQuestion.type}
                                                                onChange={(event) =>
                                                                    setRevisionQuestion({
                                                                        ...revisionQuestion,
                                                                        type: event.target.value,
                                                                        content: {
                                                                            prompt:
                                                                                revisionQuestion.content
                                                                                    .prompt ?? '',
                                                                        },
                                                                        answerKey: defaultAnswerKey(
                                                                            event.target.value,
                                                                        ),
                                                                        scoringRules:
                                                                            event.target.value === 'numeric'
                                                                                ? { tolerance: '0' }
                                                                                : {},
                                                                    })
                                                                }
                                                            >
                                                                {questionTypes.map((type) => (
                                                                    <option key={type} value={type}>
                                                                        {type.replaceAll('_', ' ')}
                                                                    </option>
                                                                ))}
                                                            </select>
                                                        </label>
                                                        <QuestionDefinitionEditor
                                                            questionType={revisionQuestion.type}
                                                            content={revisionQuestion.content}
                                                            answerKey={revisionQuestion.answerKey}
                                                            scoringRules={revisionQuestion.scoringRules}
                                                            onContentChange={(content) =>
                                                                setRevisionQuestion({
                                                                    ...revisionQuestion,
                                                                    content,
                                                                })
                                                            }
                                                            onAnswerKeyChange={(answerKey) =>
                                                                setRevisionQuestion({
                                                                    ...revisionQuestion,
                                                                    answerKey,
                                                                })
                                                            }
                                                            onScoringRulesChange={(scoringRules) =>
                                                                setRevisionQuestion({
                                                                    ...revisionQuestion,
                                                                    scoringRules,
                                                                })
                                                            }
                                                        />
                                                    </>
                                                )}
                                                <div className="flex gap-2">
                                                    <Button type="submit">Save revision</Button>
                                                    <Button
                                                        type="button"
                                                        variant="secondary"
                                                        onClick={() => setRevisionQuestion(null)}
                                                    >
                                                        Cancel
                                                    </Button>
                                                </div>
                                            </form>
                                        )}
                                        {selectedBank === bank.id && (
                                            <form
                                                onSubmit={(event) => createQuestion(event, bank.id)}
                                                className="mt-4 space-y-3 border-t pt-4"
                                            >
                                                <label className="block text-sm font-medium">
                                                    Question type
                                                    <select
                                                        className="bg-canvas mt-1 w-full rounded-lg border p-2"
                                                        value={questionType}
                                                        onChange={(event) => {
                                                            setQuestionType(event.target.value);
                                                            setQuestionContent(
                                                                JSON.stringify(
                                                                    exampleContent[event.target.value],
                                                                    null,
                                                                    2,
                                                                ),
                                                            );
                                                            setAnswerKey(
                                                                JSON.stringify(
                                                                    defaultAnswerKey(event.target.value),
                                                                    null,
                                                                    2,
                                                                ),
                                                            );
                                                            setScoringRules(
                                                                JSON.stringify(
                                                                    event.target.value === 'numeric'
                                                                        ? { tolerance: '0' }
                                                                        : {},
                                                                    null,
                                                                    2,
                                                                ),
                                                            );
                                                        }}
                                                        aria-label="Question type"
                                                    >
                                                        {questionTypes.map((type) => (
                                                            <option key={type} value={type}>
                                                                {type.replaceAll('_', ' ')}
                                                            </option>
                                                        ))}
                                                    </select>
                                                </label>
                                                <QuestionDefinitionEditor
                                                    questionType={questionType}
                                                    content={parseObject(questionContent) as QuestionContent}
                                                    answerKey={parseObject(answerKey) as QuestionAnswerKey}
                                                    scoringRules={
                                                        parseObject(scoringRules) as QuestionScoringRules
                                                    }
                                                    onContentChange={(content) =>
                                                        setQuestionContent(JSON.stringify(content, null, 2))
                                                    }
                                                    onAnswerKeyChange={(key) =>
                                                        setAnswerKey(JSON.stringify(key, null, 2))
                                                    }
                                                    onScoringRulesChange={(rules) =>
                                                        setScoringRules(JSON.stringify(rules, null, 2))
                                                    }
                                                />
                                                <Button type="submit">Save draft question</Button>
                                            </form>
                                        )}
                                        <Button
                                            variant="secondary"
                                            className="mt-3"
                                            onClick={() =>
                                                setSelectedBank(selectedBank === bank.id ? null : bank.id)
                                            }
                                        >
                                            {selectedBank === bank.id ? 'Close authoring' : 'Add question'}
                                        </Button>
                                    </div>
                                ))}
                                {banks.length === 0 && (
                                    <p className="text-text-muted text-sm">
                                        Create a bank before authoring questions.
                                    </p>
                                )}
                            </div>
                            <form
                                onSubmit={(event) =>
                                    submit(event, base + '/banks', bankForm.data, () => bankForm.reset())
                                }
                                className="mt-5 space-y-3 border-t pt-5"
                            >
                                <h3 className="font-medium">Create a question bank</h3>
                                <label className="block text-sm">
                                    Bank code
                                    <input
                                        className="bg-canvas mt-1 w-full rounded-lg border p-2"
                                        maxLength={64}
                                        value={bankForm.data.code}
                                        onChange={(event) => bankForm.setData('code', event.target.value)}
                                        required
                                    />
                                </label>
                                <label className="block text-sm">
                                    Bank name
                                    <input
                                        className="bg-canvas mt-1 w-full rounded-lg border p-2"
                                        maxLength={160}
                                        value={bankForm.data.name}
                                        onChange={(event) => bankForm.setData('name', event.target.value)}
                                        required
                                    />
                                </label>
                                <label className="block text-sm">
                                    Sharing scope
                                    <select
                                        className="bg-canvas mt-1 w-full rounded-lg border p-2"
                                        value={bankForm.data.sharing_scope}
                                        onChange={(event) =>
                                            bankForm.setData(
                                                'sharing_scope',
                                                event.target.value as 'campus' | 'institution',
                                            )
                                        }
                                    >
                                        <option value="campus">Assigned campus</option>
                                        {permissions?.bankShare && (
                                            <option value="institution">Institution</option>
                                        )}
                                    </select>
                                </label>
                                <Button type="submit" disabled={bankForm.processing}>
                                    Create bank
                                </Button>
                            </form>
                        </article>
                        <article className="bg-surface rounded-2xl border p-5">
                            <h2 className="text-lg font-semibold">Create quiz draft</h2>
                            <form onSubmit={createQuiz} className="mt-4 space-y-3">
                                <label className="block text-sm">
                                    Quiz title
                                    <input
                                        className="bg-canvas mt-1 w-full rounded-lg border p-2"
                                        value={quizForm.data.title}
                                        onChange={(event) => quizForm.setData('title', event.target.value)}
                                        required
                                        maxLength={200}
                                    />
                                </label>
                                <label className="block text-sm">
                                    Course section
                                    <select
                                        className="bg-canvas mt-1 w-full rounded-lg border p-2"
                                        value={selectedSection ?? ''}
                                        onChange={(event) => {
                                            const sectionId = Number(event.target.value);
                                            setSelectedSection(sectionId);
                                            quizForm.setData('section_ids', [sectionId]);
                                            quizForm.setData(
                                                'offering_id',
                                                sections.find((section) => section.id === sectionId)
                                                    ?.offeringId ?? 0,
                                            );
                                        }}
                                        required
                                    >
                                        <option value="">Choose section</option>
                                        {sections.map((section) => (
                                            <option key={section.id} value={section.id}>
                                                {section.course} Ã‚Â· {section.code}
                                            </option>
                                        ))}
                                    </select>
                                </label>
                                <fieldset className="space-y-2">
                                    <legend className="text-sm font-medium">Approved questions</legend>
                                    {banks
                                        .flatMap((bank) =>
                                            bank.questions.filter((question) => question.status === 'active'),
                                        )
                                        .map((question) => (
                                            <label
                                                key={question.id}
                                                className="flex items-start gap-2 rounded-lg border p-3 text-sm"
                                            >
                                                <input
                                                    type="checkbox"
                                                    checked={selectedQuestions.includes(question.id)}
                                                    onChange={(event) =>
                                                        setSelectedQuestions((current) =>
                                                            event.target.checked
                                                                ? [...current, question.id]
                                                                : current.filter((id) => id !== question.id),
                                                        )
                                                    }
                                                />
                                                <span>
                                                    {question.prompt}{' '}
                                                    <span className="text-text-muted">
                                                        ({question.type.replaceAll('_', ' ')})
                                                    </span>
                                                </span>
                                            </label>
                                        ))}
                                    {banks.every((bank) => bank.questions.length === 0) && (
                                        <p className="text-text-muted text-sm">
                                            Approve questions before adding them to a quiz.
                                        </p>
                                    )}
                                </fieldset>
                                <label className="block text-sm">
                                    Instructions
                                    <textarea
                                        className="bg-canvas mt-1 min-h-24 w-full rounded-lg border p-2"
                                        value={quizForm.data.instructions}
                                        onChange={(event) =>
                                            quizForm.setData('instructions', event.target.value)
                                        }
                                        required
                                        maxLength={8000}
                                    />
                                </label>
                                <label className="block text-sm">
                                    Assessment mode
                                    <select
                                        className="bg-canvas mt-1 w-full rounded-lg border p-2"
                                        value={quizForm.data.mode}
                                        onChange={(event) => quizForm.setData('mode', event.target.value)}
                                    >
                                        {[
                                            'practice',
                                            'self_check',
                                            'graded',
                                            'instructor_led',
                                            'lesson_check',
                                        ].map((mode) => (
                                            <option key={mode} value={mode}>
                                                {mode.replaceAll('_', ' ')}
                                            </option>
                                        ))}
                                    </select>
                                </label>
                                <div className="grid gap-3 sm:grid-cols-2">
                                    <label className="block text-sm">
                                        Time limit (minutes)
                                        <input
                                            type="number"
                                            min={0}
                                            max={240}
                                            className="bg-canvas mt-1 w-full rounded-lg border p-2"
                                            value={quizForm.data.time_limit_minutes}
                                            onChange={(event) =>
                                                quizForm.setData(
                                                    'time_limit_minutes',
                                                    Number(event.target.value),
                                                )
                                            }
                                        />
                                    </label>
                                    <label className="block text-sm">
                                        Maximum attempts
                                        <input
                                            type="number"
                                            min={1}
                                            max={10}
                                            className="bg-canvas mt-1 w-full rounded-lg border p-2"
                                            value={quizForm.data.maximum_attempts}
                                            onChange={(event) =>
                                                quizForm.setData(
                                                    'maximum_attempts',
                                                    Number(event.target.value),
                                                )
                                            }
                                        />
                                    </label>
                                </div>
                                <div className="grid gap-3 sm:grid-cols-2">
                                    <label className="block text-sm">
                                        Opens at (institution time)
                                        <input
                                            type="datetime-local"
                                            className="bg-canvas mt-1 w-full rounded-lg border p-2"
                                            value={quizForm.data.available_at}
                                            onChange={(event) =>
                                                quizForm.setData('available_at', event.target.value)
                                            }
                                        />
                                    </label>
                                    <label className="block text-sm">
                                        Closes at (institution time)
                                        <input
                                            type="datetime-local"
                                            className="bg-canvas mt-1 w-full rounded-lg border p-2"
                                            value={quizForm.data.closes_at}
                                            onChange={(event) =>
                                                quizForm.setData('closes_at', event.target.value)
                                            }
                                        />
                                    </label>
                                    <label className="block text-sm">
                                        Cooldown (minutes)
                                        <input
                                            type="number"
                                            min={0}
                                            max={43200}
                                            className="bg-canvas mt-1 w-full rounded-lg border p-2"
                                            value={quizForm.data.cooldown_minutes}
                                            onChange={(event) =>
                                                quizForm.setData(
                                                    'cooldown_minutes',
                                                    Number(event.target.value),
                                                )
                                            }
                                        />
                                    </label>
                                    <label className="block text-sm">
                                        Pass threshold (%)
                                        <input
                                            type="number"
                                            min={0}
                                            max={100}
                                            className="bg-canvas mt-1 w-full rounded-lg border p-2"
                                            value={quizForm.data.pass_threshold}
                                            onChange={(event) =>
                                                quizForm.setData('pass_threshold', event.target.value)
                                            }
                                        />
                                    </label>
                                </div>
                                <div className="grid gap-3 sm:grid-cols-2">
                                    <label className="block text-sm">
                                        Grading policy
                                        <select
                                            className="bg-canvas mt-1 w-full rounded-lg border p-2"
                                            value={quizForm.data.grading_policy}
                                            onChange={(event) =>
                                                quizForm.setData('grading_policy', event.target.value)
                                            }
                                        >
                                            {['best', 'latest', 'average'].map((value) => (
                                                <option key={value} value={value}>
                                                    {value}
                                                </option>
                                            ))}
                                        </select>
                                    </label>
                                    <label className="block text-sm">
                                        Feedback release
                                        <select
                                            className="bg-canvas mt-1 w-full rounded-lg border p-2"
                                            value={quizForm.data.feedback_release}
                                            onChange={(event) =>
                                                quizForm.setData('feedback_release', event.target.value)
                                            }
                                        >
                                            {['immediate', 'after_manual', 'after_close', 'manual'].map(
                                                (value) => (
                                                    <option key={value} value={value}>
                                                        {value.replaceAll('_', ' ')}
                                                    </option>
                                                ),
                                            )}
                                        </select>
                                    </label>
                                    <label className="block text-sm">
                                        Result visibility
                                        <select
                                            className="bg-canvas mt-1 w-full rounded-lg border p-2"
                                            value={quizForm.data.result_visibility}
                                            onChange={(event) =>
                                                quizForm.setData('result_visibility', event.target.value)
                                            }
                                        >
                                            <option value="score_only">Score only</option>
                                            <option value="score_and_feedback">Score and feedback</option>
                                        </select>
                                    </label>
                                    <label className="block text-sm">
                                        Question navigation
                                        <select
                                            className="bg-canvas mt-1 w-full rounded-lg border p-2"
                                            value={quizForm.data.navigation}
                                            onChange={(event) =>
                                                quizForm.setData('navigation', event.target.value)
                                            }
                                        >
                                            <option value="free">Free navigation</option>
                                            <option value="sequential">Sequential</option>
                                        </select>
                                    </label>
                                </div>
                                <fieldset className="flex flex-wrap gap-5 text-sm">
                                    <label className="flex items-center gap-2">
                                        <input
                                            type="checkbox"
                                            checked={quizForm.data.shuffle_questions}
                                            onChange={(event) =>
                                                quizForm.setData('shuffle_questions', event.target.checked)
                                            }
                                        />
                                        Shuffle questions
                                    </label>
                                    <label className="flex items-center gap-2">
                                        <input
                                            type="checkbox"
                                            checked={quizForm.data.shuffle_options}
                                            onChange={(event) =>
                                                quizForm.setData('shuffle_options', event.target.checked)
                                            }
                                        />
                                        Shuffle options
                                    </label>
                                </fieldset>
                                <Button
                                    type="submit"
                                    disabled={
                                        quizForm.processing ||
                                        !selectedSection ||
                                        selectedQuestions.length === 0
                                    }
                                >
                                    Save quiz draft
                                </Button>
                            </form>
                        </article>
                    </section>
                )}
                {policy && permissions?.policyManage && (
                    <section className="bg-surface rounded-2xl border p-5">
                        <h2 className="text-lg font-semibold">Assessment policy</h2>
                        <p className="text-text-muted mt-1 text-sm">
                            Changes use optimistic locking and are recorded in the tenant audit trail.
                        </p>
                        <form
                            onSubmit={(event) => {
                                event.preventDefault();
                                policyForm.put(base + '/policy', {
                                    preserveScroll: true,
                                    onError: (errors) => setError(Object.values(errors).join(' ')),
                                });
                            }}
                            className="mt-4 grid gap-4 md:grid-cols-3"
                        >
                            <label className="text-sm">
                                Maximum attempts
                                <input
                                    type="number"
                                    min={1}
                                    max={10}
                                    className="bg-canvas mt-1 w-full rounded-lg border p-2"
                                    value={policyForm.data.settings.maximum_attempts}
                                    onChange={(event) =>
                                        policyForm.setData('settings', {
                                            ...policyForm.data.settings,
                                            maximum_attempts: Number(event.target.value),
                                        })
                                    }
                                />
                            </label>
                            <label className="text-sm">
                                Maximum cooldown (minutes)
                                <input
                                    type="number"
                                    min={0}
                                    max={43200}
                                    className="bg-canvas mt-1 w-full rounded-lg border p-2"
                                    value={policyForm.data.settings.maximum_cooldown_minutes}
                                    onChange={(event) =>
                                        policyForm.setData('settings', {
                                            ...policyForm.data.settings,
                                            maximum_cooldown_minutes: Number(event.target.value),
                                        })
                                    }
                                />
                            </label>
                            <label className="flex items-center gap-2 text-sm">
                                <input
                                    type="checkbox"
                                    checked={policyForm.data.settings.require_independent_review}
                                    onChange={(event) =>
                                        policyForm.setData('settings', {
                                            ...policyForm.data.settings,
                                            require_independent_review: event.target.checked,
                                        })
                                    }
                                />
                                Require independent review
                            </label>
                            <label className="flex items-center gap-2 text-sm">
                                <input
                                    type="checkbox"
                                    checked={policyForm.data.settings.require_independent_publisher}
                                    onChange={(event) =>
                                        policyForm.setData('settings', {
                                            ...policyForm.data.settings,
                                            require_independent_publisher: event.target.checked,
                                        })
                                    }
                                />
                                Require separate publisher
                            </label>
                            <Button type="submit" disabled={policyForm.processing}>
                                Save policy
                            </Button>
                        </form>
                    </section>
                )}
            </main>
        </WorkspaceLayout>
    );
}

export default AssessmentIndex;
