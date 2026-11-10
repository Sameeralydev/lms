import { Head, Link, router, useForm } from '@inertiajs/react';
import { useState } from 'react';
import type { FormEvent } from 'react';
import { FormErrors } from '@/components/ui/form-errors';
import { PageHeader } from '@/components/ui/page-header';
import { WorkspaceLayout } from '@/layouts/workspace-layout';

type Block = {
    type:
        | 'text'
        | 'heading'
        | 'callout'
        | 'code'
        | 'divider'
        | 'external_link'
        | 'reflection'
        | 'activity'
        | 'release_policy'
        | 'completion_policy'
        | 'media_asset';
    content: {
        text?: string;
        title?: string;
        url?: string;
        language?: string;
        progression_mode?: 'free' | 'sequential';
        release_at?: string | null;
        available_until?: string | null;
        drip_days_after_enrollment?: number;
        module_locked?: boolean;
        optional_path?: boolean;
        prerequisite_lesson_ids?: number[];
        prerequisite_module_ids?: number[];
        activity_type?: string;
        prompt?: string;
        required?: boolean;
        items?: string[];
        require_all_required_activities?: boolean;
        require_instructor_confirmation?: boolean;
        asset_id?: number;
        status?: string;
        media_type?: string;
        mime_type?: string;
        original_name?: string;
        size_bytes?: number;
        alt_text?: string | null;
        license_label?: string;
        attribution?: string | null;
        transcript?: string | null;
        asset_version?: number;
        replaces_asset_id?: number | null;
    };
};
type Lesson = {
    id: number;
    title: string;
    summary: string | null;
    learning_objectives: string | null;
    estimated_minutes: number | null;
    status: string;
    lock_version: number;
    published_version_number: number | null;
    review_note: string | null;
    blocks: Block[];
};
type Member = { id: number; name: string; canTeach: boolean; canReview: boolean; canLearn: boolean };
type Assignment = {
    id: number;
    role: string;
    status: string;
    change_reason: string | null;
    effective_from: string | null;
    effective_until: string | null;
    member: { id: number; name: string };
};
type ReleaseException = {
    id: number;
    lesson_id: number;
    membership_id: number;
    requested_by: number;
    reason: string;
    status: string;
    expires_at: string;
    approved_by: number | null;
    revoked_at: string | null;
    learner_name: string;
};
type Module = { id: number; title: string; summary: string | null; sort_order: number; lessons: Lesson[] };
type Workspace = {
    id: number;
    code: string;
    title: string;
    description: string | null;
    status: string;
    delivery_mode: string;
    lock_version: number;
    course: { id: number; code: string; title: string };
    course_version: { id: number; title: string; version_number: number };
    curriculum_version: { id: number; version_number: number; status: string } | null;
    modules: Module[];
    assignments: Assignment[];
};
type LiveSession = {
    id: number;
    title: string;
    description: string | null;
    delivery_type: 'online' | 'in_person' | 'hybrid';
    timezone: string;
    starts_at: string;
    ends_at: string;
    meeting_url: string | null;
    venue: string | null;
    preparation_materials: string | null;
    post_session_resources: string | null;
    status: string;
    instructor: string;
    lesson: string | null;
    cancellation_reason: string | null;
};
type Props = {
    tenant: { slug: string };
    workspace: Workspace;
    members: Member[];
    canManage: boolean;
    canReview: boolean;
    canPublish: boolean;
    canLearn: boolean;
    canConfirmCompletion: boolean;
    sessions: LiveSession[];
    sessionInstructors: { id: number; name: string }[];
    canManageSessions: boolean;
    institutionTimezone: string;
    releaseExceptions: ReleaseException[];
    canApproveReleaseExceptions: boolean;
    actorUserId: number;
};

