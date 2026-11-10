import { Link, router, useForm } from '@inertiajs/react';
import type { FormDataConvertible } from '@inertiajs/core';
import type { FormEvent } from 'react';
import { useMemo, useState } from 'react';
import { AdmissionsPublicShell } from '@/components/admissions/public-shell';
import { PageHeader } from '@/components/ui/page-header';
import { TextField } from '@/components/ui/text-field';

type Choice = { value: string; label: string };
type AnswerValue = string | number | boolean | null | AnswerValue[] | { [key: string]: AnswerValue };
type Answers = Record<string, AnswerValue>;
type Field = {
    key: string;
    type: string;
    label: string;
    help?: string;
    required?: boolean;
    options?: Choice[];
    visible_when?: { field: string; operator: string; value: string };
};
type FormDefinition = {
    title: string;
    instructions?: string | null;
    sections: { key: string; title: string; fields: Field[] }[];
};
type Requirement = {
    id: number;
    code: string;
    name: string;
    instructions: string | null;
    required: boolean;
    allowedMimeTypes: string[];
    maximumBytes: number;
};
type Doc = {
    id: number;
    requirementId: number;
    status: string;
    scanStatus: string;
    version: number;
    reviewNote: string | null;
    downloadUrl: string | null;
};
type Props = {
    tenant: { slug: string; name: string };
    canManage: boolean;
    guardianRequired: boolean;
    application: {
        number: string;
        status: string;
        currentStep: number;
        answers: Answers;
        submittedAt: string | null;
        profile: Record<string, string | null>;
        intake: { code: string; name: string; application_deadline: string | null };
        form: FormDefinition;
        revisionCount: number;
    };
    requirements: Requirement[];
    documents: Doc[];
    guardians: {
        id: number;
        name: string;
        email: string;
        relationship: string;
        status: string;
        expiresAt: string | null;
    }[];
    timeline: { from_status: string | null; to_status: string; reason_code: string; occurred_at: string }[];
    offer: { id: number; status: string; acceptBy: string | null; conditions: string | null } | null;
};

function isVisible(field: Field, answers: Record<string, unknown>): boolean {
    const condition = field.visible_when;
    if (!condition) return true;
    const value = answers[condition.field];
    if (condition.operator === 'equals') return value === condition.value;
    if (condition.operator === 'not_equals') return value !== condition.value;
    return condition.operator === 'contains' && Array.isArray(value) && value.includes(condition.value);
}

