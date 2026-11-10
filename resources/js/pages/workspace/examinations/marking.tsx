import { Head, router } from '@inertiajs/react';
import { useState, type FormEvent } from 'react';
import { PageHeader } from '@/components/ui/page-header';
import { WorkspaceLayout } from '@/layouts/workspace-layout';

type Rubric = { key: string; title: string; description: string | null; maximumMarks: string };
type FormValue = string | number | boolean | File | null | FormValue[] | { [key: string]: FormValue };
type Allocation = {
    id: number;
    componentId: number;
    component: string;
    type: 'practical' | 'oral';
    session: string;
    candidateNumber: string;
    round: number;
    status: string;
    rubric: Rubric[];
    scripts: { id: number; name: string; url: string }[];
    draft: { rubric_marks: Record<string, string>; feedback: string; lock_version: number } | null;
};
type Props = {
    tenant: { slug: string };
    workspace: {
        allocations: { data: Allocation[] };
        moderation: {
            componentId: number;
            title: string;
            candidateId: number;
            candidateNumber: string;
            firstMarks: string | null;
            secondMarks: string | null;
            maximumMarks: string | null;
            difference: string | null;
        }[];
        history: {
            id: number;
            revision: number;
            component: string;
            candidateNumber: string;
            round: number;
            marks: string;
            maximum: string;
            submittedAt: string | null;
        }[];
        pendingApprovals: {
            id: number;
            component: string;
            candidateNumber: string;
            marks: string;
            maximum: string;
        }[];
        canManage: boolean;
        canUpload: boolean;
        canModerate: boolean;
        canApprove: boolean;
    };
    components: {
        id: number;
        sessionId: number;
        session: string;
        type: string;
        title: string;
        maximumMarks: string;
        status: string;
        secondMarking: boolean;
    }[];
    sessions: { id: number; title: string }[];
    rooms: { id: number; label: string }[];
    candidates: { id: number; number: string }[];
    examiners: { id: number; name: string }[];
};

