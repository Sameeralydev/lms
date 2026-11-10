import { Head, router, useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';
import { FormErrors } from '@/components/ui/form-errors';
import { PageHeader } from '@/components/ui/page-header';
import { WorkspaceLayout } from '@/layouts/workspace-layout';

type Section = { id: number; code: string; campus_id: number | null };
type Offering = { id: number; code: string; course: string; timezone: string; sections: Section[] };
type Rubric = { id: number; name: string; version: number; maximum_points: number };
type Props = { tenant: { slug: string }; offerings: Offering[]; rubrics: Rubric[]; types: string[] };

export default function CreateAssignment({ tenant, offerings, rubrics, types }: Props) {
    const form = useForm({
        offering_id: '',
        section_ids: [] as string[],
        title: '',
        assignment_type: 'coursework',
        category: '',
        summary: '',
        instructions: '',
        submission_mode: 'text',
        collaboration_mode: 'individual',
        maximum_attempts: '1',
        maximum_points: '',
        rubric_version_id: '',
        timezone: 'UTC',
        available_at: '',
        due_at: '',
        late_until: '',
        review_required: false,
    });
    const selectedOffering = offerings.find((offering) => String(offering.id) === form.data.offering_id);
    const selectedRubric = rubrics.find((rubric) => String(rubric.id) === form.data.rubric_version_id);
    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.post(`/t/${tenant.slug}/assignments`, {
            preserveScroll: true,
            onSuccess: () => router.visit(`/t/${tenant.slug}/assignments`),
        });
    };
    return (
        <WorkspaceLayout title="Create assignment" area="tenant">
            <Head title="Create assignment" />
            <PageHeader
                eyebrow="Coursework"
                title="Create assignment"
                description="Start with a draft. Publishing freezes a version and snapshots the eligible learners in the selected sections."
            />
            <form
                onSubmit={submit}
                className="border-border bg-surface mt-6 grid gap-5 rounded-2xl border p-5 sm:p-7"
            >
                <FormErrors errors={form.errors} />
                {offerings.length === 0 ? (
                    <p className="bg-surface-soft rounded-xl p-4 text-sm">
                        There are no authorized active course sections available for assignment.
                    </p>
                ) : (
                    <>
                        <label className="grid gap-1.5 text-sm font-medium">
                            Course offering
                            <select
                                required
                                value={form.data.offering_id}
                                onChange={(event) => {
                                    const offering = offerings.find(
                                        (item) => String(item.id) === event.currentTarget.value,
                                    );
                                    form.setData((data) => ({
                                        ...data,
                                        offering_id: event.currentTarget.value,
                                        section_ids: [],
                                        timezone: offering?.timezone ?? 'UTC',
                                    }));
                                }}
                                className="border-border bg-surface min-h-11 rounded-lg border px-3"
                            >
                                <option value="">Choose an offering</option>
                                {offerings.map((offering) => (
                                    <option key={offering.id} value={offering.id}>
                                        {offering.code} · {offering.course}
                                    </option>
                                ))}
                            </select>
                        </label>
                        <fieldset className="grid gap-2">
                            <legend className="text-sm font-medium">Target sections</legend>
                            {selectedOffering?.sections.length ? (
                                selectedOffering.sections.map((section) => (
                                    <label
                                        key={section.id}
                                        className="border-border flex min-h-11 items-center gap-3 rounded-lg border px-3 text-sm"
                                    >
                                        <input
                                            type="checkbox"
                                            checked={form.data.section_ids.includes(String(section.id))}
                                            onChange={(event) =>
                                                form.setData(
                                                    'section_ids',
                                                    event.currentTarget.checked
                                                        ? [...form.data.section_ids, String(section.id)]
                                                        : form.data.section_ids.filter(
                                                              (id) => id !== String(section.id),
                                                          ),
                                                )
                                            }
                                        />
                                        Section {section.code}
                                    </label>
                                ))
                            ) : (
                                <p className="text-text-muted text-sm">Choose an offering with sections.</p>
                            )}
                        </fieldset>
                        <div className="grid gap-4 sm:grid-cols-2">
                            <label className="grid gap-1.5 text-sm font-medium sm:col-span-2">
                                Title
                                <input
                                    required
                                    maxLength={200}
                                    value={form.data.title}
                                    onChange={(event) => form.setData('title', event.currentTarget.value)}
                                    className="border-border bg-surface min-h-11 rounded-lg border px-3"
                                />
                            </label>
                            <label className="grid gap-1.5 text-sm font-medium">
                                Type
                                <select
                                    value={form.data.assignment_type}
                                    onChange={(event) =>
                                        form.setData('assignment_type', event.currentTarget.value)
                                    }
                                    className="border-border bg-surface min-h-11 rounded-lg border px-3"
                                >
                                    {types.map((type) => (
                                        <option key={type} value={type}>
                                            {type.replaceAll('_', ' ')}
                                        </option>
                                    ))}
                                </select>
                            </label>
                            <label className="grid gap-1.5 text-sm font-medium">
                                Category
                                <input
                                    maxLength={100}
                                    value={form.data.category}
                                    onChange={(event) => form.setData('category', event.currentTarget.value)}
                                    className="border-border bg-surface min-h-11 rounded-lg border px-3"
                                />
                            </label>
                            <label className="grid gap-1.5 text-sm font-medium sm:col-span-2">
                                Summary
                                <textarea
                                    maxLength={1000}
                                    rows={2}
                                    value={form.data.summary}
                                    onChange={(event) => form.setData('summary', event.currentTarget.value)}
                                    className="border-border bg-surface rounded-lg border p-3"
                                />
                            </label>
                            <label className="grid gap-1.5 text-sm font-medium sm:col-span-2">
                                Instructions
                                <textarea
                                    maxLength={20000}
                                    rows={7}
                                    value={form.data.instructions}
                                    onChange={(event) =>
                                        form.setData('instructions', event.currentTarget.value)
                                    }
                                    className="border-border bg-surface rounded-lg border p-3"
                                />
                                <span className="text-text-muted text-xs">
                                    Plain text is rendered as text. HTML and scripts are not interpreted.
                                </span>
                            </label>
                            <label className="grid gap-1.5 text-sm font-medium">
                                Response mode
                                <select
                                    value={form.data.submission_mode}
                                    onChange={(event) =>
                                        form.setData('submission_mode', event.currentTarget.value)
                                    }
                                    className="border-border bg-surface min-h-11 rounded-lg border px-3"
                                >
                                    <option value="text">Text response</option>
                                    <option value="url">Reference URL</option>
                                    <option value="mixed">Text and URL</option>
                                    <option value="file">File submission</option>
                                </select>
                            </label>
                            <label className="grid gap-1.5 text-sm font-medium">
                                Collaboration
                                <select
                                    value={form.data.collaboration_mode}
                                    onChange={(event) =>
                                        form.setData('collaboration_mode', event.currentTarget.value)
                                    }
                                    className="border-border bg-surface min-h-11 rounded-lg border px-3"
                                >
                                    <option value="individual">Individual work</option>
                                    <option value="group">Group work (instructor-created groups)</option>
                                </select>
                            </label>
                            <label className="grid gap-1.5 text-sm font-medium">
                                Maximum attempts
                                <input
                                    required
                                    type="number"
                                    min={1}
                                    max={20}
                                    value={form.data.maximum_attempts}
                                    onChange={(event) =>
                                        form.setData('maximum_attempts', event.currentTarget.value)
                                    }
                                    className="border-border bg-surface min-h-11 rounded-lg border px-3"
                                />
                            </label>
                            <label className="grid gap-1.5 text-sm font-medium">
                                Maximum points
                                <input
                                    type="number"
                                    min={0}
                                    step="0.01"
                                    disabled={Boolean(selectedRubric)}
                                    value={form.data.maximum_points}
                                    onChange={(event) =>
                                        form.setData('maximum_points', event.currentTarget.value)
                                    }
                                    className="border-border bg-surface min-h-11 rounded-lg border px-3 disabled:opacity-70"
                                />
                            </label>
                            <label className="grid gap-1.5 text-sm font-medium">
                                Published rubric (optional)
                                <select
                                    value={form.data.rubric_version_id}
                                    onChange={(event) => {
                                        const rubric = rubrics.find(
                                            (item) => String(item.id) === event.currentTarget.value,
                                        );
                                        form.setData((data) => ({
                                            ...data,
                                            rubric_version_id: event.currentTarget.value,
                                            maximum_points: rubric
                                                ? String(rubric.maximum_points)
                                                : data.maximum_points,
                                        }));
                                    }}
                                    className="border-border bg-surface min-h-11 rounded-lg border px-3"
                                >
                                    <option value="">Use a manual points score</option>
                                    {rubrics.map((rubric) => (
                                        <option key={rubric.id} value={rubric.id}>
                                            {rubric.name} · v{rubric.version} ({rubric.maximum_points} points)
                                        </option>
                                    ))}
                                </select>
                            </label>
                            {selectedRubric && (
                                <p className="text-text-muted text-xs sm:col-span-2">
                                    This assignment will use the published criterion set from{' '}
                                    {selectedRubric.name} v{selectedRubric.version}. The maximum is fixed at{' '}
                                    {selectedRubric.maximum_points} points.
                                </p>
                            )}
                            <label className="grid gap-1.5 text-sm font-medium">
                                Institution time zone
                                <input
                                    required
                                    value={form.data.timezone}
                                    onChange={(event) => form.setData('timezone', event.currentTarget.value)}
                                    className="border-border bg-surface min-h-11 rounded-lg border px-3"
                                />
                            </label>
                            <label className="grid gap-1.5 text-sm font-medium">
                                Opens at
                                <input
                                    type="datetime-local"
                                    value={form.data.available_at}
                                    onChange={(event) =>
                                        form.setData('available_at', event.currentTarget.value)
                                    }
                                    className="border-border bg-surface min-h-11 rounded-lg border px-3"
                                />
                            </label>
                            <label className="grid gap-1.5 text-sm font-medium">
                                Due at
                                <input
                                    type="datetime-local"
                                    value={form.data.due_at}
                                    onChange={(event) => form.setData('due_at', event.currentTarget.value)}
                                    className="border-border bg-surface min-h-11 rounded-lg border px-3"
                                />
                            </label>
                            <label className="grid gap-1.5 text-sm font-medium">
                                Late cutoff
                                <input
                                    type="datetime-local"
                                    value={form.data.late_until}
                                    onChange={(event) =>
                                        form.setData('late_until', event.currentTarget.value)
                                    }
                                    className="border-border bg-surface min-h-11 rounded-lg border px-3"
                                />
                            </label>
                        </div>
                        <label className="flex min-h-11 items-center gap-3 text-sm">
                            <input
                                type="checkbox"
                                checked={form.data.review_required}
                                onChange={(event) =>
                                    form.setData('review_required', event.currentTarget.checked)
                                }
                            />
                            Require independent review before publication
                        </label>
                        <div className="flex flex-wrap justify-end gap-3">
                            <button
                                type="button"
                                onClick={() => router.visit(`/t/${tenant.slug}/assignments`)}
                                className="border-border focus-ring min-h-11 rounded-lg border px-4 text-sm font-semibold"
                            >
                                Cancel
                            </button>
                            <button
                                disabled={form.processing}
                                className="bg-brand-600 focus-ring min-h-11 rounded-lg px-5 text-sm font-semibold text-white disabled:opacity-60"
                            >
                                {form.processing ? 'Saving…' : 'Save draft'}
                            </button>
                        </div>
                    </>
                )}
            </form>
        </WorkspaceLayout>
    );
}