function LessonEditor({
    tenantSlug,
    workspaceId,
    institutionTimezone,
    lesson,
    canManage,
    canReview,
    canPublish,
    canLearn,
    canReorder,
    canMoveUp,
    canMoveDown,
    onMove,
}: {
    tenantSlug: string;
    workspaceId: number;
    institutionTimezone: string;
    lesson: Lesson;
    canManage: boolean;
    canReview: boolean;
    canPublish: boolean;
    canLearn: boolean;
    canReorder: boolean;
    canMoveUp: boolean;
    canMoveDown: boolean;
    onMove: (direction: -1 | 1) => void;
}) {
    const releasePolicy = lesson.blocks.find((block) => block.type === 'release_policy');
    const form = useForm({
        lock_version: lesson.lock_version,
        title: lesson.title,
        summary: lesson.summary ?? '',
        learning_objectives: lesson.learning_objectives ?? '',
        estimated_minutes: lesson.estimated_minutes ? String(lesson.estimated_minutes) : '',
        progression_mode: releasePolicy?.content.progression_mode ?? ('free' as const),
        release_at: releasePolicy?.content.release_at
            ? toZonedDateTime(releasePolicy.content.release_at, institutionTimezone)
            : '',
        available_until: releasePolicy?.content.available_until
            ? toZonedDateTime(releasePolicy.content.available_until, institutionTimezone)
            : '',
        drip_days_after_enrollment: String(releasePolicy?.content.drip_days_after_enrollment ?? 0),
        prerequisite_lesson_ids: (releasePolicy?.content.prerequisite_lesson_ids ?? []).join(', '),
        prerequisite_module_ids: (releasePolicy?.content.prerequisite_module_ids ?? []).join(', '),
        module_locked: releasePolicy?.content.module_locked ?? false,
        optional_path: releasePolicy?.content.optional_path ?? false,
        blocks: (lesson.blocks.length
            ? lesson.blocks
            : [{ type: 'text' as const, content: { text: '' } }]) as Block[],
    });
    const releaseAtPreview = form.data.release_at
        ? fromZonedDateTime(form.data.release_at, institutionTimezone)
        : null;
    const availableUntilPreview = form.data.available_until
        ? fromZonedDateTime(form.data.available_until, institutionTimezone)
        : null;
    const [newActivityType, setNewActivityType] = useState('reflection');
    const review = useForm({ decision: 'approved', note: '' });
    const mediaForm = useForm({
        file: null as File | null,
        alt_text: '',
        license_label: '',
        attribution: '',
        transcript: '',
        replaces_asset_id: '',
    });
    const base = `/t/${tenantSlug}/learning/${workspaceId}/lessons/${lesson.id}`;
    const save = (event: FormEvent) => {
        event.preventDefault();
        const blocks = form.data.blocks.filter(
            (block) => block.type !== 'release_policy' && block.type !== 'media_asset',
        );
        const releaseAt = form.data.release_at
            ? fromZonedDateTime(form.data.release_at, institutionTimezone)
            : null;
        const availableUntil = form.data.available_until
            ? fromZonedDateTime(form.data.available_until, institutionTimezone)
            : null;
        if ((form.data.release_at && !releaseAt) || (form.data.available_until && !availableUntil)) {
            form.setError('release_at', `Choose a valid date and time in ${institutionTimezone}.`);
            return;
        }
        const parseIds = (value: string) =>
            value.trim() === ''
                ? []
                : value
                      .split(',')
                      .map((id) => Number(id.trim()))
                      .filter((id) => Number.isSafeInteger(id) && id > 0);
        form.transform((data) => ({
            ...data,
            blocks: [
                ...blocks,
                {
                    type: 'release_policy',
                    content: {
                        progression_mode: data.progression_mode,
                        release_at: releaseAt,
                        available_until: availableUntil,
                        drip_days_after_enrollment: Number(data.drip_days_after_enrollment || 0),
                        module_locked: data.module_locked,
                        optional_path: data.optional_path,
                        prerequisite_lesson_ids: parseIds(data.prerequisite_lesson_ids),
                        prerequisite_module_ids: parseIds(data.prerequisite_module_ids),
                    },
                },
            ],
        }));
        form.put(base, {
            preserveScroll: true,
            onFinish: () => form.transform((data) => data),
        });
    };
    const textIndex = form.data.blocks.findIndex((block) => block.type === 'text');
    const updateText = (value: string) => {
        const blocks = [...form.data.blocks];
        if (textIndex < 0) blocks.push({ type: 'text', content: { text: value } });
        else
            blocks[textIndex] = {
                ...blocks[textIndex],
                content: { ...blocks[textIndex].content, text: value },
            };
        form.setData('blocks', blocks);
    };
    const updateActivity = (index: number, content: Block['content']) => {
        const blocks = [...form.data.blocks];
        blocks[index] = { ...blocks[index], content };
        form.setData('blocks', blocks);
    };
    const addActivity = () => {
        const withActivity: Block = {
            type: 'activity',
            content: {
                activity_type: newActivityType,
                prompt: '',
                required: true,
                items: [],
            },
        };
        form.setData('blocks', [
            ...form.data.blocks.filter((block) => block.type !== 'release_policy'),
            withActivity,
        ]);
    };
    const updateCompletionPolicy = (
        key: 'require_all_required_activities' | 'require_instructor_confirmation',
        value: boolean,
    ) => {
        const blocks = [...form.data.blocks];
        const index = blocks.findIndex((block) => block.type === 'completion_policy');
        const current = index >= 0 ? blocks[index] : { type: 'completion_policy' as const, content: {} };
        const updated = { ...current, content: { ...current.content, [key]: value } };
        if (index >= 0) blocks[index] = updated;
        else blocks.push(updated);
        form.setData('blocks', blocks);
    };

    return (
        <article
            id={`lesson-${lesson.id}`}
            data-lesson-id={lesson.id}
            className="border-border bg-surface mt-3 rounded-xl border p-4 sm:p-5"
        >
            <div className="flex flex-wrap items-center justify-between gap-2">
                <h4 className="font-semibold">{lesson.title}</h4>
                <div className="flex items-center gap-2">
                    <span className="bg-surface-soft rounded-full px-3 py-1 text-xs font-semibold capitalize">
                        {lesson.status.replace('_', ' ')}
                    </span>
                    {canReorder && (
                        <div className="flex gap-1">
                            <button
                                type="button"
                                aria-label={`Move lesson ${lesson.title} up`}
                                disabled={!canMoveUp}
                                onClick={() => onMove(-1)}
                                className="border-border focus-ring min-h-10 min-w-10 rounded-lg border text-sm disabled:opacity-40"
                                title="Move lesson up"
                            >
                                ↑
                            </button>
                            <button
                                type="button"
                                aria-label={`Move lesson ${lesson.title} down`}
                                disabled={!canMoveDown}
                                onClick={() => onMove(1)}
                                className="border-border focus-ring min-h-10 min-w-10 rounded-lg border text-sm disabled:opacity-40"
                                title="Move lesson down"
                            >
                                ↓
                            </button>
                        </div>
                    )}
                </div>
            </div>
            {lesson.status === 'changes_requested' && lesson.review_note && (
                <p
                    role="status"
                    className="mt-3 rounded-lg border border-amber-300 bg-amber-50 p-3 text-sm text-amber-950"
                >
                    <strong>Reviewer note:</strong> {lesson.review_note}
                </p>
            )}
            {canManage && ['draft', 'changes_requested'].includes(lesson.status) && (
                <form onSubmit={save} className="mt-4 grid gap-3">
                    <label className="grid gap-1 text-sm font-medium">
                        Lesson title
                        <input
                            required
                            maxLength={180}
                            value={form.data.title}
                            onChange={(event) => form.setData('title', event.currentTarget.value)}
                            className="border-border bg-surface min-h-11 rounded-lg border px-3"
                        />
                    </label>
                    <label className="grid gap-1 text-sm font-medium">
                        Learning objective
                        <textarea
                            required
                            maxLength={4000}
                            rows={2}
                            value={form.data.learning_objectives}
                            onChange={(event) =>
                                form.setData('learning_objectives', event.currentTarget.value)
                            }
                            className="border-border bg-surface rounded-lg border px-3 py-2"
                        />
                        <span className="text-text-muted text-xs">
                            Use plain text. Markup is not interpreted as HTML.
                        </span>
                    </label>
                    <label className="grid gap-1 text-sm font-medium">
                        Text content
                        <textarea
                            maxLength={15000}
                            rows={6}
                            value={
                                form.data.blocks.find((block) => block.type === 'text')?.content.text ?? ''
                            }
                            onChange={(event) => updateText(event.currentTarget.value)}
                            className="border-border bg-surface rounded-lg border px-3 py-2"
                        />
                    </label>
                    <section className="grid gap-3" aria-label="Learner activities">
                        <div className="flex flex-wrap items-end gap-3">
                            <label className="grid gap-1 text-sm font-medium">
                                Add ungraded activity
                                <select
                                    value={newActivityType}
                                    onChange={(event) => setNewActivityType(event.currentTarget.value)}
                                    className="border-border bg-surface min-h-11 rounded-lg border px-3"
                                >
                                    <option value="reflection">Reflection</option>
                                    <option value="reading_acknowledgement">Reading acknowledgement</option>
                                    <option value="checklist">Checklist</option>
                                    <option value="guided_practice">Guided practice</option>
                                    <option value="self_evaluation">Self-evaluation</option>
                                    <option value="step_by_step">Step-by-step activity</option>
                                    <option value="resource_exploration">Resource exploration</option>
                                    <option value="lab_preparation">Lab/workshop preparation</option>
                                </select>
                            </label>
                            <button
                                type="button"
                                onClick={addActivity}
                                className="border-border focus-ring min-h-11 rounded-lg border px-4 text-sm font-semibold"
                            >
                                Add activity
                            </button>
                        </div>
                        {form.data.blocks.map((block, index) =>
                            block.type === 'activity' ? (
                                <fieldset
                                    key={'activity-' + index}
                                    className="border-border grid gap-3 rounded-xl border p-4"
                                >
                                    <legend className="px-1 text-sm font-semibold">
                                        {block.content.activity_type?.replaceAll('_', ' ') ?? 'Activity'}
                                    </legend>
                                    <label className="grid gap-1 text-sm font-medium">
                                        Prompt or instructions
                                        <textarea
                                            required
                                            maxLength={3000}
                                            rows={3}
                                            value={block.content.prompt ?? ''}
                                            onChange={(event) =>
                                                updateActivity(index, {
                                                    ...block.content,
                                                    prompt: event.currentTarget.value,
                                                })
                                            }
                                            className="border-border bg-surface rounded-lg border px-3 py-2"
                                        />
                                    </label>
                                    {['checklist', 'step_by_step', 'resource_exploration'].includes(
                                        block.content.activity_type ?? '',
                                    ) && (
                                        <label className="grid gap-1 text-sm font-medium">
                                            Items, one per line
                                            <textarea
                                                required
                                                rows={3}
                                                value={(block.content.items ?? []).join('\n')}
                                                onChange={(event) =>
                                                    updateActivity(index, {
                                                        ...block.content,
                                                        items: event.currentTarget.value
                                                            .split('\n')
                                                            .map((item) => item.trim())
                                                            .filter(Boolean),
                                                    })
                                                }
                                                className="border-border bg-surface rounded-lg border px-3 py-2"
                                            />
                                        </label>
                                    )}
                                    <label className="flex min-h-10 items-center gap-2 text-sm">
                                        <input
                                            type="checkbox"
                                            checked={block.content.required ?? true}
                                            onChange={(event) =>
                                                updateActivity(index, {
                                                    ...block.content,
                                                    required: event.currentTarget.checked,
                                                })
                                            }
                                        />
                                        Required for lesson completion
                                    </label>
                                    <button
                                        type="button"
                                        onClick={() =>
                                            form.setData(
                                                'blocks',
                                                form.data.blocks.filter(
                                                    (_, blockIndex) => blockIndex !== index,
                                                ),
                                            )
                                        }
                                        className="text-brand-link focus-ring min-h-10 justify-self-start text-sm font-semibold underline"
                                    >
                                        Remove activity
                                    </button>
                                </fieldset>
                            ) : null,
                        )}
                        <fieldset className="border-border grid gap-2 rounded-xl border p-4">
                            <legend className="px-1 text-sm font-semibold">Completion requirements</legend>
                            <label className="flex min-h-10 items-center gap-2 text-sm">
                                <input
                                    type="checkbox"
                                    checked={
                                        form.data.blocks.find((block) => block.type === 'completion_policy')
                                            ?.content.require_all_required_activities ?? false
                                    }
                                    onChange={(event) =>
                                        updateCompletionPolicy(
                                            'require_all_required_activities',
                                            event.currentTarget.checked,
                                        )
                                    }
                                />
                                Require all activities marked required
                            </label>
                            <label className="flex min-h-10 items-center gap-2 text-sm">
                                <input
                                    type="checkbox"
                                    checked={
                                        form.data.blocks.find((block) => block.type === 'completion_policy')
                                            ?.content.require_instructor_confirmation ?? false
                                    }
                                    onChange={(event) =>
                                        updateCompletionPolicy(
                                            'require_instructor_confirmation',
                                            event.currentTarget.checked,
                                        )
                                    }
                                />
                                Require an instructor to confirm learner completion
                            </label>
                        </fieldset>
                    </section>
                    <fieldset className="border-border grid gap-3 rounded-xl border p-4 sm:grid-cols-2">
                        <legend className="px-1 text-sm font-semibold">Learner availability</legend>
                        <label className="grid gap-1 text-sm font-medium">
                            Lesson sequence
                            <select
                                value={form.data.progression_mode}
                                onChange={(event) =>
                                    form.setData(
                                        'progression_mode',
                                        event.currentTarget.value as 'free' | 'sequential',
                                    )
                                }
                                className="border-border bg-surface min-h-11 rounded-lg border px-3"
                            >
                                <option value="free">Open navigation</option>
                                <option value="sequential">Require earlier lessons completed</option>
                            </select>
                            <span className="text-text-muted text-xs">
                                Sequential access checks each earlier published lesson against this learner’s
                                current completed revision.
                            </span>
                        </label>
                        <label className="grid gap-1 text-sm font-medium">
                            Release date and time
                            <input
                                type="datetime-local"
                                value={form.data.release_at}
                                onChange={(event) => form.setData('release_at', event.currentTarget.value)}
                                className="border-border bg-surface min-h-11 rounded-lg border px-3"
                            />
                            <span className="text-text-muted text-xs">
                                Entered in the institution time zone ({institutionTimezone}) and stored as an
                                instant.
                            </span>
                        </label>
                        <label className="grid gap-1 text-sm font-medium">
                            Available until
                            <input
                                type="datetime-local"
                                value={form.data.available_until}
                                onChange={(event) =>
                                    form.setData('available_until', event.currentTarget.value)
                                }
                                className="border-border bg-surface min-h-11 rounded-lg border px-3"
                            />
                            <span className="text-text-muted text-xs">
                                The lesson is denied after this institution-local time ({institutionTimezone}
                                ).
                            </span>
                        </label>
                        <label className="grid gap-1 text-sm font-medium">
                            Drip delay after learner assignment (days)
                            <input
                                type="number"
                                min="0"
                                max="3650"
                                value={form.data.drip_days_after_enrollment}
                                onChange={(event) =>
                                    form.setData('drip_days_after_enrollment', event.currentTarget.value)
                                }
                                className="border-border bg-surface min-h-11 rounded-lg border px-3"
                            />
                        </label>
                        <label className="grid gap-1 text-sm font-medium">
                            Prerequisite lesson IDs
                            <input
                                value={form.data.prerequisite_lesson_ids}
                                onChange={(event) =>
                                    form.setData('prerequisite_lesson_ids', event.currentTarget.value)
                                }
                                placeholder="e.g. 12, 15"
                                className="border-border bg-surface min-h-11 rounded-lg border px-3"
                            />
                            <span className="text-text-muted text-xs">
                                Use lesson IDs from this course. Each must be completed at its currently
                                published revision.
                            </span>
                        </label>
                        <label className="grid gap-1 text-sm font-medium">
                            Prerequisite module IDs
                            <input
                                value={form.data.prerequisite_module_ids}
                                onChange={(event) =>
                                    form.setData('prerequisite_module_ids', event.currentTarget.value)
                                }
                                placeholder="e.g. 4"
                                className="border-border bg-surface min-h-11 rounded-lg border px-3"
                            />
                        </label>
                        <label className="flex items-start gap-2 text-sm font-medium sm:col-span-2">
                            <input
                                type="checkbox"
                                checked={form.data.optional_path}
                                onChange={(event) =>
                                    form.setData('optional_path', event.currentTarget.checked)
                                }
                                className="focus-ring mt-1 size-4"
                            />
                            Optional lesson: learners may skip it without lowering course or module
                            completion.
                        </label>
                        <label className="flex items-start gap-2 text-sm font-medium sm:col-span-2">
                            <input
                                type="checkbox"
                                checked={form.data.module_locked}
                                onChange={(event) =>
                                    form.setData('module_locked', event.currentTarget.checked)
                                }
                                className="focus-ring mt-1 size-4 rounded"
                            />
                            Lock this module until all earlier modules are completed
                        </label>
                    </fieldset>
                    <aside
                        className="border-border bg-surface-soft rounded-xl border p-4"
                        aria-label="Instructor release preview"
                    >
                        <h3 className="text-sm font-semibold">Instructor release preview</h3>
                        <p className="text-text-muted mt-1 text-sm">
                            {releaseAtPreview
                                ? `Scheduled for ${new Date(releaseAtPreview).toLocaleString(undefined, { timeZone: institutionTimezone, timeZoneName: 'short' })} (${institutionTimezone}).`
                                : 'No scheduled release time; other access rules still apply.'}
                            {availableUntilPreview
                                ? ` Access ends ${new Date(availableUntilPreview).toLocaleString(undefined, { timeZone: institutionTimezone, timeZoneName: 'short' })}.`
                                : ''}
                            {Number(form.data.drip_days_after_enrollment) > 0
                                ? ` Learner assignment starts a ${form.data.drip_days_after_enrollment}-day drip window.`
                                : ''}
                            {form.data.progression_mode === 'sequential'
                                ? ' Earlier required lessons must be completed at their current published revisions.'
                                : ' Navigation is open unless prerequisites or module locks apply.'}
                            {form.data.optional_path
                                ? ' This lesson is optional and excluded from completion totals.'
                                : ''}
                        </p>
                        <p className="text-text-muted mt-2 text-xs">
                            This summarizes the saved draft rules. Actual learner access is checked
                            server-side against assignment, campus, progress and any approved exception.
                        </p>
                    </aside>
                    <FormErrors />
                    <div className="flex flex-wrap gap-2">
                        <button
                            disabled={form.processing}
                            className="bg-brand-action hover:bg-brand-action-hover focus-ring min-h-11 rounded-lg px-4 text-sm font-semibold text-white disabled:opacity-60"
                        >
                            Save draft
                        </button>
                        <button
                            type="button"
                            onClick={() => router.post(`${base}/submit`, {}, { preserveScroll: true })}
                            className="border-border hover:bg-surface-soft focus-ring min-h-11 rounded-lg border px-4 text-sm font-semibold"
                        >
                            Submit for review
                        </button>
                    </div>
                </form>
            )}
            {canManage && ['draft', 'changes_requested'].includes(lesson.status) && (
                <section
                    className="border-border mt-5 grid gap-3 border-t pt-4"
                    aria-label="Lesson media assets"
                >
                    <h5 className="font-semibold">Private learning media</h5>
                    <p className="text-text-muted text-sm">
                        Upload PDF, image, audio, video, or plain text resources up to 20 MB. Files stay
                        private in quarantine until the configured malware scanner approves them.
                    </p>
                    {lesson.blocks.filter((block) => block.type === 'media_asset').length > 0 && (
                        <ul className="grid gap-2" aria-label="Attached media scan status">
                            {lesson.blocks
                                .filter((block) => block.type === 'media_asset')
                                .map((block) => (
                                    <li
                                        key={block.content.asset_id}
                                        className="bg-surface-soft flex flex-wrap items-center justify-between gap-2 rounded-lg p-3 text-sm"
                                    >
                                        <span>{block.content.original_name}</span>
                                        <span className="text-text-muted text-xs">
                                            Version {block.content.asset_version ?? 1}
                                        </span>
                                        <span className="text-text-muted text-xs capitalize">
                                            {block.content.status ?? 'quarantined'}
                                        </span>
                                        <button
                                            type="button"
                                            onClick={() =>
                                                router.post(
                                                    `${base}/media/${block.content.asset_id}/revoke`,
                                                    {},
                                                    { preserveScroll: true },
                                                )
                                            }
                                            className="border-border focus-ring min-h-9 rounded-lg border px-3 text-xs font-semibold"
                                        >
                                            Revoke access
                                        </button>
                                        <span className="text-text-muted text-xs">
                                            {block.content.license_label}
                                            {block.content.attribution
                                                ? ` · ${block.content.attribution}`
                                                : ''}
                                        </span>
                                    </li>
                                ))}
                        </ul>
                    )}
                    <form
                        onSubmit={(event) => {
                            event.preventDefault();
                            mediaForm.post(`${base}/media`, {
                                forceFormData: true,
                                preserveScroll: true,
                                onSuccess: () => mediaForm.reset(),
                            });
                        }}
                        className="grid gap-3 sm:grid-cols-2"
                    >
                        <label className="grid gap-1 text-sm font-medium">
                            Replace existing version (optional)
                            <select
                                value={mediaForm.data.replaces_asset_id}
                                onChange={(event) =>
                                    mediaForm.setData('replaces_asset_id', event.currentTarget.value)
                                }
                                className="border-border bg-surface min-h-11 rounded-lg border px-3"
                            >
                                <option value="">Upload as a new asset</option>
                                {lesson.blocks
                                    .filter((block) => block.type === 'media_asset')
                                    .map((block) => (
                                        <option key={block.content.asset_id} value={block.content.asset_id}>
                                            {block.content.original_name} (v{block.content.asset_version ?? 1}
                                            )
                                        </option>
                                    ))}
                            </select>
                        </label>
                        <label className="grid gap-1 text-sm font-medium">
                            File
                            <input
                                type="file"
                                required
                                accept=".pdf,.jpg,.jpeg,.png,.webp,.mp3,.ogg,.wav,.mp4,.webm,.txt"
                                onChange={(event) =>
                                    mediaForm.setData('file', event.currentTarget.files?.[0] ?? null)
                                }
                                className="border-border bg-surface min-h-11 rounded-lg border p-2"
                            />
                        </label>
                        <label className="grid gap-1 text-sm font-medium">
                            License or permission
                            <input
                                required
                                maxLength={120}
                                value={mediaForm.data.license_label}
                                onChange={(event) =>
                                    mediaForm.setData('license_label', event.currentTarget.value)
                                }
                                className="border-border bg-surface min-h-11 rounded-lg border px-3"
                            />
                        </label>
                        <label className="grid gap-1 text-sm font-medium">
                            Alternative text (required for images)
                            <input
                                maxLength={1000}
                                value={mediaForm.data.alt_text}
                                onChange={(event) => mediaForm.setData('alt_text', event.currentTarget.value)}
                                className="border-border bg-surface min-h-11 rounded-lg border px-3"
                            />
                        </label>
                        <label className="grid gap-1 text-sm font-medium">
                            Attribution
                            <input
                                maxLength={1000}
                                value={mediaForm.data.attribution}
                                onChange={(event) =>
                                    mediaForm.setData('attribution', event.currentTarget.value)
                                }
                                className="border-border bg-surface min-h-11 rounded-lg border px-3"
                            />
                        </label>
                        <label className="grid gap-1 text-sm font-medium sm:col-span-2">
                            Transcript or accessible alternative
                            <textarea
                                maxLength={50000}
                                rows={3}
                                value={mediaForm.data.transcript}
                                onChange={(event) =>
                                    mediaForm.setData('transcript', event.currentTarget.value)
                                }
                                className="border-border bg-surface rounded-lg border px-3 py-2"
                            />
                        </label>
                        <button
                            type="submit"
                            disabled={mediaForm.processing}
                            className="bg-brand-action focus-ring min-h-11 justify-self-start rounded-lg px-4 text-sm font-semibold text-white disabled:opacity-60"
                        >
                            Upload and scan
                        </button>
                    </form>
                </section>
            )}
            {!canManage && (
                <p className="text-text-muted mt-3 text-sm">
                    {lesson.summary ||
                        lesson.learning_objectives ||
                        'Lesson content is available in the current version.'}
                </p>
            )}
            {canReview && lesson.status === 'in_review' && (
                <form
                    onSubmit={(event) => {
                        event.preventDefault();
                        review.post(`${base}/review`, { preserveScroll: true });
                    }}
                    className="border-border mt-4 grid gap-3 border-t pt-4 sm:grid-cols-[1fr_2fr_auto]"
                >
                    <label className="grid gap-1 text-sm font-medium">
                        Decision
                        <select
                            value={review.data.decision}
                            onChange={(event) => review.setData('decision', event.currentTarget.value)}
                            className="border-border bg-surface min-h-11 rounded-lg border px-3"
                        >
                            <option value="approved">Approve</option>
                            <option value="changes_requested">Request changes</option>
                            <option value="rejected">Reject</option>
                        </select>
                    </label>
                    <label className="grid gap-1 text-sm font-medium">
                        Reviewer note
                        <textarea
                            rows={2}
                            value={review.data.note}
                            onChange={(event) => review.setData('note', event.currentTarget.value)}
                            className="border-border bg-surface rounded-lg border px-3 py-2"
                        />
                    </label>
                    <button className="bg-brand-action focus-ring min-h-11 self-end rounded-lg px-4 text-sm font-semibold text-white">
                        Record review
                    </button>
                </form>
            )}
            {canPublish && lesson.status === 'approved' && (
                <button
                    onClick={() => router.post(`${base}/publish`, {}, { preserveScroll: true })}
                    className="bg-brand-action focus-ring mt-4 min-h-11 rounded-lg px-4 text-sm font-semibold text-white"
                >
                    Publish reviewed version
                </button>
            )}
            {canManage && lesson.status === 'published' && (
                <button
                    onClick={() => router.post(`${base}/revise`, {}, { preserveScroll: true })}
                    className="border-border hover:bg-surface-soft focus-ring mt-4 min-h-11 rounded-lg border px-4 text-sm font-semibold"
                >
                    Start a new revision
                </button>
            )}
            {canLearn && lesson.status === 'published' && (
                <Link
                    href={`/t/${tenantSlug}/learning/${workspaceId}/lessons/${lesson.id}`}
                    className="bg-brand-action focus-ring mt-4 inline-flex min-h-11 items-center rounded-lg px-4 text-sm font-semibold text-white"
                >
                    Open published lesson
                    {lesson.published_version_number ? ` · v${lesson.published_version_number}` : ''}
                </Link>
            )}
        </article>
    );
}