export default function ExaminationMarking({
    tenant,
    workspace,
    components,
    sessions,
    rooms,
    candidates,
    examiners,
}: Props) {
    const root = `/t/${tenant.slug}/academics/examinations`;
    const [error, setError] = useState<string | null>(null);
    const [busy, setBusy] = useState(false);
    const [componentType, setComponentType] = useState<'practical' | 'oral'>('practical');
    const [maxMarks, setMaxMarks] = useState('20');
    const [rubricOne, setRubricOne] = useState('10');
    const [rubricTwo, setRubricTwo] = useState('10');
    const [scores, setScores] = useState<Record<number, Record<string, string>>>({});
    const [versions, setVersions] = useState<Record<number, number>>({});
    const [moderationScores, setModerationScores] = useState<Record<string, string>>({});

    function send(url: string, data: Record<string, FormValue>, method: 'post' | 'patch' = 'post') {
        setError(null);
        setBusy(true);
        router[method](url, data, {
            preserveScroll: true,
            onError: (errors) => setError(Object.values(errors).join(' ')),
            onFinish: () => setBusy(false),
        });
    }

    function saveDraft(event: FormEvent<HTMLFormElement>, allocation: Allocation) {
        event.preventDefault();
        const version = versions[allocation.id] ?? allocation.draft?.lock_version ?? 0;
        send(
            `${root}/marking/allocations/${allocation.id}/draft`,
            {
                marks: scores[allocation.id] ?? allocation.draft?.rubric_marks ?? {},
                feedback: String(new FormData(event.currentTarget).get('feedback') ?? ''),
                private_notes: String(new FormData(event.currentTarget).get('private_notes') ?? ''),
                expected_version: version,
            },
            'patch',
        );
        setVersions((current) => ({ ...current, [allocation.id]: version + 1 }));
    }

    return (
        <WorkspaceLayout area="tenant" title="Examiner marking">
            <Head title="Examiner marking" />
            <main className="mx-auto max-w-7xl space-y-8 px-4 py-8 sm:px-6 lg:px-8">
                <PageHeader
                    title="Practical, oral & script marking"
                    description="Rubric-based marks are saved as versioned records. Submitted marks are locked; second marking and moderation preserve both original scores."
                />
                {error ? (
                    <div role="alert" className="rounded-xl border border-red-300 bg-red-50 p-4 text-red-900">
                        {error}
                    </div>
                ) : null}

                {workspace.canManage ? (
                    <section
                        className="surface space-y-5 rounded-2xl border p-5"
                        aria-labelledby="component-create-title"
                    >
                        <h2 id="component-create-title" className="text-xl font-semibold">
                            Schedule a practical or oral component
                        </h2>
                        <form
                            className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3"
                            onSubmit={(event) => {
                                event.preventDefault();
                                const form = new FormData(event.currentTarget);
                                send(`${root}/sessions/${String(form.get('session_id'))}/components`, {
                                    component_type: componentType,
                                    code: form.get('code'),
                                    title: form.get('title'),
                                    instructions: form.get('instructions'),
                                    room_id: form.get('room_id') || null,
                                    starts_at: form.get('starts_at') || null,
                                    ends_at: form.get('ends_at') || null,
                                    maximum_marks: maxMarks,
                                    second_marking_required: form.get('second_marking_required') === 'on',
                                    blind_second_marking: form.get('blind_second_marking') === 'on',
                                    moderation_difference_threshold: form.get('threshold') || null,
                                    rubric: [
                                        {
                                            key: 'criterion_one',
                                            title: form.get('rubric_one_title'),
                                            maximum_marks: rubricOne,
                                        },
                                        {
                                            key: 'criterion_two',
                                            title: form.get('rubric_two_title'),
                                            maximum_marks: rubricTwo,
                                        },
                                    ],
                                });
                            }}
                        >
                            <label className="grid gap-1 text-sm">
                                Examination session
                                <select
                                    required
                                    name="session_id"
                                    className="min-h-11 rounded-lg border px-3"
                                >
                                    {sessions.map((item) => (
                                        <option key={item.id} value={item.id}>
                                            {item.title}
                                        </option>
                                    ))}
                                </select>
                            </label>
                            <label className="grid gap-1 text-sm">
                                Component type
                                <select
                                    value={componentType}
                                    onChange={(event) =>
                                        setComponentType(event.target.value as 'practical' | 'oral')
                                    }
                                    className="min-h-11 rounded-lg border px-3"
                                >
                                    <option value="practical">Practical</option>
                                    <option value="oral">Oral / viva</option>
                                </select>
                            </label>
                            <label className="grid gap-1 text-sm">
                                Component code
                                <input
                                    required
                                    name="code"
                                    maxLength={48}
                                    className="min-h-11 rounded-lg border px-3"
                                />
                            </label>
                            <label className="grid gap-1 text-sm">
                                Title
                                <input
                                    required
                                    name="title"
                                    maxLength={180}
                                    className="min-h-11 rounded-lg border px-3"
                                />
                            </label>
                            <label className="grid gap-1 text-sm">
                                Room / lab
                                <select name="room_id" className="min-h-11 rounded-lg border px-3">
                                    <option value="">Not assigned</option>
                                    {rooms.map((room) => (
                                        <option value={room.id} key={room.id}>
                                            {room.label}
                                        </option>
                                    ))}
                                </select>
                            </label>
                            <label className="grid gap-1 text-sm">
                                Maximum marks
                                <input
                                    required
                                    type="number"
                                    min="0.01"
                                    step="0.01"
                                    value={maxMarks}
                                    onChange={(event) => setMaxMarks(event.target.value)}
                                    className="min-h-11 rounded-lg border px-3"
                                />
                            </label>
                            <label className="grid gap-1 text-sm">
                                Starts at
                                <input
                                    name="starts_at"
                                    type="datetime-local"
                                    className="min-h-11 rounded-lg border px-3"
                                />
                            </label>
                            <label className="grid gap-1 text-sm">
                                Ends at
                                <input
                                    name="ends_at"
                                    type="datetime-local"
                                    className="min-h-11 rounded-lg border px-3"
                                />
                            </label>
                            <label className="grid gap-1 text-sm sm:col-span-2">
                                Candidate instructions
                                <textarea
                                    name="instructions"
                                    maxLength={5000}
                                    rows={3}
                                    className="rounded-lg border px-3 py-2"
                                />
                            </label>
                            <fieldset className="grid gap-3 rounded-xl border p-4 sm:col-span-2 lg:col-span-3">
                                <legend className="px-1 font-semibold">
                                    Scoring rubric (criteria must add to maximum marks)
                                </legend>
                                <div className="grid gap-3 sm:grid-cols-2">
                                    <label className="grid gap-1 text-sm">
                                        Criterion 1
                                        <input
                                            required
                                            name="rubric_one_title"
                                            defaultValue="Technical execution"
                                            className="min-h-11 rounded-lg border px-3"
                                        />
                                    </label>
                                    <label className="grid gap-1 text-sm">
                                        Criterion 1 marks
                                        <input
                                            required
                                            type="number"
                                            min="0.01"
                                            step="0.01"
                                            value={rubricOne}
                                            onChange={(event) => setRubricOne(event.target.value)}
                                            className="min-h-11 rounded-lg border px-3"
                                        />
                                    </label>
                                    <label className="grid gap-1 text-sm">
                                        Criterion 2
                                        <input
                                            required
                                            name="rubric_two_title"
                                            defaultValue="Method and explanation"
                                            className="min-h-11 rounded-lg border px-3"
                                        />
                                    </label>
                                    <label className="grid gap-1 text-sm">
                                        Criterion 2 marks
                                        <input
                                            required
                                            type="number"
                                            min="0.01"
                                            step="0.01"
                                            value={rubricTwo}
                                            onChange={(event) => setRubricTwo(event.target.value)}
                                            className="min-h-11 rounded-lg border px-3"
                                        />
                                    </label>
                                </div>
                                <label className="flex min-h-11 items-center gap-2 text-sm">
                                    <input type="checkbox" name="second_marking_required" /> Require
                                    independent second marking
                                </label>
                                <label className="flex min-h-11 items-center gap-2 text-sm">
                                    <input type="checkbox" name="blind_second_marking" /> Hide first score
                                    from second examiner
                                </label>
                                <label className="grid max-w-sm gap-1 text-sm">
                                    Moderation difference threshold (marks)
                                    <input
                                        name="threshold"
                                        type="number"
                                        min="0"
                                        step="0.01"
                                        className="min-h-11 rounded-lg border px-3"
                                    />
                                </label>
                            </fieldset>
                            <button
                                disabled={busy || sessions.length === 0}
                                className="bg-brand-action min-h-11 rounded-lg px-5 font-semibold text-white disabled:opacity-50"
                            >
                                Save component draft
                            </button>
                        </form>

                        <div className="grid gap-4">
                            <h3 className="font-semibold">Published component administration</h3>
                            {components.length === 0 ? (
                                <p className="text-text-secondary">No components have been created.</p>
                            ) : (
                                components.map((component) => (
                                    <article
                                        key={component.id}
                                        className="grid gap-4 rounded-xl border p-4 lg:grid-cols-3"
                                    >
                                        <div>
                                            <h4 className="font-semibold">{component.title}</h4>
                                            <p className="text-text-secondary text-sm">
                                                {component.session} · {component.type} ·{' '}
                                                {component.maximumMarks} marks · {component.status}
                                            </p>
                                            {component.status === 'draft' ? (
                                                <button
                                                    onClick={() =>
                                                        send(`${root}/components/${component.id}/publish`, {})
                                                    }
                                                    className="mt-3 min-h-10 rounded-lg border px-3"
                                                >
                                                    Publish component
                                                </button>
                                            ) : null}
                                        </div>
                                        <form
                                            className="grid gap-2"
                                            onSubmit={(event) => {
                                                event.preventDefault();
                                                const f = new FormData(event.currentTarget);
                                                send(`${root}/components/${component.id}/examiners`, {
                                                    user_id: f.get('user_id'),
                                                    role: f.get('role'),
                                                });
                                            }}
                                        >
                                            <label className="grid gap-1 text-sm">
                                                Assign examiner
                                                <select
                                                    name="user_id"
                                                    required
                                                    className="min-h-11 rounded-lg border px-2"
                                                >
                                                    {examiners.map((person) => (
                                                        <option value={person.id} key={person.id}>
                                                            {person.name}
                                                        </option>
                                                    ))}
                                                </select>
                                            </label>
                                            <label className="grid gap-1 text-sm">
                                                Role
                                                <select
                                                    name="role"
                                                    className="min-h-11 rounded-lg border px-2"
                                                >
                                                    <option value="primary">Primary examiner</option>
                                                    <option value="second">Second examiner</option>
                                                    <option value="moderator">Moderator</option>
                                                </select>
                                            </label>
                                            <button className="min-h-10 rounded-lg border px-3">
                                                Save role assignment
                                            </button>
                                        </form>
                                        {component.status === 'published' ? (
                                            <form
                                                className="grid gap-2"
                                                onSubmit={(event) => {
                                                    event.preventDefault();
                                                    const f = new FormData(event.currentTarget);
                                                    send(`${root}/components/${component.id}/allocations`, {
                                                        candidate_id: f.get('candidate_id'),
                                                        examiner_id: f.get('examiner_id'),
                                                        round: f.get('round'),
                                                        reason: f.get('reason'),
                                                    });
                                                }}
                                            >
                                                <label className="grid gap-1 text-sm">
                                                    Candidate
                                                    <select
                                                        required
                                                        name="candidate_id"
                                                        className="min-h-11 rounded-lg border px-2"
                                                    >
                                                        {candidates.map((candidate) => (
                                                            <option key={candidate.id} value={candidate.id}>
                                                                {candidate.number}
                                                            </option>
                                                        ))}
                                                    </select>
                                                </label>
                                                <label className="grid gap-1 text-sm">
                                                    Examiner
                                                    <select
                                                        required
                                                        name="examiner_id"
                                                        className="min-h-11 rounded-lg border px-2"
                                                    >
                                                        {examiners.map((person) => (
                                                            <option key={person.id} value={person.id}>
                                                                {person.name}
                                                            </option>
                                                        ))}
                                                    </select>
                                                </label>
                                                <label className="grid gap-1 text-sm">
                                                    Marking round
                                                    <select
                                                        name="round"
                                                        className="min-h-11 rounded-lg border px-2"
                                                    >
                                                        <option value="1">First marking</option>
                                                        <option value="2" disabled={!component.secondMarking}>
                                                            Independent second marking
                                                        </option>
                                                    </select>
                                                </label>
                                                <label className="grid gap-1 text-sm">
                                                    Allocation reason
                                                    <input
                                                        name="reason"
                                                        required
                                                        maxLength={500}
                                                        className="min-h-11 rounded-lg border px-2"
                                                    />
                                                </label>
                                                <button className="min-h-10 rounded-lg border px-3">
                                                    Allocate candidate
                                                </button>
                                            </form>
                                        ) : null}
                                    </article>
                                ))
                            )}
                        </div>
                        {workspace.canUpload ? (
                            <form
                                className="grid gap-3 rounded-xl border p-4 sm:grid-cols-4"
                                onSubmit={(event) => {
                                    event.preventDefault();
                                    const f = new FormData(event.currentTarget);
                                    send(`${root}/sessions/${String(f.get('session_id'))}/scripts`, {
                                        candidate_id: f.get('candidate_id'),
                                        component_id: f.get('component_id') || null,
                                        file: f.get('file'),
                                    });
                                }}
                            >
                                <h3 className="font-semibold sm:col-span-4">
                                    Quarantine a scanned answer script
                                </h3>
                                <label className="grid gap-1 text-sm">
                                    Session
                                    <select
                                        name="session_id"
                                        required
                                        className="min-h-11 rounded-lg border px-2"
                                    >
                                        {sessions.map((item) => (
                                            <option value={item.id} key={item.id}>
                                                {item.title}
                                            </option>
                                        ))}
                                    </select>
                                </label>
                                <label className="grid gap-1 text-sm">
                                    Candidate
                                    <select
                                        name="candidate_id"
                                        required
                                        className="min-h-11 rounded-lg border px-2"
                                    >
                                        {candidates.map((candidate) => (
                                            <option value={candidate.id} key={candidate.id}>
                                                {candidate.number}
                                            </option>
                                        ))}
                                    </select>
                                </label>
                                <label className="grid gap-1 text-sm">
                                    Component (optional)
                                    <select name="component_id" className="min-h-11 rounded-lg border px-2">
                                        <option value="">Session script</option>
                                        {components.map((item) => (
                                            <option key={item.id} value={item.id}>
                                                {item.title}
                                            </option>
                                        ))}
                                    </select>
                                </label>
                                <label className="grid gap-1 text-sm">
                                    Private script file
                                    <input
                                        required
                                        name="file"
                                        type="file"
                                        accept=".pdf,.jpg,.jpeg,.png,.webp,.txt,.doc,.docx,.odt"
                                        className="min-h-11 rounded-lg border p-2"
                                    />
                                </label>
                                <button className="min-h-11 rounded-lg border px-4 sm:col-span-4">
                                    Upload and scan
                                </button>
                            </form>
                        ) : null}
                    </section>
                ) : null}

                {workspace.canApprove ? (
                    <section className="surface rounded-2xl border p-5" aria-labelledby="single-review-title">
                        <h2 id="single-review-title" className="text-xl font-semibold">
                            Independent single-mark review
                        </h2>
                        <ul className="mt-3 divide-y">
                            {workspace.pendingApprovals.map((item) => (
                                <li
                                    key={item.id}
                                    className="flex flex-wrap items-center justify-between gap-3 py-3"
                                >
                                    <span>
                                        {item.component} · {item.candidateNumber} · {item.marks}/
                                        {item.maximum}
                                    </span>
                                    <button
                                        onClick={() => send(`${root}/markings/${item.id}/approve`, {})}
                                        className="min-h-10 rounded-lg border px-3"
                                    >
                                        Approve result
                                    </button>
                                </li>
                            ))}
                            {workspace.pendingApprovals.length === 0 ? (
                                <li className="text-text-secondary py-4">
                                    No single marks are awaiting independent approval.
                                </li>
                            ) : null}
                        </ul>
                    </section>
                ) : null}

                {workspace.canModerate ? (
                    <section className="surface rounded-2xl border p-5" aria-labelledby="moderation-title">
                        <h2 id="moderation-title" className="text-xl font-semibold">
                            Independent moderation queue
                        </h2>
                        <ul className="mt-3 divide-y">
                            {workspace.moderation.map((item) => (
                                <li
                                    key={`${item.componentId}-${item.candidateId}`}
                                    className="grid gap-3 py-4 lg:grid-cols-[1fr_auto_auto]"
                                >
                                    <div>
                                        <h3 className="font-medium">
                                            {item.title} · {item.candidateNumber}
                                        </h3>
                                        <p className="text-text-secondary text-sm">
                                            Compare the independently submitted marks, then record the
                                            authorized final score and reason.
                                        </p>
                                        <dl className="mt-3 grid gap-2 rounded-lg border p-3 text-sm sm:grid-cols-3">
                                            <div>
                                                <dt className="text-text-secondary">First examiner</dt>
                                                <dd className="font-semibold">
                                                    {item.firstMarks} / {item.maximumMarks}
                                                </dd>
                                            </div>
                                            <div>
                                                <dt className="text-text-secondary">Second examiner</dt>
                                                <dd className="font-semibold">
                                                    {item.secondMarks} / {item.maximumMarks}
                                                </dd>
                                            </div>
                                            <div>
                                                <dt className="text-text-secondary">Difference</dt>
                                                <dd className="font-semibold">{item.difference} marks</dd>
                                            </div>
                                        </dl>
                                    </div>
                                    <form
                                        className="contents"
                                        onSubmit={(event) => {
                                            event.preventDefault();
                                            const f = new FormData(event.currentTarget);
                                            send(
                                                `${root}/components/${item.componentId}/candidates/${item.candidateId}/moderate`,
                                                {
                                                    final_marks:
                                                        moderationScores[
                                                            `${item.componentId}:${item.candidateId}`
                                                        ] ?? f.get('final_marks'),
                                                    decision: f.get('decision'),
                                                    reason: f.get('reason'),
                                                },
                                            );
                                        }}
                                    >
                                        <input
                                            aria-label="Final moderated marks"
                                            required
                                            type="number"
                                            min="0"
                                            step="0.01"
                                            name="final_marks"
                                            value={
                                                moderationScores[`${item.componentId}:${item.candidateId}`] ??
                                                ''
                                            }
                                            onChange={(event) =>
                                                setModerationScores((current) => ({
                                                    ...current,
                                                    [`${item.componentId}:${item.candidateId}`]:
                                                        event.target.value,
                                                }))
                                            }
                                            className="min-h-11 rounded-lg border px-3"
                                        />
                                        <select
                                            aria-label="Moderation decision"
                                            name="decision"
                                            className="min-h-11 rounded-lg border px-3"
                                        >
                                            <option value="confirmed">Confirm an examiner mark</option>
                                            <option value="adjusted">Adjusted final score</option>
                                        </select>
                                        <input
                                            aria-label="Moderation reason"
                                            required
                                            name="reason"
                                            placeholder="Reason for decision"
                                            className="min-h-11 rounded-lg border px-3 lg:col-span-2"
                                        />
                                        <button className="bg-brand-action min-h-11 rounded-lg px-4 text-white">
                                            Finalize moderation
                                        </button>
                                    </form>
                                </li>
                            ))}
                            {workspace.moderation.length === 0 ? (
                                <li className="text-text-secondary py-4">
                                    No independent mark pairs await moderation.
                                </li>
                            ) : null}
                        </ul>
                    </section>
                ) : null}

                <section className="space-y-4" aria-labelledby="examiner-queue-title">
                    <h2 id="examiner-queue-title" className="text-2xl font-semibold">
                        My assigned marking
                    </h2>
                    {workspace.allocations.data.length === 0 ? (
                        <p role="status" className="surface text-text-secondary rounded-xl border p-6">
                            No practical, oral or script marking is assigned to you.
                        </p>
                    ) : (
                        workspace.allocations.data.map((allocation) => {
                            const currentScores =
                                scores[allocation.id] ?? allocation.draft?.rubric_marks ?? {};
                            const expected = versions[allocation.id] ?? allocation.draft?.lock_version ?? 0;
                            return (
                                <article
                                    key={allocation.id}
                                    className="surface space-y-4 rounded-2xl border p-5"
                                >
                                    <header>
                                        <p className="text-text-secondary text-sm font-semibold uppercase">
                                            {allocation.type} · {allocation.session} · Round{' '}
                                            {allocation.round}
                                        </p>
                                        <h3 className="text-xl font-semibold">{allocation.component}</h3>
                                        <p className="text-sm">
                                            Candidate {allocation.candidateNumber} · {allocation.status}
                                        </p>
                                    </header>
                                    {allocation.scripts.length ? (
                                        <section aria-label="Authorized clean scripts">
                                            <h4 className="font-medium">Answer scripts</h4>
                                            <ul className="list-disc pl-5">
                                                {allocation.scripts.map((script) => (
                                                    <li key={script.id}>
                                                        <a
                                                            className="text-brand-link underline"
                                                            href={script.url}
                                                        >
                                                            {script.name}
                                                        </a>
                                                    </li>
                                                ))}
                                            </ul>
                                        </section>
                                    ) : null}
                                    <form
                                        className="grid gap-4"
                                        onSubmit={(event) => saveDraft(event, allocation)}
                                    >
                                        <fieldset className="grid gap-3">
                                            <legend className="font-semibold">
                                                Question / criterion marks
                                            </legend>
                                            {allocation.rubric.map((criterion) => (
                                                <label
                                                    key={criterion.key}
                                                    className="grid gap-1 sm:grid-cols-[1fr_10rem] sm:items-center"
                                                >
                                                    <span>
                                                        {criterion.title}{' '}
                                                        <span className="text-text-secondary text-sm">
                                                            (max {criterion.maximumMarks})
                                                        </span>
                                                        {criterion.description ? (
                                                            <span className="text-text-secondary block text-xs">
                                                                {criterion.description}
                                                            </span>
                                                        ) : null}
                                                    </span>
                                                    <input
                                                        aria-label={`${criterion.title} marks`}
                                                        type="number"
                                                        min="0"
                                                        max={criterion.maximumMarks}
                                                        step="0.01"
                                                        value={currentScores[criterion.key] ?? ''}
                                                        onChange={(event) =>
                                                            setScores((current) => ({
                                                                ...current,
                                                                [allocation.id]: {
                                                                    ...currentScores,
                                                                    [criterion.key]: event.target.value,
                                                                },
                                                            }))
                                                        }
                                                        className="min-h-11 rounded-lg border px-3"
                                                    />
                                                </label>
                                            ))}
                                        </fieldset>
                                        <label className="grid gap-1">
                                            Candidate feedback
                                            <textarea
                                                name="feedback"
                                                maxLength={4000}
                                                defaultValue={allocation.draft?.feedback ?? ''}
                                                rows={3}
                                                className="rounded-lg border p-3"
                                            />
                                        </label>
                                        <label className="grid gap-1">
                                            Confidential examiner notes
                                            <textarea
                                                name="private_notes"
                                                maxLength={4000}
                                                rows={2}
                                                className="rounded-lg border p-3"
                                            />
                                        </label>
                                        <div className="flex flex-wrap gap-3">
                                            <button
                                                disabled={busy || allocation.status === 'submitted'}
                                                className="min-h-11 rounded-lg border px-4 disabled:opacity-50"
                                            >
                                                Save marking draft
                                            </button>
                                            <button
                                                disabled={
                                                    busy ||
                                                    allocation.status === 'submitted' ||
                                                    expected === 0
                                                }
                                                type="button"
                                                onClick={() =>
                                                    send(
                                                        `${root}/marking/allocations/${allocation.id}/submit`,
                                                        { expected_version: expected },
                                                    )
                                                }
                                                className="bg-brand-action min-h-11 rounded-lg px-4 text-white disabled:opacity-50"
                                            >
                                                Submit and lock marks
                                            </button>
                                        </div>
                                    </form>
                                </article>
                            );
                        })
                    )}
                </section>

                <section className="surface rounded-2xl border p-5" aria-labelledby="marking-history-title">
                    <h2 id="marking-history-title" className="text-xl font-semibold">
                        My submitted marking history
                    </h2>
                    {workspace.history.length === 0 ? (
                        <p role="status" className="text-text-secondary py-3">
                            No marking revisions have been submitted by your account.
                        </p>
                    ) : (
                        <div
                            className="mt-3 overflow-x-auto"
                            role="region"
                            aria-label="Scrollable submitted marking history"
                            tabIndex={0}
                        >
                            <table className="w-full min-w-[38rem] text-left text-sm">
                                <caption className="sr-only">Immutable submitted mark revisions</caption>
                                <thead>
                                    <tr>
                                        <th scope="col" className="p-2">
                                            Component
                                        </th>
                                        <th scope="col" className="p-2">
                                            Candidate
                                        </th>
                                        <th scope="col" className="p-2">
                                            Round
                                        </th>
                                        <th scope="col" className="p-2">
                                            Revision
                                        </th>
                                        <th scope="col" className="p-2">
                                            Marks
                                        </th>
                                        <th scope="col" className="p-2">
                                            Submitted
                                        </th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {workspace.history.map((item) => (
                                        <tr key={item.id} className="border-t">
                                            <td className="p-2">{item.component}</td>
                                            <td className="p-2">{item.candidateNumber}</td>
                                            <td className="p-2">{item.round}</td>
                                            <td className="p-2">{item.revision}</td>
                                            <td className="p-2">
                                                {item.marks} / {item.maximum}
                                            </td>
                                            <td className="p-2">{item.submittedAt}</td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    )}
                </section>
            </main>
        </WorkspaceLayout>
    );
}
