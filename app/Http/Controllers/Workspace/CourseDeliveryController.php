<?php

namespace App\Http\Controllers\Workspace;

use App\Domain\Learning\CourseDeliveryMediaWorkflow;
use App\Domain\Learning\CourseDeliveryWorkflow;
use App\Domain\Tenancy\TenantContext;
use App\Http\Controllers\Controller;
use App\Models\AcademicCourseVersion;
use App\Models\CourseDeliveryActivityResponse;
use App\Models\CourseDeliveryAssignment;
use App\Models\CourseDeliveryBlock;
use App\Models\CourseDeliveryLesson;
use App\Models\CourseDeliveryModule;
use App\Models\CourseDeliveryProgress;
use App\Models\CourseDeliveryReleaseException;
use App\Models\CourseDeliveryWorkspace;
use App\Models\Tenant;
use App\Models\TenantMembership;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

final class CourseDeliveryController extends Controller
{
    public function index(Tenant $tenant, TenantContext $context): Response
    {
        $institutionId = $context->tenant()->institution()->value('id');
        $membership = $context->membership();
        $workspacesQuery = CourseDeliveryWorkspace::query()->with(['course:id,title,code', 'courseVersion:id,title,version_number,status', 'campus:id,name'])
            ->withCount(['modules'])->latest('updated_at');
        if ($membership->campus_id !== null) {
            $workspacesQuery->where('campus_id', $membership->campus_id);
        }
        if (! $membership->hasPermission('learning.delivery.manage')) {
            $roles = [];
            if ($membership->hasPermission('learning.delivery.teach')) {
                $roles = [...$roles, 'coordinator', 'instructor', 'content_author'];
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
            $workspacesQuery->whereHas('assignments', fn ($query) => $query->where('membership_id', $membership->getKey())->effective()->whereIn('assignment_role', $roles));
        }
        $workspaces = $workspacesQuery->paginate(12)->withQueryString()->through(fn (CourseDeliveryWorkspace $workspace): array => [
            'id' => $workspace->getKey(), 'code' => $workspace->code, 'title' => $workspace->title,
            'description' => $workspace->description, 'status' => $workspace->status, 'delivery_mode' => $workspace->delivery_mode,
            'modules_count' => $workspace->modules_count,
            'campus' => $workspace->campus ? ['id' => $workspace->campus->getKey(), 'name' => $workspace->campus->name] : null,
            'course' => ['title' => $workspace->course->title, 'code' => $workspace->course->code],
            'course_version' => ['title' => $workspace->courseVersion->title, 'version_number' => $workspace->courseVersion->version_number],
        ]);
        $courseVersions = AcademicCourseVersion::query()->with('course:id,title,code')->where('status', 'published')
            ->whereHas('course', fn ($query) => $query->where('institution_id', $institutionId))
            ->orderBy('title')->get(['id', 'academic_course_id', 'version_number', 'title'])
            ->map(fn (AcademicCourseVersion $version): array => [
                'id' => $version->getKey(), 'academic_course_id' => $version->academic_course_id,
                'version_number' => $version->version_number, 'title' => $version->title,
                'course' => ['id' => $version->course->getKey(), 'title' => $version->course->title, 'code' => $version->course->code],
            ])->values();
        $campuses = $context->tenant()->campuses()->where('status', 'active')->orderBy('name')->get(['id', 'name', 'code']);
        if ($membership->campus_id !== null) {
            $campuses = $campuses->where('id', $membership->campus_id)->values();
        }

        return Inertia::render('workspace/learning/index', [
            'tenant' => ['slug' => $context->tenant()->slug], 'workspaces' => $workspaces,
            'courseVersions' => $courseVersions, 'campuses' => $campuses,
            'scopeCampusId' => $membership->campus_id,
            'canManage' => $membership->hasPermission('learning.delivery.manage'),
            'canAnalytics' => $membership->hasPermission('learning.delivery.manage')
                || $membership->hasPermission('learning.delivery.teach')
                || $membership->hasPermission('learning.delivery.review'),
        ]);
    }

    public function store(Request $request, Tenant $tenant, TenantContext $context, CourseDeliveryWorkflow $workflow): RedirectResponse
    {
        abort_unless($context->membership()->hasPermission('learning.delivery.manage'), 403);
        $data = $request->validate([
            'academic_course_id' => ['required', 'integer'],
            'academic_course_version_id' => ['required', 'integer'],
            'campus_id' => ['nullable', 'integer'],
            'academic_program_id' => ['nullable', 'integer'],
            'academic_curriculum_version_id' => ['nullable', 'integer'],
            'academic_year_id' => ['nullable', 'integer'],
            'code' => ['required', 'string', 'max:64', 'regex:/^[A-Za-z0-9._-]+$/'],
            'title' => ['required', 'string', 'max:180'], 'description' => ['nullable', 'string', 'max:5000'],
            'delivery_mode' => ['required', 'in:self_paced,instructor_paced,blended'],
        ]);
        $workspace = $workflow->createWorkspace($request->user(), $data);

        return to_route('tenant.learning.show', ['tenant' => $tenant, 'workspaceId' => $workspace->getKey()])->with('status', 'Course delivery workspace created.');
    }

    public function show(Tenant $tenant, int $workspaceId, TenantContext $context, CourseDeliveryWorkflow $workflow): Response
    {
        $membership = $context->membership();
        $isManager = $membership->hasPermission('learning.delivery.manage');
        $isTeacher = $membership->hasPermission('learning.delivery.teach');
        $isReviewer = $membership->hasPermission('learning.delivery.review');
        $relations = ['course:id,title,code', 'courseVersion:id,title,version_number,status', 'curriculumVersion:id,curriculum_id,version_number,status', 'modules.lessons'];
        if ($isManager || $isTeacher || $isReviewer) {
            $relations[] = 'modules.lessons.blocks';
        }
        if ($isManager) {
            $relations[] = 'assignments.membership.user';
        }
        $workspace = CourseDeliveryWorkspace::query()->with($relations)->findOrFail($workspaceId);
        $workflow->assertCanView(request()->user(), $workspace);
        $canConfirmCompletion = $isManager || ($isTeacher && CourseDeliveryAssignment::query()
            ->where('workspace_id', $workspace->getKey())->where('membership_id', $membership->getKey())->effective()
            ->whereIn('assignment_role', ['course_owner', 'coordinator', 'instructor'])->exists());
        $publishedSnapshots = collect();
        if (! ($isManager || $isTeacher || $isReviewer) && $workspace->status === 'published') {
            $publishedSnapshots = DB::table('course_delivery_lesson_revisions as revisions')
                ->join('course_delivery_lessons as lessons', function ($join): void {
                    $join->on('lessons.tenant_id', '=', 'revisions.tenant_id')
                        ->on('lessons.id', '=', 'revisions.lesson_id')
                        ->on('lessons.published_version_number', '=', 'revisions.version_number');
                })
                ->where('lessons.tenant_id', $workspace->tenant_id)->where('lessons.workspace_id', $workspace->getKey())
                ->get(['revisions.lesson_id', 'revisions.lesson_snapshot'])
                ->mapWithKeys(fn ($row): array => [(int) $row->lesson_id => json_decode((string) $row->lesson_snapshot, true)]);
        }
        $members = $isManager ? TenantMembership::query()->with(['user:id,name', 'roles.permissions'])
            ->where('institution_id', $workspace->institution_id)->where('status', 'active')
            ->where(fn ($query) => $workspace->campus_id === null
                ? $query->whereNull('campus_id')
                : $query->whereNull('campus_id')->orWhere('campus_id', $workspace->campus_id))
            ->orderBy('id')->limit(250)->get()
            ->map(fn (TenantMembership $member): array => [
                'id' => $member->getKey(), 'name' => $member->user->name ?? 'Account',
                'permissions' => $member->roles->flatMap(fn ($role) => $role->permissions->pluck('key'))->unique(),
            ])->map(fn (array $member): array => [
                'id' => $member['id'], 'name' => $member['name'],
                'canTeach' => $member['permissions']->contains('learning.delivery.teach'),
                'canReview' => $member['permissions']->contains('learning.delivery.review'),
                'canLearn' => $member['permissions']->contains('learning.delivery.learn'),
            ])->values() : collect();
        $workspaceData = [
            'id' => $workspace->getKey(), 'code' => $workspace->code, 'title' => $workspace->title,
            'description' => $workspace->description, 'status' => $workspace->status, 'delivery_mode' => $workspace->delivery_mode,
            'campus' => $workspace->campus ? ['id' => $workspace->campus->getKey(), 'name' => $workspace->campus->name] : null,
            'lock_version' => $workspace->lock_version,
            'course' => ['id' => $workspace->course->getKey(), 'title' => $workspace->course->title, 'code' => $workspace->course->code],
            'course_version' => ['id' => $workspace->courseVersion->getKey(), 'title' => $workspace->courseVersion->title, 'version_number' => $workspace->courseVersion->version_number],
            'curriculum_version' => $workspace->curriculumVersion ? ['id' => $workspace->curriculumVersion->getKey(), 'version_number' => $workspace->curriculumVersion->version_number, 'status' => $workspace->curriculumVersion->status] : null,
            'modules' => $workspace->modules->map(fn (CourseDeliveryModule $module): array => [
                'id' => $module->getKey(), 'title' => $module->title, 'summary' => $module->summary, 'sort_order' => $module->sort_order,
                'lessons' => $module->lessons->filter(fn (CourseDeliveryLesson $lesson): bool => $isManager || $isTeacher || $isReviewer || ($workspace->status === 'published' && $lesson->published_version_number !== null))
                    ->map(function (CourseDeliveryLesson $lesson) use ($isManager, $isTeacher, $isReviewer, $publishedSnapshots): array {
                        $authoringView = $isManager || $isTeacher || $isReviewer;
                        $snapshot = $publishedSnapshots->get((int) $lesson->getKey(), []);
                        $snapshot = is_array($snapshot) ? $snapshot : [];

                        return [
                            'id' => $lesson->getKey(), 'title' => $authoringView ? $lesson->title : ($snapshot['title'] ?? ''),
                            'summary' => $authoringView ? $lesson->summary : ($snapshot['summary'] ?? null),
                            'learning_objectives' => $authoringView ? $lesson->learning_objectives : ($snapshot['learning_objectives'] ?? null),
                            'estimated_minutes' => $authoringView ? $lesson->estimated_minutes : ($snapshot['estimated_minutes'] ?? null),
                            'status' => $authoringView ? $lesson->status : 'published', 'lock_version' => $lesson->lock_version,
                            'published_version_number' => $lesson->published_version_number, 'review_note' => $lesson->review_note,
                            'blocks' => $authoringView ? $lesson->blocks->map(fn (CourseDeliveryBlock $block): array => $this->serializeBlock($block))->values() : [],
                        ];
                    })->values(),
            ])->values(),
            'assignments' => $isManager ? $workspace->assignments->map(fn (CourseDeliveryAssignment $assignment): array => [
                'id' => $assignment->getKey(), 'role' => $assignment->assignment_role, 'status' => $assignment->status,
                'change_reason' => $assignment->change_reason,
                'effective_from' => $assignment->effective_from?->toDateString(), 'effective_until' => $assignment->effective_until?->toDateString(),
                'member' => ['id' => $assignment->membership->getKey(), 'name' => $assignment->membership->user->name ?? 'Account'],
            ])->values() : [],
        ];
        $canLearn = $membership->hasPermission('learning.delivery.learn')
            && $workspace->assignments()->where('membership_id', $membership->getKey())->effective()->where('assignment_role', 'learner')->exists();
        $canManageSessions = $isManager || ($isTeacher && $workspace->assignments()->where('membership_id', $membership->getKey())
            ->effective()->whereIn('assignment_role', ['course_owner', 'coordinator', 'instructor'])->exists());
        $sessionRows = DB::table('course_delivery_live_sessions as sessions')
            ->join('tenant_memberships as memberships', function ($join): void {
                $join->on('memberships.tenant_id', '=', 'sessions.tenant_id')->on('memberships.id', '=', 'sessions.instructor_membership_id');
            })->join('users', 'users.id', '=', 'memberships.user_id')
            ->leftJoin('course_delivery_lessons as lessons', function ($join): void {
                $join->on('lessons.tenant_id', '=', 'sessions.tenant_id')->on('lessons.workspace_id', '=', 'sessions.workspace_id')->on('lessons.id', '=', 'sessions.lesson_id');
            })->where('sessions.tenant_id', $workspace->tenant_id)->where('sessions.workspace_id', $workspace->getKey())
            ->orderBy('sessions.starts_at')->get([
                'sessions.id', 'sessions.title', 'sessions.description', 'sessions.delivery_type', 'sessions.timezone',
                'sessions.starts_at', 'sessions.ends_at', 'sessions.meeting_url', 'sessions.venue', 'sessions.preparation_materials',
                'sessions.post_session_resources', 'sessions.status', 'sessions.cancellation_reason', 'users.name as instructor_name', 'lessons.title as lesson_title',
            ]);
        $sessions = $sessionRows->map(fn (object $session): array => [
            'id' => (int) $session->id, 'title' => (string) $session->title, 'description' => $session->description,
            'delivery_type' => (string) $session->delivery_type, 'timezone' => (string) $session->timezone,
            'starts_at' => Carbon::parse((string) $session->starts_at, 'UTC')->toIso8601String(),
            'ends_at' => Carbon::parse((string) $session->ends_at, 'UTC')->toIso8601String(),
            'meeting_url' => $session->meeting_url, 'venue' => $session->venue,
            'preparation_materials' => $session->preparation_materials, 'post_session_resources' => $session->post_session_resources,
            'status' => (string) $session->status, 'instructor' => (string) $session->instructor_name,
            'lesson' => $session->lesson_title, 'cancellation_reason' => $session->cancellation_reason,
        ])->values();
        $today = now()->toDateString();
        $sessionInstructors = DB::table('course_delivery_assignments as assignments')
            ->join('tenant_memberships as memberships', function ($join): void {
                $join->on('memberships.tenant_id', '=', 'assignments.tenant_id')->on('memberships.id', '=', 'assignments.membership_id');
            })->join('users', 'users.id', '=', 'memberships.user_id')
            ->where('assignments.tenant_id', $workspace->tenant_id)->where('assignments.workspace_id', $workspace->getKey())
            ->where('assignments.status', 'active')->where('memberships.status', 'active')
            ->whereIn('assignments.assignment_role', ['course_owner', 'coordinator', 'instructor'])
            ->where(fn ($query) => $query->whereNull('assignments.effective_from')->orWhereDate('assignments.effective_from', '<=', $today))
            ->where(fn ($query) => $query->whereNull('assignments.effective_until')->orWhereDate('assignments.effective_until', '>=', $today))
            ->orderBy('users.name')->distinct()->get(['memberships.id', 'users.name'])->map(fn (object $member): array => [
                'id' => (int) $member->id, 'name' => (string) $member->name,
            ])->values();
        $institutionTimezone = (string) $workspace->institution()->value('timezone');
        $releaseExceptions = ($isManager || $isTeacher) ? DB::table('course_delivery_release_exceptions as exceptions')
            ->join('tenant_memberships as members', function ($join): void {
                $join->on('members.tenant_id', '=', 'exceptions.tenant_id')->on('members.id', '=', 'exceptions.membership_id');
            })->join('users as learners', 'learners.id', '=', 'members.user_id')
            ->where('exceptions.tenant_id', $workspace->tenant_id)->where('exceptions.workspace_id', $workspace->getKey())
            ->orderByDesc('exceptions.created_at')->limit(100)
            ->get(['exceptions.id', 'exceptions.lesson_id', 'exceptions.membership_id', 'exceptions.requested_by', 'exceptions.reason', 'exceptions.status', 'exceptions.expires_at', 'exceptions.approved_by', 'exceptions.revoked_at', 'learners.name as learner_name'])
            ->map(fn (object $exception): array => [
                'id' => (int) $exception->id, 'lesson_id' => (int) $exception->lesson_id,
                'membership_id' => (int) $exception->membership_id, 'requested_by' => (int) $exception->requested_by,
                'reason' => (string) $exception->reason, 'status' => (string) $exception->status,
                'expires_at' => Carbon::parse((string) $exception->expires_at, 'UTC')->toIso8601String(),
                'approved_by' => $exception->approved_by === null ? null : (int) $exception->approved_by,
                'revoked_at' => $exception->revoked_at, 'learner_name' => (string) $exception->learner_name,
            ])->values() : collect();

        return Inertia::render('workspace/learning/show', [
            'tenant' => ['slug' => $tenant->slug], 'workspace' => $workspaceData,
            'members' => $members,
            'releaseExceptions' => $releaseExceptions,
            'canApproveReleaseExceptions' => $membership->hasPermission('learning.delivery.release-exceptions.approve'),
            'actorUserId' => (int) request()->user()->getKey(),
            'canManage' => $isManager || ($isTeacher && $workspace->assignments()->where('membership_id', $membership->getKey())->effective()->whereIn('assignment_role', ['course_owner', 'coordinator', 'instructor', 'content_author'])->exists()),
            'canReview' => $isReviewer && $workspace->assignments()->where('membership_id', $membership->getKey())->effective()->where('assignment_role', 'reviewer')->exists(),
            'canPublish' => $membership->hasPermission('learning.delivery.publish') && ($isManager || $workspace->assignments()->where('membership_id', $membership->getKey())->effective()->where('assignment_role', 'publisher')->exists()),
            'canLearn' => $canLearn,
            'canConfirmCompletion' => $canConfirmCompletion,
            'sessions' => $sessions, 'sessionInstructors' => $sessionInstructors,
            'canManageSessions' => $canManageSessions, 'institutionTimezone' => $institutionTimezone ?: 'UTC',
        ]);
    }

    public function storeModule(Request $request, Tenant $tenant, int $workspaceId, CourseDeliveryWorkflow $workflow): RedirectResponse
    {
        $workspace = CourseDeliveryWorkspace::query()->findOrFail($workspaceId);
        $data = $request->validate(['title' => ['required', 'string', 'max:180'], 'summary' => ['nullable', 'string', 'max:2000']]);
        $workflow->createModule($request->user(), $workspace, $data);

        return $this->workspaceRedirect($tenant, $workspaceId, 'Module added.');
    }

    public function reorderModules(Request $request, Tenant $tenant, int $workspaceId, CourseDeliveryWorkflow $workflow): RedirectResponse
    {
        $workspace = CourseDeliveryWorkspace::query()->findOrFail($workspaceId);
        $data = $request->validate(['module_ids' => ['required', 'array', 'min:1', 'max:500'], 'module_ids.*' => ['required', 'integer', 'min:1', 'distinct']]);
        $workflow->reorderModules($request->user(), $workspace, $data['module_ids']);

        return $this->workspaceRedirect($tenant, $workspaceId, 'Module order saved.');
    }

    public function reorderLessons(Request $request, Tenant $tenant, int $workspaceId, int $moduleId, CourseDeliveryWorkflow $workflow): RedirectResponse
    {
        $workspace = CourseDeliveryWorkspace::query()->findOrFail($workspaceId);
        $module = CourseDeliveryModule::query()->where('workspace_id', $workspaceId)->findOrFail($moduleId);
        $data = $request->validate(['lesson_ids' => ['required', 'array', 'min:1', 'max:500'], 'lesson_ids.*' => ['required', 'integer', 'min:1', 'distinct']]);
        $workflow->reorderLessons($request->user(), $workspace, $module, $data['lesson_ids']);

        return $this->workspaceRedirect($tenant, $workspaceId, 'Lesson order saved.');
    }

    public function storeLesson(Request $request, Tenant $tenant, int $workspaceId, int $moduleId, CourseDeliveryWorkflow $workflow): RedirectResponse
    {
        $module = CourseDeliveryModule::query()->where('workspace_id', $workspaceId)->findOrFail($moduleId);
        $data = $request->validate([
            'title' => ['required', 'string', 'max:180'], 'summary' => ['nullable', 'string', 'max:2000'],
            'learning_objectives' => ['nullable', 'string', 'max:4000'], 'estimated_minutes' => ['nullable', 'integer', 'min:1', 'max:1440'],
        ]);
        $workflow->createLesson($request->user(), $module, $data);

        return $this->workspaceRedirect($tenant, $workspaceId, 'Lesson added as a draft.');
    }

    public function updateLesson(Request $request, Tenant $tenant, int $workspaceId, int $lessonId, CourseDeliveryWorkflow $workflow): RedirectResponse
    {
        $lesson = CourseDeliveryLesson::query()->where('workspace_id', $workspaceId)->findOrFail($lessonId);
        $data = $request->validate([
            'lock_version' => ['required', 'integer', 'min:1'], 'title' => ['required', 'string', 'max:180'],
            'summary' => ['nullable', 'string', 'max:2000'], 'learning_objectives' => ['required', 'string', 'max:4000'],
            'estimated_minutes' => ['nullable', 'integer', 'min:1', 'max:1440'],
            'blocks' => ['present', 'array', 'max:50'],
            'blocks.*.type' => ['required', 'string', 'in:text,heading,callout,code,divider,external_link,reflection,activity,release_policy,completion_policy'],
            'blocks.*.content' => ['required', 'array'],
        ]);
        $workflow->updateLesson($request->user(), $lesson, $data);

        return $this->workspaceRedirect($tenant, $workspaceId, 'Draft lesson saved.');
    }

    public function submitLesson(Request $request, Tenant $tenant, int $workspaceId, int $lessonId, CourseDeliveryWorkflow $workflow): RedirectResponse
    {
        $lesson = CourseDeliveryLesson::query()->where('workspace_id', $workspaceId)->findOrFail($lessonId);
        $workflow->submitLesson($request->user(), $lesson);

        return $this->workspaceRedirect($tenant, $workspaceId, 'Lesson submitted for independent review.');
    }

    public function reviewLesson(Request $request, Tenant $tenant, int $workspaceId, int $lessonId, CourseDeliveryWorkflow $workflow): RedirectResponse
    {
        $lesson = CourseDeliveryLesson::query()->where('workspace_id', $workspaceId)->findOrFail($lessonId);
        $data = $request->validate(['decision' => ['required', 'in:approved,changes_requested,rejected'], 'note' => ['nullable', 'string', 'max:2000']]);
        $workflow->reviewLesson($request->user(), $lesson, $data['decision'], $data['note'] ?? null);

        return $this->workspaceRedirect($tenant, $workspaceId, 'Review decision recorded.');
    }

    public function publishLesson(Request $request, Tenant $tenant, int $workspaceId, int $lessonId, CourseDeliveryWorkflow $workflow): RedirectResponse
    {
        $lesson = CourseDeliveryLesson::query()->where('workspace_id', $workspaceId)->findOrFail($lessonId);
        $workflow->publishLesson($request->user(), $lesson);

        return $this->workspaceRedirect($tenant, $workspaceId, 'Reviewed lesson published as an immutable version.');
    }

    public function reviseLesson(Request $request, Tenant $tenant, int $workspaceId, int $lessonId, CourseDeliveryWorkflow $workflow): RedirectResponse
    {
        $lesson = CourseDeliveryLesson::query()->where('workspace_id', $workspaceId)->findOrFail($lessonId);
        $workflow->reviseLesson($request->user(), $lesson);

        return $this->workspaceRedirect($tenant, $workspaceId, 'A new editable draft was opened from the published snapshot.');
    }

    public function publishWorkspace(Request $request, Tenant $tenant, int $workspaceId, CourseDeliveryWorkflow $workflow): RedirectResponse
    {
        $workspace = CourseDeliveryWorkspace::query()->findOrFail($workspaceId);
        $workflow->publishWorkspace($request->user(), $workspace);

        return $this->workspaceRedirect($tenant, $workspaceId, 'Workspace published. Learner access still requires an explicit learner assignment.');
    }

    public function assign(Request $request, Tenant $tenant, int $workspaceId, CourseDeliveryWorkflow $workflow): RedirectResponse
    {
        $workspace = CourseDeliveryWorkspace::query()->findOrFail($workspaceId);
        $data = $request->validate([
            'membership_id' => ['required', 'integer'], 'assignment_role' => ['required', 'in:course_owner,coordinator,instructor,content_author,reviewer,publisher,learner'],
            'effective_from' => ['nullable', 'date'], 'effective_until' => ['nullable', 'date', 'after_or_equal:effective_from'],
            'responsibility' => ['nullable', 'string', 'max:1000'],
        ]);
        $workflow->assign($request->user(), $workspace, $data);

        return $this->workspaceRedirect($tenant, $workspaceId, 'Scoped course assignment recorded.');
    }

    public function revokeAssignment(Request $request, Tenant $tenant, int $workspaceId, int $assignmentId, CourseDeliveryWorkflow $workflow): RedirectResponse
    {
        $workspace = CourseDeliveryWorkspace::query()->findOrFail($workspaceId);
        $data = $request->validate(['reason' => ['required', 'string', 'min:8', 'max:1000']]);
        $workflow->revokeAssignment($request->user(), $workspace, $assignmentId, $data['reason']);

        return $this->workspaceRedirect($tenant, $workspaceId, 'Course assignment revoked; effective access ended immediately.');
    }

    public function learnerLesson(Request $request, Tenant $tenant, int $workspaceId, int $lessonId, TenantContext $context, CourseDeliveryWorkflow $workflow): Response
    {
        $workspace = CourseDeliveryWorkspace::query()->findOrFail($workspaceId);
        $lesson = CourseDeliveryLesson::query()->where('workspace_id', $workspaceId)->findOrFail($lessonId);
        $progress = $workflow->recordLessonAccess($request->user(), $workspace, $lesson);
        $revision = $workflow->assertCanLearn($request->user(), $workspace, $lesson);
        $snapshot = $revision->lesson_snapshot;
        $snapshotBlocks = $snapshot['blocks'] ?? [];
        $snapshot['blocks'] = array_values(array_filter(
            array_map(function (mixed $block) use ($tenant, $workspace, $lesson): mixed {
                if (! is_array($block) || ($block['type'] ?? null) !== 'media_asset' || ! is_array($block['content'] ?? null)) {
                    return $block;
                }
                $content = $block['content'];
                $assetId = (int) ($content['asset_id'] ?? 0);

                return ['type' => 'media_asset', 'content' => [
                    'asset_id' => $assetId, 'media_type' => $content['media_type'] ?? 'resource',
                    'mime_type' => $content['mime_type'] ?? 'application/octet-stream',
                    'original_name' => $content['original_name'] ?? 'Learning resource',
                    'size_bytes' => $content['size_bytes'] ?? 0, 'alt_text' => $content['alt_text'] ?? null,
                    'license_label' => $content['license_label'] ?? null, 'attribution' => $content['attribution'] ?? null,
                    'transcript' => $content['transcript'] ?? null,
                    'url' => route('tenant.learning.media.show', [$tenant, $workspace->getKey(), $lesson->getKey(), $assetId]),
                ]];
            }, is_array($snapshotBlocks) ? $snapshotBlocks : []),
            static fn (mixed $block): bool => ! is_array($block) || ! in_array(($block['type'] ?? null), ['release_policy', 'completion_policy'], true),
        ));
        $activityResponses = CourseDeliveryActivityResponse::query()
            ->where('tenant_id', $tenant->getKey())
            ->where('workspace_id', $workspace->getKey())
            ->where('lesson_id', $lesson->getKey())
            ->where('membership_id', $context->membership()->getKey())
            ->where('revision_version', $revision->version_number)
            ->orderByDesc('version_number')
            ->get()
            ->unique('block_id')
            ->mapWithKeys(fn (CourseDeliveryActivityResponse $response): array => [(int) $response->block_id => [
                'state' => $response->state,
                'version' => $response->version_number,
                'response' => $response->response_payload,
            ]])->all();

        return Inertia::render('workspace/learning/lesson', [
            'tenant' => ['slug' => $tenant->slug],
            'workspace' => ['id' => $workspace->getKey(), 'title' => $workspace->title, 'code' => $workspace->code],
            'lesson' => $snapshot,
            'version' => $revision->version_number,
            'lessonId' => (int) $lesson->getKey(),
            'progress' => [
                'status' => $progress->status, 'resume_seconds' => $progress->resume_seconds,
                'learner_confirmed_at' => $progress->learner_confirmed_at?->toIso8601String(),
                'instructor_confirmation_pending' => $progress->learner_confirmed_at !== null && $progress->completed_at === null,
            ],
            'activityResponses' => $activityResponses,
            'courseProgress' => $workflow->learnerProgressSummary($workspace, $context->membership()),
        ]);
    }

    public function saveActivityResponse(Request $request, Tenant $tenant, int $workspaceId, int $lessonId, int $blockId, CourseDeliveryWorkflow $workflow): RedirectResponse
    {
        $workspace = CourseDeliveryWorkspace::query()->findOrFail($workspaceId);
        $lesson = CourseDeliveryLesson::query()->where('workspace_id', $workspaceId)->findOrFail($lessonId);
        $data = $request->validate([
            'idempotency_key' => ['required', 'uuid'],
            'state' => ['required', 'in:draft,submitted'],
            'response' => ['required', 'array'],
        ]);
        $workflow->saveActivityResponse(
            $request->user(),
            $workspace,
            $lesson,
            $blockId,
            $data['idempotency_key'],
            $data['state'],
            $data['response'],
        );

        return back()->with('status', $data['state'] === 'submitted' ? 'Learning activity submitted.' : 'Learning activity draft saved.');
    }

    public function completionConfirmations(Tenant $tenant, int $workspaceId, CourseDeliveryWorkflow $workflow): Response
    {
        $workspace = CourseDeliveryWorkspace::query()->findOrFail($workspaceId);
        $workflow->assertCanConfirmCompletion(request()->user(), $workspace);
        $pending = CourseDeliveryProgress::query()
            ->with(['lesson:id,title,workspace_id', 'membership.user:id,name'])
            ->where('tenant_id', $workspace->tenant_id)
            ->where('workspace_id', $workspace->getKey())
            ->whereNotNull('learner_confirmed_at')
            ->whereNull('completed_at')
            ->orderBy('learner_confirmed_at')
            ->limit(100)
            ->get()
            ->map(fn (CourseDeliveryProgress $progress): array => [
                'lesson_id' => $progress->lesson_id,
                'lesson_title' => $progress->lesson->title,
                'membership_id' => $progress->membership_id,
                'learner_name' => $progress->membership->user->name ?? 'Account',
                'revision_version' => $progress->revision_version,
                'requested_at' => $progress->learner_confirmed_at?->toIso8601String(),
            ])->values();

        return Inertia::render('workspace/learning/completion-confirmations', [
            'tenant' => ['slug' => $tenant->slug],
            'workspace' => ['id' => $workspace->getKey(), 'title' => $workspace->title, 'code' => $workspace->code],
            'pending' => $pending,
        ]);
    }

    public function confirmCompletion(Tenant $tenant, int $workspaceId, int $lessonId, int $membershipId, CourseDeliveryWorkflow $workflow): RedirectResponse
    {
        $workspace = CourseDeliveryWorkspace::query()->findOrFail($workspaceId);
        $lesson = CourseDeliveryLesson::query()->where('workspace_id', $workspaceId)->findOrFail($lessonId);
        $workflow->confirmLessonCompletion(request()->user(), $workspace, $lesson, $membershipId);

        return back()->with('status', 'Learner completion confirmed.');
    }

    public function saveProgress(Request $request, Tenant $tenant, int $workspaceId, int $lessonId, CourseDeliveryWorkflow $workflow): RedirectResponse
    {
        $workspace = CourseDeliveryWorkspace::query()->findOrFail($workspaceId);
        $lesson = CourseDeliveryLesson::query()->where('workspace_id', $workspaceId)->findOrFail($lessonId);
        $data = $request->validate(['resume_seconds' => ['required', 'integer', 'min:0', 'max:86400']]);
        $workflow->saveResumePosition($request->user(), $workspace, $lesson, (int) $data['resume_seconds']);

        return back()->with('status', 'Learning position saved.');
    }

    public function completeLesson(Request $request, Tenant $tenant, int $workspaceId, int $lessonId, CourseDeliveryWorkflow $workflow): RedirectResponse
    {
        $workspace = CourseDeliveryWorkspace::query()->findOrFail($workspaceId);
        $lesson = CourseDeliveryLesson::query()->where('workspace_id', $workspaceId)->findOrFail($lessonId);
        $workflow->completeLesson($request->user(), $workspace, $lesson);

        return back()->with('status', 'Lesson marked complete.');
    }

    public function uploadMedia(Request $request, Tenant $tenant, int $workspaceId, int $lessonId, CourseDeliveryMediaWorkflow $media): RedirectResponse
    {
        $lesson = CourseDeliveryLesson::query()->where('workspace_id', $workspaceId)->findOrFail($lessonId);
        $data = $request->validate([
            'file' => ['required', 'file', 'max:20480'],
            'alt_text' => ['nullable', 'string', 'max:1000'],
            'license_label' => ['required', 'string', 'max:120'],
            'attribution' => ['nullable', 'string', 'max:1000'],
            'transcript' => ['nullable', 'string', 'max:50000'],
            'replaces_asset_id' => ['nullable', 'integer', 'min:1'],
        ]);
        $asset = $media->upload($request->user(), $lesson, $data['file'], [
            'alt_text' => $data['alt_text'] ?? null, 'license_label' => $data['license_label'],
            'attribution' => $data['attribution'] ?? null, 'transcript' => $data['transcript'] ?? null,
        ], isset($data['replaces_asset_id']) ? (int) $data['replaces_asset_id'] : null);
        $status = $asset->content['scan_status'] ?? 'quarantined';
        $message = match ($status) {
            'clean' => 'Media passed malware scanning and is ready for lesson review.',
            'rejected' => 'The scanner rejected this file. It remains private in quarantine and cannot be published.',
            default => 'The scanner could not approve this file. It remains private in quarantine.',
        };

        return back()->with('status', $message);
    }

    public function showMedia(Tenant $tenant, int $workspaceId, int $lessonId, int $assetId, CourseDeliveryWorkflow $workflow): BinaryFileResponse
    {
        $workspace = CourseDeliveryWorkspace::query()->findOrFail($workspaceId);
        $lesson = CourseDeliveryLesson::query()->where('workspace_id', $workspaceId)->findOrFail($lessonId);
        $revision = $workflow->assertCanLearn(request()->user(), $workspace, $lesson);
        $snapshotBlocks = $revision->lesson_snapshot['blocks'] ?? [];
        $isPublishedAsset = false;
        if (is_array($snapshotBlocks)) {
            foreach ($snapshotBlocks as $snapshotBlock) {
                if (is_array($snapshotBlock) && ($snapshotBlock['type'] ?? null) === 'media_asset'
                    && is_array($snapshotBlock['content'] ?? null)
                    && (int) ($snapshotBlock['content']['asset_id'] ?? 0) === $assetId) {
                    $isPublishedAsset = true;
                    break;
                }
            }
        }
        abort_unless($isPublishedAsset, 404);
        $asset = $lesson->blocks()->whereKey($assetId)->where('block_type', 'media_asset')->firstOrFail();
        $content = $asset->content;
        abort_unless(($content['scan_status'] ?? null) === 'clean', 404);
        $mime = (string) ($content['mime_type'] ?? '');
        abort_unless(in_array($mime, [
            'application/pdf', 'image/jpeg', 'image/png', 'image/webp', 'audio/mpeg', 'audio/ogg', 'audio/wav',
            'audio/x-wav', 'video/mp4', 'video/webm', 'text/plain',
        ], true), 404);
        $storageStatus = $content['storage_status'] ?? 'active';
        $disk = $storageStatus === 'archived' ? 'local_archive' : 'local';
        $privatePath = $storageStatus === 'archived' ? ($content['archived_path'] ?? null) : ($content['private_path'] ?? null);
        $expectedPrefix = $storageStatus === 'archived'
            ? 'learning-media/'.$tenant->getKey().'/'.$lesson->getKey().'/'.$assetId.'-'
            : 'learning/'.$tenant->public_id.'/'.$workspace->public_id.'/lessons/'.$lesson->getKey().'/clean/';
        abort_unless(in_array($storageStatus, ['active', 'archived'], true) && is_string($privatePath)
            && str_starts_with($privatePath, $expectedPrefix) && ! str_contains($privatePath, '..') && ! str_contains($privatePath, '\\'), 404);
        $absolutePath = Storage::disk($disk)->path($privatePath);
        $root = realpath((string) config('filesystems.disks.'.$disk.'.root'));
        $resolved = realpath($absolutePath);
        abort_unless($root !== false && $resolved !== false && ! is_link($absolutePath)
            && str_starts_with(strtolower($resolved), strtolower($root.DIRECTORY_SEPARATOR)) && is_file($resolved), 404);

        return response()->file($resolved, [
            'Content-Type' => $mime,
            'Content-Disposition' => 'inline; filename="learning-asset-'.$assetId.'.'.pathinfo($resolved, PATHINFO_EXTENSION).'"',
            'X-Content-Type-Options' => 'nosniff', 'Cache-Control' => 'private, no-store',
            'Cross-Origin-Resource-Policy' => 'same-origin', 'Content-Security-Policy' => "default-src 'none'; sandbox",
        ]);
    }

    public function revokeMedia(Tenant $tenant, int $workspaceId, int $lessonId, int $assetId, CourseDeliveryMediaWorkflow $media): RedirectResponse
    {
        $lesson = CourseDeliveryLesson::query()->where('workspace_id', $workspaceId)->findOrFail($lessonId);
        $media->revoke(request()->user(), $lesson, $assetId);

        return back()->with('status', 'Media access has been revoked. Published lesson snapshots retain their original reference but cannot serve a revoked asset.');
    }

    public function requestReleaseException(Request $request, Tenant $tenant, int $workspaceId, int $lessonId, CourseDeliveryWorkflow $workflow): RedirectResponse
    {
        $workspace = CourseDeliveryWorkspace::query()->findOrFail($workspaceId);
        $lesson = CourseDeliveryLesson::query()->where('workspace_id', $workspaceId)->findOrFail($lessonId);
        $data = $request->validate([
            'membership_id' => ['required', 'integer', 'min:1'],
            'reason' => ['required', 'string', 'min:12', 'max:1000'],
            'expires_at' => ['required', 'date', 'after:now'],
        ]);
        $workflow->requestReleaseException($request->user(), $workspace, $lesson, (int) $data['membership_id'], $data['reason'], Carbon::parse($data['expires_at'])->toImmutable());

        return $this->workspaceRedirect($tenant, $workspaceId, 'Learner release exception submitted for independent approval.');
    }

    public function decideReleaseException(Request $request, Tenant $tenant, int $workspaceId, int $exceptionId, CourseDeliveryWorkflow $workflow): RedirectResponse
    {
        $data = $request->validate(['decision' => ['required', 'in:approve,reject']]);
        $exception = CourseDeliveryReleaseException::query()->where('workspace_id', $workspaceId)->findOrFail($exceptionId);
        $workflow->decideReleaseException($request->user(), $exception, $data['decision'] === 'approve');

        return $this->workspaceRedirect($tenant, $workspaceId, 'Release exception decision recorded.');
    }

    public function revokeReleaseException(Request $request, Tenant $tenant, int $workspaceId, int $exceptionId, CourseDeliveryWorkflow $workflow): RedirectResponse
    {
        $workspace = CourseDeliveryWorkspace::query()->findOrFail($workspaceId);
        $exception = CourseDeliveryReleaseException::query()->where('workspace_id', $workspaceId)->findOrFail($exceptionId);
        $data = $request->validate(['reason' => ['required', 'string', 'min:8', 'max:1000']]);
        $workflow->revokeReleaseException($request->user(), $workspace, $exception, $data['reason']);

        return $this->workspaceRedirect($tenant, $workspaceId, 'Learner release exception revoked.');
    }

    /** @return array{type:string,content:array<string,mixed>} */
    private function serializeBlock(CourseDeliveryBlock $block): array
    {
        $content = $block->content;
        if ($block->block_type === 'media_asset') {
            unset($content['private_path'], $content['sha256'], $content['asset_reference']);
            $content['asset_id'] = (int) $block->getKey();
            $content['status'] = $content['scan_status'] ?? 'quarantined';
            unset($content['scan_status'], $content['scanner'], $content['scanned_at']);
        }

        return ['type' => $block->block_type, 'content' => $content];
    }

    private function workspaceRedirect(Tenant $tenant, int $workspaceId, string $status): RedirectResponse
    {
        return to_route('tenant.learning.show', ['tenant' => $tenant, 'workspaceId' => $workspaceId])->with('status', $status);
    }
}