function toZonedDateTime(value: string, timezone: string): string {
    const date = new Date(value);
    if (Number.isNaN(date.getTime())) return '';
    const parts = new Intl.DateTimeFormat('en-CA', {
        timeZone: timezone,
        year: 'numeric',
        month: '2-digit',
        day: '2-digit',
        hour: '2-digit',
        minute: '2-digit',
        hourCycle: 'h23',
    }).formatToParts(date);
    const fields = Object.fromEntries(parts.map((part) => [part.type, part.value]));
    return `${fields.year}-${fields.month}-${fields.day}T${fields.hour}:${fields.minute}`;
}

function fromZonedDateTime(value: string, timezone: string): string | null {
    const match = /^(\d{4})-(\d{2})-(\d{2})T(\d{2}):(\d{2})$/.exec(value);
    if (!match) return null;
    const desired = match.slice(1).map(Number);
    const target = Date.UTC(desired[0], desired[1] - 1, desired[2], desired[3], desired[4]);
    if (new Date(target).toISOString().slice(0, 16) !== value) return null;
    let instant = target;
    try {
        for (let attempt = 0; attempt < 4; attempt++) {
            const parts = new Intl.DateTimeFormat('en-CA', {
                timeZone: timezone,
                year: 'numeric',
                month: '2-digit',
                day: '2-digit',
                hour: '2-digit',
                minute: '2-digit',
                hourCycle: 'h23',
            }).formatToParts(new Date(instant));
            const fields = Object.fromEntries(parts.map((part) => [part.type, Number(part.value)]));
            const represented = Date.UTC(
                fields.year,
                fields.month - 1,
                fields.day,
                fields.hour,
                fields.minute,
            );
            const delta = target - represented;
            instant += delta;
            if (delta === 0) return `${new Date(instant).toISOString().slice(0, 19)}Z`;
        }
    } catch {
        return null;
    }
    return null;
}

