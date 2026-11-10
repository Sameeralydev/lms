<?php

namespace App\Http\Controllers\Workspace;

use App\Domain\Tenancy\TenantContext;
use App\Http\Controllers\Controller;
use App\Models\AcademicCourse;
use App\Models\AcademicYear;
use App\Models\CourseDeliveryAssignment;
use App\Models\CourseDeliveryBlock;
use App\Models\CourseDeliveryLesson;
use App\Models\CourseDeliveryProgress;
use App\Models\CourseDeliveryWorkspace;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

final class CourseDeliveryAnalyticsController extends Controller
{
    public function __invoke(Request $request, Tenant $tenant, TenantContext $context): Response
    {
        $membership = $context->membership();
        $canManageAll = $membership->hasPermission('learning.delivery.manage');
        $canTeach = $membership->hasPermission('learning.delivery.teach');
        $canReview = $membership->hasPermission('learning.delivery.review');
        abort_unless($canManageAll || $canTeach || $canReview, 403);

        $filters = $request->validate([
            'course_id' => ['nullable', 'integer', 'min:1'],
            'instructor_membership_id' => ['nullable', 'integer', 'min:1'],
            'academic_year_id' => ['nullable', 'integer', 'min:1'],
            'publication_status' => ['nullable', 'in:all,draft,published,archived'],
            'updated_from' => ['nullable', 'date_format:Y-m-d'],
            'updated_to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:updated_from'],
        ]);
        $institution = $context->tenant()->institution()->firstOrFail();
        $workspaceQuery = CourseDeliveryWorkspace::query()
            ->with(['course:id,title,code', 'courseVersion:id,title,version_number', 'campus:id,name'])
            ->withCount('modules')
            ->orderByDesc('updated_at');

        if ($membership->campus_id !== null) {
            $workspaceQuery->where('campus_id', $membership->campus_id);
        }
        if (! $canManageAll) {
            $roles = [];
            if ($canTeach) {
                $roles = ['course_owner', 'coordinator', 'instructor', 'content_author'];
            }
            if ($canReview) {
                $roles[] = 'reviewer';
            }
            $workspaceQuery->whereHas('assignments', fn (Builder $query) => $query
                ->where('membership_id', $membership->getKey())->whereIn('assignment_role', $roles)->effective());
        }
        if (isset($filters['course_id'])) {
            $workspaceQuery->where('academic_course_id', $filters['course_id']);
        }
        if (isset($filters['academic_year_id'])) {
            $workspaceQuery->where('academic_year_id', $filters['academic_year_id']);
        }
        if (($filters['publication_status'] ?? 'all') !== 'all') {
            $workspaceQuery->where('status', $filters['publication_status']);
        }
        if (isset($filters['updated_from'])) {
            $workspaceQuery->whereDate('updated_at', '>=', $filters['updated_from']);
        }
        if (isset($filters['updated_to'])) {
            $workspaceQuery->whereDate('updated_at', '<=', $filters['updated_to']);
        }
        if (isset($filters['instructor_membership_id'])) {
            abort_unless($canManageAll || (int) $filters['instructor_membership_id'] === (int) $membership->getKey(), 403);
            $workspaceQuery->whereHas('assignments', fn (Builder $query) => $query
                ->where('membership_id', $filters['instructor_membership_id'])
                ->whereIn('assignment_role', ['course_owner', 'coordinator', 'instructor', 'content_author'])->effective());
        }

