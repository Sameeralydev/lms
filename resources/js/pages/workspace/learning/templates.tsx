import { Head, Link, router } from '@inertiajs/react';
import { useMemo, useState, type FormEvent } from 'react';
import { Button } from '@/components/ui/button';
import { FormErrors } from '@/components/ui/form-errors';
import { PageHeader } from '@/components/ui/page-header';
import { TextField } from '@/components/ui/text-field';
import { WorkspaceLayout } from '@/layouts/workspace-layout';

type WorkspaceOption = { id: number; code: string; title: string };
type Template = {
    id: number;
    name: string;
    description: string | null;
    institution_id: number;
    current_version: number;
    is_shared: boolean;
    checksum: string | null;
    provenance: {
        source_workspace_id?: number;
        course_version_id?: number;
        curriculum_version_id?: number | null;
    };
    modules: { id: number; title: string; lessons: { id: number; title: string }[] }[];
};

type Props = {
    tenant: { slug: string };
    templates: { data: Template[] };
    workspaces: WorkspaceOption[];
    sourceWorkspaces: WorkspaceOption[];
    shareTargets: { id: number; name: string }[];
    academicYears: { id: number; name: string; starts_on: string | null; ends_on: string | null }[];
};

export default function Templates({
    tenant,
    templates,
    workspaces,
    sourceWorkspaces,
    shareTargets,
    academicYears,
}: Props) {
    const [sourceWorkspaceId, setSourceWorkspaceId] = useState('');
    const [name, setName] = useState('');
    const [description, setDescription] = useState('');
    const [selectedTemplateId, setSelectedTemplateId] = useState<number | null>(
        templates.data[0]?.id ?? null,
    );
    const [destinationId, setDestinationId] = useState('');
    const [selectedModules, setSelectedModules] = useState<number[]>([]);
    const [selectedLessons, setSelectedLessons] = useState<Record<number, number[]>>({});
    const [shareInstitution, setShareInstitution] = useState('');
    const [revisionSources, setRevisionSources] = useState<Record<number, string>>({});
    const [rolloverYearId, setRolloverYearId] = useState('');
    const [rolloverCode, setRolloverCode] = useState('');
    const [rolloverTitle, setRolloverTitle] = useState('');
    const base = `/t/${tenant.slug}/learning/templates`;
    const selectedTemplate = useMemo(
        () => templates.data.find((template) => template.id === selectedTemplateId) ?? null,
        [selectedTemplateId, templates.data],
    );

    function createTemplate(event: FormEvent<HTMLFormElement>) {
        event.preventDefault();
        router.post(
            base,
            { workspace_id: sourceWorkspaceId, name, description },
            {
                onSuccess: () => {
                    setName('');
                    setDescription('');
                },
            },
        );
    }

    function copyTemplate(event: FormEvent<HTMLFormElement>) {
        event.preventDefault();
        if (!selectedTemplate) return;
        router.post(
            `${base}/${selectedTemplate.id}/copies`,
            {
                version: selectedTemplate.current_version,
                destination_workspace_id: destinationId,
                idempotency_key: crypto.randomUUID(),
                module_ids: selectedModules,
                lesson_ids: Object.fromEntries(
                    selectedModules.map((moduleId) => [
                        moduleId,
                        selectedLessons[moduleId] ??
                            selectedTemplate.modules
                                .find((module) => module.id === moduleId)
                                ?.lessons.map((lesson) => lesson.id) ??
                            [],
                    ]),
                ),
            },
            {},
        );
    }

    function toggleModule(moduleId: number, checked: boolean) {
        setSelectedModules((current) =>
            checked ? [...new Set([...current, moduleId])] : current.filter((id) => id !== moduleId),
        );
        if (!checked)
            setSelectedLessons((current) => {
                const next = { ...current };
                delete next[moduleId];
                return next;
            });
    }

    function toggleLesson(moduleId: number, lessonId: number, checked: boolean) {
        setSelectedLessons((current) => {
            const currentIds =
                current[moduleId] ??
                selectedTemplate?.modules
                    .find((module) => module.id === moduleId)
                    ?.lessons.map((lesson) => lesson.id) ??
                [];
            const nextIds = checked
                ? [...new Set([...currentIds, lessonId])]
                : currentIds.filter((id) => id !== lessonId);
            return { ...current, [moduleId]: nextIds };
        });
    }

    function shareTemplate(templateId: number) {
        if (!shareInstitution) return;
        router.post(`${base}/${templateId}/shares`, { institution_id: shareInstitution });
    }

    function rolloverTemplate(event: FormEvent<HTMLFormElement>) {
        event.preventDefault();
        if (!selectedTemplate) return;
        router.post(`${base}/${selectedTemplate.id}/rollovers`, {
            version: selectedTemplate.current_version,
            academic_year_id: rolloverYearId,
            code: rolloverCode,
            title: rolloverTitle,
            idempotency_key: crypto.randomUUID(),
            module_ids: selectedModules,
            lesson_ids: Object.fromEntries(
                selectedModules.map((moduleId) => [
                    moduleId,
                    selectedLessons[moduleId] ??
                        selectedTemplate.modules
                            .find((module) => module.id === moduleId)
                            ?.lessons.map((lesson) => lesson.id) ??
                        [],
                ]),
            ),
        });
    }

    return (
        <WorkspaceLayout title="Course templates" area="tenant">
            <Head title="Course templates" />
            <div className="space-y-6">
                <PageHeader
                    title="Course templates"
                    description="Create immutable, versioned course structures, share them within this tenant, and selectively copy content into a draft workspace. Learner records are never copied."
                    actions={
                        <Link
                            className="focus-ring rounded-lg px-3 py-2 text-sm font-semibold underline"
                            href={`/t/${tenant.slug}/learning`}
                        >
                            Back to learning
                        </Link>
                    }
                />

                <section
                    className="bg-surface border-border rounded-xl border p-5"
                    aria-labelledby="template-create-title"
                >
                    <h2 id="template-create-title" className="text-lg font-semibold">
                        Create a template version
                    </h2>
                    <p className="text-text-muted mt-1 text-sm">
                        The current workspace structure and provenance are captured. Student memberships,
                        progress, and activity responses are excluded.
                    </p>
                    <form className="learning-template-create-form mt-4 grid gap-4" onSubmit={createTemplate}>
                        <label className="grid gap-1 text-sm font-medium">
                            Source workspace
                            <select
                                className="border-border bg-surface focus-ring min-h-11 rounded-lg border px-3"
                                value={sourceWorkspaceId}
                                onChange={(event) => setSourceWorkspaceId(event.target.value)}
                                required
                            >
                                <option value="">Choose a workspace</option>
                                {sourceWorkspaces.map((workspace) => (
                                    <option key={workspace.id} value={workspace.id}>
                                        {workspace.code} · {workspace.title}
                                    </option>
                                ))}
                            </select>
                        </label>
                        <TextField
                            label="Template name"
                            id="template-name"
                            name="name"
                            value={name}
                            onChange={(event) => setName(event.target.value)}
                            required
                            maxLength={180}
                        />
                        <label className="grid gap-1 text-sm font-medium md:col-span-2">
                            Description
                            <textarea
                                className="border-border bg-surface focus-ring min-h-24 rounded-lg border p-3"
                                value={description}
                                onChange={(event) => setDescription(event.target.value)}
                                maxLength={2000}
                            />
                        </label>
                        <FormErrors />
                        <div>
                            <Button type="submit" disabled={!sourceWorkspaceId || !name.trim()}>
                                Create version 1
                            </Button>
                        </div>
                    </form>
                </section>

                <section className="learning-template-library" aria-labelledby="template-library-title">
                    <div className="learning-template-library-header mb-3 flex flex-wrap items-end justify-between gap-3">
                        <div>
                            <h2 id="template-library-title" className="text-lg font-semibold">
                                Template library
                            </h2>
                            <p className="text-text-muted text-sm">
                                Institution-owned and explicitly shared templates in this tenant.
                            </p>
                        </div>
                        <label className="learning-template-destination grid gap-1 text-sm font-medium">
                            Copy into draft workspace
                            <select
                                className="border-border bg-surface focus-ring min-h-11 rounded-lg border px-3"
                                value={destinationId}
                                onChange={(event) => setDestinationId(event.target.value)}
                            >
                                <option value="">Choose destination</option>
                                {workspaces.map((workspace) => (
                                    <option key={workspace.id} value={workspace.id}>
                                        {workspace.code} · {workspace.title}
                                    </option>
                                ))}
                            </select>
                        </label>
                    </div>
                    {templates.data.length === 0 ? (
                        <p className="bg-surface border-border rounded-xl border p-6 text-sm">
                            No templates are available. Create one from an institution workspace to begin.
                        </p>
                    ) : (
                        <div className="learning-template-list grid gap-4">
                            <div className="grid content-start gap-3">
                                {templates.data.map((template) => (
                                    <article
                                        key={template.id}
                                        className={`learning-template-card bg-surface border-border rounded-xl border p-4 ${selectedTemplateId === template.id ? 'ring-brand-action ring-2' : ''}`}
                                    >
                                        <div className="flex flex-wrap items-start justify-between gap-3">
                                            <button
                                                type="button"
                                                className="learning-template-title focus-ring rounded text-left"
                                                onClick={() => {
                                                    setSelectedTemplateId(template.id);
                                                    setSelectedModules([]);
                                                    setSelectedLessons({});
                                                }}
                                            >
                                                <span className="font-semibold">{template.name}</span>
                                                <span className="text-text-muted mt-1 block text-sm">
                                                    Version {template.current_version} ·{' '}
                                                    {template.is_shared
                                                        ? 'Shared by another institution'
                                                        : 'Institution template'}{' '}
                                                    · {template.modules.length} modules
                                                </span>
                                            </button>
                                            <Link
                                                className="focus-ring rounded px-2 py-1 text-sm underline"
                                                href={`${base}/${template.id}/versions`}
                                            >
                                                Version history
                                            </Link>
                                        </div>
                                        {template.description && (
                                            <p className="text-text-muted mt-2 text-sm">
                                                {template.description}
                                            </p>
                                        )}
                                        {!template.is_shared && sourceWorkspaces.length > 0 && (
                                            <form
                                                className="learning-template-version-form mt-3 flex flex-wrap items-end gap-2"
                                                onSubmit={(event) => {
                                                    event.preventDefault();
                                                    router.post(`${base}/${template.id}/versions`, {
                                                        workspace_id: revisionSources[template.id] ?? '',
                                                    });
                                                }}
                                            >
                                                <label className="learning-template-version-source grid gap-1 text-xs font-medium">
                                                    Publish a new immutable version from
                                                    <select
                                                        required
                                                        className="border-border bg-surface focus-ring min-h-10 rounded-lg border px-2 text-sm"
                                                        value={revisionSources[template.id] ?? ''}
                                                        onChange={(event) =>
                                                            setRevisionSources((current) => ({
                                                                ...current,
                                                                [template.id]: event.currentTarget.value,
                                                            }))
                                                        }
                                                    >
                                                        <option value="">Choose source workspace</option>
                                                        {sourceWorkspaces.map((workspace) => (
                                                            <option key={workspace.id} value={workspace.id}>
                                                                {workspace.code} · {workspace.title}
                                                            </option>
                                                        ))}
                                                    </select>
                                                </label>
                                                <Button
                                                    type="submit"
                                                    variant="secondary"
                                                    disabled={!revisionSources[template.id]}
                                                >
                                                    Publish version {template.current_version + 1}
                                                </Button>
                                            </form>
                                        )}
                                        {shareTargets.length > 0 && !template.is_shared && (
                                            <div className="mt-3 flex flex-wrap gap-2">
                                                <label className="sr-only" htmlFor={`share-${template.id}`}>
                                                    Share {template.name} with an institution
                                                </label>
                                                <select
                                                    id={`share-${template.id}`}
                                                    className="border-border bg-surface focus-ring min-h-10 rounded-lg border px-2 text-sm"
                                                    value={shareInstitution}
                                                    onChange={(event) =>
                                                        setShareInstitution(event.target.value)
                                                    }
                                                >
                                                    <option value="">Share with institution…</option>
                                                    {shareTargets.map((institution) => (
                                                        <option key={institution.id} value={institution.id}>
                                                            {institution.name}
                                                        </option>
                                                    ))}
                                                </select>
                                                <Button
                                                    type="button"
                                                    variant="secondary"
                                                    onClick={() => shareTemplate(template.id)}
                                                    disabled={!shareInstitution}
                                                >
                                                    Share template
                                                </Button>
                                            </div>
                                        )}
                                    </article>
                                ))}
                            </div>
                            {selectedTemplate && (
                                <div className="grid gap-4">
                                    <form
                                        className="bg-surface border-border rounded-xl border p-5"
                                        onSubmit={copyTemplate}
                                    >
                                        <h3 className="font-semibold">Preview and select content</h3>
                                        <p className="text-text-muted mt-1 text-sm">
                                            Template version {selectedTemplate.current_version}. Select
                                            modules and optionally choose individual lessons.
                                        </p>
                                        {selectedTemplate.provenance.source_workspace_id && (
                                            <p className="text-text-muted mt-1 text-xs">
                                                Source workspace ID{' '}
                                                {selectedTemplate.provenance.source_workspace_id}; checksum{' '}
                                                {selectedTemplate.checksum?.slice(0, 12)}…
                                            </p>
                                        )}
                                        <div className="mt-4 space-y-4">
                                            {selectedTemplate.modules.map((module) => (
                                                <fieldset
                                                    key={module.id}
                                                    className="border-border rounded-lg border p-3"
                                                >
                                                    <legend className="px-1 font-medium">
                                                        <label className="inline-flex items-center gap-2">
                                                            <input
                                                                type="checkbox"
                                                                checked={selectedModules.includes(module.id)}
                                                                onChange={(event) =>
                                                                    toggleModule(
                                                                        module.id,
                                                                        event.target.checked,
                                                                    )
                                                                }
                                                            />
                                                            {module.title}
                                                        </label>
                                                    </legend>
                                                    {selectedModules.includes(module.id) && (
                                                        <div className="mt-2 grid gap-2 pl-3">
                                                            {module.lessons.map((lesson) => (
                                                                <label
                                                                    key={lesson.id}
                                                                    className="inline-flex items-center gap-2 text-sm"
                                                                >
                                                                    <input
                                                                        type="checkbox"
                                                                        checked={(
                                                                            selectedLessons[module.id] ??
                                                                            module.lessons.map(
                                                                                (item) => item.id,
                                                                            )
                                                                        ).includes(lesson.id)}
                                                                        onChange={(event) =>
                                                                            toggleLesson(
                                                                                module.id,
                                                                                lesson.id,
                                                                                event.target.checked,
                                                                            )
                                                                        }
                                                                    />
                                                                    {lesson.title}
                                                                </label>
                                                            ))}
                                                        </div>
                                                    )}
                                                </fieldset>
                                            ))}
                                        </div>
                                        <FormErrors />
                                        <div className="mt-5">
                                            <Button
                                                type="submit"
                                                disabled={!destinationId || selectedModules.length === 0}
                                            >
                                                Copy selected content
                                            </Button>
                                        </div>
                                    </form>
                                    {!selectedTemplate.is_shared && academicYears.length > 0 && (
                                        <form
                                            className="bg-surface border-border rounded-xl border p-5"
                                            onSubmit={rolloverTemplate}
                                        >
                                            <h3 className="font-semibold">Academic session rollover</h3>
                                            <p className="text-text-muted mt-1 text-sm">
                                                Create a new draft workspace for another academic year from
                                                the selected content. The operation carries course and
                                                curriculum provenance, never learner records.
                                            </p>
                                            <div className="learning-template-rollover-form mt-4 grid gap-3">
                                                <label className="grid gap-1 text-sm font-medium">
                                                    Target academic year
                                                    <select
                                                        required
                                                        value={rolloverYearId}
                                                        onChange={(event) =>
                                                            setRolloverYearId(event.currentTarget.value)
                                                        }
                                                        className="border-border bg-surface min-h-11 rounded-lg border px-3"
                                                    >
                                                        <option value="">Choose academic year</option>
                                                        {academicYears.map((year) => (
                                                            <option key={year.id} value={year.id}>
                                                                {year.name}
                                                                {year.starts_on && year.ends_on
                                                                    ? ` · ${year.starts_on}–${year.ends_on}`
                                                                    : ''}
                                                            </option>
                                                        ))}
                                                    </select>
                                                </label>
                                                <TextField
                                                    label="New workspace code"
                                                    id="rollover-code"
                                                    name="rollover_code"
                                                    value={rolloverCode}
                                                    onChange={(event) => setRolloverCode(event.target.value)}
                                                    required
                                                    maxLength={64}
                                                />
                                                <TextField
                                                    label="New workspace title"
                                                    id="rollover-title"
                                                    name="rollover_title"
                                                    value={rolloverTitle}
                                                    onChange={(event) => setRolloverTitle(event.target.value)}
                                                    required
                                                    maxLength={180}
                                                />
                                            </div>
                                            <FormErrors />
                                            <div className="mt-4">
                                                <Button
                                                    type="submit"
                                                    disabled={
                                                        !rolloverYearId ||
                                                        !rolloverCode.trim() ||
                                                        !rolloverTitle.trim() ||
                                                        selectedModules.length === 0
                                                    }
                                                >
                                                    Create rollover draft
                                                </Button>
                                            </div>
                                        </form>
                                    )}
                                </div>
                            )}
                        </div>
                    )}
                </section>
            </div>
        </WorkspaceLayout>
    );
}