export default function LearningShow({
    tenant,
    workspace,
    members,
    canManage,
    canReview,
    canPublish,
    canLearn,
    canConfirmCompletion,
    sessions,
    sessionInstructors,
    canManageSessions,
    institutionTimezone,
    releaseExceptions,
    canApproveReleaseExceptions,
    actorUserId,
}: Props) {
    const moduleForm = useForm({ title: '', summary: '' });
    const assignmentForm = useForm({
        membership_id: '',
        assignment_role: 'instructor',
        effective_from: '',
        effective_until: '',
        responsibility: '',
    });
    const releaseExceptionForm = useForm({
        lesson_id: '',
        membership_id: '',
        reason: '',
        expires_at: '',
    });
    const addModule = (event: FormEvent) => {
        event.preventDefault();
        moduleForm.post(`/t/${tenant.slug}/learning/${workspace.id}/modules`, {
            preserveScroll: true,
            onSuccess: () => moduleForm.reset(),
        });
    };
    const addAssignment = (event: FormEvent) => {
        event.preventDefault();
        assignmentForm.post(`/t/${tenant.slug}/learning/${workspace.id}/assignments`, {
            preserveScroll: true,
            onSuccess: () => assignmentForm.reset('membership_id', 'responsibility'),
        });
    };
    const requestReleaseException = (event: FormEvent) => {
        event.preventDefault();
        const expiresAt = fromZonedDateTime(releaseExceptionForm.data.expires_at, institutionTimezone);
        if (!expiresAt) {
            releaseExceptionForm.setError(
                'expires_at',
                `Choose a valid date and time in ${institutionTimezone}.`,
            );
            return;
        }
        router.post(
            `/t/${tenant.slug}/learning/${workspace.id}/lessons/${releaseExceptionForm.data.lesson_id}/release-exceptions`,
            { ...releaseExceptionForm.data, expires_at: expiresAt },
            {
                preserveScroll: true,
                onSuccess: () => releaseExceptionForm.reset('membership_id', 'reason', 'expires_at'),
            },
        );
    };
    const assignable = members.filter((member) =>
        assignmentForm.data.assignment_role === 'learner'
            ? member.canLearn
            : assignmentForm.data.assignment_role === 'reviewer'
              ? member.canReview
              : member.canTeach,
    );
    const reorderModules = (index: number, direction: -1 | 1) => {
        const next = [...workspace.modules.map((module) => module.id)];
        const destination = index + direction;
        if (destination < 0 || destination >= next.length) return;
        [next[index], next[destination]] = [next[destination], next[index]];
        router.put(
            `/t/${tenant.slug}/learning/${workspace.id}/modules/reorder`,
            { module_ids: next },
            { preserveScroll: true },
        );
    };
    const reorderLessons = (module: Module, index: number, direction: -1 | 1) => {
        const next = [...module.lessons.map((lesson) => lesson.id)];
        const destination = index + direction;
        if (destination < 0 || destination >= next.length) return;
        [next[index], next[destination]] = [next[destination], next[index]];
        router.put(
            `/t/${tenant.slug}/learning/${workspace.id}/modules/${module.id}/lessons/reorder`,
            { lesson_ids: next },
            { preserveScroll: true },
        );
    };

    return (
        <WorkspaceLayout title={workspace.title} area="tenant">
            <Head title={workspace.title} />
            <PageHeader
                eyebrow={`Course delivery · ${workspace.code}`}
                title={workspace.title}
                description={`${workspace.course.code} · ${workspace.course.title} · catalog version ${workspace.course_version.version_number}`}
            />
            {canConfirmCompletion && (
                <Link
                    href={'/t/' + tenant.slug + '/learning/' + workspace.id + '/completion-confirmations'}
                    className="border-border focus-ring mt-4 inline-flex min-h-11 items-center rounded-lg border px-4 text-sm font-semibold"
                >
                    Review learner completion requests
                </Link>
            )}
            <div className="mt-5 flex flex-wrap items-center gap-2">
                <span className="bg-surface-soft rounded-full px-3 py-1 text-xs font-semibold capitalize">
                    {workspace.status}
                </span>
                <span className="bg-surface-soft rounded-full px-3 py-1 text-xs font-semibold capitalize">
                    {workspace.delivery_mode.replace('_', ' ')}
                </span>
                {workspace.curriculum_version && (
                    <span className="bg-surface-soft rounded-full px-3 py-1 text-xs">
                        Published curriculum v{workspace.curriculum_version.version_number}
                    </span>
                )}
                <Link
                    href={`/t/${tenant.slug}/learning`}
                    className="focus-ring text-brand-link ml-auto inline-flex min-h-11 items-center text-sm font-semibold"
                >
                    Back to directory
                </Link>
            </div>
            {workspace.description && (
                <p className="text-text-muted mt-4 max-w-3xl text-sm leading-6">{workspace.description}</p>
            )}

            <LiveSessions
                tenantSlug={tenant.slug}
                workspaceId={workspace.id}
                sessions={sessions}
                instructors={sessionInstructors}
                canManage={canManageSessions}
                defaultTimezone={institutionTimezone}
                lessons={workspace.modules.flatMap((module) =>
                    module.lessons.map((lesson) => ({ id: lesson.id, title: lesson.title })),
                )}
            />

            {canManage && (
                <section className="border-border bg-surface mt-6 rounded-2xl border p-5">
                    <h2 className="text-lg font-semibold">Assign course access</h2>
                    <p className="text-text-muted mt-1 text-sm">
                        Assignments are scoped to this workspace and cannot add permissions the institution
                        membership does not already hold.
                    </p>
                    <form onSubmit={addAssignment} className="learning-assignment-form mt-4 grid gap-3">
                        <label
                            htmlFor="learning-assignment-member"
                            className="grid gap-1 text-sm font-medium"
                        >
                            Institution member
                            <select
                                id="learning-assignment-member"
                                required
                                value={assignmentForm.data.membership_id}
                                onChange={(event) =>
                                    assignmentForm.setData('membership_id', event.currentTarget.value)
                                }
                                className="border-border bg-surface min-h-11 rounded-lg border px-3"
                            >
                                <option value="">Choose member</option>
                                {assignable.map((member) => (
                                    <option value={member.id} key={member.id}>
                                        {member.name}
                                    </option>
                                ))}
                            </select>
                        </label>
                        <label htmlFor="learning-assignment-role" className="grid gap-1 text-sm font-medium">
                            Course role
                            <select
                                id="learning-assignment-role"
                                value={assignmentForm.data.assignment_role}
                                onChange={(event) => {
                                    assignmentForm.setData('assignment_role', event.currentTarget.value);
                                    assignmentForm.setData('membership_id', '');
                                }}
                                className="border-border bg-surface min-h-11 rounded-lg border px-3"
                            >
                                <option value="course_owner">Course owner</option>
                                <option value="instructor">Instructor</option>
                                <option value="coordinator">Coordinator</option>
                                <option value="content_author">Content author</option>
                                <option value="reviewer">Reviewer</option>
                                <option value="publisher">Publisher</option>
                                <option value="learner">Learner access</option>
                            </select>
                        </label>
                        <label className="grid gap-1 text-sm font-medium">
                            Starts on
                            <input
                                type="date"
                                value={assignmentForm.data.effective_from}
                                onChange={(event) =>
                                    assignmentForm.setData('effective_from', event.currentTarget.value)
                                }
                                className="border-border bg-surface min-h-11 rounded-lg border px-3"
                            />
                        </label>
                        <label className="grid gap-1 text-sm font-medium">
                            Ends on
                            <input
                                type="date"
                                value={assignmentForm.data.effective_until}
                                onChange={(event) =>
                                    assignmentForm.setData('effective_until', event.currentTarget.value)
                                }
                                className="border-border bg-surface min-h-11 rounded-lg border px-3"
                            />
                        </label>
                        <div className="flex items-end">
                            <button
                                disabled={assignmentForm.processing || assignable.length === 0}
                                className="bg-brand-action focus-ring min-h-11 w-full rounded-lg px-4 text-sm font-semibold text-white disabled:opacity-50"
                            >
                                Assign member
                            </button>
                        </div>
                    </form>
                    {assignmentForm.errors.membership_id && (
                        <p className="mt-2 text-sm text-red-700">{assignmentForm.errors.membership_id}</p>
                    )}
                    {workspace.assignments.length > 0 && (
                        <ul className="mt-4 grid gap-2 sm:grid-cols-2">
                            {workspace.assignments.map((assignment) => (
                                <AssignmentRow
                                    key={assignment.id}
                                    tenantSlug={tenant.slug}
                                    workspaceId={workspace.id}
                                    assignment={assignment}
                                />
                            ))}
                        </ul>
                    )}
                </section>
            )}

            {canManage && workspace.status === 'draft' && (
                <form
                    onSubmit={addModule}
                    className="border-border bg-surface mt-6 grid gap-3 rounded-2xl border p-5 sm:grid-cols-[1fr_2fr_auto]"
                >
                    <label className="grid gap-1 text-sm font-medium">
                        New module
                        <input
                            required
                            maxLength={180}
                            value={moduleForm.data.title}
                            onChange={(event) => moduleForm.setData('title', event.currentTarget.value)}
                            className="border-border bg-surface min-h-11 rounded-lg border px-3"
                        />
                    </label>
                    <label className="grid gap-1 text-sm font-medium">
                        Summary
                        <input
                            maxLength={2000}
                            value={moduleForm.data.summary}
                            onChange={(event) => moduleForm.setData('summary', event.currentTarget.value)}
                            className="border-border bg-surface min-h-11 rounded-lg border px-3"
                        />
                    </label>
                    <button
                        disabled={moduleForm.processing}
                        className="bg-brand-action focus-ring min-h-11 self-end rounded-lg px-4 text-sm font-semibold text-white"
                    >
                        Add module
                    </button>
                </form>
            )}

            {(canManage || canApproveReleaseExceptions) && (
                <section
                    className="border-border bg-surface mt-6 grid gap-4 rounded-2xl border p-5"
                    aria-labelledby="release-exceptions-title"
                >
                    <div>
                        <h2 id="release-exceptions-title" className="text-lg font-semibold">
                            Learner release exceptions
                        </h2>
                        <p className="text-text-muted mt-1 text-sm">
                            Exceptions require a second authorized person, expire within 30 days, and remain
                            auditable.
                        </p>
                    </div>
                    {canManage && (
                        <form onSubmit={requestReleaseException} className="learning-release-form grid gap-3">
                            <label className="grid gap-1 text-sm font-medium">
                                Published lesson
                                <select
                                    required
                                    value={releaseExceptionForm.data.lesson_id}
                                    onChange={(event) =>
                                        releaseExceptionForm.setData('lesson_id', event.currentTarget.value)
                                    }
                                    className="border-border bg-surface min-h-11 rounded-lg border px-3"
                                >
                                    <option value="">Choose lesson</option>
                                    {workspace.modules.flatMap((module) =>
                                        module.lessons
                                            .filter((lesson) => lesson.published_version_number !== null)
                                            .map((lesson) => (
                                                <option key={lesson.id} value={lesson.id}>
                                                    {lesson.title}
                                                </option>
                                            )),
                                    )}
                                </select>
                            </label>
                            <label className="grid gap-1 text-sm font-medium">
                                Assigned learner
                                <select
                                    required
                                    value={releaseExceptionForm.data.membership_id}
                                    onChange={(event) =>
                                        releaseExceptionForm.setData(
                                            'membership_id',
                                            event.currentTarget.value,
                                        )
                                    }
                                    className="border-border bg-surface min-h-11 rounded-lg border px-3"
                                >
                                    <option value="">Choose learner</option>
                                    {workspace.assignments
                                        .filter(
                                            (assignment) =>
                                                assignment.role === 'learner' &&
                                                assignment.status === 'active',
                                        )
                                        .map((assignment) => (
                                            <option key={assignment.member.id} value={assignment.member.id}>
                                                {assignment.member.name}
                                            </option>
                                        ))}
                                </select>
                            </label>
                            <label className="grid gap-1 text-sm font-medium">
                                Reason
                                <input
                                    required
                                    minLength={12}
                                    maxLength={1000}
                                    value={releaseExceptionForm.data.reason}
                                    onChange={(event) =>
                                        releaseExceptionForm.setData('reason', event.currentTarget.value)
                                    }
                                    className="border-border bg-surface min-h-11 rounded-lg border px-3"
                                />
                            </label>
                            <label className="grid gap-1 text-sm font-medium">
                                Expires ({institutionTimezone})
                                <input
                                    type="datetime-local"
                                    required
                                    value={releaseExceptionForm.data.expires_at}
                                    onChange={(event) =>
                                        releaseExceptionForm.setData('expires_at', event.currentTarget.value)
                                    }
                                    className="border-border bg-surface min-h-11 rounded-lg border px-3"
                                />
                            </label>
                            <button
                                disabled={releaseExceptionForm.processing}
                                className="bg-brand-action focus-ring min-h-11 rounded-lg px-4 text-sm font-semibold text-white sm:col-span-2 xl:col-span-4"
                            >
                                Request independent approval
                            </button>
                            <FormErrors />
                        </form>
                    )}
                    {releaseExceptions.length === 0 ? (
                        <p className="text-text-muted text-sm">No exception requests.</p>
                    ) : (
                        <ul className="grid gap-3 md:grid-cols-2">
                            {releaseExceptions.map((exception) => (
                                <li key={exception.id} className="border-border rounded-xl border p-4">
                                    <p className="font-semibold">
                                        {exception.learner_name} · {exception.status}
                                    </p>
                                    <p className="text-text-muted mt-1 text-sm">{exception.reason}</p>
                                    <p className="text-text-muted mt-1 text-xs">
                                        Expires {new Date(exception.expires_at).toLocaleString()}
                                    </p>
                                    <div className="mt-3 flex flex-wrap gap-2">
                                        {canApproveReleaseExceptions &&
                                            exception.status === 'pending' &&
                                            exception.requested_by !== actorUserId && (
                                                <>
                                                    <button
                                                        type="button"
                                                        onClick={() =>
                                                            router.post(
                                                                `/t/${tenant.slug}/learning/${workspace.id}/release-exceptions/${exception.id}/decision`,
                                                                { decision: 'approve' },
                                                                { preserveScroll: true },
                                                            )
                                                        }
                                                        className="bg-brand-action focus-ring min-h-10 rounded-lg px-3 text-sm font-semibold text-white"
                                                    >
                                                        Approve
                                                    </button>
                                                    <button
                                                        type="button"
                                                        onClick={() =>
                                                            router.post(
                                                                `/t/${tenant.slug}/learning/${workspace.id}/release-exceptions/${exception.id}/decision`,
                                                                { decision: 'reject' },
                                                                { preserveScroll: true },
                                                            )
                                                        }
                                                        className="border-border focus-ring min-h-10 rounded-lg border px-3 text-sm font-semibold"
                                                    >
                                                        Reject
                                                    </button>
                                                </>
                                            )}
                                        {canManage &&
                                            exception.status === 'approved' &&
                                            !exception.revoked_at && (
                                                <button
                                                    type="button"
                                                    onClick={() => {
                                                        const reason = window.prompt(
                                                            'Reason for revoking this exception',
                                                        );
                                                        if (reason?.trim())
                                                            router.post(
                                                                `/t/${tenant.slug}/learning/${workspace.id}/release-exceptions/${exception.id}/revoke`,
                                                                { reason },
                                                                { preserveScroll: true },
                                                            );
                                                    }}
                                                    className="border-border focus-ring min-h-10 rounded-lg border px-3 text-sm font-semibold"
                                                >
                                                    Revoke access
                                                </button>
                                            )}
                                    </div>
                                </li>
                            ))}
                        </ul>
                    )}
                </section>
            )}

            <section className="mt-8" aria-labelledby="module-list-title">
                <div className="flex flex-wrap items-end justify-between gap-3">
                    <div>
                        <p className="text-text-muted text-xs font-semibold tracking-wide uppercase">
                            Authoring structure
                        </p>
                        <h2 id="module-list-title" className="mt-1 text-xl font-semibold">
                            Modules and lessons
                        </h2>
                    </div>
                    {canPublish && workspace.status === 'draft' && (
                        <button
                            onClick={() =>
                                router.post(
                                    `/t/${tenant.slug}/learning/${workspace.id}/publish`,
                                    {},
                                    { preserveScroll: true },
                                )
                            }
                            className="border-border hover:bg-surface-soft focus-ring min-h-11 rounded-lg border px-4 text-sm font-semibold"
                        >
                            Publish workspace
                        </button>
                    )}
                </div>
                {workspace.modules.length === 0 ? (
                    <div className="border-border bg-surface mt-4 rounded-2xl border border-dashed p-8 text-center">
                        <h3 className="font-semibold">No modules yet</h3>
                        <p className="text-text-muted mt-2 text-sm">
                            Add a module to begin structuring course content.
                        </p>
                    </div>
                ) : (
                    workspace.modules.map((module, moduleIndex) => (
                        <section
                            key={module.id}
                            className="border-border bg-surface mt-4 rounded-2xl border p-5"
                        >
                            <div className="flex flex-wrap items-center justify-between gap-3">
                                <div>
                                    <p className="text-text-muted text-xs">Module {module.sort_order}</p>
                                    <h3 className="mt-1 text-lg font-semibold">{module.title}</h3>
                                    {module.summary && (
                                        <p className="text-text-muted mt-1 text-sm">{module.summary}</p>
                                    )}
                                </div>
                                <div className="flex items-center gap-3">
                                    <span className="text-text-muted text-sm">
                                        {module.lessons.length} lessons
                                    </span>
                                    {canManage && workspace.status === 'draft' && (
                                        <div className="flex gap-1">
                                            <button
                                                type="button"
                                                aria-label={`Move module ${module.title} up`}
                                                disabled={moduleIndex === 0}
                                                onClick={() => reorderModules(moduleIndex, -1)}
                                                className="border-border focus-ring min-h-10 min-w-10 rounded-lg border text-sm disabled:opacity-40"
                                                title="Move module up"
                                            >
                                                ↑
                                            </button>
                                            <button
                                                type="button"
                                                aria-label={`Move module ${module.title} down`}
                                                disabled={moduleIndex === workspace.modules.length - 1}
                                                onClick={() => reorderModules(moduleIndex, 1)}
                                                className="border-border focus-ring min-h-10 min-w-10 rounded-lg border text-sm disabled:opacity-40"
                                                title="Move module down"
                                            >
                                                ↓
                                            </button>
                                        </div>
                                    )}
                                </div>
                            </div>
                            {canManage && workspace.status === 'draft' && (
                                <AddLessonForm
                                    tenantSlug={tenant.slug}
                                    workspaceId={workspace.id}
                                    module={module}
                                />
                            )}
                            {module.lessons.map((lesson, lessonIndex) => (
                                <LessonEditor
                                    key={lesson.id}
                                    tenantSlug={tenant.slug}
                                    workspaceId={workspace.id}
                                    institutionTimezone={institutionTimezone}
                                    lesson={lesson}
                                    canManage={canManage}
                                    canReview={canReview}
                                    canPublish={canPublish}
                                    canLearn={canLearn && workspace.status === 'published'}
                                    canReorder={canManage && workspace.status === 'draft'}
                                    canMoveUp={lessonIndex > 0}
                                    canMoveDown={lessonIndex < module.lessons.length - 1}
                                    onMove={(direction) => reorderLessons(module, lessonIndex, direction)}
                                />
                            ))}
                        </section>
                    ))
                )}
            </section>
        </WorkspaceLayout>
    );
}

