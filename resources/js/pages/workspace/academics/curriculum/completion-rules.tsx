import { router, useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';
import { useEffect } from 'react';

type CurriculumNode = {
    id: number;
    node_type: string;
    is_required: boolean;
    estimated_minutes: number | null;
    workload_unit: string;
    academic_period_id?: number | null;
    course_version?: { total_credits: number | string; contact_hours: number | string | null } | null;
    competencies: { competency: { id: number } | null }[];
};

type Rule = {
    id: number;
    code: string;
    title: string;
    rule_type: string;
    target_value: string | number | null;
    unit: string | null;
    academic_period_id: number | null;
    is_required: boolean;
    description: string | null;
    academic_period?: { id: number; code: string; name: string } | null;
};

type Props = {
    rules: Rule[];
    nodes: CurriculumNode[];
    periods: { id: number; name: string; code: string }[];
    versionId: number;
    versionLock: number;
    base: string;
    canManage: boolean;
};

const ruleTypes = [
    ['minimum_credits', 'Minimum required credits'],
    ['minimum_contact_hours', 'Minimum contact hours'],
    ['minimum_workload', 'Minimum planned workload'],
    ['required_courses', 'Required course count'],
    ['required_units', 'Required unit count'],
    ['required_competencies', 'Required competency coverage'],
    ['completion_milestone', 'Manual completion milestone'],
];
const unitOptions = [
    'credits',
    'hours',
    'minutes',
    'sessions',
    'weeks',
    'competency',
    'courses',
    'units',
    'competencies',
];
const fieldClass =
    'border-border bg-surface text-text-primary min-h-11 w-full rounded-lg border px-3 text-sm';

function planMeasure(rule: Rule, nodes: CurriculumNode[]): { value: number | null; label: string } {
    if (rule.rule_type === 'completion_milestone')
        return { value: null, label: 'Delivery tracking is outside curriculum planning.' };
    const scoped = nodes.filter(
        (node) => rule.academic_period_id === null || node.academic_period_id === rule.academic_period_id,
    );
    const required = scoped.filter((node) => node.is_required);
    if (rule.rule_type === 'minimum_credits') {
        return {
            value: required
                .filter((node) => node.node_type === 'course')
                .reduce((sum, node) => sum + Number(node.course_version?.total_credits ?? 0), 0),
            label: 'required catalog credits planned',
        };
    }
    if (rule.rule_type === 'minimum_contact_hours') {
        return {
            value: required
                .filter((node) => node.node_type === 'course')
                .reduce((sum, node) => sum + Number(node.course_version?.contact_hours ?? 0), 0),
            label: 'required course contact hours planned',
        };
    }
    if (rule.rule_type === 'required_courses') {
        return {
            value: required.filter((node) => node.node_type === 'course').length,
            label: 'required course items planned',
        };
    }
    if (rule.rule_type === 'required_units') {
        return {
            value: required.filter((node) => node.node_type === 'unit').length,
            label: 'required curriculum units planned',
        };
    }
    if (rule.rule_type === 'required_competencies') {
        const competencies = new Set(
            required.flatMap((node) =>
                node.competencies
                    .map((mapping) => mapping.competency?.id)
                    .filter((id): id is number => id !== undefined),
            ),
        );
        return { value: competencies.size, label: 'required competencies mapped' };
    }
    const workload = required
        .filter((node) => node.workload_unit === rule.unit)
        .reduce((sum, node) => sum + Number(node.estimated_minutes ?? 0), 0);
    return { value: workload, label: `required ${rule.unit ?? 'workload'} units planned` };
}

function CompletionRuleCard({
    rule,
    nodes,
    versionLock,
    base,
    versionId,
    periods,
    canManage,
}: Props & { rule: Rule }) {
    const form = useForm({
        version_lock: versionLock,
        code: rule.code,
        title: rule.title,
        rule_type: rule.rule_type,
        target_value: rule.target_value === null ? '' : String(rule.target_value),
        unit: rule.unit ?? '',
        academic_period_id: rule.academic_period_id === null ? '' : String(rule.academic_period_id),
        is_required: rule.is_required,
        description: rule.description ?? '',
    });
    const setFormData = form.setData;
    useEffect(() => setFormData('version_lock', versionLock), [versionLock, setFormData]);
    const measured = planMeasure(rule, nodes);
    const target = rule.target_value === null ? null : Number(rule.target_value);
    const met = target !== null && measured.value !== null && measured.value >= target;

    return (
        <li className="border-border grid gap-3 rounded-xl border p-4">
            <div className="flex flex-wrap items-start justify-between gap-3">
                <div>
                    <h3 className="font-semibold">{rule.title}</h3>
                    <p className="text-text-muted mt-1 text-xs">
                        {rule.code} ·{' '}
                        {rule.academic_period
                            ? `${rule.academic_period.code} · ${rule.academic_period.name}`
                            : 'All periods'}{' '}
                        · {rule.is_required ? 'Required' : 'Optional'}
                    </p>
                    {measured.value === null ? (
                        <p className="text-text-secondary mt-2 text-sm">
                            Manual milestone · {measured.label}
                        </p>
                    ) : (
                        <p className="mt-2 text-sm">
                            <strong>
                                {measured.value.toFixed(rule.unit === 'credits' ? 3 : 1)} {rule.unit}
                            </strong>{' '}
                            {measured.label}
                            {rule.is_required && target !== null && (
                                <span
                                    className={
                                        met
                                            ? 'text-success ml-2 font-medium'
                                            : 'text-warning ml-2 font-medium'
                                    }
                                >
                                    {met ? 'Plan meets target' : `Plan gap · target ${target} ${rule.unit}`}
                                </span>
                            )}
                        </p>
                    )}
                    {rule.description && (
                        <p className="text-text-secondary mt-2 text-sm">{rule.description}</p>
                    )}
                </div>
                {canManage && (
                    <button
                        type="button"
                        className="border-border min-h-10 rounded-lg border px-3 text-sm font-semibold"
                        onClick={() =>
                            router.delete(`${base}/versions/${versionId}/completion-rules/${rule.id}`, {
                                data: { version_lock: versionLock },
                            })
                        }
                    >
                        Remove rule
                    </button>
                )}
            </div>
            {canManage && (
                <form
                    className="grid gap-3 border-t pt-3 sm:grid-cols-2 xl:grid-cols-4"
                    onSubmit={(event: FormEvent) => {
                        event.preventDefault();
                        form.transform((data) => ({
                            ...data,
                            target_value: data.rule_type === 'completion_milestone' ? '' : data.target_value,
                        }));
                        form.put(`${base}/versions/${versionId}/completion-rules/${rule.id}`);
                    }}
                >
                    <label className="grid gap-1 text-sm font-medium">
                        Code
                        <input
                            className={fieldClass}
                            value={form.data.code}
                            onChange={(event) => form.setData('code', event.currentTarget.value)}
                            required
                            maxLength={48}
                        />
                    </label>
                    <label className="grid gap-1 text-sm font-medium">
                        Rule name
                        <input
                            className={fieldClass}
                            value={form.data.title}
                            onChange={(event) => form.setData('title', event.currentTarget.value)}
                            required
                            maxLength={160}
                        />
                    </label>
                    <label className="grid gap-1 text-sm font-medium">
                        Rule type
                        <select
                            className={fieldClass}
                            value={form.data.rule_type}
                            onChange={(event) => {
                                const ruleType = event.currentTarget.value;
                                const unit =
                                    ruleType === 'minimum_credits'
                                        ? 'credits'
                                        : ruleType === 'minimum_contact_hours'
                                          ? 'hours'
                                          : ruleType === 'required_courses'
                                            ? 'courses'
                                            : ruleType === 'required_units'
                                              ? 'units'
                                              : ruleType === 'required_competencies'
                                                ? 'competencies'
                                                : ruleType === 'completion_milestone'
                                                  ? ''
                                                  : form.data.unit;
                                form.setData((data) => ({ ...data, rule_type: ruleType, unit }));
                            }}
                        >
                            {ruleTypes.map(([value, label]) => (
                                <option key={value} value={value}>
                                    {label}
                                </option>
                            ))}
                        </select>
                    </label>
                    <label className="grid gap-1 text-sm font-medium">
                        Target value
                        <input
                            className={fieldClass}
                            type="number"
                            min="0"
                            step="0.001"
                            value={form.data.target_value}
                            onChange={(event) => form.setData('target_value', event.currentTarget.value)}
                            disabled={form.data.rule_type === 'completion_milestone'}
                            required={form.data.rule_type !== 'completion_milestone'}
                        />
                    </label>
                    <label className="grid gap-1 text-sm font-medium">
                        Unit
                        <select
                            className={fieldClass}
                            value={form.data.unit}
                            onChange={(event) => form.setData('unit', event.currentTarget.value)}
                            disabled={[
                                'minimum_credits',
                                'minimum_contact_hours',
                                'required_courses',
                                'required_units',
                                'required_competencies',
                                'completion_milestone',
                            ].includes(form.data.rule_type)}
                        >
                            {unitOptions.map((unit) => (
                                <option key={unit} value={unit}>
                                    {unit}
                                </option>
                            ))}
                        </select>
                    </label>
                    <label className="grid gap-1 text-sm font-medium">
                        Academic period
                        <select
                            className={fieldClass}
                            value={form.data.academic_period_id}
                            onChange={(event) =>
                                form.setData('academic_period_id', event.currentTarget.value)
                            }
                        >
                            <option value="">All periods</option>
                            {periods.map((period) => (
                                <option key={period.id} value={period.id}>
                                    {period.code} · {period.name}
                                </option>
                            ))}
                        </select>
                    </label>
                    <label className="flex min-h-11 items-center gap-2 text-sm font-medium">
                        <input
                            type="checkbox"
                            checked={form.data.is_required}
                            onChange={(event) => form.setData('is_required', event.currentTarget.checked)}
                        />
                        Required rule
                    </label>
                    <label className="grid gap-1 text-sm font-medium sm:col-span-2">
                        Description
                        <textarea
                            className={`${fieldClass} min-h-20 py-2`}
                            value={form.data.description}
                            onChange={(event) => form.setData('description', event.currentTarget.value)}
                            maxLength={2000}
                        />
                    </label>
                    <button
                        type="submit"
                        className="bg-brand-action min-h-11 rounded-lg px-4 text-sm font-semibold text-white"
                        disabled={form.processing}
                    >
                        Save rule
                    </button>
                    {Object.values(form.errors).length > 0 && (
                        <p role="alert" className="text-danger sm:col-span-2">
                            {Object.values(form.errors).join(' ')}
                        </p>
                    )}
                </form>
            )}
        </li>
    );
}

export function CompletionRules({ rules, nodes, periods, versionId, versionLock, base, canManage }: Props) {
    const form = useForm({
        version_lock: versionLock,
        code: '',
        title: '',
        rule_type: 'minimum_credits',
        target_value: '',
        unit: 'credits',
        academic_period_id: '',
        is_required: true,
        description: '',
    });
    const setFormData = form.setData;
    useEffect(() => setFormData('version_lock', versionLock), [versionLock, setFormData]);

    return (
        <section
            aria-labelledby="completion-rules-heading"
            className="border-border bg-surface rounded-2xl border p-5 shadow-sm"
        >
            <h2 id="completion-rules-heading" className="text-lg font-semibold">
                Program completion requirements
            </h2>
            <p className="text-text-secondary mt-1 text-sm">
                Define versioned program targets for credits, contact hours, workload, required courses or
                units, competency coverage, and institution-defined milestones. These measures validate
                curriculum design only; they do not represent learner completion.
            </p>
            {rules.length === 0 ? (
                <p className="text-text-secondary mt-4 rounded-xl border border-dashed p-4 text-sm">
                    No completion requirements are configured for this curriculum version.
                </p>
            ) : (
                <ul className="mt-4 grid gap-3">
                    {rules.map((rule) => (
                        <CompletionRuleCard
                            key={rule.id}
                            {...{ rules, nodes, periods, versionId, versionLock, base, canManage }}
                            rule={rule}
                        />
                    ))}
                </ul>
            )}
            {canManage && (
                <form
                    className="mt-5 grid gap-3 border-t pt-5 sm:grid-cols-2 xl:grid-cols-4"
                    onSubmit={(event: FormEvent) => {
                        event.preventDefault();
                        form.transform((data) => ({
                            ...data,
                            target_value: data.rule_type === 'completion_milestone' ? '' : data.target_value,
                        }));
                        form.post(`${base}/versions/${versionId}/completion-rules`);
                    }}
                >
                    <h3 className="font-semibold sm:col-span-2 xl:col-span-4">Add a requirement</h3>
                    <input type="hidden" value={form.data.version_lock} readOnly />
                    <label className="grid gap-1 text-sm font-medium">
                        Code
                        <input
                            className={fieldClass}
                            value={form.data.code}
                            onChange={(event) => form.setData('code', event.currentTarget.value)}
                            required
                            maxLength={48}
                        />
                    </label>
                    <label className="grid gap-1 text-sm font-medium">
                        Rule name
                        <input
                            className={fieldClass}
                            value={form.data.title}
                            onChange={(event) => form.setData('title', event.currentTarget.value)}
                            required
                            maxLength={160}
                        />
                    </label>
                    <label className="grid gap-1 text-sm font-medium">
                        Rule type
                        <select
                            className={fieldClass}
                            value={form.data.rule_type}
                            onChange={(event) => {
                                const ruleType = event.currentTarget.value;
                                const unit =
                                    ruleType === 'minimum_credits'
                                        ? 'credits'
                                        : ruleType === 'minimum_contact_hours'
                                          ? 'hours'
                                          : ruleType === 'required_courses'
                                            ? 'courses'
                                            : ruleType === 'required_units'
                                              ? 'units'
                                              : ruleType === 'required_competencies'
                                                ? 'competencies'
                                                : ruleType === 'completion_milestone'
                                                  ? ''
                                                  : form.data.unit;
                                form.setData((data) => ({ ...data, rule_type: ruleType, unit }));
                            }}
                        >
                            {ruleTypes.map(([value, label]) => (
                                <option key={value} value={value}>
                                    {label}
                                </option>
                            ))}
                        </select>
                    </label>
                    <label className="grid gap-1 text-sm font-medium">
                        Target value
                        <input
                            className={fieldClass}
                            type="number"
                            min="0"
                            step="0.001"
                            value={form.data.target_value}
                            onChange={(event) => form.setData('target_value', event.currentTarget.value)}
                            disabled={form.data.rule_type === 'completion_milestone'}
                            required={form.data.rule_type !== 'completion_milestone'}
                        />
                    </label>
                    <label className="grid gap-1 text-sm font-medium">
                        Unit
                        <select
                            className={fieldClass}
                            value={form.data.unit}
                            onChange={(event) => form.setData('unit', event.currentTarget.value)}
                            disabled={[
                                'minimum_credits',
                                'minimum_contact_hours',
                                'required_courses',
                                'required_units',
                                'required_competencies',
                                'completion_milestone',
                            ].includes(form.data.rule_type)}
                        >
                            {unitOptions.map((unit) => (
                                <option key={unit} value={unit}>
                                    {unit}
                                </option>
                            ))}
                        </select>
                    </label>
                    <label className="grid gap-1 text-sm font-medium">
                        Academic period
                        <select
                            className={fieldClass}
                            value={form.data.academic_period_id}
                            onChange={(event) =>
                                form.setData('academic_period_id', event.currentTarget.value)
                            }
                        >
                            <option value="">All periods</option>
                            {periods.map((period) => (
                                <option key={period.id} value={period.id}>
                                    {period.code} · {period.name}
                                </option>
                            ))}
                        </select>
                    </label>
                    <label className="flex min-h-11 items-center gap-2 text-sm font-medium">
                        <input
                            type="checkbox"
                            checked={form.data.is_required}
                            onChange={(event) => form.setData('is_required', event.currentTarget.checked)}
                        />
                        Required rule
                    </label>
                    <label className="grid gap-1 text-sm font-medium sm:col-span-2">
                        Description
                        <textarea
                            className={`${fieldClass} min-h-20 py-2`}
                            value={form.data.description}
                            onChange={(event) => form.setData('description', event.currentTarget.value)}
                            maxLength={2000}
                        />
                    </label>
                    <button
                        type="submit"
                        className="bg-brand-action min-h-11 rounded-lg px-4 text-sm font-semibold text-white"
                        disabled={form.processing}
                    >
                        Add requirement
                    </button>
                    {Object.values(form.errors).length > 0 && (
                        <p role="alert" className="text-danger sm:col-span-2">
                            {Object.values(form.errors).join(' ')}
                        </p>
                    )}
                </form>
            )}
        </section>
    );
}
