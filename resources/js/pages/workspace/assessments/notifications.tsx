import { Head, router } from '@inertiajs/react';
import { WorkspaceLayout } from '@/layouts/workspace-layout';
import { Button } from '@/components/ui/button';

type Notification = {
    id: number;
    title: string;
    message: string;
    event_type: string;
    read_at: string | null;
    created_at: string;
};
export default function AssessmentNotifications({
    tenant,
    notifications,
}: {
    tenant: { slug: string };
    notifications: { data: Notification[] };
}) {
    const base = `/t/${tenant.slug}/assessments`;
    return (
        <WorkspaceLayout title="Assessment notifications" area="tenant">
            <main id="main-content" className="mx-auto max-w-4xl space-y-6 px-4 py-8 sm:px-6">
                <Head title="Assessment notifications" />
                <header>
                    <h1 className="text-2xl font-semibold">Assessment notifications</h1>
                    <p className="text-text-muted mt-1 text-sm">
                        Private notifications for your signed-in account and institution.
                    </p>
                </header>
                <a className="text-primary text-sm underline" href={`${base}/`}>
                    Back to assessments
                </a>
                <section className="space-y-3" aria-label="Notifications">
                    {notifications.data.map((item) => (
                        <article
                            key={item.id}
                            className={`border-border rounded-xl border p-4 ${item.read_at ? 'bg-surface' : 'bg-primary/5'}`}
                        >
                            <div className="flex flex-wrap items-start justify-between gap-3">
                                <div>
                                    <h2 className="font-semibold">{item.title}</h2>
                                    <p className="mt-1 text-sm">{item.message}</p>
                                    <p className="text-text-muted mt-2 text-xs">
                                        {new Date(item.created_at).toLocaleString()}
                                    </p>
                                </div>
                                {!item.read_at && (
                                    <Button
                                        variant="secondary"
                                        onClick={() =>
                                            router.post(
                                                `${base}/notifications/${item.id}/read`,
                                                {},
                                                { preserveScroll: true },
                                            )
                                        }
                                    >
                                        Mark as read
                                    </Button>
                                )}
                            </div>
                        </article>
                    ))}
                    {notifications.data.length === 0 && (
                        <p className="border-border rounded-xl border border-dashed p-6">
                            No assessment notifications yet.
                        </p>
                    )}
                </section>
            </main>
        </WorkspaceLayout>
    );
}
