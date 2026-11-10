import { Link, router, useForm } from '@inertiajs/react';
import type { FormDataConvertible } from '@inertiajs/core';
import type { FormEvent } from 'react';
import { useState } from 'react';
import { MetricCard } from '@/components/ui/metric-card';
import { PageHeader } from '@/components/ui/page-header';
import { WorkspaceLayout } from '@/layouts/workspace-layout';

type Page<T> = { data: T[]; links: { url: string | null; label: string; active: boolean }[]; total?: number };
type Intake = {
    id: number;
    code: string;
    name: string;
    status: string;
    campus: string | null;
    program: string | null;
    deadline: string | null;
    capacity: number | null;
    cycle: string | null;
    academicYearId: number | null;
    campusId: number | null;
    programId: number | null;
    deliveryMode: string;
    locale: string;
    timezone: string;
    applicationsOpenAt: string | null;
    documentsDeadline: string | null;
    offerDeadline: string | null;
    reservedCapacity: number;
    applicationFeeAmount: string | null;
    applicationFeeCurrency: string | null;
    publicDescription: string | null;
    isPublic: boolean;
    eligibilityRules: Record<string, unknown>;
    applications: number;
    submitted: number;
    form: Record<string, unknown> | null;
    formVersionId: number | null;
    formStatus: string | null;
    draftForm: boolean;
    publishedForm: boolean;
};
type Application = {
    id: number;
    number: string;
    status: string;
    eligibility: string;
    createdAt: string;
    submittedAt: string | null;
    intake: string | null;
    applicant: string;
    waitlistPosition: number | null;
};
type Inquiry = {
    id: number;
    reference: string;
    name: string;
    email: string;
    phone: string | null;
    status: string;
    source: string;
    message: string | null;
    intake: string | null;
    createdAt: string;
};
type Props = {
    tenant: { slug: string; name: string };
    settings: {
        enabled: boolean;
        inquiries_enabled: boolean;
        public_applications_enabled: boolean;
        registration_mode: string;
        timezone: string;
        locale: string;
    };
    metrics: Record<string, number | null>;
    intakes: Page<Intake>;
    applications: Page<Application>;
    canManageInquiries: boolean;
    inquiries: Page<Inquiry>;
    campuses: { id: number; name: string; code: string }[];
    years: { id: number; name: string; code: string; campus_id: number | null }[];
    programs: { id: number; title: string; code: string }[];
    filters: {
        q?: string;
        status?: string;
        intake_q?: string;
        intake_status?: string;
        inquiry_q?: string;
        inquiry_status?: string;
    };
    timezone: string;
    academicFeatures: Record<string, boolean>;
};

type FormField = {
    key: string;
    type: string;
    label: string;
    help?: string;
    required?: boolean;
    options?: { value: string; label: string }[];
    document_requirement_code?: string;
};
type FormSection = { key: string; title: string; fields: FormField[] };
type FormDefinition = { title: string; instructions: string; sections: FormSection[] };
const admissionFieldTypes = [
    'short_text',
    'long_text',
    'email',
    'phone',
    'number',
    'date',
    'single_select',
    'multi_select',
    'radio',
    'checkbox',
    'consent',
    'address_group',
    'previous_education',
    'guardian_group',
    'file_upload',
];

function nextFormKey(prefix: string): string {
    const random =
        typeof crypto !== 'undefined' && 'randomUUID' in crypto
            ? crypto.randomUUID().replaceAll('-', '').slice(0, 12)
            : `${Date.now().toString(36)}${Math.random().toString(36).slice(2, 8)}`;
    return `${prefix}_${random}`;
}

function starterForm(intakeName: string): FormDefinition {
    return {
        title: `${intakeName} application`,
        instructions: 'Complete the required information and review it before submitting.',
        sections: [
            {
                key: 'application_details',
                title: 'Application details',
                fields: [
                    {
                        key: 'information_consent',
                        type: 'consent',
                        label: 'I confirm the information supplied is accurate.',
                        required: true,
                    },
                ],
            },
        ],
    };
}

const statusTransitions: Record<string, string[]> = {
    draft: ['scheduled', 'open'],
    scheduled: ['open', 'paused', 'closed'],
    open: ['paused', 'closed'],
    paused: ['open', 'closed'],
    closed: ['archived'],
};

