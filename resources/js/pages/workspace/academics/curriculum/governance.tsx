import { Head, Link, useForm, usePage } from '@inertiajs/react';
import type { FormEvent } from 'react';
import { WorkspaceLayout } from '@/layouts/workspace-layout';

type Member = { id: number; name: string; campus_id?: number | null };
type SmallOption = { id: number; code: string; title: string };
type Assignment = {
    id: number;
    user_id: number;
    responsibility: string;
    campus_id: number | null;
    academic_program_id: number | null;
    effective_until: string | null;
    revoked_at: string | null;
    assignment_reason: string;
    user?: { name: string } | null;
};
type Framework = {
    id: number;
    code: string;
    title: string;
    status: string;
    owner_user_id: number | null;
    owner?: { name: string } | null;
    administrators: Assignment[];
};
type Competency = {
    id: number;
    code: string;
    title: string;
    descriptor: string;
    parent_id: number | null;
    category: string | null;
};
type CompetencyFramework = {
    id: number;
    code: string;
    title: string;
    version_number: number;
    status: string;
    owner_user_id: number | null;
    competencies: Competency[];
};
type Policy = {
    id: number;
    scope: string;
    version_number: number;
    status: string;
    minimum_reviewers: number;
    reviewer_eligibility: string;
    effective_from: string;
    framework?: SmallOption | null;
    competency_framework?: SmallOption | null;
    checklist_template?: {
        title: string;
        items: { id: number; prompt: string; category: string; is_required: boolean }[];
    } | null;
};
type ReviewAssignment = {
    framework_id: number;
    review_id: number;
    code: string;
    title: string;
    checklist_snapshot: {
        id: number;
        category: string;
        prompt: string;
        is_required: boolean;
        requires_note: boolean;
    }[];
};
type CompetencyOption = { id: number; code: string; title: string; framework: string };
type ProgramRequirement = {
    id: number;
    expected_level: string | null;
    is_required: boolean;
    program?: SmallOption;
    competency?: { id: number; code: string; title: string };
};
type Props = {
    tenantSlug: string;
    frameworks: Framework[];
    competencyFrameworks: CompetencyFramework[];
    competencyOptions: CompetencyOption[];
    programRequirements: ProgramRequirement[];
    members: Member[];
    reviewers: Member[];
    campuses: { id: number; code: string; name: string }[];
    programs: SmallOption[];
    policies: Policy[];
    assignedReviews: ReviewAssignment[];
};

const field = 'border-border bg-surface min-h-11 w-full rounded-lg border px-3 text-sm';
const panel = 'border-border bg-surface grid gap-4 rounded-2xl border p-5 shadow-sm';
const button =
    'bg-brand-action min-h-11 rounded-lg px-4 text-sm font-semibold text-white disabled:opacity-60';

