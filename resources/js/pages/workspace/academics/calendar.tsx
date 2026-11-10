import { router, useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';
import { FormErrors } from '@/components/ui/form-errors';
import { PageHeader } from '@/components/ui/page-header';
import { WorkspaceLayout } from '@/layouts/workspace-layout';

type Period = {
    id: number;
    name: string;
    code: string;
    period_type: string;
    status: string;
    starts_on: string;
    ends_on: string;
    sequence: number;
    teaching_starts_on: string | null;
    teaching_ends_on: string | null;
    assessment_starts_on: string | null;
    assessment_ends_on: string | null;
    is_current: boolean;
};
type Year = {
    id: number;
    name: string;
    code: string;
    description: string | null;
    starts_on: string;
    ends_on: string;
    status: string;
    is_current: boolean;
    campus_id: number | null;
    academic_model: string | null;
    timezone: string;
    locale: string;
    campus: { id: number; name: string } | null;
    periods_count: number;
    periods?: Period[];
};
type Props = {
    tenant: { slug: string };
    configuration: {
        operating_model: string;
        timezone: string;
        locale: string;
        terminology: Record<string, string>;
        enabled_features: Record<string, boolean>;
    };
    years: { data: Year[]; links: { url: string | null; label: string; active: boolean }[] };
    campuses: { id: number; name: string; code: string }[];
    campusScope: number | null;
    periodTypes: string[];
};

function dateInputValue(value: string | null): string {
    return value ? value.slice(0, 10) : '';
}

function PeriodRow({
    period,
    slug,
    yearStatus,
    periodTypes,
}: {
    period: Period;
    slug: string;
    yearStatus: string;
    periodTypes: string[];
}) {
    const target = `/t/${slug}/academics`;
    const form = useForm({
        name: period.name,
        code: period.code,
        period_type: period.period_type,
        sequence: period.sequence,
        starts_on: dateInputValue(period.starts_on),
        ends_on: dateInputValue(period.ends_on),
        teaching_starts_on: dateInputValue(period.teaching_starts_on),
        teaching_ends_on: dateInputValue(period.teaching_ends_on),
        assessment_starts_on: dateInputValue(period.assessment_starts_on),
        assessment_ends_on: dateInputValue(period.assessment_ends_on),
    });
    const save = (event: FormEvent) => {
        event.preventDefault();
        form.put(`${target}/periods/${period.id}`);
    };

    return (
        <li className="border-border rounded-lg border p-3">
            <div className="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <h4 className="font-medium">
                        {period.name}{' '}
                        {period.is_current ? <span className="text-brand-link text-xs">CURRENT</span> : null}
                    </h4>
                    <p className="text-text-secondary text-sm">
                        {period.code} · {period.period_type} · {dateInputValue(period.starts_on)} to{' '}
                        {dateInputValue(period.ends_on)} · {period.status}
                    </p>
                </div>
                <div className="flex flex-wrap gap-2">
                    {period.status === 'draft' && yearStatus === 'active' ? (
                        <button
                            className="border-border focus-ring min-h-10 rounded-lg border px-3 text-sm"
                            onClick={() =>
                                router.patch(`${target}/periods/${period.id}/status`, { status: 'active' })
                            }
                        >
                            Activate
                        </button>
                    ) : null}
                    {period.status === 'active' ? (
                        <>
                            <button
                                className="border-border focus-ring min-h-10 rounded-lg border px-3 text-sm"
                                onClick={() => router.post(`${target}/periods/${period.id}/current`)}
                            >
                                Set current
                            </button>
                            <button
                                className="border-border focus-ring min-h-10 rounded-lg border px-3 text-sm"
                                onClick={() =>
                                    router.patch(`${target}/periods/${period.id}/status`, {
                                        status: 'completed',
                                    })
                                }
                            >
                                Complete
                            </button>
                        </>
                    ) : null}
                    {period.status === 'completed' ? (
                        <button
                            className="border-border focus-ring min-h-10 rounded-lg border px-3 text-sm"
                            onClick={() =>
                                router.patch(`${target}/periods/${period.id}/status`, { status: 'archived' })
                            }
                        >
                            Archive
                        </button>
                    ) : null}
                </div>
            </div>
            {['draft', 'active'].includes(period.status) ? (
                <details className="mt-3">
                    <summary className="focus-ring cursor-pointer rounded text-sm font-medium">
                        Edit period
                    </summary>
                    <form className="mt-3 grid gap-3 sm:grid-cols-2 xl:grid-cols-4" onSubmit={save}>
                        {(
                            [
                                'name',
                                'code',
                                'sequence',
                                'starts_on',
                                'ends_on',
                                'teaching_starts_on',
                                'teaching_ends_on',
                                'assessment_starts_on',
                                'assessment_ends_on',
                            ] as const
                        ).map((field) => (
                            <label key={field} className="grid gap-1 text-sm capitalize">
                                {field.replaceAll('_', ' ')}
                                <input
                                    className="border-border bg-surface min-h-11 rounded-lg border px-3"
                                    type={
                                        field === 'sequence'
                                            ? 'number'
                                            : field.endsWith('_on')
                                              ? 'date'
                                              : 'text'
                                    }
                                    min={field === 'sequence' ? 1 : undefined}
                                    value={form.data[field]}
                                    onChange={(event) =>
                                        form.setData(
                                            field,
                                            field === 'sequence'
                                                ? Number(event.currentTarget.value)
                                                : event.currentTarget.value,
                                        )
                                    }
                                    required={['name', 'code', 'sequence', 'starts_on', 'ends_on'].includes(
                                        field,
                                    )}
                                />
                            </label>
                        ))}
                        <label className="grid gap-1 text-sm">
                            Period type
                            <select
                                className="border-border bg-surface min-h-11 rounded-lg border px-3"
                                value={form.data.period_type}
                                onChange={(event) => form.setData('period_type', event.currentTarget.value)}
                            >
                                {periodTypes.map((type) => (
                                    <option key={type} value={type}>
                                        {type}
                                    </option>
                                ))}
                            </select>
                        </label>
                        <div className="flex items-end">
                            <button
                                disabled={form.processing}
                                className="bg-brand-action focus-ring min-h-11 rounded-lg px-4 text-sm font-semibold text-white disabled:opacity-60"
                            >
                                Save period
                            </button>
                        </div>
                        {Object.keys(form.errors).length > 0 ? <FormErrors /> : null}
                    </form>
                </details>
            ) : null}
        </li>
    );
}

function YearRow({
    year,
    slug,
    periodTypes,
    campuses,
    campusScope,
}: {
    year: Year;
    slug: string;
    periodTypes: string[];
    campuses: Props['campuses'];
    campusScope: number | null;
}) {
    const yearEditForm = useForm({
        name: year.name,
        code: year.code,
        campus_id: year.campus_id === null ? '' : String(year.campus_id),
        description: year.description ?? '',
        academic_model: year.academic_model ?? '',
        starts_on: dateInputValue(year.starts_on),
        ends_on: dateInputValue(year.ends_on),
        timezone: year.timezone,
        locale: year.locale,
    });
    const periodForm = useForm({
        name: '',
        code: '',
        period_type: 'term',
        sequence: year.periods_count + 1,
        starts_on: '',
        ends_on: '',
        teaching_starts_on: '',
        teaching_ends_on: '',
        assessment_starts_on: '',
        assessment_ends_on: '',
    });
    const copyForm = useForm({ name: `${year.name} Copy`, code: '', starts_on: '', ends_on: '' });
    const target = `/t/${slug}/academics`;
    return (
        <article className="academic-calendar-year-card border-border min-w-0 rounded-xl border p-4 sm:p-5">
            <div className="flex flex-wrap items-start justify-between gap-3">
                <div>
                    <h3 className="font-semibold">
                        {year.name}
                        {year.is_current ? (
                            <span className="text-brand-link ml-2 text-xs">CURRENT</span>
                        ) : null}
                    </h3>
                    <p className="text-text-secondary mt-1 text-sm">
                        {year.code} · {dateInputValue(year.starts_on)} to {dateInputValue(year.ends_on)}
                        {year.campus ? ` · ${year.campus.name}` : ' · Institution-wide'}
                    </p>
                </div>
                <span className="bg-canvas rounded-full px-3 py-1 text-xs font-medium capitalize">
                    {year.status}
                </span>
            </div>
            <div className="mt-4 flex flex-wrap gap-2">
                {year.status === 'draft' && (
                    <button
                        className="bg-brand-action focus-ring rounded-lg px-3 py-2 text-sm font-semibold text-white"
                        onClick={() =>
                            router.patch(`${target}/years/${year.id}/status`, { status: 'active' })
                        }
                    >
                        Activate
                    </button>
                )}
                {year.status === 'active' && (
                    <>
                        <button
                            className="border-border focus-ring rounded-lg border px-3 py-2 text-sm"
                            onClick={() => router.post(`${target}/years/${year.id}/current`)}
                        >
                            Set current
                        </button>
                        <button
                            className="border-border focus-ring rounded-lg border px-3 py-2 text-sm"
                            onClick={() =>
                                router.patch(`${target}/years/${year.id}/status`, { status: 'completed' })
                            }
                        >
                            Complete
                        </button>
                    </>
                )}
                {year.status === 'completed' && (
                    <button
                        className="border-border focus-ring rounded-lg border px-3 py-2 text-sm"
                        onClick={() =>
                            router.patch(`${target}/years/${year.id}/status`, { status: 'archived' })
                        }
                    >
                        Archive
                    </button>
                )}
            </div>
            {year.status !== 'archived' ? (
                <details className="border-border mt-4 border-t pt-4">
                    <summary className="focus-ring cursor-pointer rounded text-sm font-semibold">
                        Edit academic year
                    </summary>
                    <form
                        className="mt-4 grid min-w-0 grid-cols-[minmax(0,1fr)] gap-3 sm:grid-cols-2 xl:grid-cols-4"
                        onSubmit={(event) => {
                            event.preventDefault();
                            yearEditForm.put(`${target}/years/${year.id}`);
                        }}
                    >
                        {(['name', 'code', 'starts_on', 'ends_on', 'timezone', 'locale'] as const).map(
                            (field) => (
                                <label key={field} className="grid gap-1 text-sm capitalize">
                                    {field.replaceAll('_', ' ')}
                                    <input
                                        required
                                        className="border-border bg-surface min-h-11 rounded-lg border px-3"
                                        type={field.endsWith('_on') ? 'date' : 'text'}
                                        value={yearEditForm.data[field]}
                                        onChange={(event) =>
                                            yearEditForm.setData(field, event.currentTarget.value)
                                        }
                                    />
                                </label>
                            ),
                        )}
                        <label className="grid gap-1 text-sm">
                            Campus scope
                            <select
                                required={campusScope !== null}
                                className="border-border bg-surface min-h-11 rounded-lg border px-3"
                                value={yearEditForm.data.campus_id}
                                onChange={(event) =>
                                    yearEditForm.setData('campus_id', event.currentTarget.value)
                                }
                            >
                                {campusScope === null ? <option value="">Institution-wide</option> : null}
                                {campuses.map((campus) => (
                                    <option key={campus.id} value={campus.id}>
                                        {campus.name}
                                    </option>
                                ))}
                            </select>
                        </label>
                        <label className="grid gap-1 text-sm">
                            Academic model
                            <select
                                className="border-border bg-surface min-h-11 rounded-lg border px-3"
                                value={yearEditForm.data.academic_model}
                                onChange={(event) =>
                                    yearEditForm.setData('academic_model', event.currentTarget.value)
                                }
                            >
                                {['k12', 'higher_education', 'academy', 'corporate_learning', 'custom'].map(
                                    (model) => (
                                        <option key={model} value={model}>
                                            {model.replaceAll('_', ' ')}
                                        </option>
                                    ),
                                )}
                            </select>
                        </label>
                        <label className="grid gap-1 text-sm sm:col-span-2 xl:col-span-4">
                            Description
                            <textarea
                                className="border-border bg-surface min-h-20 rounded-lg border p-3"
                                value={yearEditForm.data.description}
                                onChange={(event) =>
                                    yearEditForm.setData('description', event.currentTarget.value)
                                }
                            />
                        </label>
                        <div>
                            <button
                                disabled={yearEditForm.processing}
                                className="bg-brand-action focus-ring min-h-11 rounded-lg px-4 text-sm font-semibold text-white disabled:opacity-60"
                            >
                                Save year
                            </button>
                        </div>
                        {Object.keys(yearEditForm.errors).length > 0 ? <FormErrors /> : null}
                    </form>
                </details>
            ) : null}
            {year.periods && year.periods.length > 0 ? (
                <section className="border-border mt-4 border-t pt-4" aria-label={`Periods for ${year.name}`}>
                    <h4 className="mb-3 text-sm font-semibold">Teaching periods</h4>
                    <ul className="grid gap-3">
                        {year.periods.map((period) => (
                            <PeriodRow
                                key={period.id}
                                period={period}
                                slug={slug}
                                yearStatus={year.status}
                                periodTypes={periodTypes}
                            />
                        ))}
                    </ul>
                </section>
            ) : null}
            {year.status !== 'archived' && (
                <details className="border-border mt-4 border-t pt-4">
                    <summary className="focus-ring cursor-pointer rounded text-sm font-semibold">
                        Add period or copy calendar
                    </summary>
                    <form
                        className="mt-4 grid min-w-0 grid-cols-[minmax(0,1fr)] gap-3 sm:grid-cols-2 xl:grid-cols-4"
                        onSubmit={(event: FormEvent) => {
                            event.preventDefault();
                            periodForm.post(`${target}/years/${year.id}/periods`, {
                                onSuccess: () =>
                                    periodForm.reset(
                                        'name',
                                        'code',
                                        'starts_on',
                                        'ends_on',
                                        'teaching_starts_on',
                                        'teaching_ends_on',
                                        'assessment_starts_on',
                                        'assessment_ends_on',
                                    ),
                            });
                        }}
                    >
                        <p className="text-text-secondary text-sm sm:col-span-2 xl:col-span-4">
                            Periods must fit within the year and cannot overlap another period.
                        </p>
                        <label className="grid gap-1 text-sm">
                            Name
                            <input
                                required
                                className="border-border bg-surface min-h-11 rounded-lg border px-3"
                                value={periodForm.data.name}
                                onChange={(e) => periodForm.setData('name', e.currentTarget.value)}
                            />
                        </label>
                        <label className="grid gap-1 text-sm">
                            Code
                            <input
                                required
                                className="border-border bg-surface min-h-11 rounded-lg border px-3"
                                value={periodForm.data.code}
                                onChange={(e) => periodForm.setData('code', e.currentTarget.value)}
                            />
                        </label>
                        <label className="grid gap-1 text-sm">
                            Period type
                            <select
                                className="border-border bg-surface min-h-11 rounded-lg border px-3"
                                value={periodForm.data.period_type}
                                onChange={(e) => periodForm.setData('period_type', e.currentTarget.value)}
                            >
                                {periodTypes.map((type) => (
                                    <option key={type} value={type}>
                                        {type}
                                    </option>
                                ))}
                            </select>
                        </label>
                        <label className="grid gap-1 text-sm">
                            Sequence
                            <input
                                required
                                type="number"
                                min={1}
                                className="border-border bg-surface min-h-11 rounded-lg border px-3"
                                value={periodForm.data.sequence}
                                onChange={(e) =>
                                    periodForm.setData('sequence', Number(e.currentTarget.value))
                                }
                            />
                        </label>
                        <label className="grid gap-1 text-sm">
                            Starts on
                            <input
                                required
                                type="date"
                                className="border-border bg-surface min-h-11 rounded-lg border px-3"
                                value={periodForm.data.starts_on}
                                onChange={(e) => periodForm.setData('starts_on', e.currentTarget.value)}
                            />
                        </label>
                        <label className="grid gap-1 text-sm">
                            Ends on
                            <input
                                required
                                type="date"
                                className="border-border bg-surface min-h-11 rounded-lg border px-3"
                                value={periodForm.data.ends_on}
                                onChange={(e) => periodForm.setData('ends_on', e.currentTarget.value)}
                            />
                        </label>
                        <div className="flex items-end">
                            <button
                                disabled={periodForm.processing}
                                className="bg-brand-action focus-ring min-h-11 rounded-lg px-4 text-sm font-semibold text-white disabled:opacity-60"
                            >
                                Create draft period
                            </button>
                        </div>
                        {Object.keys(periodForm.errors).length > 0 && (
                            <div className="sm:col-span-2 xl:col-span-4" role="alert">
                                {Object.values(periodForm.errors).map((error) => (
                                    <p key={error} className="text-sm text-red-700">
                                        {error}
                                    </p>
                                ))}
                            </div>
                        )}
                    </form>
                    <form
                        className="border-border mt-5 grid min-w-0 grid-cols-[minmax(0,1fr)] gap-3 border-t pt-4 sm:grid-cols-2 xl:grid-cols-5"
                        onSubmit={(event) => {
                            event.preventDefault();
                            copyForm.post(`${target}/years/${year.id}/copy`);
                        }}
                    >
                        <h4 className="font-medium sm:col-span-2 xl:col-span-5">
                            Copy this calendar into a new draft
                        </h4>
                        {(['name', 'code', 'starts_on', 'ends_on'] as const).map((field) => (
                            <label key={field} className="grid gap-1 text-sm capitalize">
                                {field.replace('_', ' ')}
                                <input
                                    required
                                    className="border-border bg-surface min-h-11 rounded-lg border px-3"
                                    type={field.endsWith('_on') ? 'date' : 'text'}
                                    value={copyForm.data[field]}
                                    onChange={(e) => copyForm.setData(field, e.currentTarget.value)}
                                />
                            </label>
                        ))}
                        <div className="flex items-end">
                            <button
                                disabled={copyForm.processing}
                                className="border-border min-h-11 rounded-lg border px-4 text-sm font-semibold disabled:opacity-60"
                            >
                                Copy
                            </button>
                        </div>
                        {Object.keys(copyForm.errors).length > 0 && (
                            <div className="sm:col-span-2 xl:col-span-5" role="alert">
                                {Object.values(copyForm.errors).map((error) => (
                                    <p key={error} className="text-sm text-red-700">
                                        {error}
                                    </p>
                                ))}
                            </div>
                        )}
                    </form>
                </details>
            )}
        </article>
    );
}

export default function AcademicCalendarPage({
    tenant,
    configuration,
    years,
    campuses,
    periodTypes,
    campusScope,
}: Props) {
    const root = `/t/${tenant.slug}/academics`;
    const yearForm = useForm({
        name: '',
        code: '',
        campus_id: campusScope === null ? '' : String(campusScope),
        description: '',
        academic_model: configuration.operating_model,
        starts_on: '',
        ends_on: '',
        timezone: configuration.timezone,
        locale: configuration.locale,
    });
    const settingsForm = useForm({
        operating_model: configuration.operating_model,
        timezone: configuration.timezone,
        locale: configuration.locale,
        terminology: { ...configuration.terminology },
        enabled_features: { ...configuration.enabled_features },
    });
    return (
        <WorkspaceLayout area="tenant" title="Academic calendar">
            <PageHeader
                title="Academic calendar"
                description="Configure local academic terminology, years, and teaching periods. Dates follow each institution or campus calendar."
            />
            <div className="mt-5 flex gap-3 text-sm">
                <a href={root} className="text-brand-link font-semibold">
                    Overview
                </a>
                <a href={`${root}/structure`} className="text-brand-link font-semibold">
                    Organization & programs
                </a>
            </div>
            {campusScope === null ? (
                <section className="border-border bg-surface mt-6 rounded-2xl border p-5 sm:p-7">
                    <h2 className="text-lg font-semibold">Institution academic settings</h2>
                    <form
                        className="mt-4 grid gap-4 sm:grid-cols-2 xl:grid-cols-4"
                        onSubmit={(event) => {
                            event.preventDefault();
                            settingsForm.put(`${root}/configuration`);
                        }}
                    >
                        <label className="grid gap-1 text-sm">
                            Operating model
                            <select
                                className="border-border bg-surface min-h-11 rounded-lg border px-3"
                                value={settingsForm.data.operating_model}
                                onChange={(e) =>
                                    settingsForm.setData('operating_model', e.currentTarget.value)
                                }
                            >
                                {['k12', 'higher_education', 'academy', 'corporate_learning', 'custom'].map(
                                    (value) => (
                                        <option key={value} value={value}>
                                            {value.replaceAll('_', ' ')}
                                        </option>
                                    ),
                                )}
                            </select>
                        </label>
                        <label className="grid gap-1 text-sm">
                            Timezone
                            <input
                                required
                                className="border-border bg-surface min-h-11 rounded-lg border px-3"
                                value={settingsForm.data.timezone}
                                onChange={(e) => settingsForm.setData('timezone', e.currentTarget.value)}
                            />
                        </label>
                        <label className="grid gap-1 text-sm">
                            Locale
                            <input
                                required
                                className="border-border bg-surface min-h-11 rounded-lg border px-3"
                                value={settingsForm.data.locale}
                                onChange={(e) => settingsForm.setData('locale', e.currentTarget.value)}
                            />
                        </label>
                        <fieldset className="sm:col-span-2 xl:col-span-4">
                            <legend className="text-sm font-medium">Institution terminology</legend>
                            <div className="mt-2 grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
                                {Object.entries(settingsForm.data.terminology).map(([key, label]) => (
                                    <label key={key} className="grid gap-1 text-sm capitalize">
                                        {key.replaceAll('_', ' ')}
                                        <input
                                            className="border-border bg-surface min-h-11 rounded-lg border px-3"
                                            value={label}
                                            onChange={(e) =>
                                                settingsForm.setData('terminology', {
                                                    ...settingsForm.data.terminology,
                                                    [key]: e.currentTarget.value,
                                                })
                                            }
                                        />
                                    </label>
                                ))}
                            </div>
                        </fieldset>
                        <fieldset className="sm:col-span-2 xl:col-span-4">
                            <legend className="text-sm font-medium">Academic capabilities</legend>
                            <div className="mt-2 flex flex-wrap gap-4">
                                {Object.entries(settingsForm.data.enabled_features).map(([key, enabled]) => (
                                    <label
                                        key={key}
                                        className="flex min-h-11 items-center gap-2 text-sm capitalize"
                                    >
                                        <input
                                            type="checkbox"
                                            checked={enabled}
                                            onChange={(e) =>
                                                settingsForm.setData('enabled_features', {
                                                    ...settingsForm.data.enabled_features,
                                                    [key]: e.currentTarget.checked,
                                                })
                                            }
                                        />
                                        {key.replaceAll('_', ' ')}
                                    </label>
                                ))}
                            </div>
                        </fieldset>
                        {Object.keys(settingsForm.errors).length > 0 && (
                            <div className="sm:col-span-2 xl:col-span-4" role="alert">
                                <FormErrors />
                            </div>
                        )}
                        <div className="sm:col-span-2 xl:col-span-4">
                            <button
                                disabled={settingsForm.processing}
                                className="bg-brand-action focus-ring min-h-11 rounded-lg px-5 text-sm font-semibold text-white disabled:opacity-60"
                            >
                                Save academic settings
                            </button>
                        </div>
                    </form>
                </section>
            ) : null}
            {configuration.enabled_features.calendar ? (
                <>
                    <section className="border-border bg-surface mt-6 rounded-2xl border p-5 sm:p-7">
                        <h2 className="text-lg font-semibold">Create academic year</h2>
                        <p className="text-text-secondary mt-1 text-sm">
                            New academic years begin as drafts. Activate after reviewing dates and periods.
                        </p>
                        <form
                            className="academic-calendar-create-year-form mt-4 grid min-w-0 grid-cols-[minmax(0,1fr)] gap-4 sm:grid-cols-2 xl:grid-cols-4"
                            onSubmit={(event) => {
                                event.preventDefault();
                                yearForm.post(`${root}/years`, {
                                    onSuccess: () =>
                                        yearForm.reset('name', 'code', 'description', 'starts_on', 'ends_on'),
                                });
                            }}
                        >
                            <label className="grid min-w-0 gap-1 text-sm">
                                Name
                                <input
                                    required
                                    className="border-border bg-surface min-h-11 w-full max-w-full min-w-0 rounded-lg border px-3"
                                    value={yearForm.data.name}
                                    onChange={(e) => yearForm.setData('name', e.currentTarget.value)}
                                />
                            </label>
                            <label className="grid min-w-0 gap-1 text-sm">
                                Code
                                <input
                                    required
                                    className="border-border bg-surface min-h-11 w-full max-w-full min-w-0 rounded-lg border px-3"
                                    value={yearForm.data.code}
                                    onChange={(e) => yearForm.setData('code', e.currentTarget.value)}
                                />
                            </label>
                            <label className="grid min-w-0 gap-1 text-sm">
                                Campus scope
                                {campusScope !== null ? (
                                    <span className="border-border bg-canvas flex min-h-11 items-center rounded-lg border px-3">
                                        {campuses[0]?.name ?? 'Assigned campus'}
                                    </span>
                                ) : (
                                    <select
                                        className="border-border bg-surface min-h-11 w-full max-w-full min-w-0 rounded-lg border px-3"
                                        value={yearForm.data.campus_id}
                                        onChange={(e) => yearForm.setData('campus_id', e.currentTarget.value)}
                                    >
                                        <option value="">Institution-wide</option>
                                        {campuses.map((campus) => (
                                            <option value={campus.id} key={campus.id}>
                                                {campus.name}
                                            </option>
                                        ))}
                                    </select>
                                )}
                            </label>
                            <label className="grid min-w-0 gap-1 text-sm">
                                Academic model
                                <select
                                    className="border-border bg-surface min-h-11 w-full max-w-full min-w-0 rounded-lg border px-3"
                                    value={yearForm.data.academic_model}
                                    onChange={(e) =>
                                        yearForm.setData('academic_model', e.currentTarget.value)
                                    }
                                >
                                    {[
                                        'k12',
                                        'higher_education',
                                        'academy',
                                        'corporate_learning',
                                        'custom',
                                    ].map((value) => (
                                        <option key={value} value={value}>
                                            {value.replaceAll('_', ' ')}
                                        </option>
                                    ))}
                                </select>
                            </label>
                            <label className="grid min-w-0 gap-1 text-sm">
                                Starts on
                                <input
                                    required
                                    type="date"
                                    className="border-border bg-surface min-h-11 w-full max-w-full min-w-0 rounded-lg border px-3"
                                    value={yearForm.data.starts_on}
                                    onChange={(e) => yearForm.setData('starts_on', e.currentTarget.value)}
                                />
                            </label>
                            <label className="grid min-w-0 gap-1 text-sm">
                                Ends on
                                <input
                                    required
                                    type="date"
                                    className="border-border bg-surface min-h-11 w-full max-w-full min-w-0 rounded-lg border px-3"
                                    value={yearForm.data.ends_on}
                                    onChange={(e) => yearForm.setData('ends_on', e.currentTarget.value)}
                                />
                            </label>
                            <label className="grid min-w-0 gap-1 text-sm sm:col-span-2">
                                Description
                                <textarea
                                    className="border-border bg-surface min-h-20 w-full max-w-full min-w-0 rounded-lg border px-3 py-2"
                                    value={yearForm.data.description}
                                    onChange={(e) => yearForm.setData('description', e.currentTarget.value)}
                                />
                            </label>
                            {Object.keys(yearForm.errors).length > 0 && (
                                <div className="sm:col-span-2 xl:col-span-4" role="alert">
                                    <FormErrors />
                                </div>
                            )}
                            <div className="sm:col-span-2 xl:col-span-4">
                                <button
                                    disabled={yearForm.processing}
                                    className="bg-brand-action focus-ring min-h-11 rounded-lg px-5 text-sm font-semibold text-white disabled:opacity-60"
                                >
                                    Create draft year
                                </button>
                            </div>
                        </form>
                    </section>
                    <section className="mt-7">
                        <div className="mb-4">
                            <h2 className="text-lg font-semibold">Academic years</h2>
                            <p className="text-text-secondary mt-1 text-sm">
                                Current year selection is unique within its institution or campus scope.
                            </p>
                        </div>
                        <div className="grid gap-4">
                            {years.data.length ? (
                                years.data.map((year) => (
                                    <YearRow
                                        key={year.id}
                                        year={year}
                                        slug={tenant.slug}
                                        periodTypes={periodTypes}
                                        campuses={campuses}
                                        campusScope={campusScope}
                                    />
                                ))
                            ) : (
                                <p className="border-border bg-surface text-text-secondary rounded-2xl border p-6">
                                    No academic years have been configured yet.
                                </p>
                            )}
                        </div>
                        <nav aria-label="Academic year pages" className="mt-5 flex flex-wrap gap-2">
                            {years.links.map((link) => (
                                <a
                                    key={link.label}
                                    aria-current={link.active ? 'page' : undefined}
                                    href={link.url ?? '#'}
                                    className={`border-border rounded-lg border px-3 py-2 text-sm ${link.active ? 'bg-brand-action text-white' : ''}`}
                                >
                                    {link.label.replace(/&laquo;/g, '‹').replace(/&raquo;/g, '›')}
                                </a>
                            ))}
                        </nav>
                    </section>
                </>
            ) : (
                <section className="border-border bg-surface mt-6 rounded-2xl border p-5 sm:p-7">
                    <h2 className="text-lg font-semibold">Academic calendar is disabled</h2>
                    <p className="text-text-secondary mt-1 text-sm">
                        Enable the calendar capability in institution academic settings to manage academic
                        years and periods.
                    </p>
                </section>
            )}
        </WorkspaceLayout>
    );
}
