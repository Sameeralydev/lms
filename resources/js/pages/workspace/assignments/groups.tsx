import { Head, useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';
import { FormErrors } from '@/components/ui/form-errors';
import { PageHeader } from '@/components/ui/page-header';
import { WorkspaceLayout } from '@/layouts/workspace-layout';

type Learner = { membership_id: number; name: string; student_id: number };
type Section = { id: number; code: string; learners: Learner[] };
type Group = {
    id: number;
    name: string;
    section_id: number;
    frozen: boolean;
    members: { id: number; name: string }[];
};
type Props = {
    tenant: { slug: string };
    assignment: { id: number; title: string };
    sections: Section[];
    groups: Group[];
};

export default function AssignmentGroups({ tenant, assignment, sections, groups }: Props) {
    const form = useForm({
        section_id: '',
        name: '',
        membership_ids: [] as string[],
        leader_membership_id: '',
    });
    const section = sections.find((item) => String(item.id) === form.data.section_id);
    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.post(`/t/${tenant.slug}/assignments/${assignment.id}/groups`, {
            preserveScroll: true,
            onSuccess: () => form.reset('name', 'membership_ids', 'leader_membership_id'),
        });
    };
    return (
        <WorkspaceLayout title="Assignment groups" area="tenant">
            <Head title={`Groups · ${assignment.title}`} />
            <PageHeader
                eyebrow="Group coursework"
                title="Manage groups"
                description={`Create section-bound groups for ${assignment.title}. Membership becomes frozen when the shared submission is received.`}
            />
            <form
                onSubmit={submit}
                className="border-border bg-surface mt-6 grid gap-4 rounded-2xl border p-5 sm:p-7"
            >
                <FormErrors errors={form.errors} />
                <div className="grid gap-4 sm:grid-cols-2">
                    <label className="grid gap-1.5 text-sm font-medium">
                        Section
                        <select
                            required
                            value={form.data.section_id}
                            onChange={(event) =>
                                form.setData((data) => ({
                                    ...data,
                                    section_id: event.currentTarget.value,
                                    membership_ids: [],
                                    leader_membership_id: '',
                                }))
                            }
                            className="border-border min-h-11 rounded-lg border px-3"
                        >
                            <option value="">Choose section</option>
                            {sections.map((item) => (
                                <option key={item.id} value={item.id}>
                                    {item.code}
                                </option>
                            ))}
                        </select>
                    </label>
                    <label className="grid gap-1.5 text-sm font-medium">
                        Group name
                        <input
                            required
                            minLength={2}
                            maxLength={120}
                            value={form.data.name}
                            onChange={(event) => form.setData('name', event.currentTarget.value)}
                            className="border-border min-h-11 rounded-lg border px-3"
                        />
                    </label>
                </div>
                <fieldset className="grid gap-2">
                    <legend className="text-sm font-medium">Eligible learners (choose 2–8)</legend>
                    {section?.learners.map((learner) => (
                        <label
                            key={learner.membership_id}
                            className="border-border flex min-h-11 items-center gap-3 rounded-lg border px-3 text-sm"
                        >
                            <input
                                type="checkbox"
                                checked={form.data.membership_ids.includes(String(learner.membership_id))}
                                onChange={(event) =>
                                    form.setData(
                                        'membership_ids',
                                        event.currentTarget.checked
                                            ? [...form.data.membership_ids, String(learner.membership_id)]
                                            : form.data.membership_ids.filter(
                                                  (id) => id !== String(learner.membership_id),
                                              ),
                                    )
                                }
                            />
                            {learner.name}
                        </label>
                    ))}
                    {section && section.learners.length === 0 && (
                        <p className="text-text-muted text-sm">
                            No eligible learners are available in this section.
                        </p>
                    )}
                </fieldset>
                <label className="grid gap-1.5 text-sm font-medium">
                    Group leader
                    <select
                        value={form.data.leader_membership_id}
                        onChange={(event) => form.setData('leader_membership_id', event.currentTarget.value)}
                        className="border-border min-h-11 rounded-lg border px-3"
                    >
                        <option value="">No designated leader</option>
                        {section?.learners
                            .filter((learner) =>
                                form.data.membership_ids.includes(String(learner.membership_id)),
                            )
                            .map((learner) => (
                                <option key={learner.membership_id} value={learner.membership_id}>
                                    {learner.name}
                                </option>
                            ))}
                    </select>
                </label>
                <div className="flex justify-end">
                    <button
                        type="submit"
                        disabled={form.processing || form.data.membership_ids.length < 2}
                        className="bg-brand-600 focus-ring min-h-11 rounded-lg px-4 text-sm font-semibold text-white disabled:opacity-50"
                    >
                        Create group
                    </button>
                </div>
            </form>
            <section className="mt-7 grid gap-3" aria-labelledby="groups-heading">
                <h2 id="groups-heading" className="text-lg font-semibold">
                    Existing groups
                </h2>
                {groups.length === 0 ? (
                    <p className="text-text-muted border-border bg-surface rounded-xl border p-5 text-sm">
                        No groups have been created for this assignment.
                    </p>
                ) : (
                    groups.map((group) => (
                        <article key={group.id} className="border-border bg-surface rounded-xl border p-4">
                            <div className="flex flex-wrap justify-between gap-3">
                                <h3 className="font-semibold">{group.name}</h3>
                                <span className="text-text-muted text-xs">
                                    {group.frozen ? 'Membership frozen' : 'Membership editable'}
                                </span>
                            </div>
                            <p className="text-text-muted mt-2 text-sm">
                                Section {sections.find((item) => item.id === group.section_id)?.code ?? ''}
                            </p>
                            <ul className="mt-3 flex flex-wrap gap-2">
                                {group.members.map((member) => (
                                    <li
                                        key={member.id}
                                        className="bg-surface-soft rounded-full px-3 py-1 text-xs"
                                    >
                                        {member.name}
                                    </li>
                                ))}
                            </ul>
                        </article>
                    ))
                )}
            </section>
        </WorkspaceLayout>
    );
}