function FieldControl({
    field,
    value,
    onChange,
    error,
}: {
    field: Field;
    value: unknown;
    onChange: (value: unknown) => void;
    error?: string;
}) {
    const base = 'border-border bg-surface min-h-11 w-full rounded-lg border px-3 py-2';
    const id = `answer-${field.key}`;
    const label = (
        <label htmlFor={id} className="text-text-primary text-sm font-medium">
            {field.label}
            {field.required ? <span aria-hidden="true"> *</span> : null}
        </label>
    );
    const help = field.help ? <p className="text-text-secondary text-xs">{field.help}</p> : null;
    const err = error ? (
        <p role="alert" className="text-danger text-sm">
            {error}
        </p>
    ) : null;
    if (field.type === 'file_upload')
        return (
            <div className="grid gap-2">
                {label}
                <p className="text-text-secondary text-sm">
                    Upload this item in the secure document section below.
                </p>
                {help}
                {err}
            </div>
        );
    if (['short_text', 'email', 'phone', 'number', 'date'].includes(field.type))
        return (
            <div className="grid gap-2">
                {label}
                <input
                    id={id}
                    className={base}
                    type={field.type === 'short_text' ? 'text' : field.type}
                    value={typeof value === 'string' || typeof value === 'number' ? value : ''}
                    required={field.required}
                    onChange={(event) => onChange(event.target.value)}
                />
                {help}
                {err}
            </div>
        );
    if (field.type === 'long_text')
        return (
            <div className="grid gap-2">
                {label}
                <textarea
                    id={id}
                    className={`${base} min-h-28`}
                    value={typeof value === 'string' ? value : ''}
                    required={field.required}
                    onChange={(event) => onChange(event.target.value)}
                />
                {help}
                {err}
            </div>
        );
    if (['single_select', 'radio'].includes(field.type))
        return (
            <div className="grid gap-2">
                {label}
                <select
                    id={id}
                    className={base}
                    value={typeof value === 'string' ? value : ''}
                    required={field.required}
                    onChange={(event) => onChange(event.target.value)}
                >
                    <option value="">Choose an option</option>
                    {(field.options ?? []).map((option) => (
                        <option key={option.value} value={option.value}>
                            {option.label}
                        </option>
                    ))}
                </select>
                {help}
                {err}
            </div>
        );
    if (field.type === 'multi_select')
        return (
            <fieldset className="grid gap-2">
                <legend className="text-text-primary text-sm font-medium">
                    {field.label}
                    {field.required ? ' *' : ''}
                </legend>
                {(field.options ?? []).map((option) => {
                    const selected = Array.isArray(value) && value.includes(option.value);
                    return (
                        <label key={option.value} className="flex min-h-10 items-center gap-3 text-sm">
                            <input
                                type="checkbox"
                                checked={selected}
                                onChange={(event) => {
                                    const current = Array.isArray(value) ? (value as string[]) : [];
                                    onChange(
                                        event.target.checked
                                            ? [...current, option.value]
                                            : current.filter((item) => item !== option.value),
                                    );
                                }}
                            />
                            <span>{option.label}</span>
                        </label>
                    );
                })}
                {help}
                {err}
            </fieldset>
        );
    if (field.type === 'checkbox' || field.type === 'consent')
        return (
            <div className="grid gap-2">
                <label className="flex min-h-11 items-start gap-3 text-sm">
                    <input
                        id={id}
                        className="mt-1 size-4"
                        type="checkbox"
                        checked={value === true || value === '1'}
                        required={field.type === 'consent' && field.required}
                        onChange={(event) => onChange(event.target.checked)}
                    />
                    <span>
                        {field.label}
                        {field.required ? ' *' : ''}
                    </span>
                </label>
                {help}
                {err}
            </div>
        );
    if (field.type === 'date_range') {
        const range = (value && typeof value === 'object' ? value : {}) as Record<string, string>;
        return (
            <fieldset className="grid gap-3 sm:grid-cols-2">
                <legend className="text-text-primary mb-2 text-sm font-medium">
                    {field.label}
                    {field.required ? ' *' : ''}
                </legend>
                {(['from', 'to'] as const).map((key) => (
                    <TextField
                        key={key}
                        label={key === 'from' ? 'From' : 'To'}
                        type="date"
                        value={range[key] ?? ''}
                        onChange={(event) => onChange({ ...range, [key]: event.target.value })}
                    />
                ))}
                {help}
                {err}
            </fieldset>
        );
    }
    if (field.type === 'address_group' || field.type === 'guardian_group') {
        const group = (value && typeof value === 'object' ? value : {}) as Record<string, string>;
        const children =
            field.type === 'address_group'
                ? [
                      ['street', 'Street address', 'text'],
                      ['city', 'City', 'text'],
                      ['region', 'Region / state', 'text'],
                      ['postal_code', 'Postal code', 'text'],
                      ['country', 'Country code', 'text'],
                  ]
                : [
                      ['name', 'Guardian name', 'text'],
                      ['email', 'Guardian email', 'email'],
                      ['relationship', 'Relationship', 'text'],
                  ];
        return (
            <fieldset className="grid gap-3 sm:grid-cols-2">
                <legend className="text-text-primary mb-2 text-sm font-medium">
                    {field.label}
                    {field.required ? ' *' : ''}
                </legend>
                {children.map(([key, title, type]) => (
                    <TextField
                        key={key}
                        label={title}
                        type={type}
                        value={group[key] ?? ''}
                        onChange={(event) => onChange({ ...group, [key]: event.target.value })}
                    />
                ))}
                {help}
                {err}
            </fieldset>
        );
    }
    if (field.type === 'previous_education') {
        const rows = Array.isArray(value) ? (value as Record<string, string>[]) : [];
        const update = (index: number, key: string, text: string) =>
            onChange(rows.map((row, rowIndex) => (rowIndex === index ? { ...row, [key]: text } : row)));
        return (
            <fieldset className="grid gap-4">
                <legend className="text-text-primary text-sm font-medium">
                    {field.label}
                    {field.required ? ' *' : ''}
                </legend>
                {rows.map((row, index) => (
                    <div key={index} className="bg-canvas grid gap-3 rounded-xl p-4 sm:grid-cols-2">
                        {[
                            ['institution', 'Institution'],
                            ['level', 'Level'],
                            ['completed_on', 'Completed'],
                            ['result', 'Result'],
                        ].map(([key, title]) => (
                            <TextField
                                key={key}
                                label={title}
                                value={row[key] ?? ''}
                                onChange={(event) => update(index, key, event.target.value)}
                            />
                        ))}
                        <button
                            type="button"
                            className="text-brand-link text-sm font-semibold sm:col-span-2 sm:justify-self-start"
                            onClick={() => onChange(rows.filter((_, rowIndex) => rowIndex !== index))}
                        >
                            Remove education
                        </button>
                    </div>
                ))}
                <button
                    type="button"
                    disabled={rows.length >= 10}
                    className="border-border min-h-11 justify-self-start rounded-lg border px-4 text-sm font-semibold disabled:opacity-50"
                    onClick={() =>
                        onChange([...rows, { institution: '', level: '', completed_on: '', result: '' }])
                    }
                >
                    Add education
                </button>
                {help}
                {err}
            </fieldset>
        );
    }
    return (
        <div className="grid gap-2">
            {label}
            <p className="text-text-secondary text-sm">
                This field will be supported when the institution publishes its updated application form.
            </p>
            {err}
        </div>
    );
}