function FormVersionEditor({ intake, slug }: { intake: Intake; slug: string }) {
    const [jsonError, setJsonError] = useState('');
    const initial =
        intake.form && typeof intake.form.title === 'string' && Array.isArray(intake.form.sections)
            ? (intake.form as unknown as FormDefinition)
            : starterForm(intake.name);
    const [definition, setDefinition] = useState<FormDefinition>(initial);
    const [advancedJson, setAdvancedJson] = useState(JSON.stringify(initial, null, 2));
    const [preview, setPreview] = useState(false);
    const updateField = (sectionIndex: number, fieldIndex: number, changes: Partial<FormField>) => {
        setDefinition((current) => ({
            ...current,
            sections: current.sections.map((section, index) =>
                index !== sectionIndex
                    ? section
                    : {
                          ...section,
                          fields: section.fields.map((field, fieldNumber) =>
                              fieldNumber === fieldIndex ? { ...field, ...changes } : field,
                          ),
                      },
            ),
        }));
    };
    const move = <T,>(items: T[], from: number, to: number): T[] => {
        if (to < 0 || to >= items.length) return items;
        const next = [...items];
        const [item] = next.splice(from, 1);
        next.splice(to, 0, item);
        return next;
    };
    const save = (event: FormEvent) => {
        event.preventDefault();
        setJsonError('');
        router.put(
            `/t/${slug}/admissions/intakes/${intake.id}/form`,
            { definition: JSON.parse(JSON.stringify(definition)) as Record<string, FormDataConvertible> },
            {
                preserveScroll: true,
                onError: (errors) =>
                    setJsonError(
                        errors.definition ??
                            Object.values(errors)[0] ??
                            'Please review the form structure and try again.',
                    ),
            },
        );
    };
    const updateSection = (sectionIndex: number, changes: Partial<FormSection>) =>
        setDefinition((current) => ({
            ...current,
            sections: current.sections.map((section, index) =>
                index === sectionIndex ? { ...section, ...changes } : section,
            ),
        }));
    const addSection = () =>
        setDefinition((current) => ({
            ...current,
            sections: [
                ...current.sections,
                {
                    key: nextFormKey('section'),
                    title: `Section ${current.sections.length + 1}`,
                    fields: [
                        {
                            key: nextFormKey('field'),
                            type: 'short_text',
                            label: 'New question',
                            required: false,
                        },
                    ],
                },
            ],
        }));
    const addField = (sectionIndex: number) =>
        updateSection(sectionIndex, {
            fields: [
                ...definition.sections[sectionIndex].fields,
                {
                    key: nextFormKey('field'),
                    type: 'short_text',
                    label: 'New question',
                    required: false,
                },
            ],
        });
    const changeJson = () => {
        try {
            const parsed = JSON.parse(advancedJson) as FormDefinition;
            if (!parsed || typeof parsed.title !== 'string' || !Array.isArray(parsed.sections))
                throw new Error();
            setDefinition(parsed);
            setJsonError('');
        } catch {
            setJsonError('Enter a valid form object with a title and sections.');
        }
    };
    return (
        <details className="border-border mt-4 border-t pt-4">
            <summary className="focus-ring cursor-pointer text-sm font-semibold">
                Application form ·{' '}
                {intake.draftForm
                    ? 'Edit the draft version'
                    : intake.publishedForm
                      ? 'Published versions are immutable; save to create a new draft'
                      : 'No form draft is available'}
            </summary>
            <form onSubmit={save} className="mt-4 grid gap-4">
                <label className="grid gap-1 text-sm font-medium">
                    Form title
                    <input
                        className="border-border bg-surface min-h-10 rounded-lg border px-3"
                        value={definition.title}
                        maxLength={160}
                        onChange={(event) => setDefinition({ ...definition, title: event.target.value })}
                        required
                    />
                </label>
                <label className="grid gap-1 text-sm font-medium">
                    Applicant instructions
                    <textarea
                        className="border-border bg-surface min-h-20 rounded-lg border p-3"
                        value={definition.instructions ?? ''}
                        maxLength={4000}
                        onChange={(event) =>
                            setDefinition({ ...definition, instructions: event.target.value })
                        }
                    />
                </label>
                <div className="grid gap-4">
                    {definition.sections.map((section, sectionIndex) => (
                        <fieldset
                            key={`${section.key}-${sectionIndex}`}
                            className="border-border grid gap-4 rounded-xl border p-4"
                        >
                            <legend className="px-2 font-semibold">Section {sectionIndex + 1}</legend>
                            <div className="grid gap-3 sm:grid-cols-2 xl:grid-cols-5">
                                <label className="grid gap-1 text-xs font-medium">
                                    Section key
                                    <input
                                        className="border-border bg-surface min-h-10 rounded-lg border px-3"
                                        value={section.key}
                                        maxLength={48}
                                        onChange={(event) =>
                                            updateSection(sectionIndex, { key: event.target.value })
                                        }
                                        required
                                    />
                                </label>
                                <label className="grid gap-1 text-xs font-medium">
                                    Section title
                                    <input
                                        className="border-border bg-surface min-h-10 rounded-lg border px-3"
                                        value={section.title}
                                        maxLength={120}
                                        onChange={(event) =>
                                            updateSection(sectionIndex, { title: event.target.value })
                                        }
                                        required
                                    />
                                </label>
                                <button
                                    type="button"
                                    aria-label={`Move section ${section.title} up`}
                                    disabled={sectionIndex === 0}
                                    className="border-border min-h-10 self-end rounded-lg border px-3 disabled:opacity-50"
                                    onClick={() =>
                                        setDefinition({
                                            ...definition,
                                            sections: move(
                                                definition.sections,
                                                sectionIndex,
                                                sectionIndex - 1,
                                            ),
                                        })
                                    }
                                >
                                    Move up
                                </button>
                                <button
                                    type="button"
                                    aria-label={`Move section ${section.title} down`}
                                    disabled={sectionIndex === definition.sections.length - 1}
                                    className="border-border min-h-10 self-end rounded-lg border px-3 disabled:opacity-50"
                                    onClick={() =>
                                        setDefinition({
                                            ...definition,
                                            sections: move(
                                                definition.sections,
                                                sectionIndex,
                                                sectionIndex + 1,
                                            ),
                                        })
                                    }
                                >
                                    Move down
                                </button>
                                <button
                                    type="button"
                                    aria-label={`Remove section ${section.title}`}
                                    disabled={definition.sections.length <= 1}
                                    className="border-border min-h-10 self-end rounded-lg border px-3 disabled:opacity-50"
                                    onClick={() =>
                                        setDefinition({
                                            ...definition,
                                            sections: definition.sections.filter(
                                                (_, index) => index !== sectionIndex,
                                            ),
                                        })
                                    }
                                >
                                    Remove section
                                </button>
                            </div>
                            <div className="grid gap-3">
                                {section.fields.map((field, fieldIndex) => (
                                    <div
                                        key={`${field.key}-${fieldIndex}`}
                                        className="bg-canvas grid gap-3 rounded-lg p-3 sm:grid-cols-2"
                                    >
                                        <label className="grid gap-1 text-xs font-medium">
                                            Field key
                                            <input
                                                className="border-border bg-surface min-h-10 rounded-lg border px-3"
                                                value={field.key}
                                                maxLength={48}
                                                onChange={(event) =>
                                                    updateField(sectionIndex, fieldIndex, {
                                                        key: event.target.value,
                                                    })
                                                }
                                                required
                                            />
                                        </label>
                                        <label className="grid gap-1 text-xs font-medium">
                                            Question label
                                            <input
                                                className="border-border bg-surface min-h-10 rounded-lg border px-3"
                                                value={field.label}
                                                maxLength={160}
                                                onChange={(event) =>
                                                    updateField(sectionIndex, fieldIndex, {
                                                        label: event.target.value,
                                                    })
                                                }
                                                required
                                            />
                                        </label>
                                        <label className="grid gap-1 text-xs font-medium">
                                            Response type
                                            <select
                                                className="border-border bg-surface min-h-10 rounded-lg border px-3"
                                                value={field.type}
                                                onChange={(event) => {
                                                    const type = event.target.value;
                                                    updateField(sectionIndex, fieldIndex, {
                                                        type,
                                                        ...(type !== 'file_upload'
                                                            ? { document_requirement_code: undefined }
                                                            : {}),
                                                        ...([
                                                            'single_select',
                                                            'multi_select',
                                                            'radio',
                                                        ].includes(type) && !field.options?.length
                                                            ? {
                                                                  options: [
                                                                      {
                                                                          value: 'option_1',
                                                                          label: 'Option 1',
                                                                      },
                                                                  ],
                                                              }
                                                            : {}),
                                                    });
                                                }}
                                            >
                                                {admissionFieldTypes.map((type) => (
                                                    <option key={type} value={type}>
                                                        {type.replaceAll('_', ' ')}
                                                    </option>
                                                ))}
                                            </select>
                                        </label>
                                        <label className="flex min-h-10 items-center gap-2 text-sm">
                                            <input
                                                type="checkbox"
                                                checked={field.required ?? false}
                                                onChange={(event) =>
                                                    updateField(sectionIndex, fieldIndex, {
                                                        required: event.target.checked,
                                                    })
                                                }
                                            />
                                            Required response
                                        </label>
                                        <label className="grid gap-1 text-xs font-medium sm:col-span-2">
                                            Help text
                                            <input
                                                className="border-border bg-surface min-h-10 rounded-lg border px-3"
                                                value={field.help ?? ''}
                                                maxLength={600}
                                                onChange={(event) =>
                                                    updateField(sectionIndex, fieldIndex, {
                                                        help: event.target.value,
                                                    })
                                                }
                                            />
                                        </label>
                                        {['single_select', 'multi_select', 'radio'].includes(field.type) ? (
                                            <div className="grid gap-2 sm:col-span-2">
                                                <p className="text-xs font-medium">Choices</p>
                                                {(field.options ?? []).map((option, optionIndex) => (
                                                    <div
                                                        key={`${option.value}-${optionIndex}`}
                                                        className="grid gap-2 sm:grid-cols-[1fr_1fr_auto]"
                                                    >
                                                        <input
                                                            aria-label={`Choice value ${optionIndex + 1}`}
                                                            className="border-border bg-surface min-h-10 rounded-lg border px-3"
                                                            value={option.value}
                                                            onChange={(event) =>
                                                                updateField(sectionIndex, fieldIndex, {
                                                                    options: (field.options ?? []).map(
                                                                        (item, index) =>
                                                                            index === optionIndex
                                                                                ? {
                                                                                      ...item,
                                                                                      value: event.target
                                                                                          .value,
                                                                                  }
                                                                                : item,
                                                                    ),
                                                                })
                                                            }
                                                        />
                                                        <input
                                                            aria-label={`Choice label ${optionIndex + 1}`}
                                                            className="border-border bg-surface min-h-10 rounded-lg border px-3"
                                                            value={option.label}
                                                            onChange={(event) =>
                                                                updateField(sectionIndex, fieldIndex, {
                                                                    options: (field.options ?? []).map(
                                                                        (item, index) =>
                                                                            index === optionIndex
                                                                                ? {
                                                                                      ...item,
                                                                                      label: event.target
                                                                                          .value,
                                                                                  }
                                                                                : item,
                                                                    ),
                                                                })
                                                            }
                                                        />
                                                        <button
                                                            type="button"
                                                            className="border-border min-h-10 rounded-lg border px-3"
                                                            onClick={() =>
                                                                updateField(sectionIndex, fieldIndex, {
                                                                    options: (field.options ?? []).filter(
                                                                        (_, index) => index !== optionIndex,
                                                                    ),
                                                                })
                                                            }
                                                        >
                                                            Remove choice
                                                        </button>
                                                    </div>
                                                ))}
                                                <button
                                                    type="button"
                                                    className="border-border min-h-10 justify-self-start rounded-lg border px-3 text-sm"
                                                    onClick={() =>
                                                        updateField(sectionIndex, fieldIndex, {
                                                            options: [
                                                                ...(field.options ?? []),
                                                                {
                                                                    value: `option_${(field.options?.length ?? 0) + 1}`,
                                                                    label: `Option ${(field.options?.length ?? 0) + 1}`,
                                                                },
                                                            ],
                                                        })
                                                    }
                                                >
                                                    Add choice
                                                </button>
                                            </div>
                                        ) : null}
                                        {field.type === 'file_upload' ? (
                                            <label className="grid gap-1 text-xs font-medium sm:col-span-2">
                                                Document requirement code
                                                <input
                                                    className="border-border bg-surface min-h-10 rounded-lg border px-3"
                                                    value={field.document_requirement_code ?? ''}
                                                    maxLength={48}
                                                    onChange={(event) =>
                                                        updateField(sectionIndex, fieldIndex, {
                                                            document_requirement_code: event.target.value,
                                                        })
                                                    }
                                                    required
                                                />
                                            </label>
                                        ) : null}
                                        <div className="flex flex-wrap gap-2 sm:col-span-2">
                                            <button
                                                type="button"
                                                disabled={fieldIndex === 0}
                                                className="border-border min-h-9 rounded-lg border px-3 text-xs disabled:opacity-50"
                                                onClick={() =>
                                                    updateSection(sectionIndex, {
                                                        fields: move(
                                                            section.fields,
                                                            fieldIndex,
                                                            fieldIndex - 1,
                                                        ),
                                                    })
                                                }
                                            >
                                                Move question up
                                            </button>
                                            <button
                                                type="button"
                                                disabled={fieldIndex === section.fields.length - 1}
                                                className="border-border min-h-9 rounded-lg border px-3 text-xs disabled:opacity-50"
                                                onClick={() =>
                                                    updateSection(sectionIndex, {
                                                        fields: move(
                                                            section.fields,
                                                            fieldIndex,
                                                            fieldIndex + 1,
                                                        ),
                                                    })
                                                }
                                            >
                                                Move question down
                                            </button>
                                            <button
                                                type="button"
                                                disabled={section.fields.length <= 1}
                                                className="border-border min-h-9 rounded-lg border px-3 text-xs disabled:opacity-50"
                                                onClick={() =>
                                                    updateSection(sectionIndex, {
                                                        fields: section.fields.filter(
                                                            (_, index) => index !== fieldIndex,
                                                        ),
                                                    })
                                                }
                                            >
                                                Remove question
                                            </button>
                                        </div>
                                    </div>
                                ))}
                                <button
                                    type="button"
                                    className="border-border min-h-10 justify-self-start rounded-lg border px-3 text-sm font-semibold"
                                    onClick={() => addField(sectionIndex)}
                                >
                                    Add question
                                </button>
                            </div>
                        </fieldset>
                    ))}
                    <button
                        type="button"
                        disabled={definition.sections.length >= 12}
                        className="border-border min-h-10 justify-self-start rounded-lg border px-3 text-sm font-semibold disabled:opacity-50"
                        onClick={addSection}
                    >
                        Add section
                    </button>
                </div>
                <section className="border-border bg-surface-soft rounded-xl border p-4">
                    <div className="flex flex-wrap items-center justify-between gap-3">
                        <h3 className="font-semibold">Applicant preview</h3>
                        <button
                            type="button"
                            aria-expanded={preview}
                            className="border-border min-h-10 rounded-lg border px-3 text-sm font-semibold"
                            onClick={() => setPreview(!preview)}
                        >
                            {preview ? 'Hide preview' : 'Show preview'}
                        </button>
                    </div>
                    {preview ? (
                        <div className="mt-4 grid gap-4">
                            <h4 className="text-lg font-semibold">{definition.title || 'Untitled form'}</h4>
                            {definition.instructions ? (
                                <p className="text-text-secondary text-sm">{definition.instructions}</p>
                            ) : null}
                            {definition.sections.map((section) => (
                                <fieldset
                                    key={section.key}
                                    className="border-border grid gap-3 rounded-lg border p-4"
                                >
                                    <legend className="px-2 font-medium">{section.title}</legend>
                                    {section.fields.map((field) => (
                                        <label key={field.key} className="grid gap-1 text-sm">
                                            {field.label}
                                            {field.required ? ' *' : ''}
                                            {[
                                                'long_text',
                                                'address_group',
                                                'previous_education',
                                                'guardian_group',
                                            ].includes(field.type) ? (
                                                <textarea
                                                    disabled
                                                    className="border-border bg-surface min-h-16 rounded-lg border p-2"
                                                />
                                            ) : ['single_select', 'multi_select', 'radio'].includes(
                                                  field.type,
                                              ) ? (
                                                <select
                                                    disabled
                                                    className="border-border bg-surface min-h-10 rounded-lg border px-2"
                                                >
                                                    <option>Choose an option</option>
                                                    {field.options?.map((option) => (
                                                        <option key={option.value}>{option.label}</option>
                                                    ))}
                                                </select>
                                            ) : (
                                                <input
                                                    disabled
                                                    type={
                                                        field.type === 'email' ||
                                                        field.type === 'number' ||
                                                        field.type === 'date'
                                                            ? field.type
                                                            : 'text'
                                                    }
                                                    className="border-border bg-surface min-h-10 rounded-lg border px-2"
                                                />
                                            )}
                                            {field.help ? (
                                                <span className="text-text-muted text-xs">{field.help}</span>
                                            ) : null}
                                        </label>
                                    ))}
                                </fieldset>
                            ))}
                        </div>
                    ) : null}
                </section>
                <details className="border-border rounded-lg border p-3">
                    <summary className="focus-ring cursor-pointer text-sm font-semibold">
                        Advanced JSON editing
                    </summary>
                    <div className="mt-3 grid gap-2">
                        <textarea
                            aria-label={`Advanced JSON form definition for ${intake.name}`}
                            className="border-border bg-surface min-h-72 rounded-lg border p-3 font-mono text-xs"
                            value={advancedJson}
                            onChange={(event) => setAdvancedJson(event.target.value)}
                        />
                        <button
                            type="button"
                            className="border-border min-h-10 justify-self-start rounded-lg border px-3 text-sm font-semibold"
                            onClick={changeJson}
                        >
                            Load JSON into visual editor
                        </button>
                    </div>
                </details>
                {jsonError ? (
                    <p role="alert" className="text-danger text-sm">
                        {jsonError}
                    </p>
                ) : null}
                <div className="flex flex-wrap gap-2">
                    <button className="border-border min-h-10 rounded-lg border px-3 text-sm font-semibold">
                        Save draft
                    </button>
                    <button
                        type="button"
                        disabled={!intake.draftForm}
                        onClick={() =>
                            router.post(
                                `/t/${slug}/admissions/intakes/${intake.id}/form/publish`,
                                {},
                                { preserveScroll: true },
                            )
                        }
                        className="bg-brand-action min-h-10 rounded-lg px-3 text-sm font-semibold text-white disabled:opacity-50"
                    >
                        Publish new version
                    </button>
                </div>
                <p className="text-text-muted text-xs">
                    Published versions stay fixed for applications already in progress. The server validates
                    field types, keys, choices, conditions and document requirements before saving.
                </p>
            </form>
        </details>
    );
}

