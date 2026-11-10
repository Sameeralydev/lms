import { Head, router, useForm } from '@inertiajs/react';
import { type FormEvent } from 'react';
import { PageHeader } from '@/components/ui/page-header';
import { WorkspaceLayout } from '@/layouts/workspace-layout';

type Props = {
    tenant: { slug: string };
    students: { id: number; student_number: string; legal_first_name: string; legal_last_name: string }[];
    periods: { id: number; name: string; code: string }[];
    templates: {
        id: number;
        record_type: string;
        name: string;
        template_key: string;
        version: number;
        status: string;
    }[];
    issues: {
        id: number;
        student: string;
        record_type: string;
        issue_number: number;
        status: string;
        verification_code: string;
        created_by: number;
    }[];
    comments: { id: number; student: string; section: string; comment: string; version: number }[];
    permissions: { issue: boolean; review: boolean };
};
const field = 'border-border bg-surface focus-ring min-h-11 w-full rounded-lg border px-3 py-2 text-sm';
const button =
    'bg-brand-action focus-ring rounded-lg px-4 py-2.5 text-sm font-semibold text-white disabled:opacity-60';

export default function OfficialRecords({
    tenant,
    students,
    periods,
    templates,
    issues,
    comments,
    permissions,
}: Props) {
    const root = `/t/${tenant.slug}/academics/records`;
    const template = useForm({
        name: '',
        template_key: '',
        record_type: 'report_card',
        minimum_cumulative_gpa: '',
    });
    const prepare = useForm({ student_id: '', template_id: '', academic_period_id: '' });
    const saveTemplate = (event: FormEvent) => {
        event.preventDefault();
        template.post(`${root}/templates`, { preserveScroll: true, onSuccess: () => template.reset() });
    };
    const createIssue = (event: FormEvent) => {
        event.preventDefault();
        prepare.post(`${root}/issues`, { preserveScroll: true });
    };
    const postAction = (id: number, action: string, payload: Record<string, string> = {}) =>
        router.post(`${root}/issues/${id}/${action}`, payload, { preserveScroll: true });

    return (
        <WorkspaceLayout area="tenant" title="Official academic records">
            <Head title="Official academic records" />
            <PageHeader
                title="Official academic records"
                description="Configure record template versions and route immutable report card, transcript, and certificate snapshots through independent approval."
            />
            <div className="grid gap-6 xl:grid-cols-2">
                <section
                    aria-labelledby="record-template-heading"
                    className="border-border bg-surface rounded-2xl border p-5 sm:p-7"
                >
                    <h2 id="record-template-heading" className="text-lg font-semibold">
                        Create template version
                    </h2>
                    <form className="mt-4 space-y-4" onSubmit={saveTemplate}>
                        <label className="block text-sm font-medium">
                            Template name
                            <input
                                className={`${field} mt-1`}
                                value={template.data.name}
                                onChange={(e) => template.setData('name', e.target.value)}
                                required
                            />
                        </label>
                        <label className="block text-sm font-medium">
                            Template key
                            <input
                                className={`${field} mt-1`}
                                value={template.data.template_key}
                                onChange={(e) => template.setData('template_key', e.target.value)}
                                required
                            />
                        </label>
                        <label className="block text-sm font-medium">
                            Record type
                            <select
                                className={`${field} mt-1`}
                                value={template.data.record_type}
                                onChange={(e) => template.setData('record_type', e.target.value)}
                            >
                                <option value="report_card">Report card</option>
                                <option value="transcript">Transcript</option>
                                <option value="certificate">Enrollment certificate</option>
                            </select>
                        </label>
                        {template.data.record_type === 'certificate' && (
                            <label className="block text-sm font-medium">
                                Minimum cumulative GPA (optional)
                                <input
                                    className={`${field} mt-1`}
                                    type="number"
                                    min="0"
                                    max="20"
                                    step="0.001"
                                    value={template.data.minimum_cumulative_gpa}
                                    onChange={(e) =>
                                        template.setData('minimum_cumulative_gpa', e.target.value)
                                    }
                                />
                            </label>
                        )}
                        <button className={button} disabled={!permissions.issue || template.processing}>
                            Save template draft
                        </button>
                    </form>
                </section>
                <section
                    aria-labelledby="record-prepare-heading"
                    className="border-border bg-surface rounded-2xl border p-5 sm:p-7"
                >
                    <h2 id="record-prepare-heading" className="text-lg font-semibold">
                        Prepare official record
                    </h2>
                    <p className="text-text-secondary mt-1 text-sm">
                        The draft freezes current published grades. Report cards require a period; transcripts
                        and certificates use cumulative history.
                    </p>
                    <form className="mt-4 space-y-4" onSubmit={createIssue}>
                        <label className="block text-sm font-medium">
                            Student
                            <select
                                className={`${field} mt-1`}
                                value={prepare.data.student_id}
                                onChange={(e) => prepare.setData('student_id', e.target.value)}
                                required
                            >
                                <option value="">Choose student</option>
                                {students.map((s) => (
                                    <option key={s.id} value={s.id}>
                                        {s.student_number} · {s.legal_first_name} {s.legal_last_name}
                                    </option>
                                ))}
                            </select>
                        </label>
                        <label className="block text-sm font-medium">
                            Active template
                            <select
                                className={`${field} mt-1`}
                                value={prepare.data.template_id}
                                onChange={(e) => prepare.setData('template_id', e.target.value)}
                                required
                            >
                                <option value="">Choose template</option>
                                {templates
                                    .filter((t) => t.status === 'active')
                                    .map((t) => (
                                        <option key={t.id} value={t.id}>
                                            {t.name} · {t.record_type} v{t.version}
                                        </option>
                                    ))}
                            </select>
                        </label>
                        {templates.find((t) => String(t.id) === prepare.data.template_id)?.record_type ===
                            'report_card' && (
                            <label className="block text-sm font-medium">
                                Academic period
                                <select
                                    className={`${field} mt-1`}
                                    value={prepare.data.academic_period_id}
                                    onChange={(e) => prepare.setData('academic_period_id', e.target.value)}
                                    required
                                >
                                    <option value="">Choose period</option>
                                    {periods.map((p) => (
                                        <option key={p.id} value={p.id}>
                                            {p.name} · {p.code}
                                        </option>
                                    ))}
                                </select>
                            </label>
                        )}
                        {templates.find((t) => String(t.id) === prepare.data.template_id)?.record_type ===
                            'report_card' && (
                            <p className="text-text-secondary text-sm">
                                Only independently approved comments from assigned instructors are included in
                                the frozen report-card snapshot.
                            </p>
                        )}
                        <button className={button} disabled={!permissions.issue || prepare.processing}>
                            Prepare draft
                        </button>
                    </form>
                </section>
            </div>
            <section
                aria-labelledby="record-templates-list"
                className="border-border bg-surface mt-6 rounded-2xl border p-5 sm:p-7"
            >
                <h2 id="record-templates-list" className="text-lg font-semibold">
                    Versioned templates
                </h2>
                {templates.length ? (
                    <ul className="mt-3 divide-y">
                        {templates.map((item) => (
                            <li
                                key={item.id}
                                className="flex flex-wrap items-center justify-between gap-3 py-3"
                            >
                                <span>
                                    {item.name} · {item.record_type} v{item.version} · {item.status}
                                </span>
                                {item.status === 'draft' && (
                                    <button
                                        className={button}
                                        type="button"
                                        onClick={() => router.post(`${root}/templates/${item.id}/activate`)}
                                    >
                                        Activate version
                                    </button>
                                )}
                            </li>
                        ))}
                    </ul>
                ) : (
                    <p className="text-text-secondary mt-3 text-sm">No templates created.</p>
                )}
            </section>
            {permissions.review && (
                <section
                    aria-labelledby="teacher-comment-review"
                    className="border-border bg-surface mt-6 rounded-2xl border p-5 sm:p-7"
                >
                    <h2 id="teacher-comment-review" className="text-lg font-semibold">
                        Submitted instructor comments
                    </h2>
                    {comments.length ? (
                        <ul className="mt-3 divide-y">
                            {comments.map((comment) => (
                                <li key={comment.id} className="py-4">
                                    <p className="font-medium">
                                        {comment.student} · {comment.section} · v{comment.version}
                                    </p>
                                    <p className="mt-2">{comment.comment}</p>
                                    <div className="mt-3 flex gap-2">
                                        <button
                                            className={button}
                                            type="button"
                                            onClick={() =>
                                                router.post(
                                                    `/t/${tenant.slug}/academics/report-card-comments/${comment.id}/decision`,
                                                    {
                                                        decision: 'approve',
                                                        reason: 'Comment reviewed for accuracy and appropriateness.',
                                                    },
                                                    { preserveScroll: true },
                                                )
                                            }
                                        >
                                            Approve
                                        </button>
                                        <button
                                            className="border-border focus-ring rounded-lg border px-3 py-2 text-sm"
                                            type="button"
                                            onClick={() =>
                                                router.post(
                                                    `/t/${tenant.slug}/academics/report-card-comments/${comment.id}/decision`,
                                                    {
                                                        decision: 'reject',
                                                        reason: 'Please revise this comment before publication.',
                                                    },
                                                    { preserveScroll: true },
                                                )
                                            }
                                        >
                                            Reject
                                        </button>
                                    </div>
                                </li>
                            ))}
                        </ul>
                    ) : (
                        <p className="text-text-secondary mt-3">No comments are awaiting review.</p>
                    )}
                </section>
            )}
            <section
                aria-labelledby="record-issues-list"
                className="border-border bg-surface mt-6 rounded-2xl border p-5 sm:p-7"
            >
                <h2 id="record-issues-list" className="text-lg font-semibold">
                    Issuance and review queue
                </h2>
                {issues.length ? (
                    <ul className="mt-3 divide-y">
                        {issues.map((item) => (
                            <li
                                key={item.id}
                                className="flex flex-wrap items-center justify-between gap-3 py-4"
                            >
                                <div>
                                    <p className="font-medium">
                                        {item.student} · {item.record_type} #{item.issue_number}
                                    </p>
                                    <p className="text-text-secondary text-sm">
                                        {item.status}
                                        {item.record_type === 'certificate' &&
                                            ` · verification ${item.verification_code}`}
                                    </p>
                                </div>
                                <div className="flex flex-wrap gap-2">
                                    {item.status === 'draft' && permissions.issue && (
                                        <button
                                            className={button}
                                            type="button"
                                            onClick={() => router.post(`${root}/issues/${item.id}/submit`)}
                                        >
                                            Submit for approval
                                        </button>
                                    )}
                                    {item.status === 'submitted' && permissions.review && (
                                        <>
                                            <button
                                                className={button}
                                                type="button"
                                                onClick={() =>
                                                    postAction(item.id, 'decision', {
                                                        decision: 'approve',
                                                        reason: 'Academic record content verified.',
                                                    })
                                                }
                                            >
                                                Approve
                                            </button>
                                            <button
                                                className="border-border focus-ring rounded-lg border px-3 py-2 text-sm"
                                                type="button"
                                                onClick={() =>
                                                    postAction(item.id, 'decision', {
                                                        decision: 'reject',
                                                        reason: 'Required source evidence is incomplete.',
                                                    })
                                                }
                                            >
                                                Reject
                                            </button>
                                        </>
                                    )}
                                    {item.status === 'approved' && permissions.review && (
                                        <button
                                            className={button}
                                            type="button"
                                            onClick={() => router.post(`${root}/issues/${item.id}/issue`)}
                                        >
                                            Issue record
                                        </button>
                                    )}
                                    {item.status === 'issued' && permissions.review && (
                                        <button
                                            className="border-border focus-ring rounded-lg border px-3 py-2 text-sm"
                                            type="button"
                                            onClick={() =>
                                                postAction(item.id, 'revoke', {
                                                    reason: 'Authorized record revocation.',
                                                })
                                            }
                                        >
                                            Revoke
                                        </button>
                                    )}
                                </div>
                            </li>
                        ))}
                    </ul>
                ) : (
                    <p className="text-text-secondary mt-3 text-sm">No record issues have been prepared.</p>
                )}
            </section>
        </WorkspaceLayout>
    );
}
