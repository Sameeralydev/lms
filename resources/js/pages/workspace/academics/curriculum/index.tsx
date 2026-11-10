import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import type { FormEvent } from 'react';
import { useEffect, useState } from 'react';
import { PageHeader } from '@/components/ui/page-header';
import { WorkspaceLayout } from '@/layouts/workspace-layout';

type Option = {
    id: number;
    code: string;
    title: string;
    status?: string;
    lock_version?: number;
    education_stage?: string | null;
    description?: string | null;
    effective_from?: string | null;
    effective_until?: string | null;
};
type Props = {
    tenantSlug: string;
    metrics: { frameworks: number; curricula: number; published: number; in_review: number; nodes: number };
    frameworks: Option[];
    learningOutcomes: { id: number; code: string; name: string }[];
    frameworkRequirements: {
        framework_id: number;
        learning_outcome_id: number;
        required: boolean | number;
        code: string;
        name: string;
    }[];
    sharedTemplates: {
        id: number;
        tenant_id: number;
        code: string;
        title: string;
        description: string | null;
        program_code: string;
        program_title: string;
        published_version: number;
    }[];
    programs: Option[];
    academicYears: { id: number; code: string; name: string }[];
    curricula: {
        data: {
            id: number;
            code: string;
            title: string;
            status: string;
            program: { code: string; title: string };
            framework: { code: string; title: string } | null;
            academic_year: { name: string } | null;
            versions: { id: number; version_number: number; status: string; updated_at: string }[];
        }[];
        links: { url: string | null; label: string; active: boolean }[];
    };
    filters: { q: string; status: string };
};

const fieldClass = 'border-border bg-surface min-h-11 rounded-lg border px-3 text-sm text-text-primary';
const sectionClass = 'border-border bg-surface rounded-2xl border p-5 shadow-sm';

function FrameworkLifecycle({ framework, base }: { framework: Option; base: string }) {
    const form = useForm({ status: framework.status === 'draft' ? 'active' : 'retired' });

    return framework.status === 'retired' ? null : (
        <form
            onSubmit={(event: FormEvent) => {
                event.preventDefault();
                form.post(`${base}/frameworks/${framework.id}/transition`);
            }}
        >
            <input type="hidden" name="status" value={form.data.status} />
            <button
                type="submit"
                disabled={form.processing}
                className="border-border min-h-10 rounded-lg border px-3 text-xs font-semibold focus-visible:outline-2"
            >
                {form.data.status === 'active' ? 'Activate framework' : 'Retire framework'}
            </button>
        </form>
    );
}

function FrameworkEditor({ framework, base }: { framework: Option; base: string }) {
    const form = useForm({
        lock_version: framework.lock_version ?? 1,
        title: framework.title,
        education_stage: framework.education_stage ?? '',
        description: framework.description ?? '',
        effective_from: framework.effective_from?.slice(0, 10) ?? '',
        effective_until: framework.effective_until?.slice(0, 10) ?? '',
    });
    const setFrameworkData = form.setData;
    useEffect(
        () => setFrameworkData('lock_version', framework.lock_version ?? 1),
        [framework.lock_version, setFrameworkData],
    );

    if (framework.status !== 'draft') return null;

    return (
        <details className="mt-3">
            <summary className="text-brand-link min-h-10 cursor-pointer py-2 text-sm font-semibold underline underline-offset-4">
                Edit draft framework
            </summary>
            <form
                className="mt-2 grid gap-2"
                onSubmit={(event: FormEvent) => {
                    event.preventDefault();
                    form.put(`${base}/frameworks/${framework.id}`);
                }}
            >
                <label className="grid gap-1 text-xs">
                    Framework title
                    <input
                        required
                        maxLength={180}
                        className={fieldClass}
                        value={form.data.title}
                        onChange={(event) => form.setData('title', event.currentTarget.value)}
                    />
                </label>
                <label className="grid gap-1 text-xs">
                    Education stage
                    <input
                        maxLength={80}
                        className={fieldClass}
                        value={form.data.education_stage}
                        onChange={(event) => form.setData('education_stage', event.currentTarget.value)}
                    />
                </label>
                <label className="grid gap-1 text-xs">
                    Description
                    <textarea
                        rows={2}
                        maxLength={5000}
                        className={`${fieldClass} py-2`}
                        value={form.data.description}
                        onChange={(event) => form.setData('description', event.currentTarget.value)}
                    />
                </label>
                <div className="grid gap-2 sm:grid-cols-2">
                    <label className="grid gap-1 text-xs">
                        Effective from
                        <input
                            type="date"
                            className={fieldClass}
                            value={form.data.effective_from}
                            onChange={(event) => form.setData('effective_from', event.currentTarget.value)}
                        />
                    </label>
                    <label className="grid gap-1 text-xs">
                        Effective until
                        <input
                            type="date"
                            className={fieldClass}
                            value={form.data.effective_until}
                            onChange={(event) => form.setData('effective_until', event.currentTarget.value)}
                        />
                    </label>
                </div>
                <button
                    type="submit"
                    disabled={form.processing}
                    className="border-border min-h-10 rounded-lg border px-3 text-xs font-semibold disabled:opacity-60"
                >
                    Save framework draft
                </button>
                {Object.values(form.errors).length > 0 && (
                    <p role="alert" className="text-danger text-xs">
                        {Object.values(form.errors).join(' ')}
                    </p>
                )}
            </form>
        </details>
    );
}