function RequirementEditor({ intake, slug }: { intake: Intake; slug: string }) {
    const form = useForm({
        code: '',
        name: '',
        instructions: '',
        is_required: true,
        allowed_mime_types: ['application/pdf'] as string[],
        maximum_bytes: 5242880,
    });
    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.post(`/t/${slug}/admissions/intakes/${intake.id}/documents/requirements`, {
            preserveScroll: true,
            onSuccess: () => form.reset(),
        });
    };
    return (
        <details className="border-border mt-4 border-t pt-4">
            <summary className="focus-ring cursor-pointer text-sm font-semibold">
                Configure document requirement
            </summary>
            <form onSubmit={submit} className="mt-4 grid gap-3 sm:grid-cols-2">
                <input
                    className="border-border min-h-10 rounded-lg border px-3"
                    aria-label="Document code"
                    placeholder="Code, e.g. transcript"
                    value={form.data.code}
                    onChange={(event) => form.setData('code', event.target.value)}
                    required
                />
                <input
                    className="border-border min-h-10 rounded-lg border px-3"
                    aria-label="Document name"
                    placeholder="Document name"
                    value={form.data.name}
                    onChange={(event) => form.setData('name', event.target.value)}
                    required
                />
                <input
                    className="border-border min-h-10 rounded-lg border px-3 sm:col-span-2"
                    aria-label="Instructions"
                    placeholder="Instructions (optional)"
                    value={form.data.instructions}
                    onChange={(event) => form.setData('instructions', event.target.value)}
                />
                <label className="flex min-h-10 items-center gap-2 text-sm">
                    <input
                        type="checkbox"
                        checked={form.data.is_required}
                        onChange={(event) => form.setData('is_required', event.target.checked)}
                    />
                    Required before submission
                </label>
                <label className="grid gap-1 text-sm">
                    Maximum file size (MB)
                    <input
                        className="border-border min-h-10 rounded-lg border px-3"
                        type="number"
                        min="1"
                        max="10"
                        value={form.data.maximum_bytes / 1048576}
                        onChange={(event) =>
                            form.setData('maximum_bytes', Number(event.target.value) * 1048576)
                        }
                    />
                </label>
                <fieldset className="flex flex-wrap gap-4 text-sm sm:col-span-2">
                    <legend className="mb-2 font-medium">Accepted formats</legend>
                    {[
                        ['application/pdf', 'PDF'],
                        ['image/jpeg', 'JPEG'],
                        ['image/png', 'PNG'],
                    ].map(([value, label]) => (
                        <label key={value} className="flex items-center gap-2">
                            <input
                                type="checkbox"
                                checked={form.data.allowed_mime_types.includes(value)}
                                onChange={(event) =>
                                    form.setData(
                                        'allowed_mime_types',
                                        event.target.checked
                                            ? [...form.data.allowed_mime_types, value]
                                            : form.data.allowed_mime_types.filter((type) => type !== value),
                                    )
                                }
                            />
                            {label}
                        </label>
                    ))}
                </fieldset>
                {form.errors.code || form.errors.name || form.errors.allowed_mime_types ? (
                    <p className="text-danger text-sm sm:col-span-2" role="alert">
                        {form.errors.code ?? form.errors.name ?? form.errors.allowed_mime_types}
                    </p>
                ) : null}
                <button
                    disabled={form.processing || form.data.allowed_mime_types.length === 0}
                    className="bg-brand-action min-h-10 rounded-lg px-3 text-sm font-semibold text-white sm:col-span-2 sm:justify-self-start"
                >
                    Add document requirement
                </button>
            </form>
        </details>
    );
}