function AssignmentPanel({
    framework,
    members,
    campuses,
    programs,
    base,
}: {
    framework: Framework;
    members: Member[];
    campuses: Props['campuses'];
    programs: SmallOption[];
    base: string;
}) {
    const form = useForm({
        user_id: '',
        responsibility: 'administrator',
        campus_id: '',
        academic_program_id: '',
        effective_from: '',
        effective_until: '',
        reason: '',
    });
    const owner = useForm({ user_id: '', reason: '' });

    return (
        <details
            className="border-border rounded-xl border p-4"
            open={framework.administrators.some((assignment) => !assignment.revoked_at)}
        >
            <summary className="min-h-10 cursor-pointer font-semibold">
                {framework.code} · {framework.title}{' '}
                <span className="text-text-muted font-normal">({framework.status})</span>
            </summary>
            <p className="text-text-secondary mt-2 text-sm">
                Framework owner: {framework.owner?.name ?? 'Not assigned'}. Assignments annotate scope and
                responsibility; they do not grant permissions.
            </p>
            <div className="mt-4 grid gap-4 xl:grid-cols-2">
                <form
                    className="grid gap-3"
                    onSubmit={(event: FormEvent) => {
                        event.preventDefault();
                        form.post(`${base}/frameworks/${framework.id}/administrators`, {
                            onSuccess: () => form.reset(),
                        });
                    }}
                >
                    <h3 className="font-semibold">Assign an existing administrator</h3>
                    <label className="grid gap-1 text-sm">
                        Member
                        <select
                            className={field}
                            required
                            value={form.data.user_id}
                            onChange={(event) => form.setData('user_id', event.currentTarget.value)}
                        >
                            <option value="">Choose active member</option>
                            {members.map((member) => (
                                <option key={member.id} value={member.id}>
                                    {member.name}
                                </option>
                            ))}
                        </select>
                    </label>
                    <label className="grid gap-1 text-sm">
                        Responsibility
                        <select
                            className={field}
                            value={form.data.responsibility}
                            onChange={(event) => form.setData('responsibility', event.currentTarget.value)}
                        >
                            <option value="administrator">Administrator</option>
                            <option value="coordinator">Coordinator</option>
                            <option value="editor">Editor</option>
                        </select>
                    </label>
                    <div className="grid gap-3 sm:grid-cols-2">
                        <label className="grid gap-1 text-sm">
                            Campus scope
                            <select
                                className={field}
                                value={form.data.campus_id}
                                onChange={(event) => form.setData('campus_id', event.currentTarget.value)}
                            >
                                <option value="">Institution-wide</option>
                                {campuses.map((campus) => (
                                    <option key={campus.id} value={campus.id}>
                                        {campus.code} · {campus.name}
                                    </option>
                                ))}
                            </select>
                        </label>
                        <label className="grid gap-1 text-sm">
                            Program scope
                            <select
                                className={field}
                                value={form.data.academic_program_id}
                                onChange={(event) =>
                                    form.setData('academic_program_id', event.currentTarget.value)
                                }
                            >
                                <option value="">All programs</option>
                                {programs.map((program) => (
                                    <option key={program.id} value={program.id}>
                                        {program.code} · {program.title}
                                    </option>
                                ))}
                            </select>
                        </label>
                    </div>
                    <div className="grid gap-3 sm:grid-cols-2">
                        <label className="grid gap-1 text-sm">
                            Effective from
                            <input
                                type="date"
                                className={field}
                                value={form.data.effective_from}
                                onChange={(event) =>
                                    form.setData('effective_from', event.currentTarget.value)
                                }
                            />
                        </label>
                        <label className="grid gap-1 text-sm">
                            Effective until
                            <input
                                type="date"
                                className={field}
                                value={form.data.effective_until}
                                onChange={(event) =>
                                    form.setData('effective_until', event.currentTarget.value)
                                }
                            />
                        </label>
                    </div>
                    <label className="grid gap-1 text-sm">
                        Reason
                        <textarea
                            required
                            minLength={8}
                            maxLength={1000}
                            className={`${field} py-2`}
                            value={form.data.reason}
                            onChange={(event) => form.setData('reason', event.currentTarget.value)}
                        />
                    </label>
                    <button className={button} disabled={form.processing}>
                        Record assignment
                    </button>
                    {Object.keys(form.errors).length > 0 && (
                        <p role="alert" className="text-danger text-sm">
                            {Object.values(form.errors).join(' ')}
                        </p>
                    )}
                </form>
                <div className="grid content-start gap-3">
                    <h3 className="font-semibold">Assignment history</h3>
                    {framework.administrators.length === 0 ? (
                        <p className="text-text-muted text-sm">No assignment history yet.</p>
                    ) : (
                        framework.administrators.map((assignment) => (
                            <div
                                key={assignment.id}
                                className="border-border flex flex-wrap items-center justify-between gap-2 rounded-lg border p-3 text-sm"
                            >
                                <div>
                                    <p className="font-medium">
                                        {assignment.user?.name ?? 'Unavailable member'} ·{' '}
                                        {assignment.responsibility}
                                    </p>
                                    <p className="text-text-muted">
                                        {assignment.assignment_reason}
                                        {assignment.revoked_at
                                            ? ' · Revoked'
                                            : assignment.effective_until
                                              ? ` · Until ${assignment.effective_until}`
                                              : ' · Active'}
                                    </p>
                                </div>
                                {!assignment.revoked_at && (
                                    <RevokeAssignment assignment={assignment} base={base} />
                                )}
                            </div>
                        ))
                    )}
                    <form
                        className="border-border mt-2 grid gap-2 rounded-xl border p-3"
                        onSubmit={(event: FormEvent) => {
                            event.preventDefault();
                            owner.post(`${base}/frameworks/${framework.id}/owner`, {
                                onSuccess: () => owner.reset(),
                            });
                        }}
                    >
                        <h4 className="font-semibold">Change framework owner</h4>
                        <select
                            className={field}
                            required
                            value={owner.data.user_id}
                            onChange={(event) => owner.setData('user_id', event.currentTarget.value)}
                        >
                            <option value="">Select authorized member</option>
                            {members.map((member) => (
                                <option key={member.id} value={member.id}>
                                    {member.name}
                                </option>
                            ))}
                        </select>
                        <textarea
                            className={`${field} py-2`}
                            required
                            minLength={8}
                            placeholder="Reason for owner change"
                            value={owner.data.reason}
                            onChange={(event) => owner.setData('reason', event.currentTarget.value)}
                        />
                        <button className={button} disabled={owner.processing}>
                            Update owner
                        </button>
                    </form>
                </div>
            </div>
        </details>
    );
}

function RevokeAssignment({ assignment, base }: { assignment: Assignment; base: string }) {
    const form = useForm({ reason: '' });
    return (
        <form
            className="flex items-center gap-2"
            onSubmit={(event: FormEvent) => {
                event.preventDefault();
                form.post(`${base}/framework-administrators/${assignment.id}/revoke`, {
                    onSuccess: () => form.reset(),
                });
            }}
        >
            <label className="sr-only" htmlFor={`revoke-reason-${assignment.id}`}>
                Revocation reason
            </label>
            <input
                id={`revoke-reason-${assignment.id}`}
                required
                minLength={8}
                maxLength={1000}
                className={`${field} min-h-9 w-48`}
                placeholder="Revocation reason"
                value={form.data.reason}
                onChange={(event) => form.setData('reason', event.currentTarget.value)}
            />
            <button
                className="border-border min-h-9 rounded-lg border px-3 text-xs font-semibold"
                disabled={form.processing}
            >
                Revoke
            </button>
        </form>
    );
}

