import { router, useForm, usePage } from '@inertiajs/react';
import type { FormEvent } from 'react';
import { FormErrors } from '@/components/ui/form-errors';
import { PageHeader } from '@/components/ui/page-header';
import { WorkspaceLayout } from '@/layouts/workspace-layout';
import { academicPlural, academicTerm, type AcademicWorkspaceConfig } from '@/types/academic';

type Page<T> = { data: T[]; links: { url: string | null; label: string; active: boolean }[] };
type Subject = {
    id: number;
    name: string;
    code: string;
    category: string;
    status: string;
    level: { name: string } | null;
    academic_unit: { name: string } | null;
};
type Version = {
    id: number;
    version_number: number;
    title: string;
    status: string;
    total_credits: string;
    is_current: boolean;
    credit_bearing: boolean;
};
type Course = {
    id: number;
    title: string;
    code: string;
    status: string;
    subject: { name: string } | null;
    level: { name: string } | null;
    versions: Version[];
};
type Policy = {
    id: number;
    name: string;
    status: string;
    academic_program_id: number | null;
    allowed_increment: string | null;
    maximum_credits: string | null;
    separate_theory_lab: boolean;
};
type Props = {
    tenant: { slug: string };
    subjects: Page<Subject>;
    courses: Page<Course>;
    levels: { id: number; name: string; code: string }[];
    units: { id: number; name: string; code: string }[];
    programs: { id: number; title: string }[];
    creditPolicies: Policy[];
    outcomes: { id: number; code: string; name: string; competency_category: string | null }[];
    configuration: { enabled_features: string[] | Record<string, boolean> } | null;
};