function dateTimeLocal(value: string | null, timezone: string): string {
    if (!value) return '';
    const parts = new Intl.DateTimeFormat('en-CA', {
        timeZone: timezone,
        year: 'numeric',
        month: '2-digit',
        day: '2-digit',
        hour: '2-digit',
        minute: '2-digit',
        hourCycle: 'h23',
    })
        .formatToParts(new Date(value))
        .reduce<Record<string, string>>((result, part) => {
            result[part.type] = part.value;
            return result;
        }, {});
    return `${parts.year}-${parts.month}-${parts.day}T${parts.hour}:${parts.minute}`;
}

function IntakeEditor({
    intake,
    slug,
    campuses,
    years,
    programs,
    academicFeatures,
}: {
    intake: Intake;
    slug: string;
    campuses: Props['campuses'];
    years: Props['years'];
    programs: Props['programs'];
    academicFeatures: Props['academicFeatures'];
}) {
    const form = useForm({
        name: intake.name,
        cycle: intake.cycle ?? '',
        academic_year_id: intake.academicYearId?.toString() ?? '',
        campus_id: intake.campusId?.toString() ?? '',
        academic_program_id: intake.programId?.toString() ?? '',
        delivery_mode: intake.deliveryMode,
        locale: intake.locale,
        timezone: intake.timezone,
        applications_open_at: dateTimeLocal(intake.applicationsOpenAt, intake.timezone),
        application_deadline: dateTimeLocal(intake.deadline, intake.timezone),
        documents_deadline: dateTimeLocal(intake.documentsDeadline, intake.timezone),
        offer_deadline: dateTimeLocal(intake.offerDeadline, intake.timezone),
        capacity: intake.capacity?.toString() ?? '',
        reserved_capacity: intake.reservedCapacity.toString(),
        application_fee_amount: intake.applicationFeeAmount ?? '',
        application_fee_currency: intake.applicationFeeCurrency ?? '',
        public_description: intake.publicDescription ?? '',
        is_public: intake.isPublic,
    });
    const update = (event: FormEvent) => {
        event.preventDefault();
        form.transform((data) => ({
            ...data,
            academic_year_id: data.academic_year_id || null,
            campus_id: data.campus_id || null,
            academic_program_id: data.academic_program_id || null,
            applications_open_at: data.applications_open_at || null,
            application_deadline: data.application_deadline || null,
            documents_deadline: data.documents_deadline || null,
            offer_deadline: data.offer_deadline || null,
            capacity: data.capacity || null,
            application_fee_amount: data.application_fee_amount || null,
            application_fee_currency: data.application_fee_currency || null,
        }));
        form.put(`/t/${slug}/admissions/intakes/${intake.id}`, { preserveScroll: true });
    };

    return (
        <details className="border-border mt-4 border-t pt-4">
            <summary className="focus-ring cursor-pointer text-sm font-semibold">Edit intake details</summary>
            <form onSubmit={update} className="mt-4 grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
                <label className="grid gap-1 text-sm font-medium">
                    Intake name
                    <input
                        className="border-border bg-surface min-h-10 rounded-lg border px-3"
                        value={form.data.name}
                        onChange={(event) => form.setData('name', event.target.value)}
                        required
                    />
                </label>
                <label className="grid gap-1 text-sm font-medium">
                    Cycle
                    <input
                        className="border-border bg-surface min-h-10 rounded-lg border px-3"
                        value={form.data.cycle}
                        onChange={(event) => form.setData('cycle', event.target.value)}
                    />
                </label>
                <label className="grid gap-1 text-sm font-medium">
                    Campus
                    <select
                        className="border-border bg-surface min-h-10 rounded-lg border px-3"
                        value={form.data.campus_id}
                        onChange={(event) => form.setData('campus_id', event.target.value)}
                    >
                        <option value="">All campuses</option>
                        {campuses.map((campus) => (
                            <option key={campus.id} value={campus.id}>
                                {campus.name}
                            </option>
                        ))}
                    </select>
                </label>
                <label className="grid gap-1 text-sm font-medium">
                    Academic year{!academicFeatures.calendar ? ' (calendar feature disabled)' : ''}
                    <select
                        disabled={!academicFeatures.calendar}
                        className="border-border bg-surface min-h-10 rounded-lg border px-3 disabled:opacity-60"
                        value={form.data.academic_year_id}
                        onChange={(event) => form.setData('academic_year_id', event.target.value)}
                    >
                        <option value="">Not assigned</option>
                        {years.map((year) => (
                            <option key={year.id} value={year.id}>
                                {year.name}
                            </option>
                        ))}
                    </select>
                </label>
                <label className="grid gap-1 text-sm font-medium">
                    Program{!academicFeatures.programs ? ' (program feature disabled)' : ''}
                    <select
                        disabled={!academicFeatures.programs}
                        className="border-border bg-surface min-h-10 rounded-lg border px-3 disabled:opacity-60"
                        value={form.data.academic_program_id}
                        onChange={(event) => form.setData('academic_program_id', event.target.value)}
                    >
                        <option value="">General admission</option>
                        {programs.map((program) => (
                            <option key={program.id} value={program.id}>
                                {program.title} ({program.code})
                            </option>
                        ))}
                    </select>
                </label>
                <label className="grid gap-1 text-sm font-medium">
                    Delivery mode
                    <select
                        className="border-border bg-surface min-h-10 rounded-lg border px-3"
                        value={form.data.delivery_mode}
                        onChange={(event) => form.setData('delivery_mode', event.target.value)}
                    >
                        {['on_campus', 'online', 'hybrid', 'distance', 'custom'].map((mode) => (
                            <option key={mode} value={mode}>
                                {mode.replaceAll('_', ' ')}
                            </option>
                        ))}
                    </select>
                </label>
                <label className="grid gap-1 text-sm font-medium">
                    Applications open
                    <input
                        type="datetime-local"
                        className="border-border min-h-10 rounded-lg border px-3"
                        value={form.data.applications_open_at}
                        onChange={(event) => form.setData('applications_open_at', event.target.value)}
                    />
                </label>
                <label className="grid gap-1 text-sm font-medium">
                    Application deadline
                    <input
                        type="datetime-local"
                        className="border-border min-h-10 rounded-lg border px-3"
                        value={form.data.application_deadline}
                        onChange={(event) => form.setData('application_deadline', event.target.value)}
                    />
                </label>
                <label className="grid gap-1 text-sm font-medium">
                    Documents deadline
                    <input
                        type="datetime-local"
                        className="border-border min-h-10 rounded-lg border px-3"
                        value={form.data.documents_deadline}
                        onChange={(event) => form.setData('documents_deadline', event.target.value)}
                    />
                </label>
                <label className="grid gap-1 text-sm font-medium">
                    Capacity
                    <input
                        type="number"
                        min="1"
                        max="100000"
                        className="border-border min-h-10 rounded-lg border px-3"
                        value={form.data.capacity}
                        onChange={(event) => form.setData('capacity', event.target.value)}
                    />
                </label>
                <label className="grid gap-1 text-sm font-medium">
                    Reserved seats
                    <input
                        type="number"
                        min="0"
                        max="100000"
                        className="border-border min-h-10 rounded-lg border px-3"
                        value={form.data.reserved_capacity}
                        onChange={(event) => form.setData('reserved_capacity', event.target.value)}
                    />
                </label>
                <label className="flex min-h-10 items-center gap-3 text-sm font-medium">
                    <input
                        type="checkbox"
                        checked={form.data.is_public}
                        onChange={(event) => form.setData('is_public', event.target.checked)}
                    />
                    Public intake
                </label>
                <label className="grid gap-1 text-sm font-medium sm:col-span-2 xl:col-span-3">
                    Public description
                    <textarea
                        className="border-border min-h-20 rounded-lg border p-3"
                        value={form.data.public_description}
                        onChange={(event) => form.setData('public_description', event.target.value)}
                    />
                </label>
                {Object.keys(form.errors).length > 0 ? (
                    <p role="alert" className="text-danger text-sm sm:col-span-2 xl:col-span-3">
                        {Object.values(form.errors)[0]}
                    </p>
                ) : null}
                <button
                    disabled={form.processing}
                    className="bg-brand-action min-h-10 rounded-lg px-3 text-sm font-semibold text-white disabled:opacity-60 sm:col-span-2 xl:col-span-3 xl:justify-self-start"
                >
                    Save intake changes
                </button>
            </form>
        </details>
    );
}

