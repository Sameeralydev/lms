<?php

namespace App\Http\Controllers\Workspace;

use App\Domain\Tenancy\TenantContext;
use App\Models\CommunicationsNotification;
use App\Models\Tenant;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

final class CommunicationsNotificationController
{
    public function __construct(private readonly TenantContext $context) {}

    public function index(Tenant $tenant): Response
    {
        $membership = $this->context->membership();
        abort_unless($membership->hasPermission('communications.announcements.view'), 403);
        $query = CommunicationsNotification::query()->where('tenant_id', $tenant->getKey())->where('user_id', $membership->user_id);
        $unreadCount = (clone $query)->whereNull('read_at')->count();
        $notifications = $query
            ->latest()->paginate(30)->withQueryString()->through(static fn (CommunicationsNotification $notification): array => [
                'id' => (int) $notification->getKey(), 'type' => $notification->type,
                'reference_id' => (int) $notification->reference_id, 'title' => $notification->title,
                'context_id' => $notification->context_id ? (int) $notification->context_id : null,
                'summary' => $notification->summary, 'read' => $notification->read_at !== null,
                'created_at' => $notification->created_at?->toIso8601String(),
            ]);

        return Inertia::render('workspace/communications/notifications', ['tenant' => ['slug' => $tenant->slug], 'notifications' => $notifications, 'unreadCount' => $unreadCount]);
    }

    public function markRead(Tenant $tenant, int $notificationId): RedirectResponse
    {
        $membership = $this->context->membership();
        abort_unless($membership->hasPermission('communications.announcements.view'), 403);
        CommunicationsNotification::query()->where('tenant_id', $tenant->getKey())->where('user_id', $membership->user_id)
            ->whereNull('read_at')->findOrFail($notificationId)->forceFill(['read_at' => now()])->save();

        return back()->with('status', 'Notification marked as read.');
    }
}
