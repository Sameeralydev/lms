import { Link } from '@inertiajs/react';
import { EmptyState } from '@/components/ui/empty-state';
import { PageHeader } from '@/components/ui/page-header';
import { WorkspaceLayout } from '@/layouts/workspace-layout';

type LinkedStudent = { id: number; name: string; student_number: string; href: string };

export default function GuardianAttendanceIndex({
    students,
}: {
    tenant: { slug: string };
    students: LinkedStudent[];
}) {
    return (
        <WorkspaceLayout area="tenant" title="Family attendance">
            <PageHeader
                title="Family attendance"
                description="Choose a learner with a verified, active relationship and profile access grant. Only authorized attendance records are available."
            />
            {students.length > 0 ? (
                <section aria-label="Verified learners" className="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
                    {students.map((student) => (
                        <article
                            key={student.id}
                            className="border-border bg-surface rounded-2xl border p-5 shadow-sm sm:p-6"
                        >
                            <p className="text-text-muted text-xs font-semibold tracking-wide uppercase">
                                Verified learner
                            </p>
                            <h2 className="mt-2 text-lg font-semibold">{student.name}</h2>
                            <p className="text-text-secondary mt-1 text-sm">
                                Student number: {student.student_number || 'Not available'}
                            </p>
                            <Link
                                href={student.href}
                                className="focus-ring bg-brand-action hover:bg-brand-action-hover mt-5 inline-flex min-h-11 items-center rounded-lg px-4 text-sm font-semibold text-white"
                            >
                                View attendance
                            </Link>
                        </article>
                    ))}
                </section>
            ) : (
                <EmptyState
                    title="No verified learner access"
                    description="A learner appears here only after the institution verifies your relationship and grants profile access."
                />
            )}
        </WorkspaceLayout>
    );
}
