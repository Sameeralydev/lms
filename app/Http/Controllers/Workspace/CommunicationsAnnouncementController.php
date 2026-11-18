<?php

namespace App\Http\Controllers\Workspace;

use App\Domain\Audit\AuditRecorder;
use App\Domain\Tenancy\TenantContext;
use App\Models\Campus;
use App\Models\CommunicationsAnnouncement;
use App\Models\CommunicationsNotificationPreference;
use App\Models\Tenant;
use App\Models\TenantMembership;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

final class CommunicationsAnnouncementController
{
    public function __construct(private readonly TenantContext $context) {}

    public function index(Request $request, Tenant $tenant): Response
    {
        $membership = $this->context->membership();
        abort_unless($membership->hasPermission('communications.announcements.view'), 403);
        $announcements = CommunicationsAnnouncement::query()
            ->where(function ($query) use ($membership): void {
                $query->where(function ($published) use ($membership): void {
                    $published->whereIn('status', ['published', 'scheduled'])->whereNotNull('published_at')->where('published_at', '<=', now());
                    $roleKeys = $membership->roles()->pluck('roles.key')->all();
                    $published->where(fn ($roles) => $roles->when($roleKeys !== [], function ($roles) use ($roleKeys): void {
                        $roles->where(function ($audience) use ($roleKeys): void {
                            foreach ($roleKeys as $roleKey) {
                                $audience->orWhereJsonContains('audience_roles', $roleKey);
                            }
                        });
                    }, fn ($roles) => $roles->whereRaw('1 = 0')));
                    if ($membership->campus_id !== null) {
                        $published->where(fn ($campus) => $campus->whereNull('campus_id')->orWhere('campus_id', $membership->campus_id));
                    }
                })->orWhere(function ($private) use ($membership): void {
                    $private->where(function ($owner) use ($membership): void {
                        $owner->where('created_by', $membership->user_id);
                        if ($membership->hasPermission('members.manage')) {
                            $owner->orWhereNotNull('created_by');
                        }
                    });
                    if ($membership->campus_id !== null) {
                        $private->where(fn ($campus) => $campus->whereNull('campus_id')->orWhere('campus_id', $membership->campus_id));
                    }
                });
            })
            ->with(['author:id,name', 'receipts' => fn ($query) => $query->where('user_id', $membership->user_id)])
            ->latest('published_at')->paginate(20)->withQueryString()
            ->through(static fn (CommunicationsAnnouncement $announcement): array => [
                'id' => (int) $announcement->getKey(),
                'title' => $announcement->title,
                'body' => $announcement->body,
                'author' => $announcement->author?->name,
                'published_at' => $announcement->published_at?->toIso8601String(),
                'status' => $announcement->status,
                'audience_roles' => $announcement->audience_roles ?? [],
                'campus_id' => $announcement->campus_id,
                'read' => $announcement->receipts->isNotEmpty() && $announcement->receipts->first()->read_at !== null,
                'can_edit' => ((int) $announcement->created_by === (int) $membership->user_id
                    || $membership->hasPermission('members.manage'))
                    && ($membership->campus_id === null || (int) $announcement->campus_id === (int) $membership->campus_id),
            ]);

        $roles = $membership->hasPermission('communications.announcements.manage')
            ? $tenant->roles()->where('scope', 'tenant')->orderBy('name')->get(['key', 'name'])->map(static fn ($role): array => ['key' => $role->key, 'name' => $role->name])->all()
            : [];
        $campuses = [];
        if ($membership->hasPermission('communications.announcements.manage')) {
            $campuses = $membership->campus_id === null
                ? Campus::query()->where('status', 'active')->orderBy('name')->get(['id', 'name'])->map(static fn (Campus $campus): array => ['id' => (int) $campus->getKey(), 'name' => $campus->name])->all()
                : Campus::query()->whereKey($membership->campus_id)->get(['id', 'name'])->map(static fn (Campus $campus): array => ['id' => (int) $campus->getKey(), 'name' => $campus->name])->all();
        }

        return Inertia::render('workspace/communications/announcements', [
            'tenant' => ['slug' => $tenant->slug],
            'announcements' => $announcements,
            'audienceRoles' => $roles,
            'campuses' => $campuses,
            'canManage' => $membership->hasPermission('communications.announcements.manage'),
        ]);
    }