function LiveSessions({
    tenantSlug,
    workspaceId,
    sessions,
    instructors,
    canManage,
    defaultTimezone,
    lessons,
}: {
    tenantSlug: string;
    workspaceId: number;
    sessions: LiveSession[];
    instructors: { id: number; name: string }[];
    canManage: boolean;
    defaultTimezone: string;
    lessons: { id: number; title: string }[];
}) {
    const form = useForm({
        lesson_id: '',
        instructor_membership_id: '',
        title: '',
        description: '',
        delivery_type: 'online',
        timezone: defaultTimezone,
        starts_at: '',
        ends_at: '',
        meeting_url: '',
        venue: '',
        preparation_materials: '',
        post_session_resources: '',
    });
    const base = `/t/${tenantSlug}/learning/${workspaceId}/sessions`;
    return (
        <section
            className="border-border bg-surface mt-6 rounded-2xl border p-5"
            aria-labelledby="live-session-title"
        >
            <h2 id="live-session-title" className="text-lg font-semibold">
                Live and blended sessions
            </h2>
            <p className="text-text-muted mt-1 text-sm">
                Session times are entered in the selected IANA time zone and stored as UTC instants.
            </p>
            {sessions.length === 0 ? (
                <p className="bg-surface-soft mt-4 rounded-lg p-4 text-sm">No live sessions are scheduled.</p>
            ) : (
                <ol className="mt-4 grid gap-3">
                    {sessions.map((session) => (
                        <LiveSessionRow
                            key={session.id}
                            session={session}
                            base={base}
                            canManage={canManage}
                        />
                    ))}
                </ol>
            )}
            {canManage && instructors.length > 0 && (
                <form
                    className="learning-session-form border-border mt-5 grid gap-3 border-t pt-5"
                    onSubmit={(event) => {
                        event.preventDefault();
                        form.post(base, {
                            preserveScroll: true,
                            onSuccess: () =>
                                form.reset(
                                    'lesson_id',
                                    'title',
                                    'description',
                                    'starts_at',
                                    'ends_at',
                                    'meeting_url',
                                    'venue',
                                    'preparation_materials',
                                    'post_session_resources',
                                ),
                        });
                    }}
                >
                    <h3 className="font-semibold md:col-span-2">Schedule a session</h3>
                    <label className="grid gap-1 text-sm font-medium">
                        Title
                        <input
                            required
                            maxLength={180}
                            value={form.data.title}
                            onChange={(event) => form.setData('title', event.currentTarget.value)}
                            className="border-border bg-surface min-h-11 rounded-lg border px-3"
                        />
                    </label>
                    <label className="grid gap-1 text-sm font-medium">
                        Assigned instructor
                        <select
                            required
                            value={form.data.instructor_membership_id}
                            onChange={(event) =>
                                form.setData('instructor_membership_id', event.currentTarget.value)
                            }
                            className="border-border bg-surface min-h-11 rounded-lg border px-3"
                        >
                            <option value="">Choose instructor</option>
                            {instructors.map((instructor) => (
                                <option key={instructor.id} value={instructor.id}>
                                    {instructor.name}
                                </option>
                            ))}
                        </select>
                    </label>
                    <label className="grid gap-1 text-sm font-medium">
                        Lesson (optional)
                        <select
                            value={form.data.lesson_id}
                            onChange={(event) => form.setData('lesson_id', event.currentTarget.value)}
                            className="border-border bg-surface min-h-11 rounded-lg border px-3"
                        >
                            <option value="">Course-wide session</option>
                            {lessons.map((lesson) => (
                                <option key={lesson.id} value={lesson.id}>
                                    {lesson.title}
                                </option>
                            ))}
                        </select>
                    </label>
                    <label className="grid gap-1 text-sm font-medium">
                        Format
                        <select
                            value={form.data.delivery_type}
                            onChange={(event) => form.setData('delivery_type', event.currentTarget.value)}
                            className="border-border bg-surface min-h-11 rounded-lg border px-3"
                        >
                            <option value="online">Online</option>
                            <option value="in_person">In person</option>
                            <option value="hybrid">Hybrid</option>
                        </select>
                    </label>
                    <label className="grid gap-1 text-sm font-medium">
                        Time zone
                        <input
                            required
                            maxLength={64}
                            value={form.data.timezone}
                            onChange={(event) => form.setData('timezone', event.currentTarget.value)}
                            className="border-border bg-surface min-h-11 rounded-lg border px-3"
                        />
                        <span className="text-text-muted text-xs">
                            Use an IANA name, such as Asia/Karachi.
                        </span>
                    </label>
                    <label className="grid gap-1 text-sm font-medium">
                        Starts at
                        <input
                            type="datetime-local"
                            required
                            value={form.data.starts_at}
                            onChange={(event) => form.setData('starts_at', event.currentTarget.value)}
                            className="border-border bg-surface min-h-11 rounded-lg border px-3"
                        />
                    </label>
                    <label className="grid gap-1 text-sm font-medium">
                        Ends at
                        <input
                            type="datetime-local"
                            required
                            value={form.data.ends_at}
                            onChange={(event) => form.setData('ends_at', event.currentTarget.value)}
                            className="border-border bg-surface min-h-11 rounded-lg border px-3"
                        />
                    </label>
                    <label className="grid gap-1 text-sm font-medium">
                        Secure meeting link (HTTPS)
                        <input
                            type="url"
                            value={form.data.meeting_url}
                            onChange={(event) => form.setData('meeting_url', event.currentTarget.value)}
                            className="border-border bg-surface min-h-11 rounded-lg border px-3"
                        />
                    </label>
                    <label className="grid gap-1 text-sm font-medium">
                        Physical venue
                        <input
                            maxLength={240}
                            value={form.data.venue}
                            onChange={(event) => form.setData('venue', event.currentTarget.value)}
                            className="border-border bg-surface min-h-11 rounded-lg border px-3"
                        />
                    </label>
                    <label className="grid gap-1 text-sm font-medium md:col-span-2">
                        Preparation materials
                        <textarea
                            maxLength={5000}
                            rows={2}
                            value={form.data.preparation_materials}
                            onChange={(event) =>
                                form.setData('preparation_materials', event.currentTarget.value)
                            }
                            className="border-border bg-surface rounded-lg border px-3 py-2"
                        />
                    </label>
                    <FormErrors />
                    <div className="md:col-span-2">
                        <button
                            disabled={form.processing}
                            className="bg-brand-action focus-ring min-h-11 rounded-lg px-4 text-sm font-semibold text-white"
                        >
                            Schedule live session
                        </button>
                    </div>
                </form>
            )}
        </section>
    );
}