function InquiryCard({ inquiry, slug }: { inquiry: Inquiry; slug: string }) {
    const [nextStatus, setNextStatus] = useState(
        inquiry.status === 'new' ? 'contacted' : inquiry.status === 'contacted' ? 'qualified' : 'closed',
    );
    const choices =
        inquiry.status === 'new'
            ? ['contacted', 'qualified', 'closed']
            : inquiry.status === 'contacted'
              ? ['qualified', 'closed']
              : inquiry.status === 'qualified'
                ? ['closed']
                : [];
    return (
        <article className="border-border grid gap-4 rounded-xl border p-4 lg:grid-cols-[minmax(0,1fr)_14rem]">
            <div className="min-w-0">
                <div className="flex flex-wrap items-center gap-2">
                    <h3 className="font-semibold">{inquiry.name}</h3>
                    <span className="bg-surface-soft rounded-full px-2 py-1 text-xs capitalize">
                        {inquiry.status.replaceAll('_', ' ')}
                    </span>
                </div>
                <p className="text-text-secondary mt-1 text-sm break-all">
                    {inquiry.email}
                    {inquiry.phone ? ` · ${inquiry.phone}` : ''}
                </p>
                <p className="text-text-muted mt-1 text-xs">
                    {inquiry.reference} · {inquiry.intake ?? 'General inquiry'} ·{' '}
                    {new Date(inquiry.createdAt).toLocaleString()}
                </p>
                {inquiry.message ? (
                    <p className="mt-3 text-sm leading-6 whitespace-pre-wrap">{inquiry.message}</p>
                ) : null}
            </div>
            {choices.length ? (
                <form
                    onSubmit={(event) => {
                        event.preventDefault();
                        router.patch(
                            `/t/${slug}/admissions/inquiries/${inquiry.id}/status`,
                            { status: nextStatus },
                            { preserveScroll: true },
                        );
                    }}
                    className="grid content-start gap-2"
                >
                    <label className="grid gap-1 text-xs font-medium">
                        Next status
                        <select
                            className="border-border bg-surface min-h-10 rounded-lg border px-2 text-sm"
                            value={nextStatus}
                            onChange={(event) => setNextStatus(event.target.value)}
                        >
                            {choices.map((status) => (
                                <option key={status} value={status}>
                                    {status.replaceAll('_', ' ')}
                                </option>
                            ))}
                        </select>
                    </label>
                    <button className="border-border min-h-10 rounded-lg border px-3 text-sm font-semibold">
                        Save status
                    </button>
                </form>
            ) : null}
        </article>
    );
}

