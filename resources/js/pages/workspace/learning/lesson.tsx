import { Head, Link, useForm } from '@inertiajs/react';
import { useState } from 'react';
import { PageHeader } from '@/components/ui/page-header';
import { WorkspaceLayout } from '@/layouts/workspace-layout';

type Snapshot = {
    title: string;
    summary: string | null;
    learning_objectives: string;
    estimated_minutes: number | null;
    blocks: {
        id?: number;
        type: string;
        content: {
            text?: string;
            title?: string;
            url?: string;
            language?: string;
            media_type?: string;
            mime_type?: string;
            original_name?: string;
            alt_text?: string | null;
            license_label?: string | null;
            attribution?: string | null;
            transcript?: string | null;
            activity_type?: string;
            prompt?: string;
            required?: boolean;
            items?: string[];
        };
    }[];
};

type ActivityResponse = {
    state: 'draft' | 'submitted';
    version: number;
    response: {
        text?: string;
        acknowledged?: boolean;
        selected_items?: number[];
        rating?: number;
        notes?: string;
    };
};

function LearnerActivity({
    tenantSlug,
    workspaceId,
    lessonId,
    blockId,
    activityType,
    prompt,
    items,
    existing,
}: {
    tenantSlug: string;
    workspaceId: number;
    lessonId: number;
    blockId: number;
    activityType: string;
    prompt: string;
    items: string[];
    existing?: ActivityResponse;
}) {
    const initialResponse = existing?.response ?? {};
    const form = useForm({
        idempotency_key: crypto.randomUUID(),
        state: 'draft' as 'draft' | 'submitted',
        response: {
            text: initialResponse.text ?? '',
            acknowledged: initialResponse.acknowledged ?? false,
            selected_items: initialResponse.selected_items ?? [],
            rating: initialResponse.rating ?? '',
            notes: initialResponse.notes ?? '',
        },
    });
    const [submitted, setSubmitted] = useState(existing?.state === 'submitted');
    const base =
        '/t/' +
        tenantSlug +
        '/learning/' +
        workspaceId +
        '/lessons/' +
        lessonId +
        '/activities/' +
        blockId +
        '/responses';
    const persist = (state: 'draft' | 'submitted') => {
        form.transform((data) => ({ ...data, state }));
        form.post(base, {
            preserveScroll: true,
            onFinish: () => form.transform((data) => data),
            onSuccess: () => {
                if (state === 'submitted') setSubmitted(true);
                else form.setData('idempotency_key', crypto.randomUUID());
            },
        });
    };
    const toggleItem = (index: number, checked: boolean) => {
        const selected = new Set(form.data.response.selected_items);
        if (checked) selected.add(index);
        else selected.delete(index);
        form.setData('response', { ...form.data.response, selected_items: [...selected].sort() });
    };
    const isListActivity = ['checklist', 'step_by_step', 'resource_exploration'].includes(activityType);
    const isTextActivity = ['reflection', 'guided_practice', 'lab_preparation'].includes(activityType);

    return (
        <form
            onSubmit={(event) => {
                event.preventDefault();
                persist('draft');
            }}
            className="border-border bg-surface-soft grid gap-3 rounded-xl border p-4"
            aria-label={'Activity: ' + activityType.replaceAll('_', ' ')}
        >
            <div>
                <h3 className="font-semibold">{activityType.replaceAll('_', ' ')}</h3>
                <p className="text-text-muted mt-1 text-sm leading-6 whitespace-pre-wrap">{prompt}</p>
            </div>
            {isTextActivity && (
                <label className="grid gap-1 text-sm font-medium">
                    Your response
                    <textarea
                        maxLength={10000}
                        rows={4}
                        disabled={submitted}
                        value={form.data.response.text}
                        onChange={(event) =>
                            form.setData('response', {
                                ...form.data.response,
                                text: event.currentTarget.value,
                            })
                        }
                        className="border-border bg-surface rounded-lg border px-3 py-2 disabled:opacity-70"
                    />
                </label>
            )}
            {activityType === 'reading_acknowledgement' && (
                <label className="flex min-h-11 items-center gap-2 text-sm">
                    <input
                        type="checkbox"
                        disabled={submitted}
                        checked={form.data.response.acknowledged}
                        onChange={(event) =>
                            form.setData('response', {
                                ...form.data.response,
                                acknowledged: event.currentTarget.checked,
                            })
                        }
                    />
                    I confirm that I completed this reading
                </label>
            )}
            {isListActivity && (
                <fieldset className="grid gap-2">
                    <legend className="text-sm font-medium">Mark each item you completed</legend>
                    {items.map((item, index) => (
                        <label
                            key={String(index) + '-' + item}
                            className="flex min-h-10 items-center gap-2 text-sm"
                        >
                            <input
                                type="checkbox"
                                disabled={submitted}
                                checked={form.data.response.selected_items.includes(index)}
                                onChange={(event) => toggleItem(index, event.currentTarget.checked)}
                            />
                            <span>{item}</span>
                        </label>
                    ))}
                </fieldset>
            )}
            {activityType === 'self_evaluation' && (
                <>
                    <label className="grid gap-1 text-sm font-medium">
                        How confident do you feel? (1 = beginning, 5 = confident)
                        <select
                            disabled={submitted}
                            value={form.data.response.rating || ''}
                            onChange={(event) =>
                                form.setData('response', {
                                    ...form.data.response,
                                    rating:
                                        event.currentTarget.value === ''
                                            ? ''
                                            : Number(event.currentTarget.value),
                                })
                            }
                            className="border-border bg-surface min-h-11 rounded-lg border px-3"
                        >
                            <option value="">Choose a rating</option>
                            {[1, 2, 3, 4, 5].map((rating) => (
                                <option key={rating} value={rating}>
                                    {rating}
                                </option>
                            ))}
                        </select>
                    </label>
                    <label className="grid gap-1 text-sm font-medium">
                        Notes (optional)
                        <textarea
                            maxLength={5000}
                            rows={3}
                            disabled={submitted}
                            value={form.data.response.notes}
                            onChange={(event) =>
                                form.setData('response', {
                                    ...form.data.response,
                                    notes: event.currentTarget.value,
                                })
                            }
                            className="border-border bg-surface rounded-lg border px-3 py-2 disabled:opacity-70"
                        />
                    </label>
                </>
            )}
            {Object.values(form.errors).length > 0 && (
                <div role="alert" className="text-sm text-red-700">
                    {Object.values(form.errors).map((message, index) => (
                        <p key={index}>{message}</p>
                    ))}
                </div>
            )}
            {submitted ? (
                <p role="status" className="text-sm font-medium">
                    Submitted for this lesson revision.
                </p>
            ) : (
                <div className="flex flex-wrap gap-2">
                    <button
                        type="submit"
                        disabled={form.processing}
                        className="border-border focus-ring min-h-10 rounded-lg border px-3 text-sm font-semibold disabled:opacity-50"
                    >
                        Save draft
                    </button>
                    <button
                        type="button"
                        disabled={form.processing}
                        onClick={() => persist('submitted')}
                        className="bg-brand-action focus-ring min-h-10 rounded-lg px-3 text-sm font-semibold text-white disabled:opacity-50"
                    >
                        Submit activity
                    </button>
                </div>
            )}
        </form>
    );
}

