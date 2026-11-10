import { Head, router, useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';
import { FormErrors } from '@/components/ui/form-errors';
import { PageHeader } from '@/components/ui/page-header';
import { WorkspaceLayout } from '@/layouts/workspace-layout';

type Criterion = { title: string; description: string; maximum_points: string };
type Version = {
    id: number;
    number: number;
    status: string;
    maximum_points: number;
    criteria: { id: number; title: string; maximum_points: number }[];
};
type Rubric = { id: number; name: string; versions: Version[] };
type Props = { tenant: { slug: string }; rubrics: Rubric[] };

function VersionEditor({ tenant, rubric }: { tenant: Props['tenant']; rubric: Rubric }) {
    const latest = rubric.versions[0];
    const form = useForm<{ maximum_points: string; criteria: Criterion[] }>({
        maximum_points: latest ? String(latest.maximum_points) : '',
        criteria: latest?.criteria.map((criterion) => ({
            title: criterion.title,
            description: '',
            maximum_points: String(criterion.maximum_points),
        })) ?? [{ title: '', description: '', maximum_points: '' }],
    });
    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.post(`/t/${tenant.slug}/assignments/rubrics/${rubric.id}/versions`, { preserveScroll: true });
    };

    return (
        <article className="border-border rounded-xl border p-4">
            <h2 className="font-semibold">{rubric.name}</h2>
            <div className="mt-3 grid gap-2">
                {rubric.versions.map((version) => (
                    <div
                        key={version.id}
                        className="bg-surface-soft flex flex-wrap items-center justify-between gap-3 rounded-lg p-3 text-sm"
                    >
                        <span>
                            Version {version.number} · {version.maximum_points} points ·{' '}
                            {version.status.replaceAll('_', ' ')}
                        </span>
                        {version.status === 'draft' && (
                            <button
                                type="button"
                                onClick={() =>
                                    router.post(
                                        `/t/${tenant.slug}/assignments/rubric-versions/${version.id}/publish`,
                                    )
                                }
                                className="border-border focus-ring min-h-10 rounded-lg border px-3 font-semibold"
                            >
                                Publish version
                            </button>
                        )}
                    </div>
                ))}
            </div>
            {latest?.status === 'published' && (
                <form onSubmit={submit} className="border-border mt-4 grid gap-3 border-t pt-4">
                    <h3 className="text-sm font-semibold">Create a new immutable version</h3>
                    <FormErrors errors={form.errors} />
                    <label className="grid gap-1 text-sm">
                        Total points
                        <input
                            required
                            type="number"
                            min="0.01"
                            step="0.01"
                            value={form.data.maximum_points}
                            onChange={(event) => form.setData('maximum_points', event.currentTarget.value)}
                            className="border-border min-h-10 rounded-lg border px-3"
                        />
                    </label>
                    {form.data.criteria.map((criterion, index) => (
                        <fieldset
                            key={index}
                            className="border-border grid gap-2 rounded-lg border p-3 sm:grid-cols-2"
                        >
                            <legend className="px-1 text-sm font-medium">Criterion {index + 1}</legend>
                            <label className="grid gap-1 text-sm">
                                Title
                                <input
                                    required
                                    maxLength={160}
                                    value={criterion.title}
                                    onChange={(event) =>
                                        form.setData(
                                            'criteria',
                                            form.data.criteria.map((item, itemIndex) =>
                                                itemIndex === index
                                                    ? { ...item, title: event.currentTarget.value }
                                                    : item,
                                            ),
                                        )
                                    }
                                    className="border-border min-h-10 rounded-lg border px-3"
                                />
                            </label>
                            <label className="grid gap-1 text-sm">
                                Points
                                <input
                                    required
                                    type="number"
                                    min="0.01"
                                    step="0.01"
                                    value={criterion.maximum_points}
                                    onChange={(event) =>
                                        form.setData(
                                            'criteria',
                                            form.data.criteria.map((item, itemIndex) =>
                                                itemIndex === index
                                                    ? { ...item, maximum_points: event.currentTarget.value }
                                                    : item,
                                            ),
                                        )
                                    }
                                    className="border-border min-h-10 rounded-lg border px-3"
                                />
                            </label>
                            <label className="grid gap-1 text-sm sm:col-span-2">
                                Description
                                <input
                                    maxLength={2000}
                                    value={criterion.description}
                                    onChange={(event) =>
                                        form.setData(
                                            'criteria',
                                            form.data.criteria.map((item, itemIndex) =>
                                                itemIndex === index
                                                    ? { ...item, description: event.currentTarget.value }
                                                    : item,
                                            ),
                                        )
                                    }
                                    className="border-border min-h-10 rounded-lg border px-3"
                                />
                            </label>
                            {form.data.criteria.length > 1 && (
                                <button
                                    type="button"
                                    onClick={() =>
                                        form.setData(
                                            'criteria',
                                            form.data.criteria.filter((_, itemIndex) => itemIndex !== index),
                                        )
                                    }
                                    className="text-danger-700 focus-ring justify-self-start rounded text-sm underline"
                                >
                                    Remove criterion
                                </button>
                            )}
                        </fieldset>
                    ))}
                    <div className="flex flex-wrap gap-2">
                        <button
                            type="button"
                            onClick={() =>
                                form.setData('criteria', [
                                    ...form.data.criteria,
                                    { title: '', description: '', maximum_points: '' },
                                ])
                            }
                            disabled={form.data.criteria.length >= 20}
                            className="border-border focus-ring min-h-10 rounded-lg border px-3 text-sm"
                        >
                            Add criterion
                        </button>
                        <button
                            disabled={form.processing}
                            className="bg-brand-600 focus-ring min-h-10 rounded-lg px-3 text-sm font-semibold text-white"
                        >
                            Save draft version
                        </button>
                    </div>
                </form>
            )}
        </article>
    );
}

