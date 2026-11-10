import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import type { FormEvent } from 'react';
import { useEffect } from 'react';
import { PageHeader } from '@/components/ui/page-header';
import { WorkspaceLayout } from '@/layouts/workspace-layout';
import { CompletionRules } from './completion-rules';

type NodeItem = {
    id: number;
    parent_id: number | null;
    node_type: string;
    code: string | null;
    title: string;
    description: string | null;
    sort_order: number;
    is_required: boolean;
    estimated_minutes: number | null;
    workload_unit: string;
    instructional_strategy: string | null;
    activity_specification: string | null;
    assessment_blueprint: string | null;
    planned_starts_on: string | null;
    planned_ends_on: string | null;
    academic_course_id?: number | null;
    academic_course_version_id?: number | null;
    academic_period_id?: number | null;
    course?: { code: string; title: string } | null;
    course_version?: {
        version_number: number;
        total_credits: number | string;
        contact_hours: number | string | null;
        title: string;
    } | null;
    academic_period?: { code: string; name: string } | null;
    lock_version: number;
    outcomes: { learning_outcome: { id: number; code: string; name: string } | null }[];
    competencies: {
        competency: { id: number; code: string; title: string } | null;
        expected_level: string | null;
        mapping_strength: string;
    }[];
    prerequisites: { prerequisite_node_id: number; dependency_type: string }[];
};
type Outcome = { id: number; code: string; name: string; description: string };
type Competency = { id: number; code: string; title: string; framework_title: string };
type CompletionRule = {
    id: number;
    code: string;
    title: string;
    rule_type: string;
    target_value: string | number | null;
    unit: string | null;
    academic_period_id: number | null;
    is_required: boolean;
    description: string | null;
    academic_period: { id: number; code: string; name: string } | null;
};
type ChecklistItem = {
    id: number;
    category: string;
    prompt: string;
    is_required: boolean;
    requires_note: boolean;
};
type Props = {
    curriculum: {
        id: number;
        code: string;
        title: string;
        description: string | null;
        status: string;
        sharing_status: 'private' | 'shared';
        academic_year: { name: string; code: string } | null;
        program: {
            code: string;
            title: string;
            credit_bearing: boolean;
            required_credits: number | string | null;
        };
        framework: { code: string; title: string } | null;
        versions: {
            id: number;
            version_number: number;
            status: string;
            title: string;
            lock_version: number;
            reviewer_user_id: number | null;
            review_note?: string | null;
        }[];
    };
    version: {
        id: number;
        version_number: number;
        status: string;
        title: string;
        revision_note: string | null;
        lock_version: number;
        reviewer_user_id: number | null;
        submitted_by: number | null;
        published_at: string | null;
    };
    nodes: NodeItem[];
    completionRules: CompletionRule[];
    outcomes: Outcome[];
    mappedOutcomeIds: number[];
    competencyFrameworks: {
        id: number;
        title: string;
        competencies: { id: number; code: string; title: string; descriptor: string; status: string }[];
    }[];
    competencies: Competency[];
    periods: { id: number; name: string; code: string; starts_on: string; ends_on: string }[];
    courses: {
        id: number;
        code: string;
        title: string;
        versions: {
            id: number;
            version_number: number;
            title: string;
            total_credits: number | string;
            contact_hours: number | string | null;
        }[];
    }[];
    reviewers: { id: number; name: string; email: string }[];
    assignedReview: {
        id: number;
        policy_version_id: number | null;
        checklist_snapshot: ChecklistItem[];
    } | null;
    competencyGaps: { competency_id: number; title: string; expected_level: string | null }[];
    validation: {
        blocking: string[];
        warnings: string[];
        required_outcomes: number;
        mapped_required_outcomes: number;
        node_count: number;
        coverage_percent: number;
    };
};

const inputClass = 'border-border bg-surface min-h-11 w-full rounded-lg border px-3 text-sm';
const cardClass = 'border-border bg-surface rounded-2xl border p-5 shadow-sm';

function CompetencyFrameworkCard({
    framework,
    base,
    canManage,
}: {
    framework: Props['competencyFrameworks'][number];
    base: string;
    canManage: boolean;
}) {
    const form = useForm({
        parent_id: '',
        code: '',
        title: '',
        descriptor: '',
        mastery_criteria: '',
        level_label: '',
    });
    return (
        <li className="border-border rounded-lg border p-3">
            <strong>{framework.title}</strong>
            {framework.competencies.length === 0 ? (
                <p className="text-text-secondary mt-2 text-sm">No competencies defined.</p>
            ) : (
                <ul className="text-text-secondary mt-2 grid gap-1 text-sm">
                    {framework.competencies.map((competency) => (
                        <li key={competency.id}>
                            {competency.code} · {competency.title}
                        </li>
                    ))}
                </ul>
            )}
            {canManage && (
                <form
                    className="border-border mt-3 grid gap-2 border-t pt-3 sm:grid-cols-2"
                    onSubmit={(event: FormEvent) => {
                        event.preventDefault();
                        form.post(`${base}/competency-frameworks/${framework.id}/competencies`, {
                            onSuccess: () => form.reset(),
                        });
                    }}
                >
                    <label className="grid gap-1 text-xs">
                        Competency code
                        <input
                            required
                            maxLength={64}
                            className={inputClass}
                            value={form.data.code}
                            onChange={(event) => form.setData('code', event.currentTarget.value)}
                        />
                    </label>
                    <label className="grid gap-1 text-xs">
                        Competency title
                        <input
                            required
                            maxLength={180}
                            className={inputClass}
                            value={form.data.title}
                            onChange={(event) => form.setData('title', event.currentTarget.value)}
                        />
                    </label>
                    <label className="grid gap-1 text-xs sm:col-span-2">
                        Descriptor
                        <textarea
                            required
                            maxLength={5000}
                            rows={2}
                            className={`${inputClass} py-2`}
                            value={form.data.descriptor}
                            onChange={(event) => form.setData('descriptor', event.currentTarget.value)}
                        />
                    </label>
                    <label className="grid gap-1 text-xs">
                        Mastery criteria
                        <input
                            maxLength={3000}
                            className={inputClass}
                            value={form.data.mastery_criteria}
                            onChange={(event) => form.setData('mastery_criteria', event.currentTarget.value)}
                        />
                    </label>
                    <label className="grid gap-1 text-xs">
                        Level label
                        <input
                            maxLength={80}
                            className={inputClass}
                            value={form.data.level_label}
                            onChange={(event) => form.setData('level_label', event.currentTarget.value)}
                        />
                    </label>
                    <button
                        type="submit"
                        disabled={form.processing}
                        className="border-border min-h-10 rounded-lg border px-3 text-xs font-semibold sm:col-span-2"
                    >
                        Add competency
                    </button>
                    {Object.values(form.errors).length > 0 && (
                        <p role="alert" className="text-danger text-xs sm:col-span-2">
                            {Object.values(form.errors).join(' ')}
                        </p>
                    )}
                </form>
            )}
        </li>
    );
}

