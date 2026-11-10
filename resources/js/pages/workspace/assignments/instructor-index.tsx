import { Head, Link, router } from '@inertiajs/react';
import { EmptyState } from '@/components/ui/empty-state';
import { PageHeader } from '@/components/ui/page-header';
import { WorkspaceLayout } from '@/layouts/workspace-layout';

type Assignment = {
    id: number;
    title: string;
    course: string;
    type: string;
    collaboration_mode: string;
    status: string;
    due_at: string | null;
    recipients_count: number;
    submissions_count: number;
};
type Props = {
    tenant: { slug: string };
    assignments: { data: Assignment[]; links: { url: string | null; label: string; active: boolean }[] };
    metrics: { drafts: number; published: number; pending_grading: number };
    permissions: { manage: boolean; publish: boolean; review: boolean; rubrics: boolean; reports: boolean };
};

export default function InstructorAssignments({ tenant, assignments, metrics, permissions }: Props) {
    return (
        <WorkspaceLayout title="Assignment management" area="tenant">
            <Head title="Assignments" />
            <PageHeader
                eyebrow="Coursework"
                title="Assignment management"
                description="Create controlled coursework, track submissions and keep section work aligned with published assignment versions."
            />
            {permissions.manage && (
                <div className="mt-5 flex flex-wrap justify-end gap-2">
                    {permissions.rubrics && (
                        <Link
                            href={`/t/${tenant.slug}/assignments/rubrics`}
                            className="border-border focus-ring min-h-11 rounded-lg border px-4 py-2 text-sm font-semibold"
                        >
                            Manage rubrics
                        </Link>
                    )}
                    {permissions.reports && (
                        <Link
                            href={`/t/${tenant.slug}/assignments/analytics`}
                            className="border-border focus-ring min-h-11 rounded-lg border px-4 py-2 text-sm font-semibold"
                        >
                            View analytics
                        </Link>
                    )}
                    <Link
                        href={`/t/${tenant.slug}/assignments/create`}
                        className="bg-brand-600 focus-ring min-h-11 rounded-lg px-4 py-2 text-sm font-semibold text-white"
                    >
                        Create assignment
                    </Link>
                </div>
            )}
            <section className="mt-6 grid gap-3 sm:grid-cols-3" aria-label="Assignment summary">
                {[
                    ['Drafts', metrics.drafts],
                    ['Published', metrics.published],
                    ['Pending marking', metrics.pending_grading],
                ].map(([label, value]) => (
                    <div key={label} className="border-border bg-surface rounded-xl border p-4">
                        <p className="text-text-muted text-sm">{label}</p>
                        <p className="mt-1 text-2xl font-semibold">{value}</p>
                    </div>
                ))}
            </section>
            <section
                className="border-border bg-surface mt-6 rounded-2xl border p-4 sm:p-6"
                aria-labelledby="assignment-list-title"
            >
                <h2 id="assignment-list-title" className="text-lg font-semibold">
                    Assignments
                </h2>
                {assignments.data.length === 0 ? (
                    <div className="mt-4">
                        <EmptyState
                            title="No assignments yet"
                            description="Assignments you create or manage for your authorized sections will appear here."
                        />
                    </div>
                ) : (
                    <div className="mt-4 overflow-x-auto">
                        <table className="w-full min-w-[680px] text-left text-sm">
                            <thead>
                                <tr className="border-border text-text-muted border-b">
                                    <th className="px-3 py-3 font-medium">Assignment</th>
                                    <th className="px-3 py-3 font-medium">Course</th>
                                    <th className="px-3 py-3 font-medium">Status</th>
                                    <th className="px-3 py-3 font-medium">Due</th>
                                    <th className="px-3 py-3 font-medium">Submitted</th>
                                    <th className="px-3 py-3 font-medium">
                                        <span className="sr-only">Actions</span>
                                    </th>
                                </tr>
                            </thead>
                            <tbody>
                                {assignments.data.map((assignment) => (
                                    <tr key={assignment.id} className="border-border border-b last:border-0">
                                        <td className="px-3 py-4">
                                            <span className="font-semibold">{assignment.title}</span>
                                            <span className="text-text-muted mt-1 block capitalize">
                                                {assignment.type}
                                            </span>
                                        </td>
                                        <td className="px-3 py-4">{assignment.course}</td>
                                        <td className="px-3 py-4">
                                            <span className="bg-surface-soft rounded-full px-2.5 py-1 capitalize">
                                                {assignment.status.replaceAll('_', ' ')}
                                            </span>
                                        </td>
                                        <td className="px-3 py-4">
                                            {assignment.due_at
                                                ? new Date(assignment.due_at).toLocaleString()
                                                : 'No deadline'}
                                        </td>
                                        <td className="px-3 py-4">
                                            {assignment.submissions_count} / {assignment.recipients_count}
                                        </td>
                                        <td className="px-3 py-4 text-right">
                                            <div className="flex flex-wrap justify-end gap-2">
                                                {permissions.manage && assignment.status === 'draft' && (
                                                    <button
                                                        type="button"
                                                        onClick={() =>
                                                            router.post(
                                                                `/t/${tenant.slug}/assignments/${assignment.id}/submit-review`,
                                                            )
                                                        }
                                                        className="border-border focus-ring min-h-10 rounded-lg border px-3 text-xs font-semibold"
                                                    >
                                                        Submit for review
                                                    </button>
                                                )}
                                                {permissions.review &&
                                                    assignment.status === 'under_review' && (
                                                        <button
                                                            type="button"
                                                            onClick={() =>
                                                                router.post(
                                                                    `/t/${tenant.slug}/assignments/${assignment.id}/review`,
                                                                    { decision: 'approve' },
                                                                )
                                                            }
                                                            className="border-border focus-ring min-h-10 rounded-lg border px-3 text-xs font-semibold"
                                                        >
                                                            Approve
                                                        </button>
                                                    )}
                                                {permissions.publish && assignment.status === 'approved' && (
                                                    <button
                                                        type="button"
                                                        onClick={() =>
                                                            router.post(
                                                                `/t/${tenant.slug}/assignments/${assignment.id}/publish`,
                                                            )
                                                        }
                                                        className="bg-brand-600 focus-ring min-h-10 rounded-lg px-3 text-xs font-semibold text-white"
                                                    >
                                                        Publish
                                                    </button>
                                                )}
                                                {permissions.manage && (
                                                    <button
                                                        type="button"
                                                        onClick={() =>
                                                            router.post(
                                                                `/t/${tenant.slug}/assignments/${assignment.id}/copy`,
                                                                {},
                                                                { preserveScroll: true },
                                                            )
                                                        }
                                                        className="border-border focus-ring min-h-10 rounded-lg border px-3 text-xs font-semibold"
                                                    >
                                                        Copy as draft
                                                    </button>
                                                )}
                                                {permissions.publish &&
                                                    ['published', 'closed', 'scheduled'].includes(
                                                        assignment.status,
                                                    ) && (
                                                        <Link
                                                            className="text-brand-700 focus-ring rounded px-2 py-2 text-xs font-semibold"
                                                            href={`/t/${tenant.slug}/assignments/${assignment.id}/submissions`}
                                                        >
                                                            Review work
                                                        </Link>
                                                    )}
                                                {assignment.collaboration_mode === 'group' && (
                                                    <Link
                                                        className="text-brand-700 focus-ring rounded px-2 py-2 text-xs font-semibold"
                                                        href={`/t/${tenant.slug}/assignments/${assignment.id}/groups`}
                                                    >
                                                        Manage groups
                                                    </Link>
                                                )}
                                            </div>
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                )}
                {assignments.links.length > 3 && (
                    <nav className="mt-4 flex flex-wrap gap-2" aria-label="Assignment pages">
                        {assignments.links.map((link, index) =>
                            link.url ? (
                                <Link
                                    key={index}
                                    href={link.url}
                                    className={`focus-ring rounded border px-3 py-2 text-sm ${link.active ? 'border-brand-600 bg-brand-50' : 'border-border'}`}
                                    dangerouslySetInnerHTML={{ __html: link.label }}
                                />
                            ) : (
                                <span
                                    key={index}
                                    className="text-text-muted px-3 py-2 text-sm"
                                    dangerouslySetInnerHTML={{ __html: link.label }}
                                />
                            ),
                        )}
                    </nav>
                )}
            </section>
        </WorkspaceLayout>
    );
}