function PolicyBuilder({
    frameworks,
    competencyFrameworks,
    base,
}: {
    frameworks: Framework[];
    competencyFrameworks: CompetencyFramework[];
    base: string;
}) {
    const form = useForm({
        scope: 'curriculum',
        framework_id: '',
        competency_framework_id: '',
        reviewer_eligibility: 'institution_wide',
        minimum_reviewers: 1,
        require_independent_reviewer: true,
        require_separate_publisher: true,
        require_changes_note: true,
        require_rejection_note: true,
        require_checklist_completion: true,
        allow_resubmission: true,
        effective_from: new Date().toISOString().slice(0, 10),
        effective_until: '',
        checklist_code: 'CURRICULUM-REVIEW',
        checklist_title: 'Curriculum review checklist',
        checklist_items: [
            {
                category: 'Alignment',
                prompt: 'Outcomes and content align to the approved program.',
                is_required: true,
                requires_note: true,
            },
        ],
    });
    const scope = form.data.scope;
    const itemList = form.data.checklist_items;
    return (
        <form
            className={panel}
            onSubmit={(event: FormEvent) => {
                event.preventDefault();
                form.post(`${base}/governance/policies`, { onSuccess: () => form.reset() });
            }}
        >
            <div>
                <h2 className="text-lg font-semibold">Publish governance policy</h2>
                <p className="text-text-secondary mt-1 text-sm">
                    Each change creates a new immutable version. Earlier reviews keep their submitted
                    snapshot.
                </p>
            </div>
            <div className="grid gap-3 sm:grid-cols-2">
                <label className="grid gap-1 text-sm">
                    Policy scope
                    <select
                        className={field}
                        value={scope}
                        onChange={(event) =>
                            form.setData((current) => ({
                                ...current,
                                scope: event.currentTarget.value,
                                framework_id: '',
                                competency_framework_id: '',
                            }))
                        }
                    >
                        <option value="curriculum">Curriculum and syllabus</option>
                        <option value="competency">Competency structures</option>
                    </select>
                </label>
                {scope === 'curriculum' ? (
                    <label className="grid gap-1 text-sm">
                        Framework scope
                        <select
                            className={field}
                            value={form.data.framework_id}
                            onChange={(event) => form.setData('framework_id', event.currentTarget.value)}
                        >
                            <option value="">Institution-wide default</option>
                            {frameworks.map((item) => (
                                <option key={item.id} value={item.id}>
                                    {item.code} · {item.title}
                                </option>
                            ))}
                        </select>
                    </label>
                ) : (
                    <label className="grid gap-1 text-sm">
                        Competency framework scope
                        <select
                            className={field}
                            value={form.data.competency_framework_id}
                            onChange={(event) =>
                                form.setData('competency_framework_id', event.currentTarget.value)
                            }
                        >
                            <option value="">Institution-wide default</option>
                            {competencyFrameworks.map((item) => (
                                <option key={item.id} value={item.id}>
                                    {item.code} · {item.title}
                                </option>
                            ))}
                        </select>
                    </label>
                )}
                <label className="grid gap-1 text-sm">
                    Reviewer eligibility
                    <select
                        className={field}
                        value={form.data.reviewer_eligibility}
                        onChange={(event) => form.setData('reviewer_eligibility', event.currentTarget.value)}
                    >
                        <option value="institution_wide">Active institution reviewers</option>
                        <option value="framework_assignees" disabled={scope === 'competency'}>
                            Framework administrators with review permission
                        </option>
                        <option value="program_scope">Institution-wide program reviewer</option>
                    </select>
                </label>
                <label className="grid gap-1 text-sm">
                    Minimum reviewers
                    <input
                        type="number"
                        min={1}
                        max={3}
                        className={field}
                        value={form.data.minimum_reviewers}
                        onChange={(event) =>
                            form.setData('minimum_reviewers', Number(event.currentTarget.value))
                        }
                    />
                </label>
                <label className="grid gap-1 text-sm">
                    Effective from
                    <input
                        type="date"
                        required
                        className={field}
                        value={form.data.effective_from}
                        onChange={(event) => form.setData('effective_from', event.currentTarget.value)}
                    />
                </label>
                <label className="grid gap-1 text-sm">
                    Expires on (optional)
                    <input
                        type="date"
                        className={field}
                        value={form.data.effective_until}
                        onChange={(event) => form.setData('effective_until', event.currentTarget.value)}
                    />
                </label>
            </div>
            <fieldset className="grid gap-2 sm:grid-cols-2">
                <legend className="font-semibold">Enforcement rules</legend>
                {(
                    [
                        ['require_independent_reviewer', 'Separate author and reviewer'],
                        ['require_separate_publisher', 'Separate publisher from author and reviewers'],
                        ['require_changes_note', 'Require a note for changes requested'],
                        ['require_rejection_note', 'Require a note for rejection'],
                        ['require_checklist_completion', 'Require checklist completion'],
                        ['allow_resubmission', 'Allow policy-authorized resubmission'],
                    ] as const
                ).map(([key, label]) => (
                    <label key={key} className="flex min-h-10 items-center gap-2 text-sm">
                        <input
                            type="checkbox"
                            checked={form.data[key]}
                            onChange={(event) => form.setData(key, event.currentTarget.checked)}
                        />
                        {label}
                    </label>
                ))}
            </fieldset>
            <fieldset className="grid gap-3">
                <legend className="font-semibold">Review checklist template</legend>
                <div className="grid gap-3 sm:grid-cols-2">
                    <label className="grid gap-1 text-sm">
                        Checklist code
                        <input
                            className={field}
                            maxLength={48}
                            value={form.data.checklist_code}
                            onChange={(event) => form.setData('checklist_code', event.currentTarget.value)}
                        />
                    </label>
                    <label className="grid gap-1 text-sm">
                        Checklist title
                        <input
                            className={field}
                            maxLength={160}
                            value={form.data.checklist_title}
                            onChange={(event) => form.setData('checklist_title', event.currentTarget.value)}
                        />
                    </label>
                </div>
                {itemList.map((item, index) => (
                    <div
                        className="border-border grid gap-2 rounded-lg border p-3 sm:grid-cols-2"
                        key={index}
                    >
                        <label className="grid gap-1 text-sm">
                            Category
                            <input
                                className={field}
                                maxLength={80}
                                value={item.category}
                                onChange={(event) =>
                                    form.setData(
                                        'checklist_items',
                                        itemList.map((entry, i) =>
                                            i === index
                                                ? { ...entry, category: event.currentTarget.value }
                                                : entry,
                                        ),
                                    )
                                }
                            />
                        </label>
                        <label className="grid gap-1 text-sm">
                            Review prompt
                            <input
                                className={field}
                                maxLength={500}
                                value={item.prompt}
                                onChange={(event) =>
                                    form.setData(
                                        'checklist_items',
                                        itemList.map((entry, i) =>
                                            i === index
                                                ? { ...entry, prompt: event.currentTarget.value }
                                                : entry,
                                        ),
                                    )
                                }
                            />
                        </label>
                        <label className="flex min-h-10 items-center gap-2 text-sm">
                            <input
                                type="checkbox"
                                checked={item.is_required}
                                onChange={(event) =>
                                    form.setData(
                                        'checklist_items',
                                        itemList.map((entry, i) =>
                                            i === index
                                                ? { ...entry, is_required: event.currentTarget.checked }
                                                : entry,
                                        ),
                                    )
                                }
                            />
                            Required
                        </label>
                        <label className="flex min-h-10 items-center gap-2 text-sm">
                            <input
                                type="checkbox"
                                checked={item.requires_note}
                                onChange={(event) =>
                                    form.setData(
                                        'checklist_items',
                                        itemList.map((entry, i) =>
                                            i === index
                                                ? { ...entry, requires_note: event.currentTarget.checked }
                                                : entry,
                                        ),
                                    )
                                }
                            />
                            Requires note
                        </label>
                    </div>
                ))}
                <button
                    type="button"
                    className="border-border min-h-10 justify-self-start rounded-lg border px-3 text-sm"
                    onClick={() =>
                        form.setData('checklist_items', [
                            ...itemList,
                            { category: '', prompt: '', is_required: true, requires_note: false },
                        ])
                    }
                >
                    Add checklist item
                </button>
            </fieldset>
            <button className={button} disabled={form.processing}>
                Publish policy version
            </button>
            {Object.keys(form.errors).length > 0 && (
                <p role="alert" className="text-danger text-sm">
                    {Object.values(form.errors).join(' ')}
                </p>
            )}
        </form>
    );
}