        $workspaceScope = clone $workspaceQuery;
        $workspaceIds = (clone $workspaceScope)->reorder()->select('course_delivery_workspaces.id');
        $lessonQuery = CourseDeliveryLesson::query()->whereIn('workspace_id', clone $workspaceIds);
        $lessonCounts = (clone $lessonQuery)->selectRaw('status, COUNT(*) as aggregate')->groupBy('status')->pluck('aggregate', 'status');
        $publishedLessonCount = (clone $lessonQuery)->whereNotNull('published_version_number')->count();
        $reviewBacklog = (clone $lessonQuery)->where('status', 'in_review')->count();
        $learnerAssignmentsByWorkspace = CourseDeliveryAssignment::query()
            ->whereIn('workspace_id', clone $workspaceIds)->where('assignment_role', 'learner')->effective()
            ->selectRaw('workspace_id, COUNT(*) as aggregate')->groupBy('workspace_id')->pluck('aggregate', 'workspace_id');
        $publishedLessonsByWorkspace = CourseDeliveryLesson::query()->whereIn('workspace_id', clone $workspaceIds)
            ->whereNotNull('published_version_number')->selectRaw('workspace_id, COUNT(*) as aggregate')
            ->groupBy('workspace_id')->pluck('aggregate', 'workspace_id');
        $publishedLessonRows = CourseDeliveryLesson::query()->whereIn('workspace_id', clone $workspaceIds)
            ->whereNotNull('published_version_number')->get(['id', 'workspace_id', 'published_version_number']);
        $publishedRevisions = DB::table('course_delivery_lesson_revisions')->whereIn('lesson_id', $publishedLessonRows->pluck('id'))
            ->get(['lesson_id', 'version_number', 'lesson_snapshot'])->keyBy(fn (object $revision): string => $revision->lesson_id.':'.$revision->version_number);
        $optionalPublishedLessonIds = [];
        foreach ($publishedLessonRows as $publishedLesson) {
            $revision = $publishedRevisions->get($publishedLesson->getKey().':'.$publishedLesson->published_version_number);
            $snapshot = $revision === null ? null : json_decode((string) $revision->lesson_snapshot, true);
            $isOptional = false;
            foreach (is_array($snapshot['blocks'] ?? null) ? $snapshot['blocks'] : [] as $block) {
                if (is_array($block) && ($block['type'] ?? null) === 'release_policy') {
                    $isOptional = ($block['content']['optional_path'] ?? false) === true;
                    break;
                }
            }
            if ($isOptional) {
                $optionalPublishedLessonIds[] = (int) $publishedLesson->getKey();
                $publishedLessonsByWorkspace[$publishedLesson->workspace_id] = max(0, (int) $publishedLessonsByWorkspace[$publishedLesson->workspace_id] - 1);
            }
        }
        $lessonStatsByWorkspace = CourseDeliveryLesson::query()->whereIn('workspace_id', clone $workspaceIds)
            ->selectRaw("workspace_id, COUNT(*) as lessons_count, SUM(CASE WHEN published_version_number IS NOT NULL THEN 1 ELSE 0 END) as published_lessons_count, SUM(CASE WHEN status = 'in_review' THEN 1 ELSE 0 END) as review_backlog")
            ->groupBy('workspace_id')->get()->keyBy('workspace_id');
        $possibleCompletions = 0;
        foreach ($learnerAssignmentsByWorkspace as $workspaceId => $learnerCount) {
            $possibleCompletions += (int) $learnerCount * (int) ($publishedLessonsByWorkspace[$workspaceId] ?? 0);
        }
        $currentCompletions = CourseDeliveryProgress::query()->join('course_delivery_lessons', function ($join): void {
            $join->on('course_delivery_lessons.id', '=', 'course_delivery_progress.lesson_id')
                ->on('course_delivery_lessons.workspace_id', '=', 'course_delivery_progress.workspace_id')
                ->on('course_delivery_lessons.published_version_number', '=', 'course_delivery_progress.revision_version');
        })->whereIn('course_delivery_progress.workspace_id', clone $workspaceIds)
            ->where('course_delivery_progress.status', 'completed')->count();
        if ($optionalPublishedLessonIds !== []) {
            $currentCompletions -= CourseDeliveryProgress::query()->join('course_delivery_lessons', function ($join): void {
                $join->on('course_delivery_lessons.id', '=', 'course_delivery_progress.lesson_id')
                    ->on('course_delivery_lessons.workspace_id', '=', 'course_delivery_progress.workspace_id')
                    ->on('course_delivery_lessons.published_version_number', '=', 'course_delivery_progress.revision_version');
            })->whereIn('course_delivery_progress.lesson_id', $optionalPublishedLessonIds)
                ->where('course_delivery_progress.status', 'completed')->count();
        }
        $engagedLearners = CourseDeliveryProgress::query()->whereIn('workspace_id', clone $workspaceIds)
            ->whereIn('status', ['started', 'completed'])->distinct()->count('membership_id');

