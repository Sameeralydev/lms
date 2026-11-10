import { Head, router } from '@inertiajs/react';
import { PageHeader } from '@/components/ui/page-header';
import { WorkspaceLayout } from '@/layouts/workspace-layout';

type Notification = {
    id: number;
    type: string;
    title: string;
    message: string;
    created_at: string | null;
    read: boolean;
};
type Props = {
    tenant: { slug: string };
    notifications: { data: Notification[]; current_page: number; last_page: number; total: number };
};

export default function AssignmentNotifications({ tenant, notifications }: Props) {
    return (
        <WorkspaceLayout title="Assignment notifications" area="tenant">
            <Head title="Assignment notifications" />
            <PageHeader
                eyebrow="Coursework updates"
                title="Notifications"
                description="Assignment, submission, extension, and released feedback updates for your institution membership."
            />
            {notifications.data.length === 0 ? (
                <div className="border-border bg-surface mt-6 rounded-2xl border p-8 text-center">
                    <h2 className="font-semibold">You’re all caught up</h2>
                    <p className="text-text-muted mt-2 text-sm">New assignment updates will appear here.</p>
                </div>
            ) : (
                <ul className="mt-6 grid gap-3" aria-label="Assignment notifications">
                    {notifications.data.map((notification) => (
                        <li
                            key={notification.id}
                            className="border-border bg-surface flex flex-wrap items-start justify-between gap-4 rounded-xl border p-4"
                        >
                            <div>
                                <div className="flex items-center gap-2">
                                    <h2 className="font-semibold">{notification.title}</h2>
                                    {!notification.read && (
                                        <span className="rounded-full bg-cyan-100 px-2 py-0.5 text-xs font-medium text-cyan-900">
                                            New
                                        </span>
                                    )}
                                </div>
                                <p className="text-text-muted mt-1 text-sm">{notification.message}</p>
                                <p className="text-text-muted mt-2 text-xs">
                                    {notification.created_at
                                        ? new Date(notification.created_at).toLocaleString()
                                        : 'Recently'}
                                </p>
                            </div>
                            {!notification.read && (
                                <button
                                    type="button"
                                    onClick={() =>
                                        router.post(
                                            `/t/${tenant.slug}/assignment-notifications/${notification.id}/read`,
                                            {},
                                            { preserveScroll: true },
                                        )
                                    }
                                    className="text-brand-700 focus-ring min-h-11 rounded-lg px-3 text-sm font-semibold underline"
                                >
                                    Mark as read
                                </button>
                            )}
                        </li>
                    ))}
                </ul>
            )}
        </WorkspaceLayout>
    );
}