function CompetencyWorkspace({
    framework,
    reviewers,
    base,
}: {
    framework: CompetencyFramework;
    reviewers: Member[];
    base: string;
}) {
    const competency = useForm({
        parent_id: '',
        code: '',
        title: '',
        descriptor: '',
        mastery_criteria: '',
        category: '',
        level_label: '',
        mastery_levels: [''],
        evidence_requirements: [''],
        sort_order: 0,
    });
    const submission = useForm({ reviewer_user_ids: [] as string[] });
    const publishForm = useForm({});
    return (
        <details className="border-border rounded-xl border p-4">
            <summary className="min-h-10 cursor-pointer font-semibold">
                {framework.code} · {framework.title} v{framework.version_number}{' '}
                <span className="text-text-muted font-normal">({framework.status})</span>
            </summary>
            <ul className="mt-3 grid gap-2">
                {framework.competencies.map((item) => (
                    <li className="border-border rounded-lg border p-3 text-sm" key={item.id}>
                        <strong>
                            {item.code} · {item.title}
                        </strong>
                        {item.category && <span className="text-text-muted"> · {item.category}</span>}
                        <p className="text-text-secondary mt-1">{item.descriptor}</p>
                    </li>
                ))}
            </ul>
            {['draft', 'changes_requested'].includes(framework.status) && (
                <form
                    className="mt-4 grid gap-3 border-t pt-4"
                    onSubmit={(event: FormEvent) => {
                        event.preventDefault();
                        competency.post(`${base}/competency-frameworks/${framework.id}/competencies`, {
                            onSuccess: () => competency.reset(),
                        });
                    }}
                >
                    <h3 className="font-semibold">Add competency</h3>
                    <label className="grid gap-1 text-sm">
                        Parent competency (optional)
                        <select
                            className={field}
                            value={competency.data.parent_id}
                            onChange={(event) => competency.setData('parent_id', event.currentTarget.value)}
                        >
                            <option value="">Top-level competency</option>
                            {framework.competencies.map((item) => (
                                <option key={item.id} value={item.id}>
                                    {item.code} · {item.title}
                                </option>
                            ))}
                        </select>
                    </label>
                    <div className="grid gap-3 sm:grid-cols-2">
                        <label className="grid gap-1 text-sm">
                            Code
                            <input
                                required
                                className={field}
                                value={competency.data.code}
                                onChange={(event) => competency.setData('code', event.currentTarget.value)}
                            />
                        </label>
                        <label className="grid gap-1 text-sm">
                            Title
                            <input
                                required
                                className={field}
                                value={competency.data.title}
                                onChange={(event) => competency.setData('title', event.currentTarget.value)}
                            />
                        </label>
                        <label className="grid gap-1 text-sm">
                            Category
                            <input
                                className={field}
                                value={competency.data.category}
                                onChange={(event) =>
                                    competency.setData('category', event.currentTarget.value)
                                }
                            />
                        </label>
                        <label className="grid gap-1 text-sm">
                            Level label
                            <input
                                className={field}
                                value={competency.data.level_label}
                                onChange={(event) =>
                                    competency.setData('level_label', event.currentTarget.value)
                                }
                            />
                        </label>
                    </div>
                    <label className="grid gap-1 text-sm">
                        Descriptor
                        <textarea
                            required
                            className={`${field} py-2`}
                            value={competency.data.descriptor}
                            onChange={(event) => competency.setData('descriptor', event.currentTarget.value)}
                        />
                    </label>
                    <label className="grid gap-1 text-sm">
                        Expected mastery definition
                        <textarea
                            className={`${field} py-2`}
                            value={competency.data.mastery_criteria}
                            onChange={(event) =>
                                competency.setData('mastery_criteria', event.currentTarget.value)
                            }
                        />
                    </label>
                    <label className="grid gap-1 text-sm">
                        Mastery levels (comma-separated)
                        <input
                            className={field}
                            onChange={(event) =>
                                competency.setData(
                                    'mastery_levels',
                                    event.currentTarget.value
                                        .split(',')
                                        .map((value) => value.trim())
                                        .filter(Boolean),
                                )
                            }
                        />
                    </label>
                    <label className="grid gap-1 text-sm">
                        Evidence requirements (comma-separated)
                        <input
                            className={field}
                            onChange={(event) =>
                                competency.setData(
                                    'evidence_requirements',
                                    event.currentTarget.value
                                        .split(',')
                                        .map((value) => value.trim())
                                        .filter(Boolean),
                                )
                            }
                        />
                    </label>
                    <button className={button} disabled={competency.processing}>
                        Add to draft
                    </button>
                </form>
            )}
            {['draft', 'changes_requested'].includes(framework.status) && (
                <form
                    className="mt-4 grid gap-3 border-t pt-4"
                    onSubmit={(event: FormEvent) => {
                        event.preventDefault();
                        submission.post(`${base}/competency-frameworks/${framework.id}/submit`);
                    }}
                >
                    <h3 className="font-semibold">Submit for review</h3>
                    <label className="grid gap-1 text-sm">
                        Independent reviewers
                        <select
                            multiple
                            className={`${field} min-h-28`}
                            required
                            value={submission.data.reviewer_user_ids}
                            onChange={(event) =>
                                submission.setData(
                                    'reviewer_user_ids',
                                    Array.from(event.currentTarget.selectedOptions).map(
                                        (option) => option.value,
                                    ),
                                )
                            }
                        >
                            {reviewers.map((reviewer) => (
                                <option key={reviewer.id} value={reviewer.id}>
                                    {reviewer.name}
                                </option>
                            ))}
                        </select>
                        <span className="text-text-muted text-xs">
                            Select the number required by the active policy.
                        </span>
                    </label>
                    <button
                        className={button}
                        disabled={submission.processing || framework.competencies.length === 0}
                    >
                        Submit framework
                    </button>
                    {Object.keys(submission.errors).length > 0 && (
                        <p role="alert" className="text-danger text-sm">
                            {Object.values(submission.errors).join(' ')}
                        </p>
                    )}
                </form>
            )}
            {framework.status === 'approved' && (
                <form
                    className="mt-4"
                    onSubmit={(event: FormEvent) => {
                        event.preventDefault();
                        publishForm.post(`${base}/competency-frameworks/${framework.id}/publish`);
                    }}
                >
                    <button className={button} disabled={publishForm.processing}>
                        Publish approved version
                    </button>
                </form>
            )}
            {['published', 'retired'].includes(framework.status) && (
                <RevisionAction frameworkId={framework.id} base={base} />
            )}
        </details>
    );
}

