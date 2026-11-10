import { Head, useForm } from '@inertiajs/react';
import { type FormEvent } from 'react';
import { PageHeader } from '@/components/ui/page-header';
import { WorkspaceLayout } from '@/layouts/workspace-layout';

type Assignment = {
    section_id: number;
    section: string;
    period_id: number;
    student_id: number;
    student_number: string;
    student_name: string;
};
type Comment = {
    id: number;
    student: string;
    period: string;
    section: string;
    status: string;
    comment: string;
};

export default function TeacherReportComments({
    tenant,
    assignments,
    comments,
}: {
    tenant: { slug: string };
    assignments: Assignment[];
    comments: Comment[];
}) {
    const form = useForm({ assignment: '', comment: '' });
    const selected = assignments.find(
        (item) => `${item.student_id}:${item.period_id}:${item.section_id}` === form.data.assignment,
    );
    const submit = (event: FormEvent) => {
        event.preventDefault();
        if (!selected) return;
        form.transform((data) => ({
            student_id: selected.student_id,
            academic_period_id: selected.period_id,
            section_id: selected.section_id,
            comment: data.comment,
        }));
        form.post(`/t/${tenant.slug}/academics/report-card-comments`, {
            preserveScroll: true,
            onSuccess: () => form.reset(),
        });
    };

    return (
        <WorkspaceLayout area="tenant" title="Report-card comments">
            <Head title="Report-card comments" />
            <PageHeader
                title="Report-card comments"
                description="Submit a course-specific learner comment only for a student in a section assigned to you. Comments require independent academic review before appearing on an issued report card."
            />
            <section className="border-border bg-surface rounded-2xl border p-5 sm:p-7">
                <form className="grid gap-4 md:grid-cols-2" onSubmit={submit}>
                    <label className="text-sm font-medium">
                        Assigned learner and section
                        <select
                            className="border-border bg-surface focus-ring mt-1 min-h-11 w-full rounded-lg border px-3"
                            required
                            value={form.data.assignment}
                            onChange={(event) => form.setData('assignment', event.target.value)}
                        >
                            <option value="">Choose learner</option>
                            {assignments.map((item) => (
                                <option
                                    key={`${item.student_id}-${item.section_id}`}
                                    value={`${item.student_id}:${item.period_id}:${item.section_id}`}
                                >
                                    {item.student_number} · {item.student_name} · {item.section}
                                </option>
                            ))}
                        </select>
                    </label>
                    <label className="text-sm font-medium md:col-span-2">
                        Comment
                        <textarea
                            className="border-border bg-surface focus-ring mt-1 min-h-28 w-full rounded-lg border px-3 py-2"
                            minLength={10}
                            maxLength={2000}
                            required
                            value={form.data.comment}
                            onChange={(event) => form.setData('comment', event.target.value)}
                            aria-describedby="comment-help"
                        />
                        <span id="comment-help" className="text-text-secondary block text-xs">
                            10–2,000 characters. Submitted comments are versioned and cannot be edited in
                            place.
                        </span>
                        {form.errors.comment && (
                            <span role="alert" className="text-danger">
                                {form.errors.comment}
                            </span>
                        )}
                    </label>
                    <button
                        className="bg-brand-action focus-ring rounded-lg px-4 py-2.5 font-semibold text-white disabled:opacity-60 md:col-span-2 md:w-fit"
                        disabled={form.processing || !selected}
                    >
                        Submit for review
                    </button>
                </form>
            </section>
            <section className="border-border bg-surface mt-6 rounded-2xl border p-5 sm:p-7">
                <h2 className="text-lg font-semibold">My submitted comment history</h2>
                {comments.length ? (
                    <ul className="mt-3 divide-y">
                        {comments.map((item) => (
                            <li key={item.id} className="py-3">
                                <p className="font-medium">
                                    {item.student} · {item.period} · {item.section}
                                </p>
                                <p className="text-text-secondary text-sm">{item.status}</p>
                                <p className="mt-1">{item.comment}</p>
                            </li>
                        ))}
                    </ul>
                ) : (
                    <p className="text-text-secondary mt-3">No comments submitted yet.</p>
                )}
            </section>
        </WorkspaceLayout>
    );
}
