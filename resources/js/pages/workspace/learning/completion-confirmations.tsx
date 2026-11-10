import { Head, router } from '@inertiajs/react';
import { PageHeader } from '@/components/ui/page-header';
import { WorkspaceLayout } from '@/layouts/workspace-layout';

type PendingConfirmation = {
    lesson_id: number;
    lesson_title: string;
    membership_id: number;
    learner_name: string;
    revision_version: number;
    requested_at: string | null;
};

export default function LearningCompletionConfirmations({
    tenant,
    workspace,
    pending,
}: {
    tenant: { slug: string };
    workspace: { id: number; title: string; code: string };
    pending: PendingConfirmation[];
}) {
    return (
        <WorkspaceLayout title="Completion confirmations" area="tenant">
            <Head title="Completion confirmations" />
            <PageHeader
                eyebrow={workspace.code}
                title="Learner completion requests"
                description="Review learner-confirmed progress for lessons configured to require instructor confirmation."
            />
            {pending.length === 0 ? (
                <section className="border-border bg-surface mt-6 rounded-2xl border border-dashed p-8 text-center">
                    <h2 className="font-semibold">No pending confirmations</h2>
                    <p className="text-text-muted mt-2 text-sm">New learner requests will appear here.</p>
                </section>
            ) : (
                <ul className="mt-6 grid gap-3">
                    {pending.map((item) => (
                        <li
                            key={item.lesson_id + '-' + item.membership_id}
                            className="border-border bg-surface flex flex-wrap items-center justify-between gap-4 rounded-xl border p-4"
                        >
                            <div>
                                <h2 className="font-semibold">{item.lesson_title}</h2>
                                <p className="text-text-muted mt-1 text-sm">
                                    {item.learner_name} · lesson revision {item.revision_version}
                                </p>
                                {item.requested_at && (
                                    <time
                                        className="text-text-muted mt-1 block text-xs"
                                        dateTime={item.requested_at}
                                    >
                                        Requested {new Date(item.requested_at).toLocaleString()}
                                    </time>
                                )}
                            </div>
                            <button
                                type="button"
                                onClick={() =>
                                    router.post(
                                        '/t/' +
                                            tenant.slug +
                                            '/learning/' +
                                            workspace.id +
                                            '/lessons/' +
                                            item.lesson_id +
                                            '/completion-confirmations/' +
                                            item.membership_id,
                                        {},
                                        { preserveScroll: true },
                                    )
                                }
                                className="bg-brand-action focus-ring min-h-11 rounded-lg px-4 text-sm font-semibold text-white"
                            >
                                Confirm completion
                            </button>
                        </li>
                    ))}
                </ul>
            )}
        </WorkspaceLayout>
    );
}