        $lessonIds = CourseDeliveryLesson::query()->whereIn('workspace_id', clone $workspaceIds)->select('id');
        $assets = CourseDeliveryBlock::query()->with('lesson.workspace')->whereIn('lesson_id', clone $lessonIds)
            ->where('block_type', 'media_asset')->get(['id', 'lesson_id', 'content']);
        $releasePolicies = CourseDeliveryBlock::query()->whereIn('lesson_id', clone $lessonIds)
            ->where('block_type', 'release_policy')->get(['id', 'lesson_id', 'content']);
        $externalLinks = CourseDeliveryBlock::query()->with('lesson.workspace')->whereIn('lesson_id', clone $lessonIds)
            ->where('block_type', 'external_link')->get(['id', 'lesson_id', 'content']);
        $malformedLinks = $externalLinks->filter(function (CourseDeliveryBlock $block): bool {
            $url = $block->content['url'] ?? null;

            return ! is_string($url) || filter_var($url, FILTER_VALIDATE_URL) === false
                || ! str_starts_with(strtolower($url), 'https://');
        });
        $scheduledReleases = $releasePolicies->filter(function (CourseDeliveryBlock $block): bool {
            $releaseAt = $block->content['release_at'] ?? null;

            return is_string($releaseAt) && Carbon::parse($releaseAt)->isFuture();
        })->count();
        $quarantinedAssets = $assets->filter(fn (CourseDeliveryBlock $block): bool => ($block->content['scan_status'] ?? 'quarantined') === 'quarantined')->count();
        $rejectedAssets = $assets->filter(fn (CourseDeliveryBlock $block): bool => ($block->content['scan_status'] ?? null) === 'rejected')->count();
        $accessibilityGaps = $assets->filter(function (CourseDeliveryBlock $block): bool {
            $type = $block->content['media_type'] ?? null;

            return ($type === 'image' && trim((string) ($block->content['alt_text'] ?? '')) === '')
                || (in_array($type, ['audio', 'video'], true) && trim((string) ($block->content['transcript'] ?? '')) === '');
        })->count();
        $workspacePage = $workspaceQuery->paginate(15)->withQueryString()->through(fn (CourseDeliveryWorkspace $workspace): array => [
            'id' => (int) $workspace->getKey(), 'title' => $workspace->title, 'code' => $workspace->code,
            'status' => $workspace->status, 'updated_at' => $workspace->updated_at?->toDateString(),
            'modules_count' => (int) $workspace->modules_count,
            'course' => ['title' => $workspace->course->title, 'code' => $workspace->course->code],
            'course_version' => ['title' => $workspace->courseVersion->title, 'version_number' => $workspace->courseVersion->version_number],
            'campus' => $workspace->campus ? ['name' => $workspace->campus->name] : null,
            'lessons_count' => (int) ($lessonStatsByWorkspace[$workspace->getKey()]->lessons_count ?? 0),
            'published_lessons_count' => (int) ($lessonStatsByWorkspace[$workspace->getKey()]->published_lessons_count ?? 0),
            'review_backlog' => (int) ($lessonStatsByWorkspace[$workspace->getKey()]->review_backlog ?? 0),
            'active_learners' => (int) ($learnerAssignmentsByWorkspace[$workspace->getKey()] ?? 0),
        ]);

        $visibleCourseIds = (clone $workspaceScope)->reorder()->select('academic_course_id');
        $courses = AcademicCourse::query()->where('institution_id', $institution->getKey())->whereIn('id', $visibleCourseIds)->orderBy('title')->get(['id', 'title', 'code']);
        $instructors = CourseDeliveryAssignment::query()->with('membership.user')
            ->whereIn('workspace_id', clone $workspaceIds)->whereIn('assignment_role', ['course_owner', 'coordinator', 'instructor', 'content_author'])->effective()
            ->get()->unique('membership_id')->map(fn (CourseDeliveryAssignment $assignment): array => [
                'id' => (int) $assignment->membership_id,
                'name' => $assignment->membership->user->name,
            ])->values();

