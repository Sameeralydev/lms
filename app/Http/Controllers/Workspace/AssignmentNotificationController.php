<?php

namespace App\Http\Controllers\Workspace;

use App\Domain\Tenancy\TenantContext;
use App\Models\AssignmentNotification;
use App\Models\Tenant;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

final class AssignmentNotificationController
{
    public function __construct(private readonly TenantContext $context) {}

    public function index(Tenant $tenant): Response
    {
        $membership = $this->context->membership();
        abort_unless($membership->hasPermission('assignments.view'), 403);
        $notifications = AssignmentNotification::query()->where('tenant_id', $tenant->getKey())->where('user_id', $membership->user_id)
            ->orderByDesc('created_at')->paginate(30)->through(static fn (AssignmentNotification $notification): array => [
                'id' => (int) $notification->getKey(), 'type' => $notification->event_type,
                'title' => $notification->title, 'message' => $notification->message,
                'created_at' => $notification->created_at?->toIso8601String(), 'read' => $notification->read_at !== null,
            ]);

        return Inertia::render('workspace/assignments/notifications', ['tenant' => ['slug' => $tenant->slug], 'notifications' => $notifications]);
    }

    public function markRead(Tenant $tenant, int $notificationId): RedirectResponse
    {
        $membership = $this->context->membership();
        abort_unless($membership->hasPermission('assignments.view'), 403);
        AssignmentNotification::query()->where('tenant_id', $tenant->getKey())->where('user_id', $membership->user_id)
            ->whereNull('read_at')->findOrFail($notificationId)->forceFill(['read_at' => now()])->save();

        return back()->with('status', 'Notification marked as read.');
    }
}