function CurriculumNode({
    node,
    allNodes,
    outcomes,
    version,
    base,
    canManage,
    competencies,
}: {
    node: NodeItem;
    allNodes: NodeItem[];
    outcomes: Outcome[];
    version: Props['version'];
    base: string;
    canManage: boolean;
    competencies: Competency[];
}) {
    const update = useForm({
        version_lock: version.lock_version,
        node_lock: node.lock_version,
        title: node.title,
        description: node.description ?? '',
        estimated_minutes: node.estimated_minutes?.toString() ?? '',
        workload_unit: node.workload_unit,
        instructional_strategy: node.instructional_strategy ?? '',
        activity_specification: node.activity_specification ?? '',
        assessment_blueprint: node.assessment_blueprint ?? '',
        planned_starts_on: node.planned_starts_on?.slice(0, 10) ?? '',
        planned_ends_on: node.planned_ends_on?.slice(0, 10) ?? '',
        is_required: node.is_required,
    });
    const mapForm = useForm({
        version_lock: version.lock_version,
        learning_outcome_id: '',
        mapping_strength: 'introduced',
    });
    const prereqForm = useForm({
        version_lock: version.lock_version,
        prerequisite_node_id: '',
        dependency_type: 'required',
    });
    const competencyMapForm = useForm({
        version_lock: version.lock_version,
        competency_id: '',
        expected_level: '',
        mapping_strength: 'introduced',
    });
    const setUpdateData = update.setData;
    const setMapData = mapForm.setData;
    const setPrereqData = prereqForm.setData;
    const setCompetencyMapData = competencyMapForm.setData;
    useEffect(() => {
        setUpdateData('version_lock', version.lock_version);
        setUpdateData('node_lock', node.lock_version);
        setMapData('version_lock', version.lock_version);
        setPrereqData('version_lock', version.lock_version);
        setCompetencyMapData('version_lock', version.lock_version);
    }, [
        version.lock_version,
        node.lock_version,
        setUpdateData,
        setMapData,
        setPrereqData,
        setCompetencyMapData,
    ]);
    const children = allNodes.filter((candidate) => candidate.parent_id === node.id);
    const editable = ['draft', 'changes_requested'].includes(version.status) && canManage;

    return (
        <li className="border-border border-l-2 pl-4" aria-label={`${node.node_type}: ${node.title}`}>
            <details className="bg-canvas rounded-xl p-4" open>
                <summary className="focus-visible:outline-focus flex min-h-11 cursor-pointer flex-wrap items-center justify-between gap-2 rounded font-semibold focus-visible:outline-2">
                    <span>
                        <span className="text-text-muted mr-2 text-xs font-semibold uppercase">
                            {node.node_type}
                        </span>
                        {node.title}
                        {node.code ? (
                            <span className="text-text-muted ml-2 text-xs font-normal">{node.code}</span>
                        ) : null}
                    </span>
                    <span className="text-text-muted text-xs font-normal">
                        Order {node.sort_order} · {node.is_required ? 'Required' : 'Optional'}
                        {node.estimated_minutes ? ` · ${node.estimated_minutes} ${node.workload_unit}` : ''}
                    </span>
                </summary>
                {node.description && <p className="text-text-secondary mt-3 text-sm">{node.description}</p>}
                {node.outcomes.length > 0 && (
                    <div className="mt-3 flex flex-wrap gap-2" aria-label="Mapped outcomes">
                        {node.outcomes.map(
                            (mapping) =>
                                mapping.learning_outcome && (
                                    <span
                                        key={mapping.learning_outcome.id}
                                        className="border-border rounded-full border px-2.5 py-1 text-xs"
                                    >
                                        {mapping.learning_outcome.code} · {mapping.learning_outcome.name}
                                    </span>
                                ),
                        )}
                    </div>
                )}
                {node.competencies.length > 0 && (
                    <div className="mt-3 flex flex-wrap gap-2" aria-label="Mapped competencies">
                        {node.competencies.map(
                            (mapping) =>
                                mapping.competency && (
                                    <span
                                        key={mapping.competency.id}
                                        className="border-border rounded-full border px-2.5 py-1 text-xs"
                                    >
                                        {mapping.competency.code} · {mapping.competency.title} ·{' '}
                                        {mapping.mapping_strength}
                                    </span>
                                ),
                        )}
                    </div>
                )}
                {node.prerequisites.length > 0 && (
                    <p className="text-text-secondary mt-2 text-xs">
                        Prerequisites:{' '}
                        {node.prerequisites
                            .map(
                                (edge) =>
                                    allNodes.find((candidate) => candidate.id === edge.prerequisite_node_id)
                                        ?.title ?? 'Unavailable item',
                            )
                            .join(', ')}
                    </p>
                )}
                {editable && (
                    <div className="border-border mt-4 grid gap-4 border-t pt-4 lg:grid-cols-2">
                        <form
                            className="grid gap-2"
                            onSubmit={(event: FormEvent) => {
                                event.preventDefault();
                                update.put(`${base}/versions/${version.id}/nodes/${node.id}`);
                            }}
                        >
                            <h3 className="text-sm font-semibold">Edit syllabus item</h3>
                            <input
                                aria-label={`Title for ${node.title}`}
                                required
                                maxLength={180}
                                className={inputClass}
                                value={update.data.title}
                                onChange={(event) => update.setData('title', event.currentTarget.value)}
                            />
                            <textarea
                                aria-label={`Description for ${node.title}`}
                                rows={2}
                                className={`${inputClass} py-2`}
                                value={update.data.description}
                                onChange={(event) => update.setData('description', event.currentTarget.value)}
                            />
                            <div className="grid gap-2 sm:grid-cols-2">
                                <label className="grid gap-1 text-xs">
                                    Estimated workload
                                    <input
                                        type="number"
                                        min="1"
                                        max="100000"
                                        className={inputClass}
                                        value={update.data.estimated_minutes}
                                        onChange={(event) =>
                                            update.setData('estimated_minutes', event.currentTarget.value)
                                        }
                                    />
                                </label>
                                <label className="grid gap-1 text-xs">
                                    Workload unit
                                    <select
                                        className={inputClass}
                                        value={update.data.workload_unit}
                                        onChange={(event) =>
                                            update.setData('workload_unit', event.currentTarget.value)
                                        }
                                    >
                                        {[
                                            'minutes',
                                            'hours',
                                            'credits',
                                            'sessions',
                                            'weeks',
                                            'competency',
                                        ].map((unit) => (
                                            <option key={unit}>{unit}</option>
                                        ))}
                                    </select>
                                </label>
                            </div>
                            <label className="flex min-h-11 items-center gap-2 text-sm">
                                <input
                                    type="checkbox"
                                    checked={update.data.is_required}
                                    onChange={(event) =>
                                        update.setData('is_required', event.currentTarget.checked)
                                    }
                                />
                                Required curriculum content
                            </label>
                            <input
                                aria-label="Instructional strategy"
                                maxLength={120}
                                placeholder="Instructional strategy"
                                className={inputClass}
                                value={update.data.instructional_strategy}
                                onChange={(event) =>
                                    update.setData('instructional_strategy', event.currentTarget.value)
                                }
                            />
                            <textarea
                                aria-label="Suggested learning activity"
                                rows={2}
                                maxLength={5000}
                                placeholder="Suggested learning activity"
                                className={`${inputClass} py-2`}
                                value={update.data.activity_specification}
                                onChange={(event) =>
                                    update.setData('activity_specification', event.currentTarget.value)
                                }
                            />
                            <textarea
                                aria-label="Assessment blueprint specification"
                                rows={2}
                                maxLength={5000}
                                placeholder="Assessment blueprint specification"
                                className={`${inputClass} py-2`}
                                value={update.data.assessment_blueprint}
                                onChange={(event) =>
                                    update.setData('assessment_blueprint', event.currentTarget.value)
                                }
                            />
                            <button
                                type="submit"
                                disabled={update.processing}
                                className="bg-brand-action min-h-10 rounded-lg px-3 text-sm font-semibold text-white disabled:opacity-60"
                            >
                                Save item
                            </button>
                            {Object.values(update.errors).map((error, index) => (
                                <p key={index} role="alert" className="text-danger text-xs">
                                    {error}
                                </p>
                            ))}
                        </form>
                        <div className="grid content-start gap-4">
                            <form
                                className="grid gap-2"
                                onSubmit={(event: FormEvent) => {
                                    event.preventDefault();
                                    mapForm.post(`${base}/versions/${version.id}/nodes/${node.id}/outcomes`, {
                                        onSuccess: () =>
                                            mapForm.reset('learning_outcome_id', 'mapping_strength'),
                                    });
                                }}
                            >
                                <h3 className="text-sm font-semibold">Map a learning outcome</h3>
                                <select
                                    required
                                    aria-label={`Learning outcome for ${node.title}`}
                                    className={inputClass}
                                    value={mapForm.data.learning_outcome_id}
                                    onChange={(event) =>
                                        mapForm.setData('learning_outcome_id', event.currentTarget.value)
                                    }
                                >
                                    <option value="">Choose outcome</option>
                                    {outcomes.map((outcome) => (
                                        <option key={outcome.id} value={outcome.id}>
                                            {outcome.code} · {outcome.name}
                                        </option>
                                    ))}
                                </select>
                                <div className="flex gap-2">
                                    <select
                                        aria-label="Outcome coverage level"
                                        className={inputClass}
                                        value={mapForm.data.mapping_strength}
                                        onChange={(event) =>
                                            mapForm.setData('mapping_strength', event.currentTarget.value)
                                        }
                                    >
                                        <option value="introduced">Introduced</option>
                                        <option value="developed">Developed</option>
                                        <option value="mastered">Mastered</option>
                                    </select>
                                    <button
                                        type="submit"
                                        disabled={mapForm.processing}
                                        className="border-border min-h-11 shrink-0 rounded-lg border px-3 text-sm font-semibold"
                                    >
                                        Map outcome
                                    </button>
                                </div>
                                {mapForm.errors.learning_outcome_id && (
                                    <p role="alert" className="text-danger text-xs">
                                        {mapForm.errors.learning_outcome_id}
                                    </p>
                                )}
                            </form>
                            {allNodes.length > 1 && (
                                <form
                                    className="grid gap-2"
                                    onSubmit={(event: FormEvent) => {
                                        event.preventDefault();
                                        prereqForm.post(
                                            `${base}/versions/${version.id}/nodes/${node.id}/prerequisites`,
                                            {
                                                onSuccess: () =>
                                                    prereqForm.reset(
                                                        'prerequisite_node_id',
                                                        'dependency_type',
                                                    ),
                                            },
                                        );
                                    }}
                                >
                                    <h3 className="text-sm font-semibold">Learning pathway prerequisite</h3>
                                    <div className="flex gap-2">
                                        <select
                                            required
                                            aria-label={`Prerequisite for ${node.title}`}
                                            className={inputClass}
                                            value={prereqForm.data.prerequisite_node_id}
                                            onChange={(event) =>
                                                prereqForm.setData(
                                                    'prerequisite_node_id',
                                                    event.currentTarget.value,
                                                )
                                            }
                                        >
                                            <option value="">Choose an earlier item</option>
                                            {allNodes
                                                .filter((candidate) => candidate.id !== node.id)
                                                .map((candidate) => (
                                                    <option key={candidate.id} value={candidate.id}>
                                                        {candidate.title}
                                                    </option>
                                                ))}
                                        </select>
                                        <button
                                            type="submit"
                                            disabled={prereqForm.processing}
                                            className="border-border min-h-11 shrink-0 rounded-lg border px-3 text-sm font-semibold"
                                        >
                                            Add
                                        </button>
                                    </div>
                                    {prereqForm.errors.prerequisite_node_id && (
                                        <p role="alert" className="text-danger text-xs">
                                            {prereqForm.errors.prerequisite_node_id}
                                        </p>
                                    )}
                                </form>
                            )}
                            {competencies.length > 0 && (
                                <form
                                    className="grid gap-2"
                                    onSubmit={(event: FormEvent) => {
                                        event.preventDefault();
                                        competencyMapForm.post(
                                            `${base}/versions/${version.id}/nodes/${node.id}/competencies`,
                                            {
                                                onSuccess: () =>
                                                    competencyMapForm.reset(
                                                        'competency_id',
                                                        'expected_level',
                                                        'mapping_strength',
                                                    ),
                                            },
                                        );
                                    }}
                                >
                                    <h3 className="text-sm font-semibold">Map a competency</h3>
                                    <select
                                        required
                                        aria-label={`Competency for ${node.title}`}
                                        className={inputClass}
                                        value={competencyMapForm.data.competency_id}
                                        onChange={(event) =>
                                            competencyMapForm.setData(
                                                'competency_id',
                                                event.currentTarget.value,
                                            )
                                        }
                                    >
                                        <option value="">Choose a competency</option>
                                        {competencies.map((competency) => (
                                            <option key={competency.id} value={competency.id}>
                                                {competency.framework_title} · {competency.code} ·{' '}
                                                {competency.title}
                                            </option>
                                        ))}
                                    </select>
                                    <div className="flex gap-2">
                                        <input
                                            aria-label="Expected competency level"
                                            maxLength={80}
                                            className={inputClass}
                                            value={competencyMapForm.data.expected_level}
                                            onChange={(event) =>
                                                competencyMapForm.setData(
                                                    'expected_level',
                                                    event.currentTarget.value,
                                                )
                                            }
                                            placeholder="Expected level (optional)"
                                        />
                                        <select
                                            aria-label="Competency mapping strength"
                                            className={inputClass}
                                            value={competencyMapForm.data.mapping_strength}
                                            onChange={(event) =>
                                                competencyMapForm.setData(
                                                    'mapping_strength',
                                                    event.currentTarget.value,
                                                )
                                            }
                                        >
                                            <option value="introduced">Introduced</option>
                                            <option value="developed">Developed</option>
                                            <option value="mastered">Mastered</option>
                                        </select>
                                    </div>
                                    <button
                                        type="submit"
                                        disabled={competencyMapForm.processing}
                                        className="border-border min-h-11 rounded-lg border px-3 text-sm font-semibold"
                                    >
                                        Map competency
                                    </button>
                                    {competencyMapForm.errors.competency_id && (
                                        <p role="alert" className="text-danger text-xs">
                                            {competencyMapForm.errors.competency_id}
                                        </p>
                                    )}
                                </form>
                            )}
                            <div className="flex flex-wrap gap-2">
                                <button
                                    type="button"
                                    aria-label={`Move ${node.title} up`}
                                    onClick={() =>
                                        router.post(`${base}/versions/${version.id}/nodes/${node.id}/order`, {
                                            direction: 'up',
                                            version_lock: version.lock_version,
                                        })
                                    }
                                    className="border-border min-h-10 rounded-lg border px-3 text-xs font-semibold"
                                >
                                    Move up
                                </button>
                                <button
                                    type="button"
                                    aria-label={`Move ${node.title} down`}
                                    onClick={() =>
                                        router.post(`${base}/versions/${version.id}/nodes/${node.id}/order`, {
                                            direction: 'down',
                                            version_lock: version.lock_version,
                                        })
                                    }
                                    className="border-border min-h-10 rounded-lg border px-3 text-xs font-semibold"
                                >
                                    Move down
                                </button>
                            </div>
                        </div>
                    </div>
                )}
                {children.length > 0 && (
                    <ol className="mt-4 grid list-none gap-3">
                        {children.map((child) => (
                            <CurriculumNode
                                key={child.id}
                                node={child}
                                allNodes={allNodes}
                                outcomes={outcomes}
                                version={version}
                                base={base}
                                canManage={canManage}
                                competencies={competencies}
                            />
                        ))}
                    </ol>
                )}
            </details>
        </li>
    );
}