function DocumentUpload({
    tenant,
    applicationNumber,
    requirement,
    existing,
}: {
    tenant: string;
    applicationNumber: string;
    requirement: Requirement;
    existing?: Doc;
}) {
    const form = useForm<{ file: File | null }>({ file: null });
    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.post(`/admissions/${tenant}/applications/${applicationNumber}/documents/${requirement.code}`, {
            forceFormData: true,
            onSuccess: () => form.reset(),
        });
    };
    return (
        <form
            onSubmit={submit}
            className="border-border grid gap-3 rounded-xl border p-4 sm:grid-cols-[1fr_auto] sm:items-end"
        >
            <div>
                <h3 className="font-semibold">
                    {requirement.name}
                    {requirement.required ? ' · Required' : ''}
                </h3>
                {requirement.instructions ? (
                    <p className="text-text-secondary mt-1 text-sm">{requirement.instructions}</p>
                ) : null}
                <p className="text-text-muted mt-1 text-xs">
                    Allowed:{' '}
                    {requirement.allowedMimeTypes
                        .map((type) => type.replace('application/', '').replace('image/', ''))
                        .join(', ')}{' '}
                    · up to {Math.round(requirement.maximumBytes / 1024 / 1024)} MB
                    {existing ? ` · Current status: ${existing.status}` : ''}
                </p>
                <input
                    className="mt-3 block max-w-full text-sm"
                    type="file"
                    required={!existing}
                    accept=".pdf,.jpg,.jpeg,.png"
                    onChange={(event) => form.setData('file', event.currentTarget.files?.[0] ?? null)}
                    aria-label={`Upload ${requirement.name}`}
                />
                {form.errors.file ? (
                    <p role="alert" className="text-danger mt-1 text-sm">
                        {form.errors.file}
                    </p>
                ) : null}
            </div>
            <button
                disabled={form.processing || !form.data.file}
                className="bg-brand-action min-h-11 rounded-lg px-4 text-sm font-semibold text-white disabled:opacity-50"
            >
                {form.processing ? 'Uploading…' : existing ? 'Replace file' : 'Upload securely'}
            </button>
        </form>
    );
}