export default function AdmissionsIndex({
    tenant,
    settings,
    metrics,
    intakes,
    applications,
    canManageInquiries,
    inquiries,
    campuses,
    years,
    programs,
    filters,
    timezone,
    academicFeatures,
}: Props) {
    const settingsForm = useForm({
        enabled: settings.enabled,
        inquiries_enabled: settings.inquiries_enabled,
        public_applications_enabled: settings.public_applications_enabled,
        registration_mode: settings.registration_mode,
        timezone: settings.timezone,
        locale: settings.locale,
    });
    const intakeForm = useForm({
        code: '',
        name: '',
        cycle: '',
        academic_year_id: '',
        campus_id: '',
        academic_program_id: '',
        delivery_mode: 'on_campus',
        locale: settings.locale,
        timezone,
        applications_open_at: '',
        application_deadline: '',
        documents_deadline: '',
        offer_deadline: '',
        capacity: '',
        reserved_capacity: '0',
        application_fee_amount: '',
        application_fee_currency: '',
        public_description: '',
        is_public: true,
    });
    const submitSettings = (event: FormEvent) => {
        event.preventDefault();
        settingsForm.put(`/t/${tenant.slug}/admissions/settings`, { preserveScroll: true });
    };
    const createIntake = (event: FormEvent) => {
        event.preventDefault();
        intakeForm.transform((data) => ({
            ...data,
            academic_year_id: data.academic_year_id || null,
            campus_id: data.campus_id || null,
            academic_program_id: data.academic_program_id || null,
            capacity: data.capacity || null,
            application_fee_amount: data.application_fee_amount || null,
            application_fee_currency: data.application_fee_currency || null,
        }));
        intakeForm.post(`/t/${tenant.slug}/admissions/intakes`, {
            preserveScroll: true,
            onSuccess: () => {
                intakeForm.reset('code', 'name', 'cycle', 'application_deadline', 'public_description');
                intakeForm.transform((data) => data);
            },
        });
    };
    const search = (event: FormEvent<HTMLFormElement>) => {
        event.preventDefault();
        const data = new FormData(event.currentTarget);
        router.get(
            `/t/${tenant.slug}/admissions`,
            {
                q: data.get('q') || undefined,
                status: data.get('status') || undefined,
                intake_q: filters.intake_q || undefined,
                intake_status: filters.intake_status || undefined,
            },
            { preserveState: true },
        );
    };
    const isWaitlistQueue = filters.status === 'waitlisted';
    const searchIntakes = (event: FormEvent<HTMLFormElement>) => {
        event.preventDefault();
        const data = new FormData(event.currentTarget);
        router.get(
            `/t/${tenant.slug}/admissions`,
            {
                intake_q: data.get('intake_q') || undefined,
                intake_status: data.get('intake_status') || undefined,
                q: filters.q || undefined,
                status: filters.status || undefined,
            },
            { preserveState: true },
        );
    };
    const searchInquiries = (event: FormEvent<HTMLFormElement>) => {
        event.preventDefault();
        const data = new FormData(event.currentTarget);
        router.get(
            `/t/${tenant.slug}/admissions`,
            {
                inquiry_q: data.get('inquiry_q') || undefined,
                inquiry_status: data.get('inquiry_status') || undefined,
                q: filters.q || undefined,
                status: filters.status || undefined,
                intake_q: filters.intake_q || undefined,
                intake_status: filters.intake_status || undefined,
            },
            { preserveState: true },
        );
    };
    return (
        <WorkspaceLayout area="tenant" title="Admissions">
            <PageHeader
                title="Admissions operations"
                description="Configure application access, manage intake campaigns, and review applicant progress. Metrics are drawn from this institution's records."
            />
            <section className="grid gap-3 sm:grid-cols-2 xl:grid-cols-4" aria-label="Admissions metrics">
                <MetricCard
                    label="Intakes"
                    value={metrics.intakes ?? 0}
                    detail={`${metrics.openIntakes ?? 0} open`}
                />
                <MetricCard
                    label="Applications started"
                    value={metrics.applicationsStarted ?? 0}
                    detail={`${metrics.applicationsSubmitted ?? 0} submitted`}
                />
                <MetricCard
                    label="In review"
                    value={metrics.underReview ?? 0}
                    detail={`${metrics.decisionsPending ?? 0} decisions pending`}
                />
                <MetricCard
                    label="Offers accepted"
                    value={metrics.offersAccepted ?? 0}
                    detail={
                        metrics.offersIssued
                            ? `${metrics.offerAcceptanceRate}% of issued offers`
                            : 'No offers issued'
                    }
                />
            </section>
            {canManageInquiries ? (
                <section className="border-border bg-surface mt-7 grid gap-4 rounded-2xl border p-5 sm:p-7">
                    <div>
                        <h2 className="text-xl font-semibold">Admissions inquiries</h2>
                        <p className="text-text-secondary mt-1 text-sm">
                            Review public inquiries and record their operational status. Messages and contact
                            details are visible only to users with inquiry management permission.
                        </p>
                    </div>
                    <form onSubmit={searchInquiries} className="grid gap-3 sm:grid-cols-[1fr_12rem_auto]">
                        <label className="grid gap-1 text-sm font-medium">
                            Search reference, name, or email
                            <input
                                name="inquiry_q"
                                defaultValue={filters.inquiry_q ?? ''}
                                className="border-border bg-surface min-h-10 rounded-lg border px-3"
                                maxLength={120}
                            />
                        </label>
                        <label className="grid gap-1 text-sm font-medium">
                            Status
                            <select
                                name="inquiry_status"
                                defaultValue={filters.inquiry_status ?? ''}
                                className="border-border bg-surface min-h-10 rounded-lg border px-3"
                            >
                                <option value="">All inquiries</option>
                                {['new', 'contacted', 'qualified', 'closed'].map((status) => (
                                    <option key={status} value={status}>
                                        {status.replaceAll('_', ' ')}
                                    </option>
                                ))}
                            </select>
                        </label>
                        <button className="border-border min-h-10 self-end rounded-lg border px-4 text-sm font-semibold">
                            Filter
                        </button>
                    </form>
                    {inquiries.data.length ? (
                        <div className="grid gap-3">
                            {inquiries.data.map((inquiry) => (
                                <InquiryCard
                                    key={`${inquiry.id}-${inquiry.status}`}
                                    inquiry={inquiry}
                                    slug={tenant.slug}
                                />
                            ))}
                        </div>
                    ) : (
                        <p className="text-text-secondary rounded-xl border border-dashed p-5 text-sm">
                            No inquiries match this view.
                        </p>
                    )}
                    {inquiries.links.length > 3 ? (
                        <nav aria-label="Inquiry pages" className="flex flex-wrap gap-2">
                            {inquiries.links.map((link, index) =>
                                link.url ? (
                                    <Link
                                        key={`${link.label}-${index}`}
                                        href={link.url}
                                        preserveScroll
                                        className={`border-border min-h-9 rounded-lg border px-3 py-2 text-xs ${link.active ? 'bg-surface-soft font-semibold' : ''}`}
                                    >
                                        {link.label.replace('&laquo;', '«').replace('&raquo;', '»')}
                                    </Link>
                                ) : null,
                            )}
                        </nav>
                    ) : null}
                </section>
            ) : null}
            <section className="border-border bg-surface mt-7 rounded-2xl border p-5 sm:p-7">
                <h2 className="text-xl font-semibold">Admissions access and policy</h2>
                <p className="text-text-secondary mt-1 text-sm">
                    Public access is disabled until explicitly enabled here.
                </p>
                <form onSubmit={submitSettings} className="mt-5 grid gap-5 sm:grid-cols-2 xl:grid-cols-3">
                    {(
                        [
                            ['enabled', 'Admissions enabled'],
                            ['inquiries_enabled', 'Accept public inquiries'],
                            ['public_applications_enabled', 'Accept public applications'],
                        ] as const
                    ).map(([key, label]) => (
                        <label
                            key={key}
                            className="border-border flex min-h-11 items-center gap-3 rounded-lg border px-3 text-sm font-medium"
                        >
                            <input
                                type="checkbox"
                                checked={settingsForm.data[key]}
                                onChange={(event) => settingsForm.setData(key, event.target.checked)}
                            />
                            {label}
                        </label>
                    ))}
                    <label className="grid gap-2 text-sm font-medium">
                        Registration policy
                        <select
                            disabled={!academicFeatures.calendar}
                            className="border-border bg-surface min-h-11 rounded-lg border px-3"
                            value={settingsForm.data.registration_mode}
                            onChange={(event) =>
                                settingsForm.setData('registration_mode', event.target.value)
                            }
                        >
                            <option value="existing_account">Existing accounts only</option>
                            <option value="open_registration">Allow applicant registration</option>
                        </select>
                    </label>
                    <label className="grid gap-2 text-sm font-medium">
                        Timezone
                        <input
                            className="border-border min-h-11 rounded-lg border px-3"
                            value={settingsForm.data.timezone}
                            onChange={(event) => settingsForm.setData('timezone', event.target.value)}
                            required
                        />
                    </label>
                    <label className="grid gap-2 text-sm font-medium">
                        Locale
                        <input
                            className="border-border min-h-11 rounded-lg border px-3"
                            value={settingsForm.data.locale}
                            onChange={(event) => settingsForm.setData('locale', event.target.value)}
                            required
                        />
                    </label>
                    {settingsForm.errors.enabled ? (
                        <p className="text-danger text-sm sm:col-span-2" role="alert">
                            {settingsForm.errors.enabled}
                        </p>
                    ) : null}
                    <button
                        disabled={settingsForm.processing}
                        className="bg-brand-action min-h-11 rounded-lg px-4 text-sm font-semibold text-white sm:col-span-2 sm:justify-self-start"
                    >
                        Save admissions settings
                    </button>
                </form>
            </section>
            <section className="border-border bg-surface mt-7 rounded-2xl border p-5 sm:p-7">
                <h2 className="text-xl font-semibold">Create an intake</h2>
                <p className="text-text-secondary mt-1 text-sm">
                    Intakes begin as drafts. Publish a form and enable public applications before opening one.
                </p>
                <form onSubmit={createIntake} className="mt-5 grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
                    <label className="grid gap-2 text-sm font-medium">
                        Intake code
                        <input
                            className="border-border min-h-11 rounded-lg border px-3"
                            maxLength={48}
                            value={intakeForm.data.code}
                            onChange={(e) => intakeForm.setData('code', e.target.value)}
                            required
                        />
                        {intakeForm.errors.code ? (
                            <span role="alert" className="text-danger">
                                {intakeForm.errors.code}
                            </span>
                        ) : null}
                    </label>
                    <label className="grid gap-2 text-sm font-medium">
                        Intake name
                        <input
                            className="border-border min-h-11 rounded-lg border px-3"
                            maxLength={160}
                            value={intakeForm.data.name}
                            onChange={(e) => intakeForm.setData('name', e.target.value)}
                            required
                        />
                        {intakeForm.errors.name ? (
                            <span role="alert" className="text-danger">
                                {intakeForm.errors.name}
                            </span>
                        ) : null}
                    </label>
                    <label className="grid gap-2 text-sm font-medium">
                        Cycle
                        <input
                            className="border-border min-h-11 rounded-lg border px-3"
                            placeholder="e.g. Fall 2027"
                            value={intakeForm.data.cycle}
                            onChange={(e) => intakeForm.setData('cycle', e.target.value)}
                        />
                    </label>
                    <label className="grid gap-2 text-sm font-medium">
                        Campus
                        <select
                            disabled={!academicFeatures.programs}
                            className="border-border bg-surface min-h-11 rounded-lg border px-3"
                            value={intakeForm.data.campus_id}
                            onChange={(e) => intakeForm.setData('campus_id', e.target.value)}
                        >
                            <option value="">Institution-wide</option>
                            {campuses.map((item) => (
                                <option key={item.id} value={item.id}>
                                    {item.name} ({item.code})
                                </option>
                            ))}
                        </select>
                    </label>
                    <label className="grid gap-2 text-sm font-medium">
                        Academic year
                        <select
                            className="border-border bg-surface min-h-11 rounded-lg border px-3"
                            value={intakeForm.data.academic_year_id}
                            onChange={(e) => intakeForm.setData('academic_year_id', e.target.value)}
                        >
                            <option value="">Not assigned</option>
                            {years.map((item) => (
                                <option key={item.id} value={item.id}>
                                    {item.name}
                                </option>
                            ))}
                        </select>
                    </label>
                    <label className="grid gap-2 text-sm font-medium">
                        Program
                        <select
                            className="border-border bg-surface min-h-11 rounded-lg border px-3"
                            value={intakeForm.data.academic_program_id}
                            onChange={(e) => intakeForm.setData('academic_program_id', e.target.value)}
                        >
                            <option value="">General admission</option>
                            {programs.map((item) => (
                                <option key={item.id} value={item.id}>
                                    {item.title} ({item.code})
                                </option>
                            ))}
                        </select>
                    </label>
                    <label className="grid gap-2 text-sm font-medium">
                        Delivery mode
                        <select
                            className="border-border bg-surface min-h-11 rounded-lg border px-3"
                            value={intakeForm.data.delivery_mode}
                            onChange={(e) => intakeForm.setData('delivery_mode', e.target.value)}
                        >
                            {['on_campus', 'online', 'hybrid', 'distance', 'custom'].map((mode) => (
                                <option key={mode} value={mode}>
                                    {mode.replaceAll('_', ' ')}
                                </option>
                            ))}
                        </select>
                    </label>
                    <label className="grid gap-2 text-sm font-medium">
                        Applications open
                        <input
                            type="datetime-local"
                            className="border-border min-h-11 rounded-lg border px-3"
                            value={intakeForm.data.applications_open_at}
                            onChange={(e) => intakeForm.setData('applications_open_at', e.target.value)}
                        />
                    </label>
                    <label className="grid gap-2 text-sm font-medium">
                        Application deadline
                        <input
                            type="datetime-local"
                            className="border-border min-h-11 rounded-lg border px-3"
                            value={intakeForm.data.application_deadline}
                            onChange={(e) => intakeForm.setData('application_deadline', e.target.value)}
                        />
                    </label>
                    <label className="grid gap-2 text-sm font-medium">
                        Capacity
                        <input
                            type="number"
                            min="1"
                            max="100000"
                            className="border-border min-h-11 rounded-lg border px-3"
                            value={intakeForm.data.capacity}
                            onChange={(e) => intakeForm.setData('capacity', e.target.value)}
                        />
                    </label>
                    <label className="flex min-h-11 items-center gap-3 text-sm font-medium">
                        <input
                            type="checkbox"
                            checked={intakeForm.data.is_public}
                            onChange={(e) => intakeForm.setData('is_public', e.target.checked)}
                        />
                        Visible on public admissions page
                    </label>
                    <label className="grid gap-2 text-sm font-medium sm:col-span-2 xl:col-span-3">
                        Public description
                        <textarea
                            className="border-border min-h-24 rounded-lg border p-3"
                            value={intakeForm.data.public_description}
                            onChange={(e) => intakeForm.setData('public_description', e.target.value)}
                        />
                    </label>
                    <button
                        disabled={intakeForm.processing}
                        className="bg-brand-action min-h-11 rounded-lg px-4 text-sm font-semibold text-white sm:col-span-2 xl:col-span-3 xl:justify-self-start"
                    >
                        Create draft intake
                    </button>
                </form>
            </section>
            <section className="mt-8">
                <div className="mb-4 flex flex-wrap items-end justify-between gap-3">
                    <div>
                        <h2 className="text-xl font-semibold">Intake directory</h2>
                        <p className="text-text-secondary mt-1 text-sm">
                            Manage versioned forms, document requirements, and intake status.
                        </p>
                    </div>
                </div>
                <form
                    onSubmit={searchIntakes}
                    className="border-border bg-surface mb-4 grid gap-3 rounded-2xl border p-4 sm:grid-cols-[1fr_14rem_auto]"
                >
                    <label className="grid gap-1 text-xs font-medium">
                        Intake name or code
                        <input
                            name="intake_q"
                            defaultValue={filters.intake_q}
                            className="border-border min-h-10 rounded-lg border px-3 text-sm"
                            placeholder="Search intakes"
                        />
                    </label>
                    <label className="grid gap-1 text-xs font-medium">
                        Intake status
                        <select
                            name="intake_status"
                            defaultValue={filters.intake_status ?? ''}
                            className="border-border bg-surface min-h-10 rounded-lg border px-3 text-sm"
                        >
                            <option value="">All statuses</option>
                            {['draft', 'scheduled', 'open', 'paused', 'closed', 'archived'].map((status) => (
                                <option key={status} value={status}>
                                    {status.replaceAll('_', ' ')}
                                </option>
                            ))}
                        </select>
                    </label>
                    <button className="border-border min-h-10 self-end rounded-lg border px-4 text-sm font-semibold">
                        Filter intakes
                    </button>
                </form>
                {intakes.data.length ? (
                    <div className="grid gap-4">
                        {intakes.data.map((intake) => (
                            <article
                                key={intake.id}
                                className="border-border bg-surface rounded-2xl border p-5 sm:p-6"
                            >
                                <div className="flex flex-wrap items-start justify-between gap-4">
                                    <div>
                                        <p className="text-text-muted text-xs">
                                            {intake.code} · {intake.campus ?? 'All campuses'}
                                            {intake.program ? ` · ${intake.program}` : ''}
                                        </p>
                                        <h3 className="mt-1 text-lg font-semibold">{intake.name}</h3>
                                        <p className="text-text-secondary mt-2 text-sm">
                                            {intake.applications} applications · {intake.submitted} submitted
                                            {intake.deadline
                                                ? ` · deadline ${new Date(intake.deadline).toLocaleString()}`
                                                : ''}
                                            {intake.capacity ? ` · capacity ${intake.capacity}` : ''}
                                        </p>
                                    </div>
                                    <span className="bg-surface-soft rounded-full px-3 py-1 text-xs font-semibold capitalize">
                                        {intake.status}
                                    </span>
                                </div>
                                <div className="mt-4 flex flex-wrap gap-2">
                                    {(statusTransitions[intake.status] ?? []).map((status) => (
                                        <button
                                            key={status}
                                            onClick={() =>
                                                router.patch(
                                                    `/t/${tenant.slug}/admissions/intakes/${intake.id}/status`,
                                                    { status },
                                                    { preserveScroll: true },
                                                )
                                            }
                                            className="border-border min-h-10 rounded-lg border px-3 text-sm font-semibold capitalize"
                                        >
                                            Mark {status}
                                        </button>
                                    ))}
                                    {intake.publishedForm ? (
                                        <span className="bg-emerald-50 px-3 py-2 text-xs font-semibold text-emerald-900">
                                            Form published
                                        </span>
                                    ) : (
                                        <span className="bg-amber-50 px-3 py-2 text-xs font-semibold text-amber-900">
                                            Form draft
                                        </span>
                                    )}
                                </div>
                                <IntakeEditor
                                    intake={intake}
                                    slug={tenant.slug}
                                    campuses={campuses}
                                    years={years}
                                    programs={programs}
                                    academicFeatures={academicFeatures}
                                />
                                <FormVersionEditor intake={intake} slug={tenant.slug} />
                                <RequirementEditor intake={intake} slug={tenant.slug} />
                            </article>
                        ))}
                    </div>
                ) : (
                    <div className="border-border bg-surface rounded-2xl border p-6">
                        <p className="font-semibold">No intakes yet</p>
                        <p className="text-text-secondary mt-1 text-sm">
                            Create a draft intake above to start configuring admissions.
                        </p>
                    </div>
                )}
                <nav aria-label="Intake pages" className="mt-4 flex flex-wrap gap-2">
                    {intakes.links.map((link, index) =>
                        link.url ? (
                            <Link
                                key={index}
                                href={link.url}
                                className={`rounded-md px-3 py-2 text-xs ${link.active ? 'bg-brand-action text-white' : 'border-border border'}`}
                            >
                                {link.label.replace('&laquo;', '‹').replace('&raquo;', '›')}
                            </Link>
                        ) : null,
                    )}
                </nav>
            </section>
            <section className="mt-8">
                <h2 className="text-xl font-semibold">Application review queue</h2>
                {isWaitlistQueue ? (
                    <p className="text-text-secondary mt-2 text-sm">
                        Waitlisted applications are ordered by submission time. Each promotion requires a new
                        recommendation and a separate approval before an offer can be issued.
                    </p>
                ) : null}
                <form
                    onSubmit={search}
                    className="border-border bg-surface mt-4 grid gap-3 rounded-2xl border p-4 sm:grid-cols-[1fr_14rem_auto]"
                >
                    <label className="grid gap-1 text-xs font-medium">
                        Application reference
                        <input
                            name="q"
                            defaultValue={filters.q}
                            className="border-border min-h-11 rounded-lg border px-3 text-sm"
                            placeholder="Search reference"
                        />
                    </label>
                    <label className="grid gap-1 text-xs font-medium">
                        Status
                        <select
                            name="status"
                            defaultValue={filters.status ?? ''}
                            className="border-border bg-surface min-h-11 rounded-lg border px-3 text-sm"
                        >
                            <option value="">All statuses</option>
                            {[
                                'draft',
                                'submitted',
                                'under_review',
                                'documents_required',
                                'assessment_pending',
                                'interview_pending',
                                'decision_pending',
                                'waitlisted',
                                'offered',
                                'offer_accepted',
                                'rejected',
                            ].map((status) => (
                                <option key={status} value={status}>
                                    {status.replaceAll('_', ' ')}
                                </option>
                            ))}
                        </select>
                    </label>
                    <button className="border-border min-h-11 self-end rounded-lg border px-4 text-sm font-semibold">
                        Filter
                    </button>
                </form>
                {applications.data.length ? (
                    <div className="border-border bg-surface mt-4 overflow-hidden rounded-2xl border">
                        <div className="overflow-x-auto">
                            <table className="w-full min-w-[760px] text-left text-sm">
                                <thead className="bg-surface-soft text-text-secondary">
                                    <tr>
                                        {[
                                            'Reference',
                                            ...(isWaitlistQueue ? ['Waitlist position'] : []),
                                            'Applicant',
                                            'Intake',
                                            'Status',
                                            'Eligibility',
                                            'Submitted',
                                        ].map((title) => (
                                            <th key={title} className="px-5 py-3 font-medium">
                                                {title}
                                            </th>
                                        ))}
                                        <th className="px-5 py-3" />
                                    </tr>
                                </thead>
                                <tbody className="divide-border divide-y">
                                    {applications.data.map((app) => (
                                        <tr key={app.id}>
                                            <td className="px-5 py-4 font-mono text-xs">{app.number}</td>
                                            {isWaitlistQueue ? (
                                                <td className="px-5 py-4 font-medium">
                                                    {app.waitlistPosition ?? '—'}
                                                </td>
                                            ) : null}
                                            <td className="px-5 py-4">{app.applicant || 'Applicant'}</td>
                                            <td className="px-5 py-4">{app.intake ?? '—'}</td>
                                            <td className="px-5 py-4 capitalize">
                                                {app.status.replaceAll('_', ' ')}
                                            </td>
                                            <td className="px-5 py-4 capitalize">
                                                {app.eligibility.replaceAll('_', ' ')}
                                            </td>
                                            <td className="px-5 py-4">
                                                {app.submittedAt
                                                    ? new Date(app.submittedAt).toLocaleDateString()
                                                    : 'Draft'}
                                            </td>
                                            <td className="px-5 py-4">
                                                <Link
                                                    className="text-brand-link font-semibold"
                                                    href={`/t/${tenant.slug}/admissions/applications/${app.id}`}
                                                >
                                                    Review
                                                </Link>
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                        <nav
                            aria-label="Application pages"
                            className="border-border flex flex-wrap gap-2 border-t px-5 py-4"
                        >
                            {applications.links.map((link, index) =>
                                link.url ? (
                                    <Link
                                        key={index}
                                        href={link.url}
                                        className={`rounded-md px-3 py-2 text-xs ${link.active ? 'bg-brand-action text-white' : 'border-border border'}`}
                                    >
                                        {link.label.replace('&laquo;', '‹').replace('&raquo;', '›')}
                                    </Link>
                                ) : null,
                            )}
                        </nav>
                    </div>
                ) : (
                    <div className="border-border bg-surface mt-4 rounded-2xl border p-6">
                        <p className="font-semibold">No applications match</p>
                        <p className="text-text-secondary mt-1 text-sm">
                            Applicant submissions will appear here.
                        </p>
                    </div>
                )}
            </section>
        </WorkspaceLayout>
    );
}