function FrameworkOutcomeManager({
    framework,
    outcomes,
    requirements,
    base,
}: {
    framework: Option;
    outcomes: Props['learningOutcomes'];
    requirements: Props['frameworkRequirements'];
    base: string;
}) {
    const form = useForm({ learning_outcome_id: '', required: true });

    return (
        <div className="mt-3 grid gap-2">
            <h3 className="text-xs font-semibold">Required framework outcomes</h3>
            {requirements.length === 0 ? (
                <p className="text-text-muted text-xs">
                    No learning outcomes are configured for this framework.
                </p>
            ) : (
                <ul className="grid gap-1 text-xs">
                    {requirements.map((item) => (
                        <li key={item.learning_outcome_id}>
                            {item.code} · {item.name} · {item.required ? 'required' : 'optional'}
                        </li>
                    ))}
                </ul>
            )}
            {framework.status === 'draft' && (
                <form
                    className="grid gap-2"
                    onSubmit={(event: FormEvent) => {
                        event.preventDefault();
                        form.post(`${base}/frameworks/${framework.id}/outcomes`, {
                            onSuccess: () => form.reset('learning_outcome_id'),
                        });
                    }}
                >
                    <label className="grid gap-1 text-xs">
                        Learning outcome
                        <select
                            required
                            className={fieldClass}
                            value={form.data.learning_outcome_id}
                            onChange={(event) =>
                                form.setData('learning_outcome_id', event.currentTarget.value)
                            }
                        >
                            <option value="">Choose an active outcome</option>
                            {outcomes.map((outcome) => (
                                <option key={outcome.id} value={outcome.id}>
                                    {outcome.code} · {outcome.name}
                                </option>
                            ))}
                        </select>
                    </label>
                    <label className="flex min-h-10 items-center gap-2 text-xs">
                        <input
                            type="checkbox"
                            checked={form.data.required}
                            onChange={(event) => form.setData('required', event.currentTarget.checked)}
                        />
                        Required for framework coverage
                    </label>
                    <button
                        type="submit"
                        disabled={form.processing || outcomes.length === 0}
                        className="border-border min-h-10 rounded-lg border px-3 text-xs font-semibold disabled:opacity-60"
                    >
                        Save outcome requirement
                    </button>
                    {Object.values(form.errors).length > 0 && (
                        <p role="alert" className="text-danger text-xs">
                            {Object.values(form.errors).join(' ')}
                        </p>
                    )}
                </form>
            )}
        </div>
    );
}