    public function store(Request $request, Tenant $tenant, AuditRecorder $audit): RedirectResponse
    {
        $membership = $this->context->membership();
        abort_unless($membership->hasPermission('communications.announcements.manage'), 403);
        $validated = $request->validate([
            'title' => ['required', 'string', 'max:180'],
            'body' => ['required', 'string', 'max:10000'],
            'status' => ['required', 'in:draft,published,scheduled'],
            'published_at' => ['nullable', 'date'],
            'audience_roles' => ['required', 'array', 'min:1', 'max:20'],
            'audience_roles.*' => ['required', 'string', 'distinct', Rule::exists('roles', 'key')->where('tenant_id', $tenant->getKey())->where('scope', 'tenant')],
            'campus_id' => ['nullable', 'integer', Rule::exists('campuses', 'id')->where('tenant_id', $tenant->getKey())->where('status', 'active')],
        ]);
        $campusId = $membership->campus_id ?? ($validated['campus_id'] ?? null);
        if ($membership->campus_id !== null && isset($validated['campus_id']) && (int) $validated['campus_id'] !== (int) $membership->campus_id) {
            abort(403);
        }

        if ($validated['status'] === 'scheduled' && (empty($validated['published_at']) || now()->parse($validated['published_at'])->isPast())) {
            throw ValidationException::withMessages(['published_at' => 'Choose a future publication time.']);
        }
        $publishedAt = match ($validated['status']) {
            'published' => now(),
            'scheduled' => $validated['published_at'],
            default => null,
        };
        DB::transaction(function () use ($audit, $campusId, $membership, $publishedAt, $request, $tenant, $validated): void {
            $announcement = CommunicationsAnnouncement::query()->create([
                'campus_id' => $campusId,
                'created_by' => $membership->user_id,
                'title' => $validated['title'],
                'body' => $validated['body'],
                'audience_roles' => array_values(array_unique($validated['audience_roles'])),
                'status' => $validated['status'],
                'published_at' => $publishedAt,
            ]);
            $audit->record('communications.announcement.'.$validated['status'], $request->user(), (int) $tenant->getKey(), $announcement, [
                'announcement_id' => $announcement->getKey(),
                'campus_id' => $campusId,
                'audience_role_count' => count($validated['audience_roles']),
            ]);
            if ($validated['status'] === 'published') {
                $this->notifyAudience($tenant, $announcement, $validated['audience_roles'], $campusId, $validated['title']);
            }
        });

        $message = match ($validated['status']) {
            'draft' => 'Draft saved.',
            'scheduled' => 'Announcement scheduled.',
            default => 'Announcement published to the selected institution roles.',
        };

        return back()->with('status', $message);
    }

    public function update(Request $request, Tenant $tenant, int $announcementId, AuditRecorder $audit): RedirectResponse
    {
        $membership = $this->context->membership();
        abort_unless($membership->hasPermission('communications.announcements.manage'), 403);
        $announcement = CommunicationsAnnouncement::query()->whereKey($announcementId)->firstOrFail();
        abort_unless((int) $announcement->created_by === (int) $membership->user_id || $membership->hasPermission('members.manage'), 403);
        if ($membership->campus_id !== null && (int) $announcement->campus_id !== (int) $membership->campus_id) {
            abort(403);
        }
        $validated = $request->validate([
            'title' => ['required', 'string', 'max:180'],
            'body' => ['required', 'string', 'max:10000'],
            'status' => ['required', 'in:draft,published,scheduled,archived'],
            'published_at' => ['nullable', 'date'],
            'audience_roles' => ['required', 'array', 'min:1', 'max:20'],
            'audience_roles.*' => ['required', 'string', 'distinct', Rule::exists('roles', 'key')->where('tenant_id', $tenant->getKey())->where('scope', 'tenant')],
            'campus_id' => ['nullable', 'integer', Rule::exists('campuses', 'id')->where('tenant_id', $tenant->getKey())->where('status', 'active')],
        ]);
        if ($membership->campus_id !== null && isset($validated['campus_id']) && (int) $validated['campus_id'] !== (int) $membership->campus_id) {
            abort(403);
        }
        $campusId = array_key_exists('campus_id', $validated)
            ? ($membership->campus_id ?? $validated['campus_id'])
            : $announcement->campus_id;
        if ($validated['status'] === 'scheduled' && (empty($validated['published_at']) || now()->parse($validated['published_at'])->isPast())) {
            throw ValidationException::withMessages(['published_at' => 'Choose a future publication time.']);
        }
        $wasVisible = $announcement->status === 'published' && $announcement->published_at !== null && $announcement->published_at->lessThanOrEqualTo(now());
        $publishedAt = match ($validated['status']) {
            'published' => $wasVisible ? $announcement->published_at : now(),
            'scheduled' => $validated['published_at'],
            default => $announcement->published_at,
        };
        DB::transaction(function () use ($announcement, $audit, $campusId, $publishedAt, $request, $tenant, $validated, $wasVisible): void {
            $announcement->fill([
                'campus_id' => $campusId,
                'title' => $validated['title'],
                'body' => $validated['body'],
                'audience_roles' => array_values(array_unique($validated['audience_roles'])),
                'status' => $validated['status'],
                'published_at' => $publishedAt,
            ])->save();
            $audit->record('communications.announcement.updated', $request->user(), (int) $tenant->getKey(), $announcement, [
                'announcement_id' => $announcement->getKey(),
                'campus_id' => $campusId,
                'audience_role_count' => count($validated['audience_roles']),
            ]);
            if ($validated['status'] === 'published' && ! $wasVisible) {
                $this->notifyAudience($tenant, $announcement, $validated['audience_roles'], $campusId, $validated['title']);
            }
        });

        return back()->with('status', 'Announcement updated.');
    }