        $findingRows = CourseDeliveryLesson::query()->whereIn('workspace_id', clone $workspaceIds)
            ->where(fn (Builder $query) => $query->whereNull('learning_objectives')->orWhere('learning_objectives', ''))
            ->with('workspace:id,title')->limit(10)->get(['id', 'workspace_id', 'title']);
        $findings = $findingRows->map(fn (CourseDeliveryLesson $lesson): array => [
            'kind' => 'missing_learning_objectives', 'label' => 'Learning objectives are missing',
            'lesson' => $lesson->title, 'workspace' => $lesson->workspace->title,
            'url' => route('tenant.learning.show', ['tenant' => $tenant, 'workspaceId' => $lesson->workspace_id]).'#lesson-'.$lesson->getKey(),
        ])->values()->all();
        foreach ($assets->filter(function (CourseDeliveryBlock $block): bool {
            $type = $block->content['media_type'] ?? null;

            return ($type === 'image' && trim((string) ($block->content['alt_text'] ?? '')) === '')
                || (in_array($type, ['audio', 'video'], true) && trim((string) ($block->content['transcript'] ?? '')) === '');
        })->take(max(0, 10 - count($findings))) as $asset) {
            $lesson = $asset->lesson;
            if ($lesson !== null) {
                $findings[] = [
                    'kind' => 'missing_accessibility_metadata', 'label' => 'Learning media needs accessibility metadata',
                    'lesson' => $lesson->title, 'workspace' => $lesson->workspace->title,
                    'url' => route('tenant.learning.show', ['tenant' => $tenant, 'workspaceId' => $lesson->workspace_id]).'#lesson-'.$lesson->getKey(),
                ];
            }
        }
        foreach ($malformedLinks->take(max(0, 10 - count($findings))) as $link) {
            $lesson = $link->lesson;
            if ($lesson !== null) {
                $findings[] = [
                    'kind' => 'malformed_external_link', 'label' => 'External link is malformed or not HTTPS',
                    'lesson' => $lesson->title, 'workspace' => $lesson->workspace->title,
                    'url' => route('tenant.learning.show', ['tenant' => $tenant, 'workspaceId' => $lesson->workspace_id]).'#lesson-'.$lesson->getKey(),
                ];
            }
        }

        return Inertia::render('workspace/learning/analytics', [
            'tenant' => ['slug' => $tenant->slug],
            'filters' => [
                'course_id' => (string) ($filters['course_id'] ?? ''),
                'instructor_membership_id' => (string) ($filters['instructor_membership_id'] ?? ''),
                'academic_year_id' => (string) ($filters['academic_year_id'] ?? ''),
                'publication_status' => (string) ($filters['publication_status'] ?? 'all'),
                'updated_from' => (string) ($filters['updated_from'] ?? ''), 'updated_to' => (string) ($filters['updated_to'] ?? ''),
            ],
            'courses' => $courses, 'instructors' => $instructors,
            'academicYears' => AcademicYear::query()->where('institution_id', $institution->getKey())->orderByDesc('starts_on')->get(['id', 'name']),
            'workspaces' => $workspacePage,
            'metrics' => [
                'workspaces' => (clone $workspaceScope)->count(), 'lessons' => (clone $lessonQuery)->count(),
                'published_lessons' => $publishedLessonCount, 'draft_lessons' => (int) ($lessonCounts['draft'] ?? 0),
                'review_backlog' => $reviewBacklog, 'active_learners' => (int) $learnerAssignmentsByWorkspace->sum(),
                'engaged_learners' => $engagedLearners,
                'current_completions' => $currentCompletions,
                'completion_percent' => $possibleCompletions === 0 ? 0 : (int) floor($currentCompletions * 100 / $possibleCompletions),
                'media_assets' => $assets->count(), 'quarantined_assets' => $quarantinedAssets,
                'rejected_assets' => $rejectedAssets, 'accessibility_gaps' => $accessibilityGaps,
                'scheduled_releases' => $scheduledReleases, 'malformed_external_links' => $malformedLinks->count(),
                'missing_learning_objectives' => (clone $lessonQuery)->where(fn (Builder $query) => $query->whereNull('learning_objectives')->orWhere('learning_objectives', ''))->count(),
                'curriculum_unlinked_workspaces' => (clone $workspaceScope)->whereNull('academic_curriculum_version_id')->count(),
            ],
            'findings' => $findings,
        ]);
    }
}