function CourseVersions({
    course,
    slug,
    policies,
    outcomes,
    creditsEnabled,
}: {
    course: Course;
    slug: string;
    policies: Policy[];
    outcomes: Props['outcomes'];
    creditsEnabled: boolean;
}) {
    const form = useForm({
        title: course.title,
        credit_bearing: false,
        theory_credits: '0',
        laboratory_credits: '0',
        credit_policy_id: '',
        contact_hours: '',
        duration_amount: '',
        duration_unit: '',
        delivery_modes: ['on_campus'] as string[],
        description: '',
        effective_from: '',
        effective_until: '',
    });
    const attachForm = useForm({ learning_outcome_id: '' });
    return (
        <details className="border-border mt-3 border-t pt-3">
            <summary className="focus-ring cursor-pointer text-sm font-semibold">
                {course.status === 'active'
                    ? 'Manage versions and learning outcomes'
                    : 'Read-only version history'}
            </summary>
            <div className="mt-3 grid gap-2">
                {course.versions.map((version) => (
                    <div
                        key={version.id}
                        className="bg-canvas flex flex-wrap items-center justify-between gap-2 rounded-lg p-3 text-sm"
                    >
                        <span>
                            v{version.version_number} · {version.title} ·{' '}
                            {version.credit_bearing
                                ? `${version.total_credits} credits`
                                : 'Non-credit-bearing'}{' '}
                            · <span className="capitalize">{version.status}</span>
                            {version.is_current ? ' · Current' : ''}
                        </span>
                        {course.status === 'active' && version.status === 'draft' && (
                            <button
                                type="button"
                                onClick={() =>
                                    window.confirm('Publish this immutable course version?') &&
                                    router.post(`/t/${slug}/academics/course-versions/${version.id}/publish`)
                                }
                                className="text-brand-link font-semibold"
                            >
                                Publish
                            </button>
                        )}
                    </div>
                ))}
                {course.versions.length === 0 && (
                    <p className="text-text-secondary text-sm">No course version has been created.</p>
                )}
            </div>
            {course.status === 'active' ? (
                <form
                    className="mt-4 grid gap-3 sm:grid-cols-2 xl:grid-cols-4"
                    onSubmit={(event: FormEvent) => {
                        event.preventDefault();
                        form.post(`/t/${slug}/academics/courses/${course.id}/versions`, {
                            onSuccess: () =>
                                form.reset(
                                    'theory_credits',
                                    'laboratory_credits',
                                    'credit_policy_id',
                                    'contact_hours',
                                    'duration_amount',
                                    'duration_unit',
                                    'description',
                                    'effective_from',
                                    'effective_until',
                                ),
                        });
                    }}
                >
                    <label className="grid gap-1 text-sm sm:col-span-2">
                        Version title
                        <input
                            required
                            className="border-border bg-surface min-h-11 rounded-lg border px-3"
                            value={form.data.title}
                            onChange={(e) => form.setData('title', e.currentTarget.value)}
                        />
                    </label>
                    <label className="flex min-h-11 items-center gap-2 text-sm">
                        <input
                            type="checkbox"
                            disabled={!creditsEnabled}
                            checked={form.data.credit_bearing}
                            onChange={(e) => form.setData('credit_bearing', e.currentTarget.checked)}
                        />
                        Credit-bearing{!creditsEnabled ? ' (enable credits in academic settings)' : ''}
                    </label>
                    <label className="grid gap-1 text-sm">
                        Active credit policy
                        <select
                            disabled={!creditsEnabled || !form.data.credit_bearing}
                            className="border-border bg-surface min-h-11 rounded-lg border px-3 disabled:opacity-60"
                            value={form.data.credit_policy_id}
                            onChange={(e) => form.setData('credit_policy_id', e.currentTarget.value)}
                        >
                            <option value="">Choose policy</option>
                            {policies
                                .filter((policy) => policy.status === 'active')
                                .map((policy) => (
                                    <option key={policy.id} value={policy.id}>
                                        {policy.name}
                                        {policy.allowed_increment
                                            ? ` · ${policy.allowed_increment} increment`
                                            : ''}
                                    </option>
                                ))}
                        </select>
                    </label>
                    <label className="grid gap-1 text-sm">
                        Theory credits
                        <input
                            disabled={!creditsEnabled || !form.data.credit_bearing}
                            type="number"
                            min="0"
                            step="0.001"
                            className="border-border bg-surface min-h-11 rounded-lg border px-3 disabled:opacity-60"
                            value={form.data.theory_credits}
                            onChange={(e) => form.setData('theory_credits', e.currentTarget.value)}
                        />
                    </label>
                    <label className="grid gap-1 text-sm">
                        Laboratory credits
                        <input
                            disabled={!creditsEnabled || !form.data.credit_bearing}
                            type="number"
                            min="0"
                            step="0.001"
                            className="border-border bg-surface min-h-11 rounded-lg border px-3 disabled:opacity-60"
                            value={form.data.laboratory_credits}
                            onChange={(e) => form.setData('laboratory_credits', e.currentTarget.value)}
                        />
                    </label>
                    <label className="grid gap-1 text-sm">
                        Contact hours
                        <input
                            type="number"
                            min="0"
                            step="0.001"
                            className="border-border bg-surface min-h-11 rounded-lg border px-3"
                            value={form.data.contact_hours}
                            onChange={(e) => form.setData('contact_hours', e.currentTarget.value)}
                        />
                    </label>
                    <fieldset className="sm:col-span-2 xl:col-span-4">
                        <legend className="text-sm font-medium">Delivery modes</legend>
                        <div className="mt-2 flex flex-wrap gap-4">
                            {['on_campus', 'online', 'hybrid', 'workplace', 'self_paced'].map((mode) => (
                                <label
                                    key={mode}
                                    className="flex min-h-9 items-center gap-2 text-sm capitalize"
                                >
                                    <input
                                        type="checkbox"
                                        checked={form.data.delivery_modes.includes(mode)}
                                        onChange={(e) =>
                                            form.setData(
                                                'delivery_modes',
                                                e.currentTarget.checked
                                                    ? [...form.data.delivery_modes, mode]
                                                    : form.data.delivery_modes.filter(
                                                          (item) => item !== mode,
                                                      ),
                                            )
                                        }
                                    />
                                    {mode.replace('_', ' ')}
                                </label>
                            ))}
                        </div>
                    </fieldset>
                    <div className="sm:col-span-2 xl:col-span-4">
                        <button
                            disabled={form.processing}
                            className="border-border min-h-11 rounded-lg border px-4 text-sm font-semibold"
                        >
                            Create immutable draft version
                        </button>
                    </div>
                    {Object.keys(form.errors).length > 0 && (
                        <div className="sm:col-span-2 xl:col-span-4">
                            <FormErrors />
                        </div>
                    )}
                </form>
            ) : (
                <p className="text-text-secondary mt-4 text-sm">
                    This course is retired. Its versions remain available for historical reference.
                </p>
            )}
            {course.status === 'active' &&
                course.versions.some((version) => version.is_current) &&
                outcomes.length > 0 && (
                    <form
                        className="border-border mt-4 flex flex-wrap items-end gap-3 border-t pt-4"
                        onSubmit={(event) => {
                            event.preventDefault();
                            const current = course.versions.find((version) => version.is_current);
                            if (current)
                                attachForm.post(
                                    `/t/${slug}/academics/course-versions/${current.id}/outcomes`,
                                );
                        }}
                    >
                        <label className="grid min-w-64 gap-1 text-sm">
                            Link outcome to current version
                            <select
                                required
                                className="border-border bg-surface min-h-11 rounded-lg border px-3"
                                value={attachForm.data.learning_outcome_id}
                                onChange={(e) =>
                                    attachForm.setData('learning_outcome_id', e.currentTarget.value)
                                }
                            >
                                <option value="">Select outcome</option>
                                {outcomes.map((outcome) => (
                                    <option key={outcome.id} value={outcome.id}>
                                        {outcome.code} · {outcome.name}
                                    </option>
                                ))}
                            </select>
                        </label>
                        <button className="border-border min-h-11 rounded-lg border px-4 text-sm font-semibold">
                            Link outcome
                        </button>
                    </form>
                )}
        </details>
    );
}

