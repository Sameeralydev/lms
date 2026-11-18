<?php

namespace App\Http\Controllers\Workspace;

use App\Domain\Audit\AuditRecorder;
use App\Domain\Tenancy\TenantContext;
use App\Models\Campus;
use App\Models\CommunicationsAnnouncement;
use App\Models\Tenant;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

final class CommunicationsAnnouncementController
{
    public function __construct(private readonly TenantContext $context) {}

    public function index(Request $request, Tenant $tenant): Response
    {
        $membership = $this->context->membership();
        abort_unless($membership->hasPermission('communications.announcements.view'), 403);
        $roleKeys = $membership->roles()->pluck('roles.key')->all();

        $announcements = CommunicationsAnnouncement::query()
            ->where('status', 'published')
            ->whereNotNull('published_at')->where('published_at', '<=', now())
            ->when($roleKeys !== [], fn ($query) => $query->where(function ($query) use ($roleKeys): void {
                foreach ($roleKeys as $key) {
                    $query->orWhereJsonContains('audience_roles', $key);
                }
            }), fn ($query) => $query->whereRaw('1 = 0'))
            ->when($membership->campus_id !== null, fn ($query) => $query->where(fn ($scope) => $scope
                ->whereNull('campus_id')->orWhere('campus_id', $membership->campus_id)))
            ->with(['author:id,name', 'receipts' => fn ($query) => $query->where('user_id', $membership->user_id)])
            ->latest('published_at')->paginate(20)->withQueryString()
            ->through(static fn (CommunicationsAnnouncement $announcement): array => [
                'id' => (int) $announcement->getKey(),
                'title' => $announcement->title,
                'body' => $announcement->body,
                'author' => $announcement->author?->name,
                'published_at' => $announcement->published_at?->toIso8601String(),
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
            'audience_roles' => ['required', 'array', 'min:1', 'max:20'],
            'audience_roles.*' => ['required', 'string', 'distinct', Rule::exists('roles', 'key')->where('tenant_id', $tenant->getKey())->where('scope', 'tenant')],
            'campus_id' => ['nullable', 'integer', Rule::exists('campuses', 'id')->where('tenant_id', $tenant->getKey())->where('status', 'active')],
        ]);
        $campusId = $membership->campus_id ?? ($validated['campus_id'] ?? null);
        if ($membership->campus_id !== null && isset($validated['campus_id']) && (int) $validated['campus_id'] !== (int) $membership->campus_id) {
            abort(403);
        }

        DB::transaction(function () use ($audit, $campusId, $membership, $request, $tenant, $validated): void {
            $announcement = CommunicationsAnnouncement::query()->create([
                'campus_id' => $campusId,
                'created_by' => $membership->user_id,
                'title' => $validated['title'],
                'body' => $validated['body'],
                'audience_roles' => array_values(array_unique($validated['audience_roles'])),
                'status' => 'published',
                'published_at' => now(),
            ]);
            $audit->record('communications.announcement.published', $request->user(), (int) $tenant->getKey(), $announcement, [
                'announcement_id' => $announcement->getKey(),
                'campus_id' => $campusId,
                'audience_role_count' => count($validated['audience_roles']),
            ]);
        });

        return back()->with('status', 'Announcement published to the selected institution roles.');
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
        DB::transaction(function () use ($announcement, $audit, $campusId, $request, $tenant, $validated): void {
            $announcement->fill([
                'campus_id' => $campusId,
                'title' => $validated['title'],
                'body' => $validated['body'],
                'audience_roles' => array_values(array_unique($validated['audience_roles'])),
            ])->save();
            $audit->record('communications.announcement.updated', $request->user(), (int) $tenant->getKey(), $announcement, [
                'announcement_id' => $announcement->getKey(),
                'campus_id' => $campusId,
                'audience_role_count' => count($validated['audience_roles']),
            ]);
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
            ->where('status', 'published')->whereNotNull('published_at')->where('published_at', '<=', now())
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
}