function TemplateAdoption({
    template,
    base,
    programs,
}: {
    template: Props['sharedTemplates'][number];
    base: string;
    programs: Option[];
}) {
    const form = useForm({ academic_program_id: '', code: '', title: '', description: '' });

    return (
        <article className="border-border bg-surface grid gap-3 rounded-xl border p-4">
            <div>
                <h3 className="font-semibold">{template.title}</h3>
                <p className="text-text-secondary mt-1 text-sm">
                    {template.code} · {template.program_code} · published v{template.published_version}
                </p>
                {template.description && (
                    <p className="text-text-secondary mt-2 text-sm">{template.description}</p>
                )}
            </div>
            <form
                className="grid gap-2 sm:grid-cols-2"
                onSubmit={(event: FormEvent) => {
                    event.preventDefault();
                    form.post(`${base}/templates/${template.id}/adopt`, { onSuccess: () => form.reset() });
                }}
            >
                <label className="grid gap-1 text-sm">
                    Local academic program
                    <select
                        required
                        className={fieldClass}
                        value={form.data.academic_program_id}
                        onChange={(event) => form.setData('academic_program_id', event.currentTarget.value)}
                    >
                        <option value="">Choose a program</option>
                        {programs.map((program) => (
                            <option key={program.id} value={program.id}>
                                {program.code} · {program.title}
                            </option>
                        ))}
                    </select>
                </label>
                <label className="grid gap-1 text-sm">
                    Local curriculum code
                    <input
                        required
                        maxLength={48}
                        className={fieldClass}
                        value={form.data.code}
                        onChange={(event) => form.setData('code', event.currentTarget.value)}
                    />
                </label>
                <label className="grid gap-1 text-sm sm:col-span-2">
                    Local curriculum title
                    <input
                        required
                        maxLength={180}
                        className={fieldClass}
                        value={form.data.title}
                        onChange={(event) => form.setData('title', event.currentTarget.value)}
                    />
                </label>
                <button
                    disabled={form.processing || programs.length === 0}
                    type="submit"
                    className="bg-brand-action min-h-11 rounded-lg px-4 text-sm font-semibold text-white disabled:opacity-60 sm:col-span-2"
                >
                    Adopt an independent copy
                </button>
                {Object.values(form.errors).length > 0 && (
                    <p role="alert" className="text-danger text-sm sm:col-span-2">
                        {Object.values(form.errors).join(' ')}
                    </p>
                )}
            </form>
        </article>
    );
}