export default function AcademicCatalogPage({
    tenant,
    subjects,
    courses,
    levels,
    units,
    programs,
    creditPolicies,
    outcomes,
}: Props) {
    const { academicConfig } = usePage<{ academicConfig: AcademicWorkspaceConfig | null }>().props;
    const terminology = academicConfig?.terminology ?? {};
    const subject = academicTerm(terminology, 'subject', 'Subject');
    const course = academicTerm(terminology, 'course', 'Course');
    const creditsEnabled = academicConfig?.enabledFeatures.credits ?? false;
    const catalogTitle = `${academicPlural(subject)} & ${academicPlural(course)}`;
    const root = `/t/${tenant.slug}/academics`;
    const subjectForm = useForm({
        code: '',
        name: '',
        category: 'general',
        academic_level_id: '',
        academic_unit_id: '',
        description: '',
    });
    const courseForm = useForm({
        code: '',
        title: '',
        academic_subject_id: '',
        academic_unit_id: '',
        academic_level_id: '',
        description: '',
    });
    const policyForm = useForm({
        policy_key: '',
        name: '',
        academic_program_id: '',
        credit_hours_enabled: true,
        separate_theory_lab: false,
        allowed_increment: '',
        maximum_credits: '',
        theory_contact_hours_per_credit: '',
        lab_contact_hours_per_credit: '',
        definition: '',
        effective_from: '',
        effective_until: '',
    });
    const outcomeForm = useForm({
        code: '',
        name: '',
        description: '',
        academic_level_id: '',
        competency_category: '',
        framework_reference: '',
        framework_version: '',
        source_organization: '',
    });
    return (
        <WorkspaceLayout area="tenant" title={catalogTitle}>
            <PageHeader
                title={catalogTitle}
                description="Manage reusable subject definitions, stable course identities, immutable versions, and institution credit policies."
            />
            <div className="mt-5 flex gap-3 text-sm">
                <a href={root} className="text-brand-link font-semibold">
                    Overview
                </a>
                <a href={`${root}/structure`} className="text-brand-link font-semibold">
                    Organization & programs
                </a>
                <a href={`${root}/calendar`} className="text-brand-link font-semibold">
                    Academic calendar
                </a>
            </div>
            <section className="border-border bg-surface mt-6 rounded-2xl border p-5 sm:p-7">
                <h2 className="text-lg font-semibold">{subject} catalog</h2>
                <form
                    className="mt-4 grid gap-3 sm:grid-cols-2 xl:grid-cols-4"
                    onSubmit={(event) => {
                        event.preventDefault();
                        subjectForm.post(`${root}/subjects`, { onSuccess: () => subjectForm.reset() });
                    }}
                >
                    <label className="grid gap-1 text-sm">
                        {subject} name
                        <input
                            required
                            className="border-border bg-surface min-h-11 rounded-lg border px-3"
                            value={subjectForm.data.name}
                            onChange={(e) => subjectForm.setData('name', e.currentTarget.value)}
                        />
                    </label>
                    <label className="grid gap-1 text-sm">
                        Code
                        <input
                            required
                            className="border-border bg-surface min-h-11 rounded-lg border px-3"
                            value={subjectForm.data.code}
                            onChange={(e) => subjectForm.setData('code', e.currentTarget.value)}
                        />
                    </label>
                    <label className="grid gap-1 text-sm">
                        Category
                        <input
                            required
                            className="border-border bg-surface min-h-11 rounded-lg border px-3"
                            value={subjectForm.data.category}
                            onChange={(e) => subjectForm.setData('category', e.currentTarget.value)}
                        />
                    </label>
                    <label className="grid gap-1 text-sm">
                        Level
                        <select
                            className="border-border bg-surface min-h-11 rounded-lg border px-3"
                            value={subjectForm.data.academic_level_id}
                            onChange={(e) => subjectForm.setData('academic_level_id', e.currentTarget.value)}
                        >
                            <option value="">Any level</option>
                            {levels.map((level) => (
                                <option key={level.id} value={level.id}>
                                    {level.name}
                                </option>
                            ))}
                        </select>
                    </label>
                    <label className="grid gap-1 text-sm">
                        Academic unit
                        <select
                            className="border-border bg-surface min-h-11 rounded-lg border px-3"
                            value={subjectForm.data.academic_unit_id}
                            onChange={(e) => subjectForm.setData('academic_unit_id', e.currentTarget.value)}
                        >
                            <option value="">Unassigned</option>
                            {units.map((unit) => (
                                <option key={unit.id} value={unit.id}>
                                    {unit.name}
                                </option>
                            ))}
                        </select>
                    </label>
                    <div className="flex items-end">
                        <button
                            disabled={subjectForm.processing}
                            className="bg-brand-action focus-ring min-h-11 rounded-lg px-4 text-sm font-semibold text-white"
                        >
                            Add subject
                        </button>
                    </div>
                    {Object.keys(subjectForm.errors).length > 0 && (
                        <div className="sm:col-span-2 xl:col-span-4">
                            <FormErrors />
                        </div>
                    )}
                </form>
                <div className="mt-5 grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
                    {subjects.data.map((subject) => (
                        <article key={subject.id} className="border-border rounded-xl border p-4">
                            <h3 className="font-semibold">{subject.name}</h3>
                            <p className="text-text-secondary mt-1 text-sm">
                                {subject.code} · {subject.category}
                            </p>
                            <p className="text-text-muted mt-1 text-xs capitalize">{subject.status}</p>
                            <p className="text-text-muted mt-2 text-xs">
                                {subject.level?.name ?? 'All levels'} ·{' '}
                                {subject.academic_unit?.name ?? 'No unit'}
                            </p>
                            {subject.status === 'active' ? (
                                <button
                                    type="button"
                                    className="border-border focus-ring mt-3 min-h-10 rounded-lg border px-3 text-sm font-semibold"
                                    onClick={() =>
                                        window.confirm(`Retire subject ${subject.name}?`) &&
                                        router.post(`${root}/subjects/${subject.id}/retire`)
                                    }
                                >
                                    Retire subject
                                </button>
                            ) : null}
                        </article>
                    ))}
                </div>
                {subjects.data.length === 0 && (
                    <p className="text-text-secondary mt-4 text-sm">No subjects configured.</p>
                )}
            </section>
            <section className="border-border bg-surface mt-6 rounded-2xl border p-5 sm:p-7">
                <h2 className="text-lg font-semibold">{course} definitions</h2>
                <p className="text-text-secondary mt-1 text-sm">
                    A course identity remains stable; changes to delivery, content, and credit values are
                    recorded as numbered versions.
                </p>
                <form
                    className="mt-4 grid gap-3 sm:grid-cols-2 xl:grid-cols-4"
                    onSubmit={(event) => {
                        event.preventDefault();
                        courseForm.post(`${root}/courses`, { onSuccess: () => courseForm.reset() });
                    }}
                >
                    <label className="grid gap-1 text-sm">
                        {course} title
                        <input
                            required
                            className="border-border bg-surface min-h-11 rounded-lg border px-3"
                            value={courseForm.data.title}
                            onChange={(e) => courseForm.setData('title', e.currentTarget.value)}
                        />
                    </label>
                    <label className="grid gap-1 text-sm">
                        Course code
                        <input
                            required
                            className="border-border bg-surface min-h-11 rounded-lg border px-3"
                            value={courseForm.data.code}
                            onChange={(e) => courseForm.setData('code', e.currentTarget.value)}
                        />
                    </label>
                    <label className="grid gap-1 text-sm">
                        Subject
                        <select
                            className="border-border bg-surface min-h-11 rounded-lg border px-3"
                            value={courseForm.data.academic_subject_id}
                            onChange={(e) => courseForm.setData('academic_subject_id', e.currentTarget.value)}
                        >
                            <option value="">None</option>
                            {subjects.data.map((subject) => (
                                <option key={subject.id} value={subject.id}>
                                    {subject.name}
                                </option>
                            ))}
                        </select>
                    </label>
                    <label className="grid gap-1 text-sm">
                        Academic level
                        <select
                            className="border-border bg-surface min-h-11 rounded-lg border px-3"
                            value={courseForm.data.academic_level_id}
                            onChange={(e) => courseForm.setData('academic_level_id', e.currentTarget.value)}
                        >
                            <option value="">Any level</option>
                            {levels.map((level) => (
                                <option key={level.id} value={level.id}>
                                    {level.name}
                                </option>
                            ))}
                        </select>
                    </label>
                    <label className="grid gap-1 text-sm">
                        Academic unit
                        <select
                            className="border-border bg-surface min-h-11 rounded-lg border px-3"
                            value={courseForm.data.academic_unit_id}
                            onChange={(e) => courseForm.setData('academic_unit_id', e.currentTarget.value)}
                        >
                            <option value="">Unassigned</option>
                            {units.map((unit) => (
                                <option key={unit.id} value={unit.id}>
                                    {unit.name}
                                </option>
                            ))}
                        </select>
                    </label>
                    <div className="flex items-end">
                        <button
                            disabled={courseForm.processing}
                            className="bg-brand-action focus-ring min-h-11 rounded-lg px-4 text-sm font-semibold text-white"
                        >
                            Add course
                        </button>
                    </div>
                    {Object.keys(courseForm.errors).length > 0 && (
                        <div className="sm:col-span-2 xl:col-span-4">
                            <FormErrors />
                        </div>
                    )}
                </form>
                <div className="mt-5 grid gap-4">
                    {courses.data.map((course) => (
                        <article key={course.id} className="border-border rounded-xl border p-4">
                            <h3 className="font-semibold">
                                {course.title}
                                <span className="text-text-muted ml-2 text-sm">{course.code}</span>
                            </h3>
                            <p className="text-text-secondary mt-1 text-sm">
                                {course.subject?.name ?? 'No subject'} · {course.level?.name ?? 'All levels'}
                            </p>
                            <p className="text-text-muted mt-1 text-xs capitalize">{course.status}</p>
                            <div className="mt-3 flex flex-wrap items-center gap-3">
                                <CourseVersions
                                    course={course}
                                    slug={tenant.slug}
                                    policies={creditPolicies}
                                    outcomes={outcomes}
                                    creditsEnabled={creditsEnabled}
                                />
                                {course.status === 'active' ? (
                                    <button
                                        type="button"
                                        className="border-border focus-ring min-h-10 rounded-lg border px-3 text-sm font-semibold"
                                        onClick={() =>
                                            window.confirm(`Retire course ${course.title}?`) &&
                                            router.post(`${root}/courses/${course.id}/retire`)
                                        }
                                    >
                                        Retire course
                                    </button>
                                ) : null}
                            </div>
                        </article>
                    ))}
                </div>
                {courses.data.length === 0 && (
                    <p className="text-text-secondary mt-4 text-sm">No course definitions configured.</p>
                )}
            </section>
            {creditsEnabled ? (
                <section className="border-border bg-surface mt-6 rounded-2xl border p-5 sm:p-7">
                    <h2 className="text-lg font-semibold">Credit-hour policies</h2>
                    <p className="text-text-secondary mt-1 text-sm">
                        Policies are versioned by key. Only approved active policies can be selected for new
                        credit-bearing course versions.
                    </p>
                    <form
                        className="mt-4 grid gap-3 sm:grid-cols-2 xl:grid-cols-4"
                        onSubmit={(event) => {
                            event.preventDefault();
                            policyForm.post(`${root}/credit-policies`, {
                                onSuccess: () => policyForm.reset(),
                            });
                        }}
                    >
                        <label className="grid gap-1 text-sm">
                            Policy key
                            <input
                                required
                                className="border-border bg-surface min-h-11 rounded-lg border px-3"
                                value={policyForm.data.policy_key}
                                onChange={(e) => policyForm.setData('policy_key', e.currentTarget.value)}
                            />
                        </label>
                        <label className="grid gap-1 text-sm">
                            Name
                            <input
                                required
                                className="border-border bg-surface min-h-11 rounded-lg border px-3"
                                value={policyForm.data.name}
                                onChange={(e) => policyForm.setData('name', e.currentTarget.value)}
                            />
                        </label>
                        <label className="grid gap-1 text-sm">
                            Program scope
                            <select
                                className="border-border bg-surface min-h-11 rounded-lg border px-3"
                                value={policyForm.data.academic_program_id}
                                onChange={(e) =>
                                    policyForm.setData('academic_program_id', e.currentTarget.value)
                                }
                            >
                                <option value="">Institution-wide</option>
                                {programs.map((program) => (
                                    <option key={program.id} value={program.id}>
                                        {program.title}
                                    </option>
                                ))}
                            </select>
                        </label>
                        <label className="grid gap-1 text-sm">
                            Allowed increment
                            <input
                                type="number"
                                min="0.001"
                                step="0.001"
                                className="border-border bg-surface min-h-11 rounded-lg border px-3"
                                value={policyForm.data.allowed_increment}
                                onChange={(e) =>
                                    policyForm.setData('allowed_increment', e.currentTarget.value)
                                }
                            />
                        </label>
                        <label className="grid gap-1 text-sm">
                            Maximum credits
                            <input
                                type="number"
                                min="0.001"
                                step="0.001"
                                className="border-border bg-surface min-h-11 rounded-lg border px-3"
                                value={policyForm.data.maximum_credits}
                                onChange={(e) => policyForm.setData('maximum_credits', e.currentTarget.value)}
                            />
                        </label>
                        <label className="grid gap-1 text-sm">
                            Theory contact hours / credit
                            <input
                                type="number"
                                min="0.001"
                                step="0.001"
                                className="border-border bg-surface min-h-11 rounded-lg border px-3"
                                value={policyForm.data.theory_contact_hours_per_credit}
                                onChange={(e) =>
                                    policyForm.setData(
                                        'theory_contact_hours_per_credit',
                                        e.currentTarget.value,
                                    )
                                }
                            />
                        </label>
                        <label className="grid gap-1 text-sm">
                            Laboratory contact hours / credit
                            <input
                                type="number"
                                min="0.001"
                                step="0.001"
                                className="border-border bg-surface min-h-11 rounded-lg border px-3"
                                value={policyForm.data.lab_contact_hours_per_credit}
                                onChange={(e) =>
                                    policyForm.setData('lab_contact_hours_per_credit', e.currentTarget.value)
                                }
                            />
                        </label>
                        <label className="flex min-h-11 items-center gap-2 text-sm">
                            <input
                                type="checkbox"
                                checked={policyForm.data.credit_hours_enabled}
                                onChange={(e) =>
                                    policyForm.setData('credit_hours_enabled', e.currentTarget.checked)
                                }
                            />
                            Credit hours enabled
                        </label>
                        <label className="flex min-h-11 items-center gap-2 text-sm">
                            <input
                                type="checkbox"
                                checked={policyForm.data.separate_theory_lab}
                                onChange={(e) =>
                                    policyForm.setData('separate_theory_lab', e.currentTarget.checked)
                                }
                            />
                            Separate theory / laboratory credits
                        </label>
                        <div className="sm:col-span-2 xl:col-span-4">
                            <button
                                disabled={policyForm.processing}
                                className="bg-brand-action focus-ring min-h-11 rounded-lg px-4 text-sm font-semibold text-white"
                            >
                                Create draft policy version
                            </button>
                        </div>
                        {Object.keys(policyForm.errors).length > 0 && (
                            <div className="sm:col-span-2 xl:col-span-4">
                                <FormErrors />
                            </div>
                        )}
                    </form>
                    <ul className="mt-5 grid gap-3 sm:grid-cols-2">
                        {creditPolicies.map((policy) => (
                            <li
                                key={policy.id}
                                className="border-border flex flex-wrap items-center justify-between gap-3 rounded-xl border p-4"
                            >
                                <span>
                                    <strong>{policy.name}</strong>
                                    <span className="text-text-muted ml-2 text-sm">
                                        {policy.allowed_increment
                                            ? `${policy.allowed_increment} increment`
                                            : 'No fixed increment'}{' '}
                                        ·{' '}
                                        {policy.maximum_credits
                                            ? `maximum ${policy.maximum_credits}`
                                            : 'No maximum'}
                                    </span>
                                </span>
                                <form method="post" action={`${root}/credit-policies/${policy.id}/activate`}>
                                    <input
                                        type="hidden"
                                        name="_token"
                                        value={
                                            document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')
                                                ?.content ?? ''
                                        }
                                    />
                                    <button className="text-brand-link text-sm font-semibold">
                                        Activate
                                    </button>
                                </form>
                            </li>
                        ))}
                    </ul>
                </section>
            ) : null}
            <section className="border-border bg-surface mt-6 rounded-2xl border p-5 sm:p-7">
                <h2 className="text-lg font-semibold">Learning outcomes</h2>
                <p className="text-text-secondary mt-1 text-sm">
                    Maintain versioned learning-outcome references, including source framework metadata.
                    Linking outcomes is a reference operation, not accreditation.
                </p>
                <form
                    className="mt-4 grid gap-3 sm:grid-cols-2 xl:grid-cols-4"
                    onSubmit={(event) => {
                        event.preventDefault();
                        outcomeForm.post(`${root}/outcomes`, { onSuccess: () => outcomeForm.reset() });
                    }}
                >
                    <label className="grid gap-1 text-sm">
                        Outcome code
                        <input
                            required
                            className="border-border bg-surface min-h-11 rounded-lg border px-3"
                            value={outcomeForm.data.code}
                            onChange={(e) => outcomeForm.setData('code', e.currentTarget.value)}
                        />
                    </label>
                    <label className="grid gap-1 text-sm">
                        Outcome name
                        <input
                            required
                            className="border-border bg-surface min-h-11 rounded-lg border px-3"
                            value={outcomeForm.data.name}
                            onChange={(e) => outcomeForm.setData('name', e.currentTarget.value)}
                        />
                    </label>
                    <label className="grid gap-1 text-sm">
                        Academic level
                        <select
                            className="border-border bg-surface min-h-11 rounded-lg border px-3"
                            value={outcomeForm.data.academic_level_id}
                            onChange={(e) => outcomeForm.setData('academic_level_id', e.currentTarget.value)}
                        >
                            <option value="">All levels</option>
                            {levels.map((level) => (
                                <option key={level.id} value={level.id}>
                                    {level.name}
                                </option>
                            ))}
                        </select>
                    </label>
                    <label className="grid gap-1 text-sm">
                        Competency category
                        <input
                            className="border-border bg-surface min-h-11 rounded-lg border px-3"
                            value={outcomeForm.data.competency_category}
                            onChange={(e) =>
                                outcomeForm.setData('competency_category', e.currentTarget.value)
                            }
                        />
                    </label>
                    <label className="grid gap-1 text-sm sm:col-span-2 xl:col-span-4">
                        Description
                        <textarea
                            required
                            className="border-border bg-surface min-h-20 rounded-lg border px-3 py-2"
                            value={outcomeForm.data.description}
                            onChange={(e) => outcomeForm.setData('description', e.currentTarget.value)}
                        />
                    </label>
                    <div className="sm:col-span-2 xl:col-span-4">
                        <button
                            disabled={outcomeForm.processing}
                            className="bg-brand-action focus-ring min-h-11 rounded-lg px-4 text-sm font-semibold text-white"
                        >
                            Add outcome reference
                        </button>
                    </div>
                    {Object.keys(outcomeForm.errors).length > 0 && (
                        <div className="sm:col-span-2 xl:col-span-4">
                            <FormErrors />
                        </div>
                    )}
                </form>
                <ul className="mt-5 grid gap-3 sm:grid-cols-2">
                    {outcomes.map((outcome) => (
                        <li key={outcome.id} className="border-border rounded-xl border p-4">
                            <strong>{outcome.name}</strong>
                            <span className="text-text-muted ml-2 text-sm">{outcome.code}</span>
                            <p className="text-text-secondary mt-1 text-sm">
                                {outcome.competency_category ?? 'Unclassified'}
                            </p>
                        </li>
                    ))}
                </ul>
                {outcomes.length === 0 && (
                    <p className="text-text-secondary mt-4 text-sm">No outcomes recorded.</p>
                )}
            </section>
        </WorkspaceLayout>
    );
}