export default function ApplicantApplication({
    tenant,
    canManage,
    guardianRequired,
    application,
    requirements,
    documents,
    guardians,
    timeline,
    offer,
}: Props) {
    const [answers, setAnswers] = useState<Answers>(application.answers);
    const [step] = useState(application.currentStep);
    const [errors, setErrors] = useState<Record<string, string>>({});
    const [saving, setSaving] = useState(false);
    const guardianForm = useForm({
        guardian_name: application.profile.guardian_name ?? '',
        guardian_email: application.profile.guardian_email ?? '',
        relationship: application.profile.guardian_relationship ?? 'parent',
    });
    const save = (event?: FormEvent, submitAfterSave = false) => {
        event?.preventDefault();
        const visibleAnswers = Object.fromEntries(
            Object.entries(answers).filter(([key]) =>
                application.form.sections
                    .flatMap((section) => section.fields)
                    .some((field) => field.key === key && isVisible(field, answers)),
            ),
        );
        setErrors({});
        router.put(
            `/admissions/${tenant.slug}/applications/${application.number}`,
            {
                answers: visibleAnswers as Record<string, FormDataConvertible>,
                step,
            },
            {
                preserveScroll: true,
                onStart: () => setSaving(true),
                onError: (nextErrors) => setErrors(nextErrors as Record<string, string>),
                onSuccess: () => {
                    if (submitAfterSave) {
                        router.post(
                            `/admissions/${tenant.slug}/applications/${application.number}/submit`,
                            {},
                            {
                                preserveScroll: true,
                                onError: (nextErrors) => setErrors(nextErrors as Record<string, string>),
                            },
                        );
                    }
                },
                onFinish: () => setSaving(false),
            },
        );
    };
    const visibleFields = useMemo(
        () =>
            application.form.sections.map((section) => ({
                ...section,
                fields: section.fields.filter((field) => isVisible(field, answers)),
            })),
        [application.form.sections, answers],
    );
    const update = (key: string, value: unknown) => {
        const nextAnswers = { ...answers, [key]: value as AnswerValue };
        const allFields = application.form.sections.flatMap((section) => section.fields);
        for (const answerKey of Object.keys(nextAnswers)) {
            if (!allFields.some((field) => field.key === answerKey && isVisible(field, nextAnswers))) {
                delete nextAnswers[answerKey];
            }
        }
        setAnswers(nextAnswers);
    };
    const submit = () => save(undefined, true);
    const respond = (response: 'accepted' | 'declined') =>
        offer &&
        router.post(
            `/admissions/${tenant.slug}/offers/${offer.id}/response`,
            { response },
            {
                preserveScroll: true,
                onError: (nextErrors) => setErrors(nextErrors as Record<string, string>),
            },
        );
    const editable = canManage && application.status === 'draft';
    const submittedFields = visibleFields.flatMap((section) => section.fields);
    return (
        <AdmissionsPublicShell title={`Application ${application.number}`} tenant={tenant}>
            <PageHeader
                title={application.intake.name}
                description={`Application ${application.number} · ${application.status.replaceAll('_', ' ')}`}
                actions={
                    <Link
                        className="border-border inline-flex min-h-11 items-center rounded-lg border px-4 text-sm font-semibold"
                        href={`/admissions/${tenant.slug}/my-applications`}
                    >
                        Back to applications
                    </Link>
                }
            />
            <div className="mb-6 flex flex-wrap gap-2">
                <span className="bg-surface-soft rounded-full px-3 py-1.5 text-xs font-semibold capitalize">
                    {application.status.replaceAll('_', ' ')}
                </span>
                <span className="text-text-muted px-2 py-1.5 text-xs">
                    {application.revisionCount} saved revisions
                </span>
                {application.intake.application_deadline ? (
                    <span className="text-text-muted px-2 py-1.5 text-xs">
                        Deadline {new Date(application.intake.application_deadline).toLocaleString()}
                    </span>
                ) : null}
            </div>
            {editable ? (
                <form onSubmit={save} className="grid gap-6">
                    {Object.keys(errors).length ? (
                        <p
                            className="text-danger rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm"
                            role="alert"
                        >
                            {Object.values(errors).join(' ')}
                        </p>
                    ) : null}
                    <section className="border-border bg-surface rounded-2xl border p-5 sm:p-7">
                        <h2 className="text-xl font-semibold">{application.form.title}</h2>
                        {application.form.instructions ? (
                            <p className="text-text-secondary mt-2 text-sm leading-6">
                                {application.form.instructions}
                            </p>
                        ) : null}
                        <div className="mt-6 grid gap-8">
                            {visibleFields.map((section) => (
                                <fieldset key={section.key} className="grid gap-4">
                                    <legend className="text-lg font-semibold">{section.title}</legend>
                                    <div className="grid gap-5 sm:grid-cols-2">
                                        {section.fields.map((field) => (
                                            <div
                                                key={field.key}
                                                className={
                                                    [
                                                        'long_text',
                                                        'multi_select',
                                                        'address_group',
                                                        'guardian_group',
                                                        'previous_education',
                                                        'date_range',
                                                    ].includes(field.type)
                                                        ? 'sm:col-span-2'
                                                        : ''
                                                }
                                            >
                                                <FieldControl
                                                    field={field}
                                                    value={answers[field.key]}
                                                    onChange={(value) => update(field.key, value)}
                                                    error={errors[`answers.${field.key}`]}
                                                />
                                            </div>
                                        ))}
                                    </div>
                                </fieldset>
                            ))}
                        </div>
                        {Object.keys(errors).length ? (
                            <p className="text-danger mt-5 text-sm" role="alert">
                                Review the highlighted fields before saving.
                            </p>
                        ) : null}
                    </section>
                    {requirements.length ? (
                        <section className="border-border bg-surface grid gap-4 rounded-2xl border p-5 sm:p-7">
                            <div>
                                <h2 className="text-xl font-semibold">Supporting documents</h2>
                                <p className="text-text-secondary mt-1 text-sm">
                                    Files are stored privately and can only be viewed by you and authorized
                                    reviewers.
                                </p>
                            </div>
                            {requirements.map((requirement) => (
                                <DocumentUpload
                                    key={requirement.code}
                                    tenant={tenant.slug}
                                    applicationNumber={application.number}
                                    requirement={requirement}
                                    existing={documents.find(
                                        (document) => document.requirementId === requirement.id,
                                    )}
                                />
                            ))}
                        </section>
                    ) : null}
                    <div className="flex flex-wrap justify-end gap-3">
                        <button
                            disabled={saving}
                            type="submit"
                            className="border-border min-h-11 rounded-lg border px-4 text-sm font-semibold"
                        >
                            {saving ? 'Saving…' : 'Save draft'}
                        </button>
                        <button
                            type="button"
                            onClick={submit}
                            disabled={saving}
                            className="bg-brand-action min-h-11 rounded-lg px-4 text-sm font-semibold text-white"
                        >
                            Review and submit
                        </button>
                    </div>
                </form>
            ) : (
                <div className="grid gap-6 lg:grid-cols-[minmax(0,1fr)_18rem]">
                    {Object.keys(errors).length ? (
                        <p
                            className="text-danger rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm lg:col-span-2"
                            role="alert"
                        >
                            {Object.values(errors).join(' ')}
                        </p>
                    ) : null}
                    <div className="grid gap-6">
                        <section className="border-border bg-surface rounded-2xl border p-5 sm:p-7">
                            <h2 className="text-xl font-semibold">Submitted answers</h2>
                            <dl className="mt-5 grid gap-4 sm:grid-cols-2">
                                {submittedFields.map((field) => (
                                    <div key={field.key} className="border-border border-b pb-3">
                                        <dt className="text-text-muted text-xs">{field.label}</dt>
                                        <dd className="mt-1 text-sm whitespace-pre-wrap">
                                            {Array.isArray(application.answers[field.key])
                                                ? (application.answers[field.key] as unknown[]).join(', ')
                                                : typeof application.answers[field.key] === 'object'
                                                  ? JSON.stringify(application.answers[field.key])
                                                  : String(application.answers[field.key] ?? '—')}
                                        </dd>
                                    </div>
                                ))}
                            </dl>
                        </section>
                        {offer ? (
                            <section className="border-border bg-surface rounded-2xl border p-5">
                                <h2 className="text-lg font-semibold">Admission offer</h2>
                                <p className="text-text-secondary mt-2 text-sm">
                                    Status: {offer.status}
                                    {offer.acceptBy
                                        ? ` · Respond by ${new Date(offer.acceptBy).toLocaleString()}`
                                        : ''}
                                </p>
                                {offer.conditions ? (
                                    <p className="mt-3 text-sm whitespace-pre-wrap">{offer.conditions}</p>
                                ) : null}
                                {canManage && offer.status === 'issued' ? (
                                    <div className="mt-4 flex flex-wrap gap-3">
                                        <button
                                            onClick={() => respond('accepted')}
                                            className="bg-brand-action min-h-11 rounded-lg px-4 text-sm font-semibold text-white"
                                        >
                                            Accept offer
                                        </button>
                                        <button
                                            onClick={() => respond('declined')}
                                            className="border-border min-h-11 rounded-lg border px-4 text-sm font-semibold"
                                        >
                                            Decline
                                        </button>
                                    </div>
                                ) : null}
                            </section>
                        ) : null}
                        <section className="border-border bg-surface rounded-2xl border p-5">
                            <h2 className="text-lg font-semibold">Application timeline</h2>
                            <ol className="mt-4 grid gap-4">
                                {timeline.map((event, index) => (
                                    <li
                                        key={`${event.to_status}-${event.occurred_at}-${index}`}
                                        className="border-border border-l-2 pl-4"
                                    >
                                        <p className="font-medium capitalize">
                                            {event.to_status.replaceAll('_', ' ')}
                                        </p>
                                        <p className="text-text-muted mt-1 text-xs">
                                            {new Date(event.occurred_at).toLocaleString()}
                                        </p>
                                    </li>
                                ))}
                            </ol>
                        </section>
                    </div>
                    <aside className="border-border bg-surface h-fit rounded-2xl border p-5">
                        <h2 className="font-semibold">Documents</h2>
                        <ul className="mt-3 grid gap-3">
                            {requirements.map((requirement) => {
                                const document = documents.find(
                                    (item) => item.requirementId === requirement.id,
                                );
                                return (
                                    <li
                                        key={requirement.code}
                                        className="border-border border-b pb-3 text-sm"
                                    >
                                        <p className="font-medium">{requirement.name}</p>
                                        <p className="text-text-muted mt-1 capitalize">
                                            {document?.status ??
                                                (requirement.required
                                                    ? 'Required · not uploaded'
                                                    : 'Optional · not uploaded')}
                                        </p>
                                        {document ? (
                                            <p className="text-text-muted mt-1 text-xs">
                                                File scan: {document.scanStatus.replaceAll('_', ' ')}.
                                                Unverified files remain in private quarantine.
                                            </p>
                                        ) : null}
                                        {document?.downloadUrl ? (
                                            <Link
                                                className="text-brand-link mt-2 inline-block font-semibold"
                                                href={document.downloadUrl}
                                            >
                                                Download uploaded document
                                            </Link>
                                        ) : null}
                                    </li>
                                );
                            })}
                        </ul>
                        {application.submittedAt ? (
                            <p className="text-text-muted mt-4 text-xs">
                                Submitted {new Date(application.submittedAt).toLocaleString()}
                            </p>
                        ) : null}
                    </aside>
                </div>
            )}
            {canManage && (guardianRequired || guardians.length > 0) ? (
                <section className="border-border bg-surface mt-6 grid gap-4 rounded-2xl border p-5 sm:p-7">
                    <div>
                        <h2 className="text-lg font-semibold">Guardian authorization</h2>
                        <p className="text-text-secondary mt-1 text-sm">
                            A guardian must verify their email and the institution must verify the
                            relationship. A contact name or checkbox alone does not grant access or count as
                            consent.
                        </p>
                    </div>
                    {guardians.map((guardian) => (
                        <div
                            key={guardian.id}
                            className="border-border flex flex-wrap items-center justify-between gap-3 rounded-xl border p-4"
                        >
                            <div>
                                <p className="font-medium">
                                    {guardian.name} · {guardian.relationship.replaceAll('_', ' ')}
                                </p>
                                <p className="text-text-muted text-sm">
                                    {guardian.email} · {guardian.status.replaceAll('_', ' ')}
                                </p>
                            </div>
                            {['invited', 'guardian_verified', 'active'].includes(guardian.status) ? (
                                <button
                                    type="button"
                                    className="border-border min-h-10 rounded-lg border px-3 text-sm font-semibold"
                                    onClick={() =>
                                        router.post(
                                            `/admissions/${tenant.slug}/applications/${application.number}/guardians/${guardian.id}/revoke`,
                                            {},
                                            { preserveScroll: true },
                                        )
                                    }
                                >
                                    Revoke access
                                </button>
                            ) : null}
                        </div>
                    ))}
                    {guardians.some((guardian) => guardian.status === 'active') ? (
                        <p className="text-success text-sm" role="status">
                            A guardian relationship is active. The applicant may submit when the form is
                            complete.
                        </p>
                    ) : null}
                    {guardianRequired &&
                    application.status === 'draft' &&
                    !guardians.some((guardian) => guardian.status === 'active') ? (
                        <form
                            onSubmit={(event) => {
                                event.preventDefault();
                                guardianForm.post(
                                    `/admissions/${tenant.slug}/applications/${application.number}/guardians`,
                                    { preserveScroll: true },
                                );
                            }}
                            className="grid gap-4 sm:grid-cols-2"
                        >
                            <TextField
                                label="Guardian name"
                                name="guardian_name"
                                required
                                value={guardianForm.data.guardian_name}
                                onChange={(event) =>
                                    guardianForm.setData('guardian_name', event.target.value)
                                }
                                error={guardianForm.errors.guardian_name}
                            />
                            <TextField
                                label="Guardian email"
                                name="guardian_email"
                                type="email"
                                required
                                value={guardianForm.data.guardian_email}
                                onChange={(event) =>
                                    guardianForm.setData('guardian_email', event.target.value)
                                }
                                error={guardianForm.errors.guardian_email}
                            />
                            <label className="grid gap-1 text-sm font-medium sm:col-span-2">
                                Relationship
                                <select
                                    className="border-border bg-surface min-h-11 rounded-lg border px-3"
                                    value={guardianForm.data.relationship}
                                    onChange={(event) =>
                                        guardianForm.setData('relationship', event.target.value)
                                    }
                                >
                                    <option value="mother">Mother</option>
                                    <option value="father">Father</option>
                                    <option value="parent">Parent</option>
                                    <option value="legal_guardian">Legal guardian</option>
                                    <option value="other">Other</option>
                                </select>
                            </label>
                            {Object.keys(guardianForm.errors).length ? (
                                <p className="text-danger text-sm sm:col-span-2" role="alert">
                                    Review the guardian request details.
                                </p>
                            ) : null}
                            <button
                                disabled={guardianForm.processing}
                                className="bg-brand-action min-h-11 justify-self-start rounded-lg px-4 text-sm font-semibold text-white disabled:opacity-60 sm:col-span-2"
                            >
                                {guardianForm.processing ? 'Sending request…' : 'Send verification request'}
                            </button>
                        </form>
                    ) : null}
                </section>
            ) : null}
            {!canManage ? (
                <section className="border-border bg-surface mt-6 rounded-2xl border p-5" aria-live="polite">
                    <h2 className="font-semibold">Guardian access</h2>
                    <p className="text-text-secondary mt-2 text-sm">
                        You have read-only access as a verified guardian. The applicant can revoke this access
                        at any time.
                    </p>
                    {guardians.map((guardian) => (
                        <button
                            key={guardian.id}
                            type="button"
                            className="border-border mt-4 min-h-10 rounded-lg border px-3 text-sm font-semibold"
                            onClick={() =>
                                router.post(
                                    `/admissions/${tenant.slug}/applications/${application.number}/guardians/${guardian.id}/revoke`,
                                    {},
                                    { preserveScroll: true },
                                )
                            }
                        >
                            Revoke my access
                        </button>
                    ))}
                </section>
            ) : null}
        </AdmissionsPublicShell>
    );
}