function LiveSessionRow({
    session,
    base,
    canManage,
}: {
    session: LiveSession;
    base: string;
    canManage: boolean;
}) {
    const [editing, setEditing] = useState(false);
    const form = useForm({
        title: session.title,
        description: session.description ?? '',
        delivery_type: session.delivery_type,
        timezone: session.timezone,
        starts_at: toZonedDateTime(session.starts_at, session.timezone),
        ends_at: toZonedDateTime(session.ends_at, session.timezone),
        meeting_url: session.meeting_url ?? '',
        venue: session.venue ?? '',
        preparation_materials: session.preparation_materials ?? '',
        post_session_resources: session.post_session_resources ?? '',
    });
    const cancellation = useForm({ reason: '' });
    return (
        <li className="border-border rounded-xl border p-4">
            <div className="flex flex-wrap items-start justify-between gap-3">
                <div>
                    <h3 className="font-semibold">{session.title}</h3>
                    <p className="text-text-muted text-sm">
                        {new Date(session.starts_at).toLocaleString(undefined, {
                            timeZone: session.timezone,
                            timeZoneName: 'short',
                        })}{' '}
                        –{' '}
                        {new Date(session.ends_at).toLocaleTimeString(undefined, {
                            timeZone: session.timezone,
                            timeZoneName: 'short',
                        })}{' '}
                        · {session.timezone}
                    </p>
                    <p className="text-text-muted text-sm">
                        {session.instructor} · {session.delivery_type.replace('_', ' ')}
                        {session.lesson ? ` · ${session.lesson}` : ''} · {session.status}
                    </p>
                </div>
                {session.status === 'scheduled' && session.meeting_url && (
                    <a
                        className="focus-ring text-brand-link underline"
                        href={session.meeting_url}
                        target="_blank"
                        rel="noreferrer"
                    >
                        Join meeting
                    </a>
                )}
            </div>
            {session.status === 'cancelled' && (
                <p className="mt-2 text-sm">Cancellation reason: {session.cancellation_reason}</p>
            )}
            {session.venue && <p className="mt-2 text-sm">Venue: {session.venue}</p>}
            {session.preparation_materials && (
                <p className="mt-2 text-sm">Preparation: {session.preparation_materials}</p>
            )}
            {session.post_session_resources && (
                <p className="mt-2 text-sm">Resources: {session.post_session_resources}</p>
            )}
            {canManage && session.status === 'scheduled' && (
                <div className="mt-3 flex flex-wrap gap-2">
                    <button
                        type="button"
                        className="border-border focus-ring min-h-10 rounded-lg border px-3 text-sm"
                        onClick={() => setEditing((value) => !value)}
                    >
                        {editing ? 'Close editor' : 'Reschedule or edit'}
                    </button>
                    <form
                        className="flex min-w-0 flex-1 gap-2"
                        onSubmit={(event) => {
                            event.preventDefault();
                            cancellation.post(`${base}/${session.id}/cancel`, { preserveScroll: true });
                        }}
                    >
                        <label className="sr-only" htmlFor={`cancel-reason-${session.id}`}>
                            Cancellation reason
                        </label>
                        <input
                            id={`cancel-reason-${session.id}`}
                            required
                            minLength={3}
                            maxLength={1000}
                            value={cancellation.data.reason}
                            onChange={(event) => cancellation.setData('reason', event.currentTarget.value)}
                            placeholder="Reason to cancel"
                            className="border-border bg-surface min-h-10 min-w-0 flex-1 rounded-lg border px-3 text-sm"
                        />
                        <button className="border-border focus-ring min-h-10 rounded-lg border px-3 text-sm">
                            Cancel session
                        </button>
                    </form>
                </div>
            )}
            {editing && (
                <form
                    className="mt-4 grid gap-3 md:grid-cols-2"
                    onSubmit={(event) => {
                        event.preventDefault();
                        form.put(`${base}/${session.id}`, {
                            preserveScroll: true,
                            onSuccess: () => setEditing(false),
                        });
                    }}
                >
                    <label className="grid gap-1 text-sm font-medium">
                        Title
                        <input
                            required
                            value={form.data.title}
                            onChange={(event) => form.setData('title', event.currentTarget.value)}
                            className="border-border bg-surface min-h-10 rounded-lg border px-3"
                        />
                    </label>
                    <label className="grid gap-1 text-sm font-medium">
                        Time zone
                        <input
                            required
                            value={form.data.timezone}
                            onChange={(event) => form.setData('timezone', event.currentTarget.value)}
                            className="border-border bg-surface min-h-10 rounded-lg border px-3"
                        />
                    </label>
                    <label className="grid gap-1 text-sm font-medium">
                        Starts at
                        <input
                            type="datetime-local"
                            required
                            value={form.data.starts_at}
                            onChange={(event) => form.setData('starts_at', event.currentTarget.value)}
                            className="border-border bg-surface min-h-10 rounded-lg border px-3"
                        />
                    </label>
                    <label className="grid gap-1 text-sm font-medium">
                        Ends at
                        <input
                            type="datetime-local"
                            required
                            value={form.data.ends_at}
                            onChange={(event) => form.setData('ends_at', event.currentTarget.value)}
                            className="border-border bg-surface min-h-10 rounded-lg border px-3"
                        />
                    </label>
                    <label className="grid gap-1 text-sm font-medium">
                        Format
                        <select
                            value={form.data.delivery_type}
                            onChange={(event) =>
                                form.setData(
                                    'delivery_type',
                                    event.currentTarget.value as LiveSession['delivery_type'],
                                )
                            }
                            className="border-border bg-surface min-h-10 rounded-lg border px-3"
                        >
                            <option value="online">Online</option>
                            <option value="in_person">In person</option>
                            <option value="hybrid">Hybrid</option>
                        </select>
                    </label>
                    <label className="grid gap-1 text-sm font-medium">
                        HTTPS meeting URL
                        <input
                            type="url"
                            value={form.data.meeting_url}
                            onChange={(event) => form.setData('meeting_url', event.currentTarget.value)}
                            className="border-border bg-surface min-h-10 rounded-lg border px-3"
                        />
                    </label>
                    <label className="grid gap-1 text-sm font-medium">
                        Venue
                        <input
                            value={form.data.venue}
                            onChange={(event) => form.setData('venue', event.currentTarget.value)}
                            className="border-border bg-surface min-h-10 rounded-lg border px-3"
                        />
                    </label>
                    <label className="grid gap-1 text-sm font-medium">
                        Post-session resources
                        <textarea
                            rows={2}
                            value={form.data.post_session_resources}
                            onChange={(event) =>
                                form.setData('post_session_resources', event.currentTarget.value)
                            }
                            className="border-border bg-surface rounded-lg border px-3 py-2"
                        />
                    </label>
                    <div className="md:col-span-2">
                        <button className="bg-brand-action focus-ring min-h-10 rounded-lg px-3 text-sm font-semibold text-white">
                            Save session changes
                        </button>
                    </div>
                    <FormErrors />
                </form>
            )}
        </li>
    );
}

