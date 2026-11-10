<?php

namespace App\Http\Controllers\Workspace;

use App\Domain\Learning\CourseDeliveryLiveSessionWorkflow;
use App\Domain\Tenancy\TenantContext;
use App\Http\Controllers\Controller;
use App\Models\CourseDeliveryAssignment;
use App\Models\CourseDeliveryLiveSession;
use App\Models\CourseDeliveryWorkspace;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

final class CourseDeliveryLiveSessionController extends Controller
{
    public function calendar(Request $request, Tenant $tenant, TenantContext $context): Response
    {
        $membership = $context->membership();
        $canManageAll = $membership->hasPermission('learning.delivery.manage');
        $filters = $request->validate([
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
            'status' => ['nullable', 'in:all,scheduled,cancelled'],
        ]);
        $query = CourseDeliveryLiveSession::query()->with(['workspace:id,code,title', 'lesson:id,title', 'instructor.user:id,name'])
            ->where('tenant_id', $context->tenant()->getKey())
            ->whereHas('workspace', fn (Builder $workspaces) => $workspaces->where('institution_id', $membership->institution_id));
        if ($membership->campus_id !== null) {
            $query->whereHas('workspace', fn (Builder $workspaces) => $workspaces->where('campus_id', $membership->campus_id));
        }
        if (! $canManageAll) {
            $roles = [];
            if ($membership->hasPermission('learning.delivery.teach')) {
                $roles = ['course_owner', 'coordinator', 'instructor', 'content_author'];
            }
            if ($membership->hasPermission('learning.delivery.review')) {
                $roles[] = 'reviewer';
            }
            if ($membership->hasPermission('learning.delivery.publish')) {
                $roles[] = 'publisher';
            }
            if ($membership->hasPermission('learning.delivery.learn')) {
                $roles[] = 'learner';
            }
            $query->whereIn('workspace_id', CourseDeliveryAssignment::query()->where('membership_id', $membership->getKey())
                ->where(function (Builder $assignments) use ($roles): void {
                    $staffRoles = array_values(array_diff($roles, ['learner']));
                    if ($roles === []) {
                        $assignments->whereRaw('1 = 0');

                        return;
                    }
                    if ($staffRoles !== []) {
                        $assignments->whereIn('assignment_role', $staffRoles);
                    }
                    if (in_array('learner', $roles, true)) {
                        $method = $staffRoles === [] ? 'where' : 'orWhere';
                        $assignments->{$method}(fn (Builder $learnerAssignment) => $learnerAssignment
                            ->where('assignment_role', 'learner')->whereHas('workspace', fn (Builder $workspace) => $workspace->where('status', 'published')));
                    }
                })->effective()->select('workspace_id'));
        }
        if (isset($filters['from'])) {
            $query->whereDate('starts_at', '>=', $filters['from']);
        }
        if (isset($filters['to'])) {
            $query->whereDate('starts_at', '<=', $filters['to']);
        }
        if (($filters['status'] ?? 'scheduled') !== 'all') {
            $query->where('status', $filters['status'] ?? 'scheduled');
        }
        $sessions = $query->orderBy('starts_at')->paginate(30)->withQueryString()->through(fn (CourseDeliveryLiveSession $session): array => [
            'id' => (int) $session->getKey(), 'title' => $session->title, 'status' => $session->status,
            'delivery_type' => $session->delivery_type, 'timezone' => $session->timezone,
            'starts_at' => $session->starts_at->toIso8601String(), 'ends_at' => $session->ends_at->toIso8601String(),
            'meeting_url' => $session->status === 'scheduled' ? $session->meeting_url : null,
            'venue' => $session->venue, 'workspace' => ['id' => (int) $session->workspace_id,
                'code' => $session->workspace->code, 'title' => $session->workspace->title],
            'lesson' => $session->lesson?->title, 'instructor' => $session->instructor?->user->name ?? 'Instructor',
        ]);

        return Inertia::render('workspace/learning/sessions', [
            'tenant' => ['slug' => $tenant->slug], 'sessions' => $sessions,
            'filters' => ['from' => (string) ($filters['from'] ?? ''), 'to' => (string) ($filters['to'] ?? ''), 'status' => (string) ($filters['status'] ?? 'scheduled')],
        ]);
    }

    public function store(Request $request, Tenant $tenant, int $workspaceId, CourseDeliveryLiveSessionWorkflow $workflow): RedirectResponse
    {
        $workspace = CourseDeliveryWorkspace::query()->findOrFail($workspaceId);
        $workflow->schedule($request->user(), $workspace, $this->validated($request, true));

        return to_route('tenant.learning.show', [$tenant, $workspaceId])->with('status', 'Live session scheduled.');
    }

    public function update(Request $request, Tenant $tenant, int $workspaceId, int $sessionId, CourseDeliveryLiveSessionWorkflow $workflow): RedirectResponse
    {
        $session = CourseDeliveryLiveSession::query()->where('workspace_id', $workspaceId)->findOrFail($sessionId);
        $workflow->reschedule($request->user(), $session, $this->validated($request, false));

        return to_route('tenant.learning.show', [$tenant, $workspaceId])->with('status', 'Live session details and time updated.');
    }

    public function cancel(Request $request, Tenant $tenant, int $workspaceId, int $sessionId, CourseDeliveryLiveSessionWorkflow $workflow): RedirectResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:1000']]);
        $session = CourseDeliveryLiveSession::query()->where('workspace_id', $workspaceId)->findOrFail($sessionId);
        $workflow->cancel($request->user(), $session, $data['reason']);

        return to_route('tenant.learning.show', [$tenant, $workspaceId])->with('status', 'Live session cancelled with an audit record.');
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, bool $creating): array
    {
        return $request->validate([
            'lesson_id' => [$creating ? 'nullable' : 'sometimes', 'integer', 'min:1'],
            'instructor_membership_id' => [$creating ? 'required' : 'sometimes', 'integer', 'min:1'],
            'title' => ['required', 'string', 'max:180'], 'description' => ['nullable', 'string', 'max:3000'],
            'delivery_type' => ['required', 'in:online,in_person,hybrid'],
            'timezone' => ['required', 'string', 'max:64'], 'starts_at' => ['required', 'date_format:Y-m-d\TH:i'],
            'ends_at' => ['required', 'date_format:Y-m-d\TH:i'],
            'meeting_url' => ['nullable', 'url:https', 'max:2048'], 'venue' => ['nullable', 'string', 'max:240'],
            'preparation_materials' => ['nullable', 'string', 'max:5000'], 'post_session_resources' => ['nullable', 'string', 'max:5000'],
        ]);
    }
}
