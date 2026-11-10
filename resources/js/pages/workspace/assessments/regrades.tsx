import { Head, router } from '@inertiajs/react';
import { WorkspaceLayout } from '@/layouts/workspace-layout';
import { Button } from '@/components/ui/button';

type Regrade = {
    id: number;
    reason: string;
    created_at: string;
    attempt: {
        id: number;
        student: { legal_first_name: string; legal_last_name: string };
        quiz_version: { title: string };
    };
};
export default function AssessmentRegrades({
    tenant,
    requests,
}: {
    tenant: { slug: string };
    requests: { data: Regrade[] };
}) {
    const base = `/t/${tenant.slug}/assessments`;
    return (
        <WorkspaceLayout title="Regrade requests" area="tenant">
            <main id="main-content" className="mx-auto max-w-5xl space-y-6 px-4 py-8 sm:px-6">
                <Head title="Regrade requests" />
                <header>
                    <h1 className="text-2xl font-semibold">Regrade requests</h1>
                    <p className="text-text-muted mt-1 text-sm">
                        An independent reviewer decides each request. Approval returns the attempt to the
                        manual review queue and preserves prior grade revisions.
                    </p>
                </header>
                <nav aria-label="Assessment workspace" className="flex flex-wrap gap-4 text-sm">
                    <a className="text-primary underline" href={`${base}/`}>
                        Assessments
                    </a>
                    <a className="text-primary underline" href={`${base}/grading`}>
                        Grading queue
                    </a>
                    <a className="text-primary underline" href={`${base}/analytics`}>
                        Analytics
                    </a>
                </nav>
                {requests.data.map((item) => (
                    <article
                        key={item.id}
                        className="border-border bg-surface space-y-3 rounded-2xl border p-5"
                    >
                        <h2 className="font-semibold">
                            {item.attempt.quiz_version.title} · {item.attempt.student.legal_first_name}{' '}
                            {item.attempt.student.legal_last_name}
                        </h2>
                        <p className="text-sm whitespace-pre-wrap">{item.reason}</p>
                        <p className="text-text-muted text-xs">
                            Requested {new Date(item.created_at).toLocaleString()}
                        </p>
                        <div className="flex flex-wrap gap-2">
                            <Button
                                onClick={() =>
                                    router.post(
                                        `${base}/regrade-requests/${item.id}/decision`,
                                        { decision: 'approve', notes: '' },
                                        { preserveScroll: true },
                                    )
                                }
                            >
                                Approve regrade
                            </Button>
                            <Button
                                variant="secondary"
                                onClick={() =>
                                    router.post(
                                        `${base}/regrade-requests/${item.id}/decision`,
                                        { decision: 'reject', notes: '' },
                                        { preserveScroll: true },
                                    )
                                }
                            >
                                Decline
                            </Button>
                        </div>
                    </article>
                ))}
                {requests.data.length === 0 && (
                    <p className="border-border rounded-xl border border-dashed p-6">
                        There are no open regrade requests.
                    </p>
                )}
            </main>
        </WorkspaceLayout>
    );
}