function AddLessonForm({
    tenantSlug,
    workspaceId,
    module,
}: {
    tenantSlug: string;
    workspaceId: number;
    module: Module;
}) {
    const form = useForm({ title: '', summary: '', learning_objectives: '', estimated_minutes: '' });
    return (
        <form
            onSubmit={(event) => {
                event.preventDefault();
                form.post(`/t/${tenantSlug}/learning/${workspaceId}/modules/${module.id}/lessons`, {
                    preserveScroll: true,
                    onSuccess: () => form.reset(),
                });
            }}
            className="border-border mt-4 grid gap-2 border-t pt-4 sm:grid-cols-[1fr_1fr_auto]"
        >
            <label className="grid gap-1 text-sm font-medium">
                New lesson
                <input
                    required
                    maxLength={180}
                    value={form.data.title}
                    onChange={(event) => form.setData('title', event.currentTarget.value)}
                    className="border-border bg-surface min-h-11 rounded-lg border px-3"
                />
            </label>
            <label className="grid gap-1 text-sm font-medium">
                Objective
                <textarea
                    rows={1}
                    value={form.data.learning_objectives}
                    onChange={(event) => form.setData('learning_objectives', event.currentTarget.value)}
                    className="border-border bg-surface rounded-lg border px-3 py-2"
                />
            </label>
            <button className="border-border hover:bg-surface-soft focus-ring min-h-11 self-end rounded-lg border px-4 text-sm font-semibold">
                Add lesson
            </button>
        </form>
    );
}