export default function CurriculumShow({
    curriculum,
    version,
    nodes,
    completionRules,
    outcomes,
    competencyFrameworks,
    competencies,
    periods,
    courses,
    reviewers,
    validation,
    assignedReview,
    competencyGaps,
}: Props) {
    const base = `/t/${window.location.pathname.split('/')[2] ?? ''}/academics/curriculum`;
    const newNode = useForm({
        version_lock: version.lock_version,
        parent_id: '',
        node_type: 'unit',
        title: '',
        code: '',
        description: '',
        academic_course_id: '',
        academic_course_version_id: '',
        academic_period_id: '',
        is_required: true,
        estimated_minutes: '',
        workload_unit: 'minutes',
        instructional_strategy: '',
        activity_specification: '',
        assessment_blueprint: '',
        planned_starts_on: '',
        planned_ends_on: '',
    });
    const submitForm = useForm({ reviewer_user_ids: [] as string[], version_lock: version.lock_version });
    const reviewForm = useForm({
        decision: 'approve',
        review_note: '',
        checklist_responses: Object.fromEntries(
            (assignedReview?.checklist_snapshot ?? []).map((item) => [
                String(item.id),
                { complete: false, note: '', evidence_reference: '' },
            ]),
        ) as Record<string, { complete: boolean; note: string; evidence_reference: string }>,
    });
    const revisionForm = useForm({ revision_note: '' });
    const competencyFrameworkForm = useForm({ code: '', title: '', description: '' });
    const page = usePage<{
        auth?: { user?: { id: number } | null; tenant?: { permissions?: string[] } | null };
    }>();
    const permissions = page.props.auth?.tenant?.permissions ?? [];
    const canManage = permissions.includes('academic.curriculum.manage');
    const canReview = permissions.includes('academic.curriculum.review');
    const canAudit = permissions.includes('academic.curriculum.audit.view');
    const canPublish = permissions.includes('academic.curriculum.publish');
    const canShare = permissions.includes('academic.curriculum.templates.share');
    const rootNodes = nodes.filter((node) => node.parent_id === null);
    const coursePlan = nodes.filter((node) => node.node_type === 'course');
    const plannedCredits = coursePlan.reduce(
        (sum, node) => sum + Number(node.course_version?.total_credits ?? 0),
        0,
    );
    const requiredPlannedCredits = coursePlan
        .filter((node) => node.is_required)
        .reduce((sum, node) => sum + Number(node.course_version?.total_credits ?? 0), 0);
    const isDraft = ['draft', 'changes_requested'].includes(version.status) && canManage;
    const reviewerAssigned = version.status === 'in_review';
    const mayReview = reviewerAssigned && canReview && assignedReview !== null;
    const setNewNodeData = newNode.setData;
    const setSubmitFormData = submitForm.setData;
    useEffect(() => {
        setNewNodeData('version_lock', version.lock_version);
        setSubmitFormData('version_lock', version.lock_version);
    }, [version.lock_version, setNewNodeData, setSubmitFormData]);

    return (
        <WorkspaceLayout title={curriculum.title} area="tenant">
            <Head title={`${curriculum.title} · v${version.version_number}`} />
            <div className="grid gap-6">
                <PageHeader
                    title={curriculum.title}
                    description={`${curriculum.code} · ${curriculum.program.code} ${curriculum.program.title}${curriculum.academic_year ? ` · ${curriculum.academic_year.name}` : ''}`}
                />
                <div className="flex flex-wrap items-center gap-2">
                    <Link
                        href={base}
                        className="border-border inline-flex min-h-11 items-center rounded-lg border px-4 text-sm font-semibold"
                    >
                        Back to curriculum
                    </Link>
                    <Link
                        href={`${base}/curricula/${curriculum.id}/matrix?version=${version.id}`}
                        className="border-border bg-surface inline-flex min-h-11 items-center rounded-lg border px-4 text-sm font-semibold"
                    >
                        Outcome matrix
                    </Link>
                    <Link
                        href={`${base}/curricula/${curriculum.id}/pathway?version=${version.id}`}
                        className="border-border bg-surface inline-flex min-h-11 items-center rounded-lg border px-4 text-sm font-semibold"
                    >
                        Prerequisite explorer
                    </Link>
                    {canAudit && (
                        <Link
                            href={`${base}/curricula/${curriculum.id}/reviews`}
                            className="border-border bg-surface inline-flex min-h-11 items-center rounded-lg border px-4 text-sm font-semibold"
                        >
                            Review history
                        </Link>
                    )}
                    {curriculum.versions.length > 1 && (
                        <Link
                            href={`${base}/curricula/${curriculum.id}/compare`}
                            className="border-border bg-surface inline-flex min-h-11 items-center rounded-lg border px-4 text-sm font-semibold"
                        >
                            Compare versions
                        </Link>
                    )}
                    <span className="border-border bg-surface rounded-full border px-3 py-2 text-xs font-semibold capitalize">
                        Version {version.version_number} · {version.status.replace('_', ' ')}
                    </span>
                    {canShare && curriculum.versions.some((item) => item.status === 'published') && (
                        <button
                            type="button"
                            className="border-border bg-surface min-h-11 rounded-lg border px-4 text-sm font-semibold"
                            onClick={() =>
                                router.post(`${base}/curricula/${curriculum.id}/sharing`, {
                                    sharing_status:
                                        curriculum.sharing_status === 'shared' ? 'private' : 'shared',
                                })
                            }
                        >
                            {curriculum.sharing_status === 'shared'
                                ? 'Stop sharing template'
                                : 'Share published template'}
                        </button>
                    )}
                    {version.status === 'published' && (
                        <a
                            href={`${base}/curricula/${curriculum.id}/export`}
                            className="border-border bg-surface inline-flex min-h-11 items-center rounded-lg border px-4 text-sm font-semibold"
                        >
                            Export published syllabus
                        </a>
                    )}
                    {curriculum.versions.some((item) => item.status === 'published') && (
                        <Link
                            href={`${base}/curricula/${curriculum.id}/print`}
                            className="border-border bg-surface inline-flex min-h-11 items-center rounded-lg border px-4 text-sm font-semibold"
                        >
                            Print published syllabus
                        </Link>
                    )}
                    {isDraft && (
                        <Link
                            href={`${base}/curricula/${curriculum.id}/import/${version.id}`}
                            className="border-border bg-surface inline-flex min-h-11 items-center rounded-lg border px-4 text-sm font-semibold"
                        >
                            Import outline
                        </Link>
                    )}
                    {version.status === 'published' && canManage && (
                        <form
                            onSubmit={(event: FormEvent) => {
                                event.preventDefault();
                                revisionForm.post(`${base}/curricula/${curriculum.id}/revisions`);
                            }}
                            className="flex gap-2"
                        >
                            <label className="sr-only" htmlFor="revision-note">
                                Revision note
                            </label>
                            <input
                                id="revision-note"
                                required
                                className={inputClass}
                                placeholder="Why create a revision?"
                                value={revisionForm.data.revision_note}
                                onChange={(event) =>
                                    revisionForm.setData('revision_note', event.currentTarget.value)
                                }
                            />
                            <button
                                className="bg-brand-action min-h-11 rounded-lg px-4 text-sm font-semibold text-white"
                                type="submit"
                            >
                                Create new revision
                            </button>
                        </form>
                    )}
                </div>

                <section aria-label="Curriculum validation and coverage" className={cardClass}>
                    <div className="flex flex-wrap items-center justify-between gap-3">
                        <div>
                            <h2 className="text-lg font-semibold">Design coverage</h2>
                            <p className="text-text-secondary mt-1 text-sm">
                                Coverage measures required curriculum outcomes mapped to this version, not
                                student attainment.
                            </p>
                        </div>
                        <div className="text-right">
                            <p className="text-2xl font-semibold tabular-nums">
                                {validation.coverage_percent}%
                            </p>
                            <p className="text-text-muted text-xs">
                                {validation.mapped_required_outcomes} / {validation.required_outcomes}{' '}
                                required outcomes
                            </p>
                        </div>
                    </div>
                    {validation.blocking.length > 0 ? (
                        <ul
                            className="text-danger mt-4 grid gap-2 text-sm"
                            aria-label="Blocking curriculum validation findings"
                        >
                            {validation.blocking.map((issue) => (
                                <li key={issue}>{issue}</li>
                            ))}
                        </ul>
                    ) : (
                        <p className="text-success mt-4 text-sm font-medium">
                            No blocking curriculum design gaps were found.
                        </p>
                    )}
                    {validation.warnings.length > 0 && (
                        <ul className="text-warning mt-2 grid gap-1 text-sm">
                            {validation.warnings.map((issue) => (
                                <li key={issue}>{issue}</li>
                            ))}
                        </ul>
                    )}
                    {competencyGaps.length > 0 && (
                        <div
                            className="border-border mt-4 rounded-xl border p-4"
                            aria-label="Program competency coverage gaps"
                        >
                            <h3 className="font-semibold">Program competency coverage gaps</h3>
                            <p className="text-text-secondary mt-1 text-sm">
                                These are design mappings only; no learner attainment or scores are inferred.
                            </p>
                            <ul className="text-warning mt-2 grid gap-1 text-sm">
                                {competencyGaps.map((gap) => (
                                    <li key={gap.competency_id}>
                                        {gap.title}
                                        {gap.expected_level ? ` · expected ${gap.expected_level}` : ''}
                                    </li>
                                ))}
                            </ul>
                        </div>
                    )}
                </section>

                <section aria-labelledby="program-plan-heading" className={cardClass}>
                    <div className="flex flex-wrap items-end justify-between gap-3">
                        <div>
                            <h2 id="program-plan-heading" className="text-lg font-semibold">
                                Program and term plan
                            </h2>
                            <p className="text-text-secondary mt-1 text-sm">
                                Course allocation uses the institution catalog and academic periods attached
                                to this curriculum year.
                            </p>
                        </div>
                        <div className="text-right text-sm">
                            <p>
                                <strong>{coursePlan.length}</strong> catalog courses planned
                            </p>
                            <p className="text-text-muted">
                                {plannedCredits.toFixed(3)} catalog credits across linked course versions
                            </p>
                            {curriculum.program.credit_bearing &&
                            curriculum.program.required_credits !== null ? (
                                <p className="mt-1 font-medium">
                                    {requiredPlannedCredits.toFixed(3)} required credits planned of{' '}
                                    {curriculum.program.required_credits} required for program completion.
                                </p>
                            ) : (
                                <p className="text-text-muted mt-1">
                                    {curriculum.program.credit_bearing
                                        ? 'No program credit threshold is configured.'
                                        : 'This is a non-credit program; no credit threshold is inferred.'}
                                </p>
                            )}
                        </div>
                    </div>
                    {coursePlan.length === 0 ? (
                        <p className="text-text-secondary mt-4 rounded-xl border border-dashed p-4 text-sm">
                            No catalog courses are assigned yet. Add a course item to the draft and choose its
                            published catalog version and academic period.
                        </p>
                    ) : (
                        <div className="border-border mt-4 overflow-x-auto rounded-xl border">
                            <table className="w-full min-w-[680px] text-left text-sm">
                                <caption className="sr-only">
                                    Course and academic period allocation for this curriculum
                                </caption>
                                <thead className="bg-surface-muted">
                                    <tr>
                                        <th className="p-3">Curriculum item</th>
                                        <th className="p-3">Catalog course/version</th>
                                        <th className="p-3">Academic period</th>
                                        <th className="p-3">Requirement</th>
                                        <th className="p-3">Credits</th>
                                        <th className="p-3">Contact hours</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {coursePlan.map((node) => (
                                        <tr key={node.id} className="border-border border-t">
                                            <td className="p-3">{node.title}</td>
                                            <td className="p-3">
                                                {node.course
                                                    ? `${node.course.code} · ${node.course.title}${node.course_version ? ` · v${node.course_version.version_number}` : ''}`
                                                    : 'Not linked to catalog'}
                                            </td>
                                            <td className="p-3">
                                                {node.academic_period
                                                    ? `${node.academic_period.code} · ${node.academic_period.name}`
                                                    : 'Not period-specific'}
                                            </td>
                                            <td className="p-3">
                                                {node.is_required ? 'Required' : 'Optional'}
                                            </td>
                                            <td className="p-3">
                                                {node.course_version?.total_credits ?? '—'}
                                            </td>
                                            <td className="p-3">
                                                {node.course_version?.contact_hours ?? '—'}
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    )}
                </section>

                <CompletionRules
                    rules={completionRules}
                    nodes={nodes}
                    periods={periods}
                    versionId={version.id}
                    versionLock={version.lock_version}
                    base={base}
                    canManage={isDraft}
                />

                {isDraft && (
                    <section className={cardClass}>
                        <h2 className="text-lg font-semibold">Add a syllabus item</h2>
                        <p className="text-text-secondary mt-1 text-sm">
                            Keyboard-accessible forms and move controls are provided alongside the ordered
                            outline.
                        </p>
                        <form
                            className="mt-4 grid gap-3 sm:grid-cols-2 xl:grid-cols-4"
                            onSubmit={(event: FormEvent) => {
                                event.preventDefault();
                                newNode.post(`${base}/versions/${version.id}/nodes`, {
                                    onSuccess: () =>
                                        newNode.reset(
                                            'title',
                                            'code',
                                            'description',
                                            'parent_id',
                                            'estimated_minutes',
                                            'instructional_strategy',
                                            'activity_specification',
                                            'assessment_blueprint',
                                            'planned_starts_on',
                                            'planned_ends_on',
                                            'academic_course_id',
                                            'academic_course_version_id',
                                            'academic_period_id',
                                        ),
                                });
                            }}
                        >
                            <label className="grid gap-1 text-sm">
                                Parent item
                                <select
                                    className={inputClass}
                                    value={newNode.data.parent_id}
                                    onChange={(event) =>
                                        newNode.setData('parent_id', event.currentTarget.value)
                                    }
                                >
                                    <option value="">Top level unit/course</option>
                                    {nodes.map((node) => (
                                        <option key={node.id} value={node.id}>
                                            {node.node_type} · {node.title}
                                        </option>
                                    ))}
                                </select>
                            </label>
                            <label className="grid gap-1 text-sm">
                                Item type
                                <select
                                    className={inputClass}
                                    value={newNode.data.node_type}
                                    onChange={(event) =>
                                        newNode.setData('node_type', event.currentTarget.value)
                                    }
                                >
                                    {['course', 'unit', 'topic', 'subtopic', 'objective', 'lesson'].map(
                                        (kind) => (
                                            <option key={kind}>{kind}</option>
                                        ),
                                    )}
                                </select>
                            </label>
                            <label className="grid gap-1 text-sm">
                                Title
                                <input
                                    required
                                    maxLength={180}
                                    className={inputClass}
                                    value={newNode.data.title}
                                    onChange={(event) => newNode.setData('title', event.currentTarget.value)}
                                />
                                {newNode.errors.title && (
                                    <span className="text-danger text-xs">{newNode.errors.title}</span>
                                )}
                            </label>
                            <label className="grid gap-1 text-sm">
                                Code (optional)
                                <input
                                    maxLength={64}
                                    className={inputClass}
                                    value={newNode.data.code}
                                    onChange={(event) => newNode.setData('code', event.currentTarget.value)}
                                />
                            </label>
                            <label className="grid gap-1 text-sm xl:col-span-2">
                                Description
                                <textarea
                                    rows={2}
                                    maxLength={5000}
                                    className={`${inputClass} py-2`}
                                    value={newNode.data.description}
                                    onChange={(event) =>
                                        newNode.setData('description', event.currentTarget.value)
                                    }
                                />
                            </label>
                            <label className="grid gap-1 text-sm">
                                Academic period
                                <select
                                    className={inputClass}
                                    value={newNode.data.academic_period_id}
                                    onChange={(event) =>
                                        newNode.setData('academic_period_id', event.currentTarget.value)
                                    }
                                >
                                    <option value="">Not period-specific</option>
                                    {periods.map((period) => (
                                        <option key={period.id} value={period.id}>
                                            {period.code} · {period.name}
                                        </option>
                                    ))}
                                </select>
                            </label>
                            {newNode.data.node_type === 'course' && (
                                <>
                                    <label className="grid gap-1 text-sm">
                                        Catalog course
                                        <select
                                            className={inputClass}
                                            value={newNode.data.academic_course_id}
                                            onChange={(event) => {
                                                newNode.setData(
                                                    'academic_course_id',
                                                    event.currentTarget.value,
                                                );
                                                newNode.setData('academic_course_version_id', '');
                                            }}
                                        >
                                            <option value="">No catalog link</option>
                                            {courses.map((course) => (
                                                <option key={course.id} value={course.id}>
                                                    {course.code} · {course.title}
                                                </option>
                                            ))}
                                        </select>
                                    </label>
                                    <label className="grid gap-1 text-sm">
                                        Published course version
                                        <select
                                            className={inputClass}
                                            value={newNode.data.academic_course_version_id}
                                            onChange={(event) =>
                                                newNode.setData(
                                                    'academic_course_version_id',
                                                    event.currentTarget.value,
                                                )
                                            }
                                            disabled={!newNode.data.academic_course_id}
                                        >
                                            <option value="">Catalog course only</option>
                                            {courses
                                                .find(
                                                    (course) =>
                                                        String(course.id) === newNode.data.academic_course_id,
                                                )
                                                ?.versions.map((courseVersion) => (
                                                    <option key={courseVersion.id} value={courseVersion.id}>
                                                        v{courseVersion.version_number} ·{' '}
                                                        {courseVersion.title} · {courseVersion.total_credits}{' '}
                                                        credits
                                                    </option>
                                                ))}
                                        </select>
                                    </label>
                                </>
                            )}
                            <label className="grid gap-1 text-sm">
                                Estimated minutes
                                <input
                                    type="number"
                                    min="1"
                                    max="100000"
                                    className={inputClass}
                                    value={newNode.data.estimated_minutes}
                                    onChange={(event) =>
                                        newNode.setData('estimated_minutes', event.currentTarget.value)
                                    }
                                />
                            </label>
                            <label className="grid gap-1 text-sm">
                                Planned start
                                <input
                                    type="date"
                                    className={inputClass}
                                    value={newNode.data.planned_starts_on}
                                    onChange={(event) =>
                                        newNode.setData('planned_starts_on', event.currentTarget.value)
                                    }
                                />
                            </label>
                            <label className="grid gap-1 text-sm">
                                Planned end
                                <input
                                    type="date"
                                    className={inputClass}
                                    value={newNode.data.planned_ends_on}
                                    onChange={(event) =>
                                        newNode.setData('planned_ends_on', event.currentTarget.value)
                                    }
                                />
                            </label>
                            <label className="flex min-h-11 items-center gap-2 text-sm">
                                <input
                                    type="checkbox"
                                    checked={newNode.data.is_required}
                                    onChange={(event) =>
                                        newNode.setData('is_required', event.currentTarget.checked)
                                    }
                                />
                                Required
                            </label>
                            <button
                                type="submit"
                                disabled={newNode.processing}
                                className="bg-brand-action min-h-11 rounded-lg px-4 text-sm font-semibold text-white disabled:opacity-60"
                            >
                                {newNode.processing ? 'Adding…' : 'Add item'}
                            </button>
                            {Object.values(newNode.errors).map((error, index) => (
                                <p key={index} role="alert" className="text-danger text-xs">
                                    {error}
                                </p>
                            ))}
                        </form>
                    </section>
                )}

                <section className={cardClass}>
                    <div className="flex flex-wrap items-end justify-between gap-3">
                        <div>
                            <h2 className="text-lg font-semibold">Syllabus hierarchy</h2>
                            <p className="text-text-secondary mt-1 text-sm">
                                {nodes.length} of 2,000 available items in this version
                            </p>
                        </div>
                        {version.status === 'published' && (
                            <span className="text-success text-sm font-semibold">
                                Published snapshot is read-only
                            </span>
                        )}
                    </div>
                    {rootNodes.length === 0 ? (
                        <div className="border-border bg-canvas mt-4 rounded-xl border border-dashed p-8 text-center">
                            <h3 className="font-semibold">The syllabus is empty</h3>
                            <p className="text-text-secondary mt-1 text-sm">
                                Add a unit or course above. At least one item is required before review.
                            </p>
                        </div>
                    ) : (
                        <ol className="mt-5 grid list-none gap-3">
                            {rootNodes.map((node) => (
                                <CurriculumNode
                                    key={node.id}
                                    node={node}
                                    allNodes={nodes}
                                    outcomes={outcomes}
                                    version={version}
                                    base={base}
                                    canManage={canManage}
                                    competencies={competencies}
                                />
                            ))}
                        </ol>
                    )}
                </section>

                <section className={cardClass}>
                    <h2 className="text-lg font-semibold">Curriculum governance</h2>
                    <p className="text-text-secondary mt-1 text-sm">
                        Authors, reviewers and publishers are recorded separately. Self-approval and
                        post-publication edits are denied server-side.
                    </p>
                    {isDraft && (
                        <form
                            className="mt-4 grid gap-3 sm:grid-cols-[minmax(0,1fr)_auto]"
                            onSubmit={(event: FormEvent) => {
                                event.preventDefault();
                                submitForm.post(`${base}/versions/${version.id}/submit`);
                            }}
                        >
                            <label className="grid gap-1 text-sm">
                                Independent reviewers
                                <select
                                    required
                                    multiple
                                    className={inputClass}
                                    value={submitForm.data.reviewer_user_ids}
                                    onChange={(event) =>
                                        submitForm.setData(
                                            'reviewer_user_ids',
                                            Array.from(event.currentTarget.selectedOptions).map(
                                                (option) => option.value,
                                            ),
                                        )
                                    }
                                >
                                    {reviewers.map((reviewer) => (
                                        <option key={reviewer.id} value={reviewer.id}>
                                            {reviewer.name} · {reviewer.email}
                                        </option>
                                    ))}
                                </select>
                                <span className="text-text-muted text-xs">
                                    Select the number required by the active governance policy.
                                </span>
                                {submitForm.errors.reviewer_user_ids && (
                                    <span className="text-danger text-xs">
                                        {submitForm.errors.reviewer_user_ids}
                                    </span>
                                )}
                            </label>
                            <button
                                disabled={submitForm.processing || validation.blocking.length > 0}
                                className="bg-brand-action min-h-11 self-end rounded-lg px-4 text-sm font-semibold text-white disabled:opacity-60"
                                type="submit"
                            >
                                Submit for review
                            </button>
                            {Object.values(submitForm.errors).map((error, index) => (
                                <p key={index} role="alert" className="text-danger text-sm sm:col-span-2">
                                    {error}
                                </p>
                            ))}
                        </form>
                    )}
                    {mayReview && (
                        <form
                            className="mt-4 grid gap-3 sm:grid-cols-[minmax(0,1fr)_minmax(0,2fr)_auto]"
                            onSubmit={(event: FormEvent) => {
                                event.preventDefault();
                                reviewForm.post(`${base}/versions/${version.id}/decision`);
                            }}
                        >
                            {assignedReview?.checklist_snapshot.map((item) => {
                                const response = reviewForm.data.checklist_responses[String(item.id)] ?? {
                                    complete: false,
                                    note: '',
                                    evidence_reference: '',
                                };
                                return (
                                    <fieldset
                                        key={item.id}
                                        className="border-border grid gap-2 rounded-lg border p-3 sm:col-span-3"
                                    >
                                        <legend className="px-1 text-sm font-semibold">
                                            {item.category}
                                        </legend>
                                        <p className="text-sm">
                                            {item.prompt}
                                            {item.is_required ? ' *' : ''}
                                        </p>
                                        <label className="flex min-h-9 items-center gap-2 text-sm">
                                            <input
                                                type="checkbox"
                                                checked={response.complete}
                                                onChange={(event) =>
                                                    reviewForm.setData('checklist_responses', {
                                                        ...reviewForm.data.checklist_responses,
                                                        [String(item.id)]: {
                                                            ...response,
                                                            complete: event.currentTarget.checked,
                                                        },
                                                    })
                                                }
                                            />
                                            Reviewed
                                        </label>
                                        {item.requires_note && (
                                            <label className="grid gap-1 text-sm">
                                                Checklist note
                                                <textarea
                                                    className={`${inputClass} py-2`}
                                                    required={response.complete}
                                                    value={response.note}
                                                    onChange={(event) =>
                                                        reviewForm.setData('checklist_responses', {
                                                            ...reviewForm.data.checklist_responses,
                                                            [String(item.id)]: {
                                                                ...response,
                                                                note: event.currentTarget.value,
                                                            },
                                                        })
                                                    }
                                                />
                                            </label>
                                        )}
                                        <label className="grid gap-1 text-sm">
                                            Evidence reference (optional)
                                            <input
                                                className={inputClass}
                                                maxLength={500}
                                                value={response.evidence_reference}
                                                onChange={(event) =>
                                                    reviewForm.setData('checklist_responses', {
                                                        ...reviewForm.data.checklist_responses,
                                                        [String(item.id)]: {
                                                            ...response,
                                                            evidence_reference: event.currentTarget.value,
                                                        },
                                                    })
                                                }
                                            />
                                        </label>
                                    </fieldset>
                                );
                            })}
                            <label className="grid gap-1 text-sm">
                                Review decision
                                <select
                                    className={inputClass}
                                    value={reviewForm.data.decision}
                                    onChange={(event) =>
                                        reviewForm.setData('decision', event.currentTarget.value)
                                    }
                                >
                                    <option value="approve">Approve</option>
                                    <option value="request_changes">Request changes</option>
                                    <option value="reject">Reject</option>
                                </select>
                            </label>
                            <label className="grid gap-1 text-sm">
                                Reviewer note
                                <textarea
                                    maxLength={3000}
                                    rows={2}
                                    className={`${inputClass} py-2`}
                                    value={reviewForm.data.review_note}
                                    onChange={(event) =>
                                        reviewForm.setData('review_note', event.currentTarget.value)
                                    }
                                />
                            </label>
                            <button
                                className="bg-brand-action min-h-11 self-end rounded-lg px-4 text-sm font-semibold text-white"
                                type="submit"
                            >
                                Record review
                            </button>
                            {reviewForm.errors.decision && (
                                <p role="alert" className="text-danger text-sm sm:col-span-3">
                                    {reviewForm.errors.decision}
                                </p>
                            )}
                        </form>
                    )}
                    {version.status === 'approved' && canPublish && (
                        <button
                            type="button"
                            onClick={() =>
                                window.confirm('Publish this approved, immutable curriculum snapshot?') &&
                                router.post(`${base}/versions/${version.id}/publish`)
                            }
                            className="bg-brand-action mt-4 min-h-11 rounded-lg px-4 text-sm font-semibold text-white"
                        >
                            Publish approved version
                        </button>
                    )}
                    {version.status === 'in_review' && (
                        <p className="border-border bg-canvas mt-4 rounded-lg border p-3 text-sm">
                            This version has active review assignments. Each reviewer must use their own
                            current assignment. The author cannot review or publish when the active policy
                            requires separation.
                        </p>
                    )}
                </section>

                <section className={cardClass}>
                    <h2 className="text-lg font-semibold">Competency framework</h2>
                    <p className="text-text-secondary mt-1 text-sm">
                        Institution competency definitions are available for curriculum design mapping.
                    </p>
                    <form
                        className="mt-4 grid gap-2 sm:grid-cols-3"
                        onSubmit={(event: FormEvent) => {
                            event.preventDefault();
                            competencyFrameworkForm.post(`${base}/competency-frameworks`, {
                                onSuccess: () => competencyFrameworkForm.reset(),
                            });
                        }}
                    >
                        <label className="grid gap-1 text-sm">
                            Framework code
                            <input
                                required
                                className={inputClass}
                                maxLength={48}
                                value={competencyFrameworkForm.data.code}
                                onChange={(event) =>
                                    competencyFrameworkForm.setData('code', event.currentTarget.value)
                                }
                            />
                        </label>
                        <label className="grid gap-1 text-sm">
                            Framework title
                            <input
                                required
                                className={inputClass}
                                maxLength={180}
                                value={competencyFrameworkForm.data.title}
                                onChange={(event) =>
                                    competencyFrameworkForm.setData('title', event.currentTarget.value)
                                }
                            />
                        </label>
                        <label className="grid gap-1 text-sm">
                            Description
                            <input
                                className={inputClass}
                                maxLength={5000}
                                value={competencyFrameworkForm.data.description}
                                onChange={(event) =>
                                    competencyFrameworkForm.setData('description', event.currentTarget.value)
                                }
                            />
                        </label>
                        <button
                            className="border-border min-h-11 rounded-lg border px-4 text-sm font-semibold sm:col-span-3"
                            type="submit"
                        >
                            Create competency framework
                        </button>
                    </form>
                    {competencyFrameworks.length === 0 ? (
                        <p className="text-text-secondary mt-3 text-sm">
                            No competency frameworks defined yet.
                        </p>
                    ) : (
                        <ul className="mt-3 grid gap-2">
                            {competencyFrameworks.map((framework) => (
                                <CompetencyFrameworkCard
                                    key={framework.id}
                                    framework={framework}
                                    base={base}
                                    canManage={canManage && isDraft}
                                />
                            ))}
                        </ul>
                    )}
                </section>
            </div>
        </WorkspaceLayout>
    );
}