export default function LearningLesson({
    tenant,
    workspace,
    lesson,
    version,
    lessonId,
    progress,
    courseProgress,
    activityResponses,
}: {
    tenant: { slug: string };
    workspace: { id: number; title: string; code: string };
    lesson: Snapshot;
    version: number;
    lessonId: number;
    progress: {
        status: 'started' | 'completed';
        resume_seconds: number;
        learner_confirmed_at: string | null;
        instructor_confirmation_pending: boolean;
    };
    activityResponses: Record<number, ActivityResponse>;
    courseProgress: {
        completed: number;
        total: number;
        percent: number;
        modules: { module_id: number; title: string; completed: number; total: number; percent: number }[];
    };
}) {
    const positionForm = useForm({ resume_seconds: String(progress.resume_seconds) });
    const completionForm = useForm({});
    const base = `/t/${tenant.slug}/learning/${workspace.id}/lessons/${lessonId}`;

    return (
        <WorkspaceLayout title={lesson.title} area="tenant">
            <Head title={lesson.title} />
            <PageHeader
                eyebrow={`${workspace.code} · published version ${version}`}
                title={lesson.title}
                description={lesson.summary ?? 'Published learning content.'}
            />
            <div className="border-border bg-surface mt-6 rounded-2xl border p-5 sm:p-8">
                <section
                    aria-label="Learning progress"
                    className="border-border mb-6 flex flex-wrap items-end gap-3 border-b pb-5"
                >
                    <p className="text-sm font-semibold" role="status">
                        {progress.status === 'completed'
                            ? 'Completed'
                            : progress.instructor_confirmation_pending
                              ? 'Waiting for instructor confirmation'
                              : 'In progress'}
                    </p>
                    <div className="min-w-48 flex-1 basis-full sm:basis-56">
                        <div className="mb-1 flex justify-between gap-3 text-xs">
                            <span>Course completion</span>
                            <span>
                                {courseProgress.completed} of {courseProgress.total} lessons
                            </span>
                        </div>
                        <progress
                            aria-label="Course lesson completion"
                            value={courseProgress.percent}
                            max={100}
                            className="h-2 w-full accent-[var(--brand-action)]"
                        />
                    </div>
                    {courseProgress.modules.length > 1 && (
                        <ul className="grid w-full gap-2 pt-2 sm:grid-cols-2" aria-label="Module completion">
                            {courseProgress.modules.map((module) => (
                                <li key={module.module_id} className="bg-surface-soft rounded-lg p-3">
                                    <div className="mb-1 flex justify-between gap-3 text-xs">
                                        <span>{module.title}</span>
                                        <span>
                                            {module.completed}/{module.total}
                                        </span>
                                    </div>
                                    <progress
                                        aria-label={`${module.title} completion`}
                                        value={module.percent}
                                        max={100}
                                        className="h-2 w-full accent-[var(--brand-action)]"
                                    />
                                </li>
                            ))}
                        </ul>
                    )}
                    {progress.status === 'started' && (
                        <>
                            <form
                                onSubmit={(event) => {
                                    event.preventDefault();
                                    positionForm.put(`${base}/progress`);
                                }}
                                className="flex items-end gap-2"
                            >
                                <label className="grid gap-1 text-xs font-medium">
                                    Resume position (seconds)
                                    <input
                                        type="number"
                                        min="0"
                                        max="86400"
                                        value={positionForm.data.resume_seconds}
                                        onChange={(event) =>
                                            positionForm.setData('resume_seconds', event.currentTarget.value)
                                        }
                                        className="border-border bg-surface min-h-10 w-36 rounded-lg border px-2"
                                    />
                                </label>
                                <button
                                    type="submit"
                                    disabled={positionForm.processing}
                                    className="border-border focus-ring min-h-10 rounded-lg border px-3 text-sm font-semibold"
                                >
                                    Save position
                                </button>
                            </form>
                            <button
                                type="button"
                                disabled={completionForm.processing}
                                onClick={() => completionForm.post(`${base}/complete`)}
                                className="bg-brand-action focus-ring min-h-10 rounded-lg px-3 text-sm font-semibold text-white"
                            >
                                Confirm lesson completion
                            </button>
                        </>
                    )}
                </section>
                <section aria-labelledby="objective-heading">
                    <h2 id="objective-heading" className="text-sm font-semibold tracking-wide uppercase">
                        Learning objective
                    </h2>
                    <p className="text-text-muted mt-2 leading-7 whitespace-pre-wrap">
                        {lesson.learning_objectives}
                    </p>
                    {lesson.estimated_minutes && (
                        <p className="text-text-muted mt-3 text-sm">
                            Estimated time: {lesson.estimated_minutes} minutes
                        </p>
                    )}
                </section>
                <div className="mt-8 grid gap-5">
                    {lesson.blocks.map((block, index) => (
                        <section
                            key={`${block.type}-${index}`}
                            className={
                                block.type === 'callout'
                                    ? 'border-brand-action bg-surface-soft rounded-r-xl border-l-4 p-4'
                                    : ''
                            }
                        >
                            {block.type === 'activity' && block.id ? (
                                <LearnerActivity
                                    tenantSlug={tenant.slug}
                                    workspaceId={workspace.id}
                                    lessonId={lessonId}
                                    blockId={block.id}
                                    activityType={block.content.activity_type ?? 'reflection'}
                                    prompt={block.content.prompt ?? ''}
                                    items={block.content.items ?? []}
                                    existing={activityResponses[block.id]}
                                />
                            ) : block.type === 'heading' ? (
                                <h2 className="text-xl font-semibold">
                                    {block.content.title || block.content.text}
                                </h2>
                            ) : block.type === 'divider' ? (
                                <hr className="border-border" />
                            ) : block.type === 'external_link' && block.content.url ? (
                                <a
                                    className="text-brand-link focus-ring underline"
                                    href={block.content.url}
                                    target="_blank"
                                    rel="noreferrer noopener"
                                >
                                    {block.content.title || block.content.url}
                                    <span className="sr-only"> (opens in a new tab)</span>
                                </a>
                            ) : block.type === 'media_asset' && block.content.url ? (
                                <div className="grid gap-3">
                                    {block.content.media_type === 'image' ? (
                                        <img
                                            src={block.content.url}
                                            alt={block.content.alt_text ?? ''}
                                            loading="lazy"
                                            className="h-auto max-h-[70vh] w-auto max-w-full rounded-lg"
                                        />
                                    ) : block.content.media_type === 'video' ? (
                                        <video
                                            controls
                                            preload="metadata"
                                            aria-label={block.content.original_name ?? 'Course video'}
                                            className="max-h-[70vh] w-full rounded-lg bg-black"
                                        >
                                            <source src={block.content.url} type={block.content.mime_type} />
                                            Your browser does not support this video. Use the resource link
                                            below.
                                        </video>
                                    ) : block.content.media_type === 'audio' ? (
                                        <audio
                                            controls
                                            preload="metadata"
                                            aria-label={block.content.original_name ?? 'Course audio'}
                                            className="w-full"
                                        >
                                            <source src={block.content.url} type={block.content.mime_type} />
                                            Your browser does not support this audio. Use the resource link
                                            below.
                                        </audio>
                                    ) : (
                                        <a
                                            className="text-brand-link focus-ring underline"
                                            href={block.content.url}
                                        >
                                            Open resource:{' '}
                                            {block.content.original_name ?? 'Learning resource'}
                                        </a>
                                    )}
                                    {block.content.transcript && (
                                        <details className="border-border rounded-lg border p-3">
                                            <summary className="focus-ring cursor-pointer font-semibold">
                                                Read transcript
                                            </summary>
                                            <p className="text-text-muted mt-3 leading-7 whitespace-pre-wrap">
                                                {block.content.transcript}
                                            </p>
                                        </details>
                                    )}
                                    <p className="text-text-muted text-xs">
                                        {block.content.license_label}
                                        {block.content.attribution ? ` · ${block.content.attribution}` : ''}
                                    </p>
                                </div>
                            ) : (
                                <p className="text-text leading-7 whitespace-pre-wrap">
                                    {block.content.text || block.content.title}
                                </p>
                            )}
                        </section>
                    ))}
                </div>
            </div>
            <Link
                href={`/t/${tenant.slug}/learning/${workspace.id}`}
                className="focus-ring text-brand-link mt-6 inline-flex min-h-11 items-center text-sm font-semibold"
            >
                Return to course
            </Link>
        </WorkspaceLayout>
    );
}