export default function AssignmentRubrics({ tenant, rubrics }: Props) {
    const form = useForm<{ name: string; maximum_points: string; criteria: Criterion[] }>({
        name: '',
        maximum_points: '100',
        criteria: [{ title: '', description: '', maximum_points: '100' }],
    });
    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.post(`/t/${tenant.slug}/assignments/rubrics`, {
            preserveScroll: true,
            onSuccess: () => form.reset(),
        });
    };

    return (
        <WorkspaceLayout title="Assignment rubrics" area="tenant">
            <Head title="Assignment rubrics" />
            <PageHeader
                eyebrow="Coursework"
                title="Rubric library"
                description="Create immutable, criterion-based grading versions for reuse in assignments. Published versions remain fixed for historical grading."
            />
            <div className="mt-6 grid gap-6 xl:grid-cols-[minmax(0,1fr)_minmax(20rem,0.8fr)]">
                <section className="grid content-start gap-4" aria-label="Institution rubrics">
                    {rubrics.length === 0 ? (
                        <p className="border-border bg-surface rounded-xl border p-5 text-sm">
                            No rubrics yet. Create a draft with criterion maximums that add up to the total.
                        </p>
                    ) : (
                        rubrics.map((rubric) => (
                            <VersionEditor key={rubric.id} tenant={tenant} rubric={rubric} />
                        ))
                    )}
                </section>
                <form
                    onSubmit={submit}
                    className="border-border bg-surface grid content-start gap-4 rounded-2xl border p-5"
                >
                    <h2 className="text-lg font-semibold">Create a rubric</h2>
                    <p className="text-text-muted text-sm">
                        Criterion point totals must equal the rubric maximum before publication.
                    </p>
                    <FormErrors errors={form.errors} />
                    <label className="grid gap-1 text-sm">
                        Rubric name
                        <input
                            required
                            minLength={2}
                            maxLength={160}
                            value={form.data.name}
                            onChange={(event) => form.setData('name', event.currentTarget.value)}
                            className="border-border min-h-10 rounded-lg border px-3"
                        />
                    </label>
                    <label className="grid gap-1 text-sm">
                        Total points
                        <input
                            required
                            type="number"
                            min="0.01"
                            step="0.01"
                            value={form.data.maximum_points}
                            onChange={(event) => form.setData('maximum_points', event.currentTarget.value)}
                            className="border-border min-h-10 rounded-lg border px-3"
                        />
                    </label>
                    {form.data.criteria.map((criterion, index) => (
                        <fieldset key={index} className="border-border grid gap-2 rounded-lg border p-3">
                            <legend className="px-1 text-sm font-medium">Criterion {index + 1}</legend>
                            <label className="grid gap-1 text-sm">
                                Title
                                <input
                                    required
                                    maxLength={160}
                                    value={criterion.title}
                                    onChange={(event) =>
                                        form.setData(
                                            'criteria',
                                            form.data.criteria.map((item, itemIndex) =>
                                                itemIndex === index
                                                    ? { ...item, title: event.currentTarget.value }
                                                    : item,
                                            ),
                                        )
                                    }
                                    className="border-border min-h-10 rounded-lg border px-3"
                                />
                            </label>
                            <label className="grid gap-1 text-sm">
                                Description
                                <input
                                    maxLength={2000}
                                    value={criterion.description}
                                    onChange={(event) =>
                                        form.setData(
                                            'criteria',
                                            form.data.criteria.map((item, itemIndex) =>
                                                itemIndex === index
                                                    ? { ...item, description: event.currentTarget.value }
                                                    : item,
                                            ),
                                        )
                                    }
                                    className="border-border min-h-10 rounded-lg border px-3"
                                />
                            </label>
                            <label className="grid gap-1 text-sm">
                                Points
                                <input
                                    required
                                    type="number"
                                    min="0.01"
                                    step="0.01"
                                    value={criterion.maximum_points}
                                    onChange={(event) =>
                                        form.setData(
                                            'criteria',
                                            form.data.criteria.map((item, itemIndex) =>
                                                itemIndex === index
                                                    ? { ...item, maximum_points: event.currentTarget.value }
                                                    : item,
                                            ),
                                        )
                                    }
                                    className="border-border min-h-10 rounded-lg border px-3"
                                />
                            </label>
                            {form.data.criteria.length > 1 && (
                                <button
                                    type="button"
                                    onClick={() =>
                                        form.setData(
                                            'criteria',
                                            form.data.criteria.filter((_, itemIndex) => itemIndex !== index),
                                        )
                                    }
                                    className="text-danger-700 focus-ring justify-self-start rounded text-sm underline"
                                >
                                    Remove criterion
                                </button>
                            )}
                        </fieldset>
                    ))}
                    <div className="flex flex-wrap gap-2">
                        <button
                            type="button"
                            onClick={() =>
                                form.setData('criteria', [
                                    ...form.data.criteria,
                                    { title: '', description: '', maximum_points: '' },
                                ])
                            }
                            disabled={form.data.criteria.length >= 20}
                            className="border-border focus-ring min-h-10 rounded-lg border px-3 text-sm"
                        >
                            Add criterion
                        </button>
                        <button
                            disabled={form.processing}
                            className="bg-brand-600 focus-ring min-h-10 rounded-lg px-4 text-sm font-semibold text-white"
                        >
                            Create draft rubric
                        </button>
                    </div>
                </form>
            </div>
        </WorkspaceLayout>
    );
}