function RevisionAction({ frameworkId, base }: { frameworkId: number; base: string }) {
    const form = useForm({ revision_note: '' });
    return (
        <form
            className="mt-4 grid gap-2 border-t pt-4"
            onSubmit={(event: FormEvent) => {
                event.preventDefault();
                form.post(`${base}/competency-frameworks/${frameworkId}/revisions`);
            }}
        >
            <label className="grid gap-1 text-sm">
                Create next draft revision
                <textarea
                    required
                    minLength={8}
                    className={`${field} py-2`}
                    value={form.data.revision_note}
                    onChange={(event) => form.setData('revision_note', event.currentTarget.value)}
                />
            </label>
            <button className="border-border min-h-10 rounded-lg border px-3 text-sm">Create revision</button>
        </form>
    );
}

export default function CurriculumGovernance({
    tenantSlug,
    frameworks,
    competencyFrameworks,
    competencyOptions,
    programRequirements,
    members,
    reviewers,
    campuses,
    programs,
    policies,
    assignedReviews,
}: Props) {
    const base = `/t/${tenantSlug}/academics/curriculum`;
    const page = usePage<{ auth?: { tenant?: { permissions?: string[] } }; flash?: { status?: string } }>();
    const canManage = page.props.auth?.tenant?.permissions?.includes('academic.curriculum.manage') ?? false;
    const competencyForm = useForm({
        code: '',
        title: '',
        description: '',
        effective_from: '',
        effective_until: '',
        is_shared_template: false,
    });
    const mappingForm = useForm({
        program_id: '',
        competency_id: '',
        expected_level: '',
        evidence_requirements: [] as string[],
        is_required: true,
    });
    return (
        <WorkspaceLayout title="Curriculum governance" area="tenant">
            <Head title="Curriculum governance" />
            <main className="mx-auto grid w-full max-w-7xl gap-6 px-4 py-6 sm:px-6 lg:px-8">
                <header className="flex flex-wrap items-end justify-between gap-3">
                    <div>
                        <p className="text-text-muted text-sm">Curriculum controls</p>
                        <h1 className="text-2xl font-bold">Governance and competency</h1>
                        <p className="text-text-secondary mt-1 max-w-3xl text-sm">
                            Manage framework assignments, versioned review rules and competency approval
                            without changing institutional roles.
                        </p>
                    </div>
                    <Link
                        href={base}
                        className="border-border bg-surface min-h-11 rounded-lg border px-4 py-3 text-sm font-semibold"
                    >
                        Back to curriculum
                    </Link>
                </header>
                {canManage && (
                    <section className={panel}>
                        <div>
                            <h2 className="text-lg font-semibold">Framework administrators</h2>
                            <p className="text-text-secondary text-sm">
                                Changes are tenant and institution scoped, reasoned, and retained as history.
                            </p>
                        </div>
                        {frameworks.length === 0 ? (
                            <p className="text-text-muted text-sm">Create a curriculum framework first.</p>
                        ) : (
                            frameworks.map((framework) => (
                                <AssignmentPanel
                                    key={framework.id}
                                    framework={framework}
                                    members={members}
                                    campuses={campuses}
                                    programs={programs}
                                    base={base}
                                />
                            ))
                        )}
                    </section>
                )}
                {canManage && (
                    <PolicyBuilder
                        frameworks={frameworks}
                        competencyFrameworks={competencyFrameworks}
                        base={base}
                    />
                )}
                <section className={panel}>
                    <div>
                        <h2 className="text-lg font-semibold">Policy versions</h2>
                        <p className="text-text-secondary text-sm">
                            Resolved policy applies by scope and effective dates; review rows retain their
                            original policy version.
                        </p>
                    </div>
                    {policies.length === 0 ? (
                        <p className="text-text-muted text-sm">
                            No governance policies are configured; existing independent-review defaults remain
                            in force.
                        </p>
                    ) : (
                        <div className="grid gap-2">
                            {policies.map((policy) => (
                                <article
                                    key={policy.id}
                                    className="border-border rounded-lg border p-3 text-sm"
                                >
                                    <h3 className="font-semibold">
                                        {policy.scope === 'curriculum'
                                            ? (policy.framework?.title ?? 'Institution curriculum default')
                                            : (policy.competency_framework?.title ??
                                              'Institution competency default')}{' '}
                                        · v{policy.version_number}
                                    </h3>
                                    <p className="text-text-secondary mt-1">
                                        {policy.status} · {policy.minimum_reviewers} reviewer(s) ·{' '}
                                        {policy.reviewer_eligibility} · effective {policy.effective_from}
                                    </p>
                                    {policy.checklist_template && (
                                        <p className="mt-1">
                                            Checklist: {policy.checklist_template.title} (
                                            {policy.checklist_template.items.length} items)
                                        </p>
                                    )}
                                </article>
                            ))}
                        </div>
                    )}
                </section>
                {canManage && (
                    <section className={panel}>
                        <div>
                            <h2 className="text-lg font-semibold">Competency framework</h2>
                            <p className="text-text-secondary text-sm">
                                Draft, review and publish versioned competency definitions. No learner
                                attainment or scores are created here.
                            </p>
                        </div>
                        <form
                            className="grid gap-3 sm:grid-cols-2"
                            onSubmit={(event: FormEvent) => {
                                event.preventDefault();
                                competencyForm.post(`${base}/competency-frameworks`, {
                                    onSuccess: () => competencyForm.reset(),
                                });
                            }}
                        >
                            <label className="grid gap-1 text-sm">
                                Framework code
                                <input
                                    required
                                    className={field}
                                    value={competencyForm.data.code}
                                    onChange={(event) =>
                                        competencyForm.setData('code', event.currentTarget.value)
                                    }
                                />
                            </label>
                            <label className="grid gap-1 text-sm">
                                Framework title
                                <input
                                    required
                                    className={field}
                                    value={competencyForm.data.title}
                                    onChange={(event) =>
                                        competencyForm.setData('title', event.currentTarget.value)
                                    }
                                />
                            </label>
                            <label className="grid gap-1 text-sm sm:col-span-2">
                                Description
                                <textarea
                                    className={`${field} py-2`}
                                    value={competencyForm.data.description}
                                    onChange={(event) =>
                                        competencyForm.setData('description', event.currentTarget.value)
                                    }
                                />
                            </label>
                            <label className="flex min-h-10 items-center gap-2 text-sm">
                                <input
                                    type="checkbox"
                                    checked={competencyForm.data.is_shared_template}
                                    onChange={(event) =>
                                        competencyForm.setData(
                                            'is_shared_template',
                                            event.currentTarget.checked,
                                        )
                                    }
                                />
                                Allow reuse within this tenant after publication
                            </label>
                            <button className={button} disabled={competencyForm.processing}>
                                Create draft framework
                            </button>
                        </form>
                        {competencyFrameworks.length === 0 ? (
                            <p className="text-text-muted text-sm">No competency frameworks exist.</p>
                        ) : (
                            <div className="grid gap-3">
                                {competencyFrameworks.map((framework) => (
                                    <CompetencyWorkspace
                                        key={framework.id}
                                        framework={framework}
                                        reviewers={reviewers}
                                        base={base}
                                    />
                                ))}
                            </div>
                        )}
                    </section>
                )}
                {canManage && (
                    <section className={panel}>
                        <div>
                            <h2 className="text-lg font-semibold">Program competency requirements</h2>
                            <p className="text-text-secondary text-sm">
                                Map published competency definitions to programs. Curriculum versions then
                                report unmapped design requirements.
                            </p>
                        </div>
                        <form
                            className="grid gap-3 sm:grid-cols-2"
                            onSubmit={(event: FormEvent) => {
                                event.preventDefault();
                                mappingForm.post(
                                    `${base}/programs/${mappingForm.data.program_id}/competencies/${mappingForm.data.competency_id}`,
                                    { onSuccess: () => mappingForm.reset() },
                                );
                            }}
                        >
                            <label className="grid gap-1 text-sm">
                                Program
                                <select
                                    required
                                    className={field}
                                    value={mappingForm.data.program_id}
                                    onChange={(event) =>
                                        mappingForm.setData('program_id', event.currentTarget.value)
                                    }
                                >
                                    <option value="">Choose program</option>
                                    {programs.map((program) => (
                                        <option key={program.id} value={program.id}>
                                            {program.code} · {program.title}
                                        </option>
                                    ))}
                                </select>
                            </label>
                            <label className="grid gap-1 text-sm">
                                Published competency
                                <select
                                    required
                                    className={field}
                                    value={mappingForm.data.competency_id}
                                    onChange={(event) =>
                                        mappingForm.setData('competency_id', event.currentTarget.value)
                                    }
                                >
                                    <option value="">Choose competency</option>
                                    {competencyOptions.map((competency) => (
                                        <option key={competency.id} value={competency.id}>
                                            {competency.framework} · {competency.code} · {competency.title}
                                        </option>
                                    ))}
                                </select>
                            </label>
                            <label className="grid gap-1 text-sm">
                                Expected level
                                <input
                                    className={field}
                                    maxLength={80}
                                    value={mappingForm.data.expected_level}
                                    onChange={(event) =>
                                        mappingForm.setData('expected_level', event.currentTarget.value)
                                    }
                                />
                            </label>
                            <label className="grid gap-1 text-sm">
                                Evidence requirements (comma-separated)
                                <input
                                    className={field}
                                    onChange={(event) =>
                                        mappingForm.setData(
                                            'evidence_requirements',
                                            event.currentTarget.value
                                                .split(',')
                                                .map((value) => value.trim())
                                                .filter(Boolean),
                                        )
                                    }
                                />
                            </label>
                            <label className="flex min-h-10 items-center gap-2 text-sm">
                                <input
                                    type="checkbox"
                                    checked={mappingForm.data.is_required}
                                    onChange={(event) =>
                                        mappingForm.setData('is_required', event.currentTarget.checked)
                                    }
                                />
                                Required program competency
                            </label>
                            <button
                                className={button}
                                disabled={mappingForm.processing || competencyOptions.length === 0}
                            >
                                Save program requirement
                            </button>
                            {Object.keys(mappingForm.errors).length > 0 && (
                                <p role="alert" className="text-danger text-sm sm:col-span-2">
                                    {Object.values(mappingForm.errors).join(' ')}
                                </p>
                            )}
                        </form>
                        {programRequirements.length > 0 && (
                            <ul className="grid gap-2">
                                {programRequirements.map((item) => (
                                    <li key={item.id} className="border-border rounded-lg border p-3 text-sm">
                                        <strong>
                                            {item.program?.code} · {item.program?.title}
                                        </strong>{' '}
                                        — {item.competency?.code} · {item.competency?.title}
                                        {item.expected_level ? ` · ${item.expected_level}` : ''}
                                        {item.is_required ? ' · Required' : ' · Optional'}
                                    </li>
                                ))}
                            </ul>
                        )}
                    </section>
                )}
                {assignedReviews.length > 0 && (
                    <section className={panel}>
                        <div>
                            <h2 className="text-lg font-semibold">Your assigned competency reviews</h2>
                            <p className="text-text-secondary text-sm">
                                Decisions are accepted only for the current user’s active assignment.
                            </p>
                        </div>
                        {assignedReviews.map((assignment) => (
                            <AssignedCompetencyReview
                                key={assignment.review_id}
                                assignment={assignment}
                                base={base}
                            />
                        ))}
                    </section>
                )}
                {page.props.flash?.status && (
                    <p role="status" className="text-success">
                        {page.props.flash.status}
                    </p>
                )}
            </main>
        </WorkspaceLayout>
    );
}