export default function CurriculumIndex({
    tenantSlug,
    metrics,
    frameworks,
    learningOutcomes,
    frameworkRequirements,
    sharedTemplates,
    programs,
    academicYears,
    curricula,
    filters,
}: Props) {
    const frameworkForm = useForm({
        code: '',
        title: '',
        education_stage: '',
        description: '',
        effective_from: '',
        effective_until: '',
    });
    const curriculumForm = useForm({
        academic_program_id: '',
        framework_id: '',
        academic_year_id: '',
        code: '',
        title: '',
        description: '',
        effective_from: '',
        effective_until: '',
    });
    const [search, setSearch] = useState(filters.q);
    const base = `/t/${tenantSlug}/academics/curriculum`;
    const page = usePage<{ auth?: { tenant?: { permissions?: string[] } | null } }>();
    const permissions = page.props.auth?.tenant?.permissions ?? [];
    const canManage = permissions.includes('academic.curriculum.manage');
    const canAudit = permissions.includes('academic.curriculum.audit.view');
    const canReview = permissions.includes('academic.curriculum.review');

    return (
        <WorkspaceLayout title="Curriculum" area="tenant">
            <Head title="Curriculum management" />
            <div className="grid gap-6">
                <PageHeader
                    title="Curriculum workspace"
                    description="Build versioned curriculum plans from your institution’s academic programs, catalog, outcomes and calendar."
                    actions={
                        <>
                            <Link
                                href={`${base}/analytics`}
                                className="border-border bg-surface inline-flex min-h-11 items-center rounded-lg border px-4 text-sm font-semibold"
                            >
                                Analytics
                            </Link>
                            <Link
                                href={`${base}/quality`}
                                className="border-border bg-surface inline-flex min-h-11 items-center rounded-lg border px-4 text-sm font-semibold"
                            >
                                Quality review
                            </Link>
                            <Link
                                href={`${base}/resources`}
                                className="border-border bg-surface inline-flex min-h-11 items-center rounded-lg border px-4 text-sm font-semibold"
                            >
                                Resource library
                            </Link>
                            {canManage && (
                                <Link
                                    href={`${base}/governance`}
                                    className="border-border bg-surface inline-flex min-h-11 items-center rounded-lg border px-4 text-sm font-semibold"
                                >
                                    Governance
                                </Link>
                            )}
                            {canReview && (
                                <Link
                                    href={`${base}/reviews`}
                                    className="border-border bg-surface inline-flex min-h-11 items-center rounded-lg border px-4 text-sm font-semibold"
                                >
                                    Assigned reviews
                                </Link>
                            )}
                        </>
                    }
                />

                <section
                    aria-label="Curriculum design metrics"
                    className="grid gap-3 sm:grid-cols-2 xl:grid-cols-5"
                >
                    {[
                        ['Frameworks', metrics.frameworks],
                        ['Curriculum plans', metrics.curricula],
                        ['Published versions', metrics.published],
                        ['Awaiting review', metrics.in_review],
                        ['Syllabus items', metrics.nodes],
                    ].map(([label, value]) => (
                        <article key={label} className={`${sectionClass} p-4`}>
                            <p className="text-text-muted text-xs font-semibold tracking-wide uppercase">
                                {label}
                            </p>
                            <p className="mt-2 text-2xl font-semibold tabular-nums">{value}</p>
                        </article>
                    ))}
                </section>

                {canManage && (
                    <div className="grid gap-5 xl:grid-cols-2">
                        <section className={sectionClass}>
                            <h2 className="text-lg font-semibold">Create a framework</h2>
                            <p className="text-text-secondary mt-1 text-sm">
                                Define local learning areas and terminology without imposing a national
                                framework.
                            </p>
                            <form
                                className="mt-4 grid gap-3 sm:grid-cols-2"
                                onSubmit={(event: FormEvent) => {
                                    event.preventDefault();
                                    frameworkForm.post(`${base}/frameworks`, {
                                        onSuccess: () => frameworkForm.reset(),
                                    });
                                }}
                            >
                                <label className="grid gap-1 text-sm">
                                    Code
                                    <input
                                        required
                                        maxLength={48}
                                        className={fieldClass}
                                        value={frameworkForm.data.code}
                                        onChange={(event) =>
                                            frameworkForm.setData('code', event.currentTarget.value)
                                        }
                                    />
                                    {frameworkForm.errors.code && (
                                        <span className="text-danger text-xs">
                                            {frameworkForm.errors.code}
                                        </span>
                                    )}
                                </label>
                                <label className="grid gap-1 text-sm">
                                    Title
                                    <input
                                        required
                                        maxLength={180}
                                        className={fieldClass}
                                        value={frameworkForm.data.title}
                                        onChange={(event) =>
                                            frameworkForm.setData('title', event.currentTarget.value)
                                        }
                                    />
                                    {frameworkForm.errors.title && (
                                        <span className="text-danger text-xs">
                                            {frameworkForm.errors.title}
                                        </span>
                                    )}
                                </label>
                                <label className="grid gap-1 text-sm">
                                    Education stage
                                    <input
                                        maxLength={80}
                                        className={fieldClass}
                                        placeholder="Optional, e.g. upper secondary"
                                        value={frameworkForm.data.education_stage}
                                        onChange={(event) =>
                                            frameworkForm.setData(
                                                'education_stage',
                                                event.currentTarget.value,
                                            )
                                        }
                                    />
                                </label>
                                <label className="grid gap-1 text-sm">
                                    Effective from
                                    <input
                                        type="date"
                                        className={fieldClass}
                                        value={frameworkForm.data.effective_from}
                                        onChange={(event) =>
                                            frameworkForm.setData('effective_from', event.currentTarget.value)
                                        }
                                    />
                                </label>
                                <label className="grid gap-1 text-sm sm:col-span-2">
                                    Description
                                    <textarea
                                        rows={3}
                                        maxLength={5000}
                                        className={`${fieldClass} py-2`}
                                        value={frameworkForm.data.description}
                                        onChange={(event) =>
                                            frameworkForm.setData('description', event.currentTarget.value)
                                        }
                                    />
                                </label>
                                <button
                                    disabled={frameworkForm.processing}
                                    className="bg-brand-action min-h-11 rounded-lg px-4 text-sm font-semibold text-white disabled:opacity-60 sm:col-span-2"
                                    type="submit"
                                >
                                    {frameworkForm.processing ? 'Saving…' : 'Create framework draft'}
                                </button>
                            </form>
                            {frameworkForm.errors.effective_until && (
                                <p role="alert" className="text-danger mt-2 text-sm">
                                    {frameworkForm.errors.effective_until}
                                </p>
                            )}
                        </section>

                        <section className={sectionClass}>
                            <h2 className="text-lg font-semibold">Start a program curriculum</h2>
                            <p className="text-text-secondary mt-1 text-sm">
                                Plans reference the existing program, academic year and optional framework.
                            </p>
                            <form
                                className="mt-4 grid gap-3 sm:grid-cols-2"
                                onSubmit={(event: FormEvent) => {
                                    event.preventDefault();
                                    curriculumForm.post(`${base}/curricula`, {
                                        onSuccess: () => curriculumForm.reset(),
                                    });
                                }}
                            >
                                <label className="grid gap-1 text-sm sm:col-span-2">
                                    Academic program
                                    <select
                                        required
                                        className={fieldClass}
                                        value={curriculumForm.data.academic_program_id}
                                        onChange={(event) =>
                                            curriculumForm.setData(
                                                'academic_program_id',
                                                event.currentTarget.value,
                                            )
                                        }
                                    >
                                        <option value="">Choose a program</option>
                                        {programs.map((item) => (
                                            <option key={item.id} value={item.id}>
                                                {item.code} · {item.title}
                                            </option>
                                        ))}
                                    </select>
                                    {curriculumForm.errors.academic_program_id && (
                                        <span className="text-danger text-xs">
                                            {curriculumForm.errors.academic_program_id}
                                        </span>
                                    )}
                                </label>
                                <label className="grid gap-1 text-sm">
                                    Framework
                                    <select
                                        className={fieldClass}
                                        value={curriculumForm.data.framework_id}
                                        onChange={(event) =>
                                            curriculumForm.setData('framework_id', event.currentTarget.value)
                                        }
                                    >
                                        <option value="">No framework selected</option>
                                        {frameworks.map((item) => (
                                            <option key={item.id} value={item.id}>
                                                {item.code} · {item.title}
                                            </option>
                                        ))}
                                    </select>
                                </label>
                                <label className="grid gap-1 text-sm">
                                    Academic year
                                    <select
                                        className={fieldClass}
                                        value={curriculumForm.data.academic_year_id}
                                        onChange={(event) =>
                                            curriculumForm.setData(
                                                'academic_year_id',
                                                event.currentTarget.value,
                                            )
                                        }
                                    >
                                        <option value="">Any applicable year</option>
                                        {academicYears.map((item) => (
                                            <option key={item.id} value={item.id}>
                                                {item.code} · {item.name}
                                            </option>
                                        ))}
                                    </select>
                                </label>
                                <label className="grid gap-1 text-sm">
                                    Plan code
                                    <input
                                        required
                                        maxLength={48}
                                        className={fieldClass}
                                        value={curriculumForm.data.code}
                                        onChange={(event) =>
                                            curriculumForm.setData('code', event.currentTarget.value)
                                        }
                                    />
                                    {curriculumForm.errors.code && (
                                        <span className="text-danger text-xs">
                                            {curriculumForm.errors.code}
                                        </span>
                                    )}
                                </label>
                                <label className="grid gap-1 text-sm">
                                    Plan title
                                    <input
                                        required
                                        maxLength={180}
                                        className={fieldClass}
                                        value={curriculumForm.data.title}
                                        onChange={(event) =>
                                            curriculumForm.setData('title', event.currentTarget.value)
                                        }
                                    />
                                    {curriculumForm.errors.title && (
                                        <span className="text-danger text-xs">
                                            {curriculumForm.errors.title}
                                        </span>
                                    )}
                                </label>
                                <label className="grid gap-1 text-sm sm:col-span-2">
                                    Description
                                    <textarea
                                        rows={2}
                                        maxLength={5000}
                                        className={`${fieldClass} py-2`}
                                        value={curriculumForm.data.description}
                                        onChange={(event) =>
                                            curriculumForm.setData('description', event.currentTarget.value)
                                        }
                                    />
                                </label>
                                <button
                                    disabled={curriculumForm.processing || programs.length === 0}
                                    className="bg-brand-action min-h-11 rounded-lg px-4 text-sm font-semibold text-white disabled:opacity-60 sm:col-span-2"
                                    type="submit"
                                >
                                    {curriculumForm.processing ? 'Creating…' : 'Create draft curriculum'}
                                </button>
                                {programs.length === 0 && (
                                    <p className="text-text-secondary text-sm sm:col-span-2">
                                        Create an active academic program before starting a curriculum plan.
                                    </p>
                                )}
                            </form>
                        </section>
                    </div>
                )}

                <section className={sectionClass} aria-labelledby="framework-library-heading">
                    <h2 id="framework-library-heading" className="text-lg font-semibold">
                        Framework library
                    </h2>
                    {frameworks.length === 0 ? (
                        <p className="text-text-secondary mt-2 text-sm">
                            No curriculum frameworks have been defined yet.
                        </p>
                    ) : (
                        <ul className="mt-3 grid gap-3 md:grid-cols-2 xl:grid-cols-3">
                            {frameworks.map((framework) => (
                                <li
                                    key={framework.id}
                                    className="border-border flex flex-wrap items-center justify-between gap-3 rounded-xl border p-4"
                                >
                                    <div className="min-w-0 flex-1">
                                        <strong>{framework.title}</strong>
                                        <p className="text-text-secondary mt-1 text-sm">
                                            {framework.code} ·{' '}
                                            <span className="capitalize">{framework.status}</span>
                                        </p>
                                        {canManage && <FrameworkEditor framework={framework} base={base} />}
                                        {canManage && (
                                            <FrameworkOutcomeManager
                                                framework={framework}
                                                outcomes={learningOutcomes}
                                                requirements={frameworkRequirements.filter(
                                                    (item) => item.framework_id === framework.id,
                                                )}
                                                base={base}
                                            />
                                        )}
                                        {canAudit && (
                                            <Link
                                                href={`${base}/frameworks/${framework.id}/history`}
                                                className="text-brand-link mt-2 inline-flex min-h-10 items-center text-xs font-semibold underline underline-offset-4"
                                            >
                                                View framework history
                                            </Link>
                                        )}
                                    </div>
                                    {canManage && <FrameworkLifecycle framework={framework} base={base} />}
                                </li>
                            ))}
                        </ul>
                    )}
                </section>

                {canManage && sharedTemplates.length > 0 && (
                    <section className={sectionClass} aria-labelledby="shared-templates-heading">
                        <h2 id="shared-templates-heading" className="text-lg font-semibold">
                            Shared curriculum templates
                        </h2>
                        <p className="text-text-secondary mt-1 text-sm">
                            Published templates are visible across institutions by explicit sharing. Adoption
                            copies their public syllabus content into a separate local draft.
                        </p>
                        <div className="mt-4 grid gap-3 xl:grid-cols-2">
                            {sharedTemplates.map((template) => (
                                <TemplateAdoption
                                    key={`${template.id}-${template.tenant_id}`}
                                    template={template}
                                    base={base}
                                    programs={programs}
                                />
                            ))}
                        </div>
                    </section>
                )}

                <section className={sectionClass}>
                    <div className="flex flex-wrap items-end justify-between gap-3">
                        <div>
                            <h2 className="text-lg font-semibold">Curriculum plans</h2>
                            <p className="text-text-secondary mt-1 text-sm">
                                Search and filter institution-owned curriculum versions.
                            </p>
                        </div>
                        <form
                            className="flex flex-wrap gap-2"
                            onSubmit={(event: FormEvent) => {
                                event.preventDefault();
                                router.get(
                                    base,
                                    { q: search, status: filters.status },
                                    { preserveState: true, preserveScroll: true },
                                );
                            }}
                        >
                            <label className="sr-only" htmlFor="curriculum-search">
                                Search curricula
                            </label>
                            <input
                                id="curriculum-search"
                                className={fieldClass}
                                placeholder="Search code or title"
                                value={search}
                                name="q"
                                onChange={(event) => setSearch(event.currentTarget.value)}
                            />
                            <label className="sr-only" htmlFor="curriculum-status">
                                Filter by status
                            </label>
                            <select
                                id="curriculum-status"
                                className={fieldClass}
                                value={filters.status}
                                onChange={(event) =>
                                    router.get(
                                        base,
                                        { q: filters.q, status: event.currentTarget.value },
                                        { preserveState: true, preserveScroll: true },
                                    )
                                }
                            >
                                <option value="">All statuses</option>
                                <option value="draft">Draft</option>
                                <option value="published">Published</option>
                                <option value="archived">Archived</option>
                            </select>
                        </form>
                    </div>
                    {curricula.data.length === 0 ? (
                        <div className="border-border bg-canvas mt-5 rounded-xl border border-dashed p-8 text-center">
                            <h3 className="font-semibold">No curriculum plans yet</h3>
                            <p className="text-text-secondary mt-1 text-sm">
                                Create a plan from an active program to start writing a syllabus.
                            </p>
                        </div>
                    ) : (
                        <div className="mt-5 overflow-x-auto">
                            <table className="w-full min-w-[680px] text-left text-sm">
                                <caption className="sr-only">
                                    Institution curriculum plans and current versions
                                </caption>
                                <thead className="text-text-muted border-border border-b text-xs uppercase">
                                    <tr>
                                        <th scope="col" className="py-3 pr-4">
                                            Curriculum
                                        </th>
                                        <th scope="col" className="py-3 pr-4">
                                            Program
                                        </th>
                                        <th scope="col" className="py-3 pr-4">
                                            Framework
                                        </th>
                                        <th scope="col" className="py-3 pr-4">
                                            Version state
                                        </th>
                                        <th scope="col" className="py-3">
                                            Action
                                        </th>
                                    </tr>
                                </thead>
                                <tbody className="divide-border divide-y">
                                    {curricula.data.map((item) => {
                                        const current = item.versions[0];
                                        return (
                                            <tr key={item.id} className="align-top">
                                                <th scope="row" className="py-4 pr-4 font-semibold">
                                                    <span className="block">{item.title}</span>
                                                    <span className="text-text-muted mt-1 block font-normal">
                                                        {item.code}
                                                        {item.academic_year
                                                            ? ` · ${item.academic_year.name}`
                                                            : ''}
                                                    </span>
                                                </th>
                                                <td className="py-4 pr-4">
                                                    {item.program.code} · {item.program.title}
                                                </td>
                                                <td className="py-4 pr-4">
                                                    {item.framework?.title ?? 'Institution-defined'}
                                                </td>
                                                <td className="py-4 pr-4">
                                                    <span className="capitalize">
                                                        {current?.status ?? item.status}
                                                    </span>
                                                    {current ? ` · v${current.version_number}` : ''}
                                                </td>
                                                <td className="py-4">
                                                    <Link
                                                        className="text-brand-link inline-flex min-h-11 items-center font-semibold underline-offset-4 hover:underline focus-visible:outline-2"
                                                        href={`${base}/curricula/${item.id}${current ? `?version=${current.id}` : ''}`}
                                                    >
                                                        Open plan
                                                        <span className="sr-only">: {item.title}</span>
                                                    </Link>
                                                </td>
                                            </tr>
                                        );
                                    })}
                                </tbody>
                            </table>
                        </div>
                    )}
                    <nav className="mt-4 flex flex-wrap gap-2" aria-label="Curriculum plan pages">
                        {curricula.links.map((link) => (
                            <Link
                                key={`${link.label}-${link.url}`}
                                href={link.url ?? '#'}
                                preserveScroll
                                aria-current={link.active ? 'page' : undefined}
                                aria-disabled={!link.url}
                                className={`border-border min-h-10 rounded-lg border px-3 py-2 text-xs ${link.active ? 'bg-brand-action text-white' : 'bg-surface'} ${link.url ? '' : 'text-text-muted pointer-events-none'}`}
                            >
                                {link.label.replace('&laquo;', '‹').replace('&raquo;', '›')}
                            </Link>
                        ))}
                    </nav>
                </section>
            </div>
        </WorkspaceLayout>
    );
}