    public function markRead(Tenant $tenant, int $announcementId): RedirectResponse
    {
        $membership = $this->context->membership();
        abort_unless($membership->hasPermission('communications.announcements.view'), 403);
        $roleKeys = $membership->roles()->pluck('roles.key')->all();
        abort_if($roleKeys === [], 404);
        $announcement = CommunicationsAnnouncement::query()->whereKey($announcementId)
            ->whereIn('status', ['published', 'scheduled'])->whereNotNull('published_at')->where('published_at', '<=', now())
            ->where(function ($query) use ($roleKeys): void {
                foreach ($roleKeys as $key) {
                    $query->orWhereJsonContains('audience_roles', $key);
                }
            })
            ->when($membership->campus_id !== null, fn ($query) => $query->where(fn ($scope) => $scope
                ->whereNull('campus_id')->orWhere('campus_id', $membership->campus_id)))
            ->firstOrFail();

        $timestamp = now();
        DB::table('communications_announcement_receipts')->insertOrIgnore([
            'tenant_id' => $tenant->getKey(),
            'announcement_id' => $announcement->getKey(),
            'user_id' => $membership->user_id,
            'read_at' => $timestamp,
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);
        DB::table('communications_announcement_receipts')->where('tenant_id', $tenant->getKey())
            ->where('announcement_id', $announcement->getKey())->where('user_id', $membership->user_id)
            ->whereNull('read_at')->update(['read_at' => $timestamp, 'updated_at' => $timestamp]);

        return back()->with('status', 'Announcement marked as read.');
    }

    public function archive(Request $request, Tenant $tenant, int $announcementId, AuditRecorder $audit): RedirectResponse
    {
        $membership = $this->context->membership();
        abort_unless($membership->hasPermission('communications.announcements.manage'), 403);
        $announcement = CommunicationsAnnouncement::query()->whereKey($announcementId)->firstOrFail();
        abort_unless((int) $announcement->created_by === (int) $membership->user_id || $membership->hasPermission('members.manage'), 403);
        abort_if($membership->campus_id !== null && (int) $announcement->campus_id !== (int) $membership->campus_id, 403);

        DB::transaction(function () use ($announcement, $audit, $request, $tenant): void {
            $announcement->forceFill(['status' => 'archived'])->save();
            $audit->record('communications.announcement.archived', $request->user(), (int) $tenant->getKey(), $announcement, [
                'announcement_id' => $announcement->getKey(), 'campus_id' => $announcement->campus_id,
            ]);
        });

        return back()->with('status', 'Announcement archived.');
    }

    private function notifyAudience(Tenant $tenant, CommunicationsAnnouncement $announcement, array $audienceRoles, ?int $campusId, string $title): void
    {
        $recipients = TenantMembership::query()->where('tenant_id', $tenant->getKey())->where('status', 'active')
            ->whereHas('roles', fn ($query) => $query->where('roles.tenant_id', $tenant->getKey())->whereIn('roles.key', $audienceRoles))
            ->when($campusId !== null, fn ($query) => $query->where('campus_id', $campusId))
            ->distinct()->pluck('user_id')->all();
        $disabledUsers = CommunicationsNotificationPreference::query()->where('event_type', 'announcement')
            ->where('tenant_id', $tenant->getKey())->where('in_app_enabled', false)->whereIn('user_id', $recipients)
            ->pluck('user_id')->map(static fn ($id): int => (int) $id)->all();
        $recipients = array_values(array_diff(array_map('intval', $recipients), $disabledUsers));
        $timestamp = now();
        $rows = array_map(static fn (int $userId): array => [
            'tenant_id' => $tenant->getKey(), 'user_id' => $userId, 'type' => 'announcement',
            'reference_id' => $announcement->getKey(), 'context_id' => null, 'title' => 'New institution announcement',
            'summary' => mb_substr($title, 0, 500), 'read_at' => null,
            'created_at' => $timestamp, 'updated_at' => $timestamp,
        ], $recipients);
        if ($rows !== []) {
            DB::table('communications_notifications')->insertOrIgnore($rows);
        }
    }
}
