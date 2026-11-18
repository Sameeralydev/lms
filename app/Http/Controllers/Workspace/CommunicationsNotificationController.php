<?php

namespace App\Http\Controllers\Workspace;

use App\Domain\Audit\AuditRecorder;
use App\Domain\Tenancy\TenantContext;
use App\Models\CommunicationsNotification;
use App\Models\CommunicationsNotificationPreference;
use App\Models\Tenant;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
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

        $preferences = CommunicationsNotificationPreference::query()->where('user_id', $membership->user_id)
            ->pluck('in_app_enabled', 'event_type')->map(static fn ($enabled): bool => (bool) $enabled)->all();

        return Inertia::render('workspace/communications/notifications', [
            'tenant' => ['slug' => $tenant->slug], 'notifications' => $notifications, 'unreadCount' => $unreadCount,
            'preferences' => ['announcement' => $preferences['announcement'] ?? true, 'message' => $preferences['message'] ?? true],
        ]);
    }

    public function updatePreferences(Request $request, Tenant $tenant, AuditRecorder $audit): RedirectResponse
    {
        $membership = $this->context->membership();
        abort_unless($membership->hasPermission('communications.preferences.manage-own'), 403);
        $validated = $request->validate([
            'announcement' => ['required', 'boolean'],
            'message' => ['required', 'boolean'],
        ]);

        $timestamp = now();
        DB::table('communications_notification_preferences')->upsert(array_map(
            static fn (string $eventType, bool $enabled): array => [
                'tenant_id' => $tenant->getKey(), 'user_id' => $membership->user_id,
                'event_type' => $eventType, 'in_app_enabled' => $enabled,
                'created_at' => $timestamp, 'updated_at' => $timestamp,
            ],
            array_keys($validated), array_values($validated),
        ), ['tenant_id', 'user_id', 'event_type'], ['in_app_enabled', 'updated_at']);
        $audit->record('communications.preferences.updated', $request->user(), (int) $tenant->getKey(), null, [
            'event_types' => array_keys($validated),
        ]);

        return back()->with('status', 'Notification preferences saved.');
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