function AssignedCompetencyReview({ assignment, base }: { assignment: ReviewAssignment; base: string }) {
    const form = useForm({
        decision: 'approve',
        review_note: '',
        checklist_responses: {} as Record<
            string,
            { complete: boolean; note: string; evidence_reference: string }
        >,
    });
    return (
        <form
            className="border-border grid gap-3 rounded-xl border p-4"
            onSubmit={(event: FormEvent) => {
                event.preventDefault();
                form.post(`${base}/competency-frameworks/${assignment.framework_id}/decision`);
            }}
        >
            <div>
                <h3 className="font-semibold">
                    {assignment.code} · {assignment.title}
                </h3>
                <p className="text-text-muted text-xs">Review assignment #{assignment.review_id}</p>
            </div>
            {assignment.checklist_snapshot.map((item) => (
                <fieldset key={item.id} className="border-border grid gap-2 rounded-lg border p-3">
                    <legend className="px-1 text-sm font-semibold">{item.category}</legend>
                    <p className="text-sm">
                        {item.prompt}
                        {item.is_required && <span aria-label="required"> *</span>}
                    </p>
                    <label className="flex min-h-9 items-center gap-2 text-sm">
                        <input
                            type="checkbox"
                            checked={form.data.checklist_responses[String(item.id)]?.complete ?? false}
                            onChange={(event) =>
                                form.setData('checklist_responses', {
                                    ...form.data.checklist_responses,
                                    [String(item.id)]: {
                                        complete: event.currentTarget.checked,
                                        note: form.data.checklist_responses[String(item.id)]?.note ?? '',
                                        evidence_reference:
                                            form.data.checklist_responses[String(item.id)]
                                                ?.evidence_reference ?? '',
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
                                required={form.data.checklist_responses[String(item.id)]?.complete ?? false}
                                className={`${field} py-2`}
                                value={form.data.checklist_responses[String(item.id)]?.note ?? ''}
                                onChange={(event) =>
                                    form.setData('checklist_responses', {
                                        ...form.data.checklist_responses,
                                        [String(item.id)]: {
                                            complete:
                                                form.data.checklist_responses[String(item.id)]?.complete ??
                                                false,
                                            note: event.currentTarget.value,
                                            evidence_reference:
                                                form.data.checklist_responses[String(item.id)]
                                                    ?.evidence_reference ?? '',
                                        },
                                    })
                                }
                            />
                        </label>
                    )}
                    <label className="grid gap-1 text-sm">
                        Evidence reference (optional)
                        <input
                            className={field}
                            maxLength={500}
                            value={form.data.checklist_responses[String(item.id)]?.evidence_reference ?? ''}
                            onChange={(event) =>
                                form.setData('checklist_responses', {
                                    ...form.data.checklist_responses,
                                    [String(item.id)]: {
                                        complete:
                                            form.data.checklist_responses[String(item.id)]?.complete ?? false,
                                        note: form.data.checklist_responses[String(item.id)]?.note ?? '',
                                        evidence_reference: event.currentTarget.value,
                                    },
                                })
                            }
                        />
                    </label>
                </fieldset>
            ))}
            <label className="grid gap-1 text-sm">
                Decision
                <select
                    className={field}
                    value={form.data.decision}
                    onChange={(event) => form.setData('decision', event.currentTarget.value)}
                >
                    <option value="approve">Approve</option>
                    <option value="request_changes">Request changes</option>
                    <option value="reject">Reject</option>
                </select>
            </label>
            <label className="grid gap-1 text-sm">
                Review note
                <textarea
                    className={`${field} py-2`}
                    value={form.data.review_note}
                    onChange={(event) => form.setData('review_note', event.currentTarget.value)}
                />
            </label>
            <button className={button} disabled={form.processing}>
                Record decision
            </button>
            {Object.keys(form.errors).length > 0 && (
                <p role="alert" className="text-danger text-sm">
                    {Object.values(form.errors).join(' ')}
                </p>
            )}
        </form>
    );
}