function AssignmentRow({
    tenantSlug,
    workspaceId,
    assignment,
}: {
    tenantSlug: string;
    workspaceId: number;
    assignment: Assignment;
}) {
    const form = useForm({ reason: '' });
    return (
        <li className="bg-surface-soft rounded-lg p-3 text-sm">
            <div className="flex flex-wrap items-center justify-between gap-2">
                <span>
                    {assignment.member.name} ·{' '}
                    <span className="capitalize">{assignment.role.replace('_', ' ')}</span>
                </span>
                <span className="text-text-muted text-xs capitalize">{assignment.status}</span>
            </div>
            {assignment.change_reason && (
                <p className="text-text-muted mt-1 text-xs">Change reason: {assignment.change_reason}</p>
            )}
            {assignment.status === 'active' && (
                <form
                    onSubmit={(event) => {
                        event.preventDefault();
                        form.post(
                            `/t/${tenantSlug}/learning/${workspaceId}/assignments/${assignment.id}/revoke`,
                            { preserveScroll: true, onSuccess: () => form.reset() },
                        );
                    }}
                    className="mt-3 flex flex-wrap gap-2"
                >
                    <label className="sr-only" htmlFor={`revoke-reason-${assignment.id}`}>
                        Reason for revoking {assignment.role} assignment
                    </label>
                    <input
                        id={`revoke-reason-${assignment.id}`}
                        minLength={8}
                        maxLength={1000}
                        required
                        value={form.data.reason}
                        onChange={(event) => form.setData('reason', event.currentTarget.value)}
                        placeholder="Reason for revocation"
                        className="border-border bg-surface min-h-10 min-w-0 flex-1 rounded-lg border px-3 text-sm"
                    />
                    <button className="border-border hover:bg-surface min-h-10 rounded-lg border px-3 text-xs font-semibold">
                        Revoke
                    </button>
                </form>
            )}
        </li>
    );
}
