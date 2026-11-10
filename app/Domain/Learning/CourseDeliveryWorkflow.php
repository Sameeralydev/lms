<?php

namespace App\Domain\Learning;

use App\Domain\Audit\AuditRecorder;
use App\Domain\Tenancy\TenantContext;
use App\Models\AcademicCourse;
use App\Models\AcademicCourseVersion;
use App\Models\AcademicCurriculumVersion;
use App\Models\AcademicProgram;
use App\Models\AcademicYear;
use App\Models\Campus;
use App\Models\CourseDeliveryActivityResponse;
use App\Models\CourseDeliveryAssignment;
use App\Models\CourseDeliveryBlock;
use App\Models\CourseDeliveryLesson;
use App\Models\CourseDeliveryLessonRevision;
use App\Models\CourseDeliveryModule;
use App\Models\CourseDeliveryProgress;
use App\Models\CourseDeliveryReleaseException;
use App\Models\CourseDeliveryWorkspace;
use App\Models\TenantMembership;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class CourseDeliveryWorkflow
{
    public function __construct(private readonly TenantContext $context, private readonly AuditRecorder $audit) {}

    /** @param array<string, mixed> $data */
    public function createWorkspace(User $actor, array $data): CourseDeliveryWorkspace
    {
        $tenantId = (int) $this->context->tenant()->getKey();
        $institution = $this->context->tenant()->institution()->firstOrFail();
        $campusId = $data['campus_id'] ?? null;
        $memberCampusId = $this->context->membership()->campus_id;
        if ($memberCampusId !== null && (int) $memberCampusId !== (int) $campusId) {
            throw new NotFoundHttpException;
        }
        if ($campusId !== null) {
            Campus::query()->where('institution_id', $institution->getKey())->whereKey($campusId)->firstOrFail();
        }
        $course = AcademicCourse::query()->where('institution_id', $institution->getKey())->whereKey($data['academic_course_id'])->firstOrFail();
        $courseVersion = AcademicCourseVersion::query()->where('academic_course_id', $course->getKey())->whereKey($data['academic_course_version_id'])->where('status', 'published')->firstOrFail();

        $programId = $data['academic_program_id'] ?? null;
        if ($programId !== null) {
            $program = AcademicProgram::query()->where('institution_id', $institution->getKey())->whereKey($programId)->firstOrFail();
            if ($campusId !== null && $program->campuses()->exists() && ! $program->campuses()->whereKey($campusId)->exists()) {
                throw new NotFoundHttpException;
            }
        }
        $curriculumVersionId = $data['academic_curriculum_version_id'] ?? null;
        if ($curriculumVersionId !== null) {
            $curriculumVersion = AcademicCurriculumVersion::query()->whereKey($curriculumVersionId)->where('status', 'published')->firstOrFail();
            $curriculum = $curriculumVersion->curriculum()->firstOrFail();
            if ((int) $curriculum->institution_id !== (int) $institution->getKey()
                || ($programId !== null && (int) $curriculum->academic_program_id !== (int) $programId)) {
                throw new NotFoundHttpException;
            }
            $programId ??= $curriculum->academic_program_id;
            if ($campusId !== null) {
                $curriculumProgram = AcademicProgram::query()->findOrFail($programId);
                if ($curriculumProgram->campuses()->exists() && ! $curriculumProgram->campuses()->whereKey($campusId)->exists()) {
                    throw new NotFoundHttpException;
                }
            }
        }
        $yearId = $data['academic_year_id'] ?? null;
        if ($yearId !== null) {
            AcademicYear::query()->where('institution_id', $institution->getKey())->whereKey($yearId)->firstOrFail();
        }

        return DB::transaction(function () use ($actor, $data, $tenantId, $institution, $course, $courseVersion, $programId, $curriculumVersionId, $yearId, $campusId): CourseDeliveryWorkspace {
            $workspace = CourseDeliveryWorkspace::query()->create([
                'institution_id' => $institution->getKey(),
                'campus_id' => $campusId,
                'academic_program_id' => $programId,
                'academic_course_id' => $course->getKey(),
                'academic_course_version_id' => $courseVersion->getKey(),
                'academic_curriculum_version_id' => $curriculumVersionId,
                'academic_year_id' => $yearId,
                'public_id' => (string) str()->uuid(),
                'code' => $data['code'],
                'title' => $data['title'],
                'description' => $data['description'] ?? null,
                'delivery_mode' => $data['delivery_mode'],
                'status' => 'draft',
                'created_by' => $actor->getKey(),
            ]);
            $this->audit->record('learning.delivery.workspace.created', $actor, $tenantId, $workspace, [
                'course_id' => $course->getKey(), 'course_version_id' => $courseVersion->getKey(),
                'curriculum_version_id' => $curriculumVersionId, 'delivery_mode' => $workspace->delivery_mode,
            ]);

            return $workspace;
        });
    }

    /** @param array<string, mixed> $data */
    public function createModule(User $actor, CourseDeliveryWorkspace $workspace, array $data): CourseDeliveryModule
    {
        $this->assertCanManage($actor, $workspace);

        return DB::transaction(function () use ($actor, $workspace, $data): CourseDeliveryModule {
            $locked = CourseDeliveryWorkspace::query()->lockForUpdate()->findOrFail($workspace->getKey());
            $this->assertDraftWorkspace($locked);
            $position = (int) CourseDeliveryModule::query()->where('workspace_id', $locked->getKey())->max('sort_order') + 1;
            $module = CourseDeliveryModule::query()->create([
                'workspace_id' => $locked->getKey(), 'title' => $data['title'], 'summary' => $data['summary'] ?? null,
                'sort_order' => $position, 'status' => 'draft',
            ]);
            $this->audit->record('learning.delivery.module.created', $actor, (int) $locked->tenant_id, $module, ['workspace_id' => $locked->getKey(), 'sort_order' => $position]);

            return $module;
        });
    }

    /** @param list<int> $orderedIds */
    public function reorderModules(User $actor, CourseDeliveryWorkspace $workspace, array $orderedIds): void
    {
        $this->assertCanManage($actor, $workspace);
        DB::transaction(function () use ($actor, $workspace, $orderedIds): void {
            $locked = CourseDeliveryWorkspace::query()->lockForUpdate()->findOrFail($workspace->getKey());
            $this->assertDraftWorkspace($locked);
            $ids = CourseDeliveryModule::query()->where('workspace_id', $locked->getKey())->orderBy('sort_order')->pluck('id')->map(static fn ($id): int => (int) $id)->all();
            $requested = array_map('intval', $orderedIds);
            $expectedSorted = $ids;
            $requestedSorted = $requested;
            sort($expectedSorted);
            sort($requestedSorted);
            if ($requestedSorted !== $expectedSorted) {
                throw ValidationException::withMessages(['module_ids' => 'The submitted order must contain every module in this workspace exactly once.']);
            }
            $offset = (int) (CourseDeliveryModule::query()->where('workspace_id', $locked->getKey())->max('sort_order') ?? 0) + count($ids) + 1;
            CourseDeliveryModule::query()->where('workspace_id', $locked->getKey())->update(['sort_order' => DB::raw('sort_order + '.$offset)]);
            foreach ($requested as $position => $id) {
                CourseDeliveryModule::query()->where('workspace_id', $locked->getKey())->whereKey($id)->update(['sort_order' => $position + 1]);
            }
            $this->audit->record('learning.delivery.modules.reordered', $actor, (int) $locked->tenant_id, $locked, ['module_count' => count($requested)]);
        });
    }

    /** @param list<int> $orderedIds */
    public function reorderLessons(User $actor, CourseDeliveryWorkspace $workspace, CourseDeliveryModule $module, array $orderedIds): void
    {
        $module = CourseDeliveryModule::query()->where('workspace_id', $workspace->getKey())->findOrFail($module->getKey());
        $this->assertCanManage($actor, $workspace);
        DB::transaction(function () use ($actor, $workspace, $module, $orderedIds): void {
            $locked = CourseDeliveryWorkspace::query()->lockForUpdate()->findOrFail($workspace->getKey());
            $this->assertDraftWorkspace($locked);
            $ids = CourseDeliveryLesson::query()->where('workspace_id', $locked->getKey())->where('module_id', $module->getKey())->orderBy('sort_order')->pluck('id')->map(static fn ($id): int => (int) $id)->all();
            $requested = array_map('intval', $orderedIds);
            $expectedSorted = $ids;
            $requestedSorted = $requested;
            sort($expectedSorted);
            sort($requestedSorted);
            if ($requestedSorted !== $expectedSorted) {
                throw ValidationException::withMessages(['lesson_ids' => 'The submitted order must contain every lesson in this module exactly once.']);
            }
            $offset = (int) (CourseDeliveryLesson::query()->where('module_id', $module->getKey())->max('sort_order') ?? 0) + count($ids) + 1;
            CourseDeliveryLesson::query()->where('workspace_id', $locked->getKey())->where('module_id', $module->getKey())->update(['sort_order' => DB::raw('sort_order + '.$offset)]);
            foreach ($requested as $position => $id) {
                CourseDeliveryLesson::query()->where('workspace_id', $locked->getKey())->where('module_id', $module->getKey())->whereKey($id)->update(['sort_order' => $position + 1]);
            }
            $this->audit->record('learning.delivery.lessons.reordered', $actor, (int) $locked->tenant_id, $module, ['workspace_id' => $locked->getKey(), 'lesson_count' => count($requested)]);
        });
    }

    /** @param array<string, mixed> $data */
    public function createLesson(User $actor, CourseDeliveryModule $module, array $data): CourseDeliveryLesson
    {
        $module = CourseDeliveryModule::query()->with('workspace')->findOrFail($module->getKey());
        $workspace = $module->workspace;
        $this->assertCanManage($actor, $workspace);

        return DB::transaction(function () use ($actor, $module, $workspace, $data): CourseDeliveryLesson {
            $locked = CourseDeliveryWorkspace::query()->lockForUpdate()->findOrFail($workspace->getKey());
            $this->assertDraftWorkspace($locked);
            $position = (int) CourseDeliveryLesson::query()->where('module_id', $module->getKey())->max('sort_order') + 1;
            $lesson = CourseDeliveryLesson::query()->create([
                'workspace_id' => $locked->getKey(), 'module_id' => $module->getKey(), 'title' => $data['title'],
                'summary' => $data['summary'] ?? null, 'learning_objectives' => $data['learning_objectives'] ?? null,
                'estimated_minutes' => $data['estimated_minutes'] ?? null, 'sort_order' => $position, 'status' => 'draft',
                'lock_version' => 1, 'created_by' => $actor->getKey(), 'draft_author_user_id' => $actor->getKey(),
            ]);
            $this->audit->record('learning.delivery.lesson.created', $actor, (int) $locked->tenant_id, $lesson, ['workspace_id' => $locked->getKey(), 'module_id' => $module->getKey()]);

            return $lesson;
        });
    }

    /** @param array<string, mixed> $data */
    public function updateLesson(User $actor, CourseDeliveryLesson $lesson, array $data): CourseDeliveryLesson
    {
        $lesson = CourseDeliveryLesson::query()->with('workspace')->findOrFail($lesson->getKey());
        $this->assertCanManage($actor, $lesson->workspace);
        $this->assertMutableLesson($lesson);
        $validatedBlocks = $this->validateBlocks($data['blocks'] ?? []);
        $this->assertValidReleaseDependencies($lesson, $validatedBlocks);

        return DB::transaction(function () use ($actor, $lesson, $data, $validatedBlocks): CourseDeliveryLesson {
            $locked = CourseDeliveryLesson::query()->lockForUpdate()->findOrFail($lesson->getKey());
            if ((int) $locked->lock_version !== (int) $data['lock_version']) {
                abort(409, 'This lesson changed since it was loaded. Reload and reapply the edits.');
            }
            $this->assertMutableLesson($locked);
            $locked->fill([
                'title' => $data['title'], 'summary' => $data['summary'] ?? null,
                'learning_objectives' => $data['learning_objectives'] ?? null,
                'estimated_minutes' => $data['estimated_minutes'] ?? null,
                'draft_author_user_id' => $actor->getKey(), 'lock_version' => $locked->lock_version + 1,
            ])->save();
            $mediaAssets = $locked->blocks()->where('block_type', 'media_asset')->orderBy('sort_order')->get();
            $this->archiveActivityBlocksWithResponses($locked);
            $locked->blocks()->where('block_type', '!=', 'media_asset')->delete();
            $mediaOffset = max((int) ($locked->blocks()->max('sort_order') ?? 0) + $mediaAssets->count() + count($validatedBlocks) + 10, 100);
            foreach ($mediaAssets as $index => $mediaAsset) {
                $mediaAsset->forceFill(['sort_order' => $mediaOffset + $index])->save();
            }
            foreach ($validatedBlocks as $position => $block) {
                CourseDeliveryBlock::query()->create([
                    'lesson_id' => $locked->getKey(), 'sort_order' => $position + 1,
                    'block_type' => $block['type'], 'content' => $block['content'],
                ]);
            }
            foreach ($mediaAssets as $index => $mediaAsset) {
                $mediaAsset->forceFill(['sort_order' => count($validatedBlocks) + $index + 1])->save();
            }
            $this->audit->record('learning.delivery.lesson.updated', $actor, (int) $locked->tenant_id, $locked, ['lock_version' => $locked->lock_version, 'block_count' => count($validatedBlocks) + $mediaAssets->count()]);

            return $locked->fresh(['blocks']);
        });
    }

    public function submitLesson(User $actor, CourseDeliveryLesson $lesson): CourseDeliveryLesson
    {
        $lesson = CourseDeliveryLesson::query()->with(['workspace', 'blocks'])->findOrFail($lesson->getKey());
        $this->assertCanManage($actor, $lesson->workspace);
        $this->assertMutableLesson($lesson);
        if ($lesson->blocks->isEmpty() || trim((string) $lesson->learning_objectives) === '') {
            throw ValidationException::withMessages(['lesson' => 'Add at least one content block and a learning objective before review.']);
        }
        if ($lesson->blocks->contains(fn (CourseDeliveryBlock $block): bool => $block->block_type === 'media_asset' && ($block->content['scan_status'] ?? null) !== 'clean')) {
            throw ValidationException::withMessages(['lesson' => 'Every attached media asset must pass a current malware scan before review.']);
        }

        return DB::transaction(function () use ($actor, $lesson): CourseDeliveryLesson {
            $locked = CourseDeliveryLesson::query()->lockForUpdate()->findOrFail($lesson->getKey());
            $this->assertMutableLesson($locked);
            $locked->forceFill([
                'status' => 'in_review', 'submitted_by' => $actor->getKey(), 'submitted_at' => now(),
                'reviewer_user_id' => null, 'review_decision' => null, 'review_note' => null, 'reviewed_at' => null,
                'lock_version' => $locked->lock_version + 1,
            ])->save();
            $this->audit->record('learning.delivery.lesson.submitted', $actor, (int) $locked->tenant_id, $locked, ['lock_version' => $locked->lock_version]);

            return $locked;
        });
    }

    public function reviewLesson(User $actor, CourseDeliveryLesson $lesson, string $decision, ?string $note): CourseDeliveryLesson
    {
        abort_unless($this->context->membership()->hasPermission('learning.delivery.review'), 403);
        $lesson = CourseDeliveryLesson::query()->with('workspace')->findOrFail($lesson->getKey());
        $this->assertCampusScope($lesson->workspace);
        abort_unless(CourseDeliveryAssignment::query()->where('workspace_id', $lesson->workspace_id)->where('membership_id', $this->context->membership()->getKey())->effective()->where('assignment_role', 'reviewer')->exists(), 404);
        abort_unless($lesson->status === 'in_review', 409, 'Only submitted lessons can be reviewed.');
        abort_if((int) $lesson->draft_author_user_id === (int) $actor->getKey(), 403, 'Authors cannot review their own lesson.');
        if ($decision !== 'approved' && trim((string) $note) === '') {
            throw ValidationException::withMessages(['note' => 'A note is required when requesting changes or rejecting a lesson.']);
        }

        return DB::transaction(function () use ($actor, $lesson, $decision, $note): CourseDeliveryLesson {
            $locked = CourseDeliveryLesson::query()->lockForUpdate()->findOrFail($lesson->getKey());
            abort_unless($locked->status === 'in_review', 409, 'The lesson review state changed. Reload before deciding.');
            $locked->forceFill([
                'status' => match ($decision) {
                    'approved' => 'approved', 'changes_requested' => 'changes_requested', default => 'rejected'
                },
                'reviewer_user_id' => $actor->getKey(), 'review_decision' => $decision,
                'review_note' => $note, 'reviewed_at' => now(), 'lock_version' => $locked->lock_version + 1,
            ])->save();
            $this->audit->record('learning.delivery.lesson.reviewed', $actor, (int) $locked->tenant_id, $locked, ['decision' => $decision]);

            return $locked;
        });
    }

    public function publishLesson(User $actor, CourseDeliveryLesson $lesson): CourseDeliveryLessonRevision
    {
        $lesson = CourseDeliveryLesson::query()->with('workspace')->findOrFail($lesson->getKey());
        $this->assertCanPublish($actor, $lesson->workspace);

        return DB::transaction(function () use ($actor, $lesson): CourseDeliveryLessonRevision {
            $locked = CourseDeliveryLesson::query()->with('blocks')->lockForUpdate()->findOrFail($lesson->getKey());
            abort_unless($locked->status === 'approved' && $locked->review_decision === 'approved' && $locked->reviewer_user_id !== null, 409, 'A current independent approval is required.');
            abort_if((int) $locked->draft_author_user_id === (int) $actor->getKey(), 403, 'Authors cannot publish their own lesson.');
            abort_if((int) $locked->reviewer_user_id === (int) $actor->getKey(), 403, 'The reviewer cannot publish the same lesson.');
            $snapshot = [
                'title' => $locked->title, 'summary' => $locked->summary,
                'learning_objectives' => $locked->learning_objectives, 'estimated_minutes' => $locked->estimated_minutes,
                'blocks' => $locked->blocks->map(function (CourseDeliveryBlock $block): array {
                    $content = $block->content;
                    if ($block->block_type === 'media_asset') {
                        $content = array_intersect_key($content, array_flip([
                            'original_name', 'mime_type', 'media_type', 'size_bytes', 'alt_text',
                            'license_label', 'attribution', 'transcript',
                        ]));
                        $content['scan_status'] = 'clean';
                        $content['asset_id'] = (int) $block->getKey();
                    }

                    return ['id' => (int) $block->getKey(), 'type' => $block->block_type, 'content' => $content];
                })->values()->all(),
            ];
            abort_if($locked->blocks->contains(fn (CourseDeliveryBlock $block): bool => $block->block_type === 'media_asset' && ($block->content['scan_status'] ?? null) !== 'clean'), 409, 'Only clean-scanned learning media can be published.');
            $encoded = json_encode($snapshot, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
            $version = (int) $locked->revisions()->max('version_number') + 1;
            $revision = CourseDeliveryLessonRevision::query()->create([
                'lesson_id' => $locked->getKey(), 'version_number' => $version, 'lesson_snapshot' => $snapshot,
                'content_checksum' => hash('sha256', $encoded), 'authored_by' => $locked->draft_author_user_id,
                'reviewed_by' => $locked->reviewer_user_id, 'published_by' => $actor->getKey(), 'published_at' => now(),
            ]);
            $locked->forceFill(['status' => 'published', 'published_by' => $actor->getKey(), 'published_at' => now(), 'published_version_number' => $version, 'lock_version' => $locked->lock_version + 1])->save();
            $this->audit->record('learning.delivery.lesson.published', $actor, (int) $locked->tenant_id, $revision, ['lesson_id' => $locked->getKey(), 'version' => $version, 'checksum' => $revision->content_checksum]);

            return $revision;
        });
    }

    public function reviseLesson(User $actor, CourseDeliveryLesson $lesson): CourseDeliveryLesson
    {
        $lesson = CourseDeliveryLesson::query()->with(['workspace', 'revisions'])->findOrFail($lesson->getKey());
        $this->assertCanManage($actor, $lesson->workspace);
        abort_unless($lesson->status === 'published', 409, 'Only a published lesson can be revised.');
        $snapshot = $lesson->revisions->first()?->lesson_snapshot;
        abort_unless(is_array($snapshot), 409, 'The published lesson snapshot is missing.');

        return DB::transaction(function () use ($actor, $lesson, $snapshot): CourseDeliveryLesson {
            $locked = CourseDeliveryLesson::query()->lockForUpdate()->findOrFail($lesson->getKey());
            abort_unless($locked->status === 'published', 409);
            $locked->forceFill([
                'title' => $snapshot['title'], 'summary' => $snapshot['summary'], 'learning_objectives' => $snapshot['learning_objectives'],
                'estimated_minutes' => $snapshot['estimated_minutes'], 'status' => 'draft', 'draft_author_user_id' => $actor->getKey(),
                'submitted_by' => null, 'submitted_at' => null, 'reviewer_user_id' => null, 'review_decision' => null,
                'review_note' => null, 'reviewed_at' => null,
                'lock_version' => $locked->lock_version + 1,
            ])->save();
            $mediaAssets = $locked->blocks()->where('block_type', 'media_asset')->orderBy('sort_order')->get();
            $this->archiveActivityBlocksWithResponses($locked);
            $locked->blocks()->where('block_type', '!=', 'media_asset')->delete();
            $snapshotBlocks = array_values(array_filter($snapshot['blocks'], static fn (array $block): bool => ($block['type'] ?? null) !== 'media_asset'));
            $mediaOffset = max((int) ($locked->blocks()->max('sort_order') ?? 0) + $mediaAssets->count() + count($snapshotBlocks) + 10, 100);
            foreach ($mediaAssets as $index => $mediaAsset) {
                $mediaAsset->forceFill(['sort_order' => $mediaOffset + $index])->save();
            }
            foreach ($snapshotBlocks as $position => $block) {
                CourseDeliveryBlock::query()->create(['lesson_id' => $locked->getKey(), 'sort_order' => $position + 1, 'block_type' => $block['type'], 'content' => $block['content']]);
            }
            foreach ($mediaAssets as $index => $mediaAsset) {
                $mediaAsset->forceFill(['sort_order' => count($snapshotBlocks) + $index + 1])->save();
            }
            $this->audit->record('learning.delivery.lesson.revision_started', $actor, (int) $locked->tenant_id, $locked, ['published_version' => $lesson->published_version_number]);

            return $locked->fresh(['blocks']);
        });
    }

    public function publishWorkspace(User $actor, CourseDeliveryWorkspace $workspace): CourseDeliveryWorkspace
    {
        $this->assertCanPublish($actor, $workspace);

        return DB::transaction(function () use ($actor, $workspace): CourseDeliveryWorkspace {
            $locked = CourseDeliveryWorkspace::query()->lockForUpdate()->findOrFail($workspace->getKey());
            $this->assertDraftWorkspace($locked);
            $publishedLessons = CourseDeliveryLesson::query()->where('workspace_id', $locked->getKey())->where('status', 'published')->count();
            abort_if($publishedLessons === 0, 409, 'Publish at least one reviewed lesson before publishing this workspace.');
            $locked->forceFill(['status' => 'published', 'published_at' => now(), 'lock_version' => $locked->lock_version + 1])->save();
            $this->audit->record('learning.delivery.workspace.published', $actor, (int) $locked->tenant_id, $locked, ['published_lesson_count' => $publishedLessons]);

            return $locked;
        });
    }

    /** @param array<string, mixed> $data */
    public function assign(User $actor, CourseDeliveryWorkspace $workspace, array $data): CourseDeliveryAssignment
    {
        $this->assertCanManage($actor, $workspace);
        $membership = TenantMembership::query()
            ->whereKey($data['membership_id'])->where('tenant_id', $workspace->tenant_id)
            ->where('institution_id', $workspace->institution_id)->where('status', 'active')->firstOrFail();
        if ($membership->campus_id !== null && (int) $membership->campus_id !== (int) $workspace->campus_id) {
            throw new NotFoundHttpException;
        }
        $requiredPermission = match ($data['assignment_role']) {
            'learner' => 'learning.delivery.learn',
            'reviewer' => 'learning.delivery.review',
            'publisher' => 'learning.delivery.publish',
            default => 'learning.delivery.teach',
        };
        abort_unless($membership->hasPermission($requiredPermission), 422, 'The member does not hold the required institution permission for this assignment.');

        return DB::transaction(function () use ($actor, $workspace, $membership, $data): CourseDeliveryAssignment {
            CourseDeliveryWorkspace::query()->lockForUpdate()->findOrFail($workspace->getKey());
            abort_if(CourseDeliveryAssignment::query()->where('workspace_id', $workspace->getKey())->where('membership_id', $membership->getKey())->where('assignment_role', $data['assignment_role'])->effective()->exists(), 409, 'This member already has an effective assignment with that role.');
            $sequence = (int) CourseDeliveryAssignment::query()->where('workspace_id', $workspace->getKey())->where('membership_id', $membership->getKey())->where('assignment_role', $data['assignment_role'])->max('sequence') + 1;
            $assignment = CourseDeliveryAssignment::query()->create([
                'workspace_id' => $workspace->getKey(), 'membership_id' => $membership->getKey(),
                'assignment_role' => $data['assignment_role'], 'sequence' => $sequence,
                'effective_from' => $data['effective_from'] ?? null, 'effective_until' => $data['effective_until'] ?? null,
                'status' => 'active', 'responsibility' => $data['responsibility'] ?? null, 'assigned_by' => $actor->getKey(),
            ]);
            $this->audit->record('learning.delivery.assignment.created', $actor, (int) $workspace->tenant_id, $assignment, ['workspace_id' => $workspace->getKey(), 'membership_id' => $membership->getKey(), 'role' => $assignment->assignment_role]);

            return $assignment;
        });
    }

    public function revokeAssignment(User $actor, CourseDeliveryWorkspace $workspace, int $assignmentId, string $reason): void
    {
        $this->assertCanManage($actor, $workspace);
        DB::transaction(function () use ($actor, $workspace, $assignmentId, $reason): void {
            CourseDeliveryWorkspace::query()->lockForUpdate()->findOrFail($workspace->getKey());
            $assignment = CourseDeliveryAssignment::query()->where('workspace_id', $workspace->getKey())->lockForUpdate()->findOrFail($assignmentId);
            if ($assignment->assignment_role === 'course_owner' && CourseDeliveryAssignment::query()->where('workspace_id', $workspace->getKey())->where('assignment_role', 'course_owner')->effective()->count() <= 1) {
                throw ValidationException::withMessages(['assignment' => 'Assign another effective course owner before revoking the last one.']);
            }
            abort_unless($assignment->status === 'active', 409, 'Only active assignments can be revoked.');
            $assignment->forceFill(['status' => 'revoked', 'revoked_by' => $actor->getKey(), 'revoked_at' => now(), 'change_reason' => $reason])->save();
            $this->audit->record('learning.delivery.assignment.revoked', $actor, (int) $workspace->tenant_id, $assignment, ['workspace_id' => $workspace->getKey(), 'membership_id' => $assignment->membership_id, 'role' => $assignment->assignment_role, 'reason_recorded' => true]);
        });
    }

    public function assertCanView(User $actor, CourseDeliveryWorkspace $workspace): void
    {
        $membership = $this->context->membership();
        if ($membership->campus_id !== null && (int) $membership->campus_id !== (int) $workspace->campus_id) {
            throw new NotFoundHttpException;
        }
        if ($membership->hasPermission('learning.delivery.manage')) {
            return;
        }
        $roles = CourseDeliveryAssignment::query()->where('workspace_id', $workspace->getKey())->where('membership_id', $membership->getKey())->effective()->pluck('assignment_role');
        $mayTeach = $membership->hasPermission('learning.delivery.teach') && $roles->contains(fn (string $role): bool => in_array($role, ['course_owner', 'coordinator', 'instructor', 'content_author'], true));
        $mayReview = $membership->hasPermission('learning.delivery.review') && $roles->contains('reviewer');
        $mayPublish = $membership->hasPermission('learning.delivery.publish') && $roles->contains('publisher');
        $mayLearn = $membership->hasPermission('learning.delivery.learn') && $roles->contains('learner') && $workspace->status === 'published';
        abort_unless($mayTeach || $mayReview || $mayPublish || $mayLearn, 404);
    }

    public function assertCanLearn(User $actor, CourseDeliveryWorkspace $workspace, CourseDeliveryLesson $lesson): CourseDeliveryLessonRevision
    {
        $membership = $this->context->membership();
        $this->assertCampusScope($workspace);
        abort_unless((int) $membership->user_id === (int) $actor->getKey(), 404);
        abort_unless($membership->hasPermission('learning.delivery.learn'), 404);
        $learnerAssignment = CourseDeliveryAssignment::query()->where('workspace_id', $workspace->getKey())
            ->where('membership_id', $membership->getKey())->effective()->where('assignment_role', 'learner')->first();
        abort_unless($learnerAssignment !== null, 404);
        abort_unless($workspace->status === 'published' && $lesson->published_version_number !== null && (int) $lesson->workspace_id === (int) $workspace->getKey(), 404);

        $revision = CourseDeliveryLessonRevision::query()->where('lesson_id', $lesson->getKey())->where('version_number', $lesson->published_version_number)->firstOrFail();
        $policy = [];
        $snapshotBlocks = $revision->lesson_snapshot['blocks'] ?? [];
        if (is_array($snapshotBlocks)) {
            foreach ($snapshotBlocks as $block) {
                if (is_array($block) && ($block['type'] ?? null) === 'release_policy' && is_array($block['content'] ?? null)) {
                    $policy = $block['content'];
                    break;
                }
            }
        }
        $releaseException = CourseDeliveryReleaseException::query()
            ->where('tenant_id', $workspace->tenant_id)
            ->where('workspace_id', $workspace->getKey())
            ->where('lesson_id', $lesson->getKey())
            ->where('membership_id', $membership->getKey())
            ->where('status', 'approved')
            ->where('expires_at', '>', now())
            ->whereNull('revoked_at')
            ->latest('approved_at')
            ->first();
        if ($releaseException !== null) {
            $this->recordReleaseDecision($actor, $workspace, $lesson, $revision, true, 'approved_exception');

            return $revision;
        }
        $releaseAt = $policy['release_at'] ?? null;
        if (is_string($releaseAt) && CarbonImmutable::parse($releaseAt)->isFuture()) {
            $this->recordReleaseDecision($actor, $workspace, $lesson, $revision, false, 'not_started');
            throw new NotFoundHttpException;
        }
        $availableUntil = $policy['available_until'] ?? null;
        if (is_string($availableUntil) && ! CarbonImmutable::parse($availableUntil)->isFuture()) {
            $this->recordReleaseDecision($actor, $workspace, $lesson, $revision, false, 'expired');
            throw new NotFoundHttpException;
        }
        $dripDays = (int) ($policy['drip_days_after_enrollment'] ?? 0);
        if ($dripDays !== 0 && ($learnerAssignment->effective_from === null
            || ! CarbonImmutable::parse($learnerAssignment->effective_from)->addDays($dripDays)->isPast())) {
            $this->recordReleaseDecision($actor, $workspace, $lesson, $revision, false, 'drip_window');
            throw new NotFoundHttpException;
        }

        $requiredLessonIds = array_map('intval', $policy['prerequisite_lesson_ids'] ?? []);
        $requiredModuleIds = array_map('intval', $policy['prerequisite_module_ids'] ?? []);
        $module = CourseDeliveryModule::query()->where('workspace_id', $workspace->getKey())->findOrFail($lesson->module_id);
        if (($policy['module_locked'] ?? false) === true) {
            $requiredModuleIds = [...$requiredModuleIds, ...CourseDeliveryModule::query()
                ->where('workspace_id', $workspace->getKey())->where('sort_order', '<', $module->sort_order)->pluck('id')->map(fn ($id): int => (int) $id)->all()];
        }

        $requiredModules = CourseDeliveryModule::query()->where('workspace_id', $workspace->getKey())->whereIn('id', array_unique($requiredModuleIds))->get(['id']);
        abort_unless($requiredModules->count() === count(array_unique($requiredModuleIds)), 404);
        $moduleLessonIds = CourseDeliveryLesson::query()->where('workspace_id', $workspace->getKey())
            ->whereIn('module_id', $requiredModules->pluck('id'))->whereNotNull('published_version_number')->pluck('id')->map(fn ($id): int => (int) $id)->all();
        $requiredLessonIds = array_values(array_unique([...$requiredLessonIds, ...$moduleLessonIds]));
        if ($requiredLessonIds !== []) {
            $requiredLessons = CourseDeliveryLesson::query()->where('workspace_id', $workspace->getKey())
                ->whereNotNull('published_version_number')->whereIn('id', $requiredLessonIds)->get(['id', 'module_id', 'published_version_number'])->keyBy('id');
            abort_unless($requiredLessons->count() === count($requiredLessonIds), 404);
            $completed = CourseDeliveryProgress::query()->where('workspace_id', $workspace->getKey())
                ->where('membership_id', $membership->getKey())->where('status', 'completed')
                ->whereIn('lesson_id', $requiredLessonIds)->get()->keyBy('lesson_id');
            foreach ($requiredLessonIds as $requiredLessonId) {
                $requiredLesson = $requiredLessons->get($requiredLessonId);
                $completedProgress = $completed->get($requiredLessonId);
                if ($completedProgress === null || (int) $completedProgress->revision_version !== (int) $requiredLesson->published_version_number) {
                    $this->recordReleaseDecision($actor, $workspace, $lesson, $revision, false, 'prerequisite_incomplete');
                    throw new NotFoundHttpException;
                }
            }
        }

        if (($policy['progression_mode'] ?? 'free') === 'sequential') {
            $orderedLessons = CourseDeliveryModule::query()->where('workspace_id', $workspace->getKey())
                ->with(['lessons' => fn ($query) => $query->whereNotNull('published_version_number')])
                ->orderBy('sort_order')->get()
                ->flatMap(fn (CourseDeliveryModule $module) => $module->lessons->sortBy('sort_order')->values())
                ->values();
            $optionalLessonIds = $this->optionalLessonIds($orderedLessons);
            $currentIndex = $orderedLessons->search(fn (CourseDeliveryLesson $candidate): bool => (int) $candidate->getKey() === (int) $lesson->getKey());
            abort_unless($currentIndex !== false, 404);
            $requiredLessons = $orderedLessons->take($currentIndex)
                ->reject(fn (CourseDeliveryLesson $candidate): bool => in_array((int) $candidate->getKey(), $optionalLessonIds, true));
            $progress = CourseDeliveryProgress::query()->where('workspace_id', $workspace->getKey())
                ->where('membership_id', $membership->getKey())->where('status', 'completed')
                ->whereIn('lesson_id', $requiredLessons->pluck('id'))->get()->keyBy('lesson_id');
            foreach ($requiredLessons as $requiredLesson) {
                $completed = $progress->get($requiredLesson->getKey());
                if ($completed === null || (int) $completed->revision_version !== (int) $requiredLesson->published_version_number) {
                    $this->recordReleaseDecision($actor, $workspace, $lesson, $revision, false, 'sequential_progression');
                    throw new NotFoundHttpException;
                }
            }
        }

        $this->recordReleaseDecision($actor, $workspace, $lesson, $revision, true, 'eligible');

        return $revision;
    }

    private function recordReleaseDecision(User $actor, CourseDeliveryWorkspace $workspace, CourseDeliveryLesson $lesson, CourseDeliveryLessonRevision $revision, bool $allowed, string $reason): void
    {
        $this->audit->record('learning.delivery.release.access_decided', $actor, (int) $workspace->tenant_id, $lesson, [
            'workspace_id' => (int) $workspace->getKey(),
            'lesson_id' => (int) $lesson->getKey(),
            'revision_version' => (int) $revision->version_number,
            'decision' => $allowed ? 'allow' : 'deny',
            'reason_code' => $reason,
        ]);
    }

    public function requestReleaseException(User $actor, CourseDeliveryWorkspace $workspace, CourseDeliveryLesson $lesson, int $learnerMembershipId, string $reason, CarbonImmutable $expiresAt): CourseDeliveryReleaseException
    {
        $this->assertCanManage($actor, $workspace);
        abort_unless((int) $lesson->workspace_id === (int) $workspace->getKey(), 404);
        abort_unless($expiresAt->isFuture() && $expiresAt->lessThanOrEqualTo(now()->addDays(30)), 422, 'An exception must expire within the next 30 days.');
        $learnerAssignment = CourseDeliveryAssignment::query()->where('workspace_id', $workspace->getKey())
            ->where('membership_id', $learnerMembershipId)->effective()->where('assignment_role', 'learner')->exists();
        abort_unless($learnerAssignment, 404);

        return DB::transaction(function () use ($actor, $workspace, $lesson, $learnerMembershipId, $reason, $expiresAt): CourseDeliveryReleaseException {
            CourseDeliveryLesson::query()->whereKey($lesson->getKey())->lockForUpdate()->firstOrFail();
            $pending = CourseDeliveryReleaseException::query()->where('tenant_id', $workspace->tenant_id)
                ->where('workspace_id', $workspace->getKey())->where('lesson_id', $lesson->getKey())
                ->where('membership_id', $learnerMembershipId)->where('status', 'pending')->exists();
            abort_if($pending, 409, 'A release exception request is already pending for this learner and lesson.');
            $exception = CourseDeliveryReleaseException::query()->create([
                'tenant_id' => $workspace->tenant_id, 'workspace_id' => $workspace->getKey(),
                'lesson_id' => $lesson->getKey(), 'membership_id' => $learnerMembershipId,
                'requested_by' => $actor->getKey(), 'reason' => trim($reason), 'expires_at' => $expiresAt,
                'status' => 'pending',
            ]);
            $this->audit->record('learning.delivery.release_exception.requested', $actor, (int) $workspace->tenant_id, $exception, [
                'workspace_id' => $workspace->getKey(), 'lesson_id' => $lesson->getKey(),
                'membership_id' => $learnerMembershipId, 'expires_at' => $expiresAt->toIso8601String(),
            ]);

            return $exception;
        });
    }

    public function decideReleaseException(User $actor, CourseDeliveryReleaseException $exception, bool $approve): CourseDeliveryReleaseException
    {
        $membership = $this->context->membership();
        abort_unless($membership->hasPermission('learning.delivery.release-exceptions.approve'), 403);
        $exception = CourseDeliveryReleaseException::query()->where('tenant_id', $membership->tenant_id)->findOrFail($exception->getKey());
        abort_if((int) $exception->requested_by === (int) $actor->getKey(), 403, 'The requester cannot approve their own exception.');

        return DB::transaction(function () use ($actor, $exception, $approve): CourseDeliveryReleaseException {
            $locked = CourseDeliveryReleaseException::query()->lockForUpdate()->findOrFail($exception->getKey());
            abort_unless($locked->status === 'pending', 409, 'Only pending exception requests can be decided.');
            if ($approve) {
                abort_unless($locked->expires_at->isFuture(), 409, 'Expired exception requests cannot be approved.');
                $locked->forceFill(['status' => 'approved', 'approved_by' => $actor->getKey(), 'approved_at' => now()])->save();
            } else {
                $locked->forceFill(['status' => 'rejected'])->save();
            }
            $this->audit->record('learning.delivery.release_exception.'.($approve ? 'approved' : 'rejected'), $actor, (int) $locked->tenant_id, $locked, [
                'workspace_id' => $locked->workspace_id, 'lesson_id' => $locked->lesson_id,
                'membership_id' => $locked->membership_id, 'expires_at' => $locked->expires_at->toIso8601String(),
            ]);

            return $locked->refresh();
        });
    }

    public function revokeReleaseException(User $actor, CourseDeliveryWorkspace $workspace, CourseDeliveryReleaseException $exception, string $reason): void
    {
        $this->assertCanManage($actor, $workspace);
        DB::transaction(function () use ($actor, $workspace, $exception, $reason): void {
            $locked = CourseDeliveryReleaseException::query()->where('tenant_id', $workspace->tenant_id)
                ->where('workspace_id', $workspace->getKey())->lockForUpdate()->findOrFail($exception->getKey());
            abort_unless($locked->status === 'approved' && $locked->revoked_at === null, 409, 'Only active approved exceptions can be revoked.');
            $locked->forceFill(['status' => 'revoked', 'revoked_by' => $actor->getKey(), 'revoked_at' => now()])->save();
            $this->audit->record('learning.delivery.release_exception.revoked', $actor, (int) $workspace->tenant_id, $locked, [
                'workspace_id' => $workspace->getKey(), 'lesson_id' => $locked->lesson_id,
                'membership_id' => $locked->membership_id, 'reason' => trim($reason),
            ]);
        });
    }

    /** @return array{completed:int,total:int,percent:int,modules:list<array{module_id:int,title:string,completed:int,total:int,percent:int}>} */
    public function learnerProgressSummary(CourseDeliveryWorkspace $workspace, TenantMembership $membership): array
    {
        $modules = CourseDeliveryModule::query()->where('workspace_id', $workspace->getKey())->with(['lessons' => fn ($query) => $query->whereNotNull('published_version_number')])->orderBy('sort_order')->get();
        $lessons = $modules->flatMap(fn (CourseDeliveryModule $module) => $module->lessons)->values();
        $optionalLessonIds = $this->optionalLessonIds($lessons);
        $lessons = $lessons->reject(fn (CourseDeliveryLesson $lesson): bool => in_array((int) $lesson->getKey(), $optionalLessonIds, true))->values();
        $progress = CourseDeliveryProgress::query()->where('workspace_id', $workspace->getKey())
            ->where('membership_id', $membership->getKey())->where('status', 'completed')
            ->whereIn('lesson_id', $lessons->pluck('id'))->get()->keyBy('lesson_id');
        $isCompleted = fn (CourseDeliveryLesson $lesson): bool => isset($progress[$lesson->getKey()])
            && (int) $progress[$lesson->getKey()]->revision_version === (int) $lesson->published_version_number;
        $completed = $lessons->filter($isCompleted)->count();
        $total = $lessons->count();

        return [
            'completed' => $completed,
            'total' => $total,
            'percent' => $total === 0 ? 0 : (int) floor($completed * 100 / $total),
            'modules' => $modules->map(function (CourseDeliveryModule $module) use ($isCompleted, $optionalLessonIds): array {
                $moduleLessons = $module->lessons->reject(fn (CourseDeliveryLesson $lesson): bool => in_array((int) $lesson->getKey(), $optionalLessonIds, true));
                $moduleCompleted = $moduleLessons->filter($isCompleted)->count();
                $moduleTotal = $moduleLessons->count();

                return [
                    'module_id' => (int) $module->getKey(), 'title' => $module->title,
                    'completed' => $moduleCompleted, 'total' => $moduleTotal,
                    'percent' => $moduleTotal === 0 ? 0 : (int) floor($moduleCompleted * 100 / $moduleTotal),
                ];
            })->values()->all(),
        ];
    }

    /** @param Collection<int, CourseDeliveryLesson> $lessons
     * @return list<int>
     */
    private function optionalLessonIds(Collection $lessons): array
    {
        if ($lessons->isEmpty()) {
            return [];
        }
        $revisions = CourseDeliveryLessonRevision::query()->whereIn('lesson_id', $lessons->pluck('id'))
            ->get(['lesson_id', 'version_number', 'lesson_snapshot'])
            ->keyBy(fn (CourseDeliveryLessonRevision $revision): string => $revision->lesson_id.':'.$revision->version_number);

        return $lessons->filter(function (CourseDeliveryLesson $lesson) use ($revisions): bool {
            $revision = $revisions->get($lesson->getKey().':'.$lesson->published_version_number);
            if (! $revision instanceof CourseDeliveryLessonRevision) {
                return false;
            }
            foreach ($revision->lesson_snapshot['blocks'] ?? [] as $block) {
                if (is_array($block) && ($block['type'] ?? null) === 'release_policy') {
                    return ($block['content']['optional_path'] ?? false) === true;
                }
            }

            return false;
        })->map(fn (CourseDeliveryLesson $lesson): int => (int) $lesson->getKey())->values()->all();
    }

    public function recordLessonAccess(User $actor, CourseDeliveryWorkspace $workspace, CourseDeliveryLesson $lesson): CourseDeliveryProgress
    {
        $revision = $this->assertCanLearn($actor, $workspace, $lesson);
        $membershipId = (int) $this->context->membership()->getKey();
        $now = now();
        $key = [
            'tenant_id' => (int) $workspace->tenant_id,
            'workspace_id' => (int) $workspace->getKey(),
            'lesson_id' => (int) $lesson->getKey(),
            'membership_id' => $membershipId,
        ];
        $existing = CourseDeliveryProgress::query()->where($key)->first();
        if ($existing === null) {
            DB::table('course_delivery_progress')->insertOrIgnore($key + [
                'revision_version' => $revision->version_number,
                'status' => 'started', 'resume_seconds' => 0, 'started_at' => $now,
                'last_activity_at' => $now, 'created_at' => $now, 'updated_at' => $now,
            ]);
        } elseif ((int) $existing->revision_version !== (int) $revision->version_number) {
            $existing->forceFill([
                'revision_version' => $revision->version_number, 'status' => 'started', 'resume_seconds' => 0,
                'started_at' => $now, 'last_activity_at' => $now, 'completed_at' => null,
                'learner_confirmed_at' => null, 'instructor_confirmed_at' => null, 'instructor_confirmed_by' => null,
            ])->save();
        } elseif ($existing->status !== 'completed') {
            $existing->forceFill(['last_activity_at' => $now])->save();
        }

        return CourseDeliveryProgress::query()->where($key)->firstOrFail();
    }

    public function saveResumePosition(User $actor, CourseDeliveryWorkspace $workspace, CourseDeliveryLesson $lesson, int $seconds): CourseDeliveryProgress
    {
        abort_unless($seconds >= 0 && $seconds <= 86400, 422, 'Resume position is outside the supported range.');
        $revision = $this->assertCanLearn($actor, $workspace, $lesson);
        $membershipId = (int) $this->context->membership()->getKey();
        $key = [
            'tenant_id' => (int) $workspace->tenant_id,
            'workspace_id' => (int) $workspace->getKey(),
            'lesson_id' => (int) $lesson->getKey(),
            'membership_id' => $membershipId,
        ];

        return DB::transaction(function () use ($key, $revision, $seconds): CourseDeliveryProgress {
            $now = now();
            DB::table('course_delivery_progress')->insertOrIgnore($key + [
                'revision_version' => $revision->version_number,
                'status' => 'started',
                'resume_seconds' => 0,
                'started_at' => $now,
                'last_activity_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $progress = CourseDeliveryProgress::query()->where($key)->lockForUpdate()->firstOrFail();

            if ((int) $progress->revision_version !== (int) $revision->version_number) {
                $progress->forceFill([
                    'revision_version' => $revision->version_number,
                    'status' => 'started',
                    'resume_seconds' => 0,
                    'started_at' => $now,
                    'last_activity_at' => $now,
                    'completed_at' => null,
                    'learner_confirmed_at' => null,
                    'instructor_confirmed_at' => null,
                    'instructor_confirmed_by' => null,
                ]);
            }

            abort_unless($progress->status !== 'completed', 409, 'Completed lesson progress is immutable.');
            $progress->forceFill(['resume_seconds' => $seconds, 'last_activity_at' => $now])->save();

            return $progress->refresh();
        }, 3);
    }

    public function completeLesson(User $actor, CourseDeliveryWorkspace $workspace, CourseDeliveryLesson $lesson): CourseDeliveryProgress
    {
        $revision = $this->assertCanLearn($actor, $workspace, $lesson);
        $this->recordLessonAccess($actor, $workspace, $lesson);
        $membershipId = (int) $this->context->membership()->getKey();
        $key = ['tenant_id' => (int) $workspace->tenant_id, 'workspace_id' => (int) $workspace->getKey(), 'lesson_id' => (int) $lesson->getKey(), 'membership_id' => $membershipId];

        return DB::transaction(function () use ($actor, $workspace, $lesson, $key, $revision): CourseDeliveryProgress {
            $progress = CourseDeliveryProgress::query()->where($key)->lockForUpdate()->firstOrFail();
            if ($progress->status !== 'completed') {
                $now = now();
                $policy = $this->completionPolicy($revision->lesson_snapshot);
                if (($policy['require_all_required_activities'] ?? false) === true) {
                    foreach ($this->requiredActivityIds($revision->lesson_snapshot) as $activityId) {
                        $submitted = CourseDeliveryActivityResponse::query()
                            ->where('tenant_id', $workspace->tenant_id)
                            ->where('workspace_id', $workspace->getKey())
                            ->where('lesson_id', $lesson->getKey())
                            ->where('membership_id', $progress->membership_id)
                            ->where('revision_version', $revision->version_number)
                            ->where('block_id', $activityId)
                            ->where('state', 'submitted')
                            ->exists();
                        abort_unless($submitted, 409, 'Complete each required learning activity before confirming this lesson.');
                    }
                }
                $requiresInstructor = ($policy['require_instructor_confirmation'] ?? false) === true;
                $progress->forceFill([
                    'learner_confirmed_at' => $progress->learner_confirmed_at ?? $now,
                    'status' => $requiresInstructor ? 'started' : 'completed',
                    'completed_at' => $requiresInstructor ? null : ($progress->completed_at ?? $now),
                    'last_activity_at' => $now,
                ])->save();
                $this->audit->record(
                    $requiresInstructor ? 'learning.delivery.lesson.completion_confirmation_requested' : 'learning.delivery.lesson.completed',
                    $actor,
                    (int) $workspace->tenant_id,
                    $progress,
                    [
                        'workspace_id' => $workspace->getKey(), 'lesson_id' => $lesson->getKey(), 'revision_version' => $progress->revision_version,
                    ],
                );
            }

            return $progress->refresh();
        });
    }

    /** @param array<string, mixed> $response */
    public function saveActivityResponse(
        User $actor,
        CourseDeliveryWorkspace $workspace,
        CourseDeliveryLesson $lesson,
        int $blockId,
        string $idempotencyKey,
        string $state,
        array $response,
    ): CourseDeliveryActivityResponse {
        $revision = $this->assertCanLearn($actor, $workspace, $lesson);
        $definition = $this->activityDefinition($revision->lesson_snapshot, $blockId);
        $payload = $this->validateActivityResponse($definition, $response, $state);
        $membership = $this->context->membership();

        return DB::transaction(function () use ($actor, $workspace, $lesson, $blockId, $idempotencyKey, $state, $payload, $revision, $membership, $definition): CourseDeliveryActivityResponse {
            $priorRequest = CourseDeliveryActivityResponse::query()
                ->where('tenant_id', $workspace->tenant_id)
                ->where('membership_id', $membership->getKey())
                ->where('idempotency_key', $idempotencyKey)
                ->first();
            if ($priorRequest !== null) {
                abort_unless(
                    (int) $priorRequest->workspace_id === (int) $workspace->getKey()
                    && (int) $priorRequest->lesson_id === (int) $lesson->getKey()
                    && (int) $priorRequest->block_id === $blockId
                    && (int) $priorRequest->revision_version === (int) $revision->version_number,
                    409,
                    'This request key was already used for another learning activity.',
                );

                return $priorRequest;
            }

            $latest = CourseDeliveryActivityResponse::query()
                ->where('tenant_id', $workspace->tenant_id)
                ->where('workspace_id', $workspace->getKey())
                ->where('lesson_id', $lesson->getKey())
                ->where('membership_id', $membership->getKey())
                ->where('revision_version', $revision->version_number)
                ->where('block_id', $blockId)
                ->orderByDesc('version_number')
                ->lockForUpdate()
                ->first();
            abort_if($latest?->state === 'submitted', 409, 'Submitted activity responses are immutable for this published revision.');

            $record = CourseDeliveryActivityResponse::query()->create([
                'tenant_id' => $workspace->tenant_id,
                'workspace_id' => $workspace->getKey(),
                'lesson_id' => $lesson->getKey(),
                'membership_id' => $membership->getKey(),
                'revision_version' => $revision->version_number,
                'block_id' => $blockId,
                'version_number' => $latest === null ? 1 : $latest->version_number + 1,
                'state' => $state,
                'response_payload' => $payload,
                'idempotency_key' => $idempotencyKey,
                'created_by' => $actor->getKey(),
                'submitted_at' => $state === 'submitted' ? now() : null,
                'retention_until' => now()->addDays((int) config('learning.activity_response_retention_days', 1095)),
            ]);
            $this->audit->record('learning.delivery.activity_response.saved', $actor, (int) $workspace->tenant_id, $record, [
                'workspace_id' => $workspace->getKey(),
                'lesson_id' => $lesson->getKey(),
                'revision_version' => $revision->version_number,
                'activity_id' => $blockId,
                'activity_type' => $definition['activity_type'],
                'version' => $record->version_number,
                'state' => $state,
            ]);

            return $record;
        });
    }

    public function assertCanConfirmCompletion(User $actor, CourseDeliveryWorkspace $workspace): void
    {
        $membership = $this->context->membership();
        $this->assertCampusScope($workspace);
        abort_unless((int) $membership->user_id === (int) $actor->getKey(), 403);
        if ($membership->hasPermission('learning.delivery.manage')) {
            return;
        }
        $assignedInstructor = $membership->hasPermission('learning.delivery.teach')
            && CourseDeliveryAssignment::query()->where('workspace_id', $workspace->getKey())
                ->where('membership_id', $membership->getKey())->effective()
                ->whereIn('assignment_role', ['course_owner', 'coordinator', 'instructor'])->exists();
        abort_unless($assignedInstructor, 403);
    }

    public function confirmLessonCompletion(User $actor, CourseDeliveryWorkspace $workspace, CourseDeliveryLesson $lesson, int $learnerMembershipId): CourseDeliveryProgress
    {
        $this->assertCanConfirmCompletion($actor, $workspace);

        return DB::transaction(function () use ($actor, $workspace, $lesson, $learnerMembershipId): CourseDeliveryProgress {
            $progress = CourseDeliveryProgress::query()
                ->where('tenant_id', $workspace->tenant_id)
                ->where('workspace_id', $workspace->getKey())
                ->where('lesson_id', $lesson->getKey())
                ->where('membership_id', $learnerMembershipId)
                ->lockForUpdate()
                ->firstOrFail();
            abort_unless($progress->learner_confirmed_at !== null && $progress->completed_at === null, 409, 'There is no pending learner completion confirmation.');
            abort_unless((int) $progress->membership()->value('user_id') !== (int) $actor->getKey(), 403, 'Learners cannot confirm their own completion.');
            abort_unless((int) $lesson->published_version_number === (int) $progress->revision_version, 409, 'The learner confirmation belongs to an older lesson revision.');

            $revision = CourseDeliveryLessonRevision::query()
                ->where('lesson_id', $lesson->getKey())->where('version_number', $progress->revision_version)->firstOrFail();
            $policy = $this->completionPolicy($revision->lesson_snapshot);
            abort_unless(($policy['require_instructor_confirmation'] ?? false) === true, 409, 'This lesson does not require instructor confirmation.');
            if (($policy['require_all_required_activities'] ?? false) === true) {
                foreach ($this->requiredActivityIds($revision->lesson_snapshot) as $activityId) {
                    abort_unless(CourseDeliveryActivityResponse::query()
                        ->where('tenant_id', $workspace->tenant_id)->where('workspace_id', $workspace->getKey())
                        ->where('lesson_id', $lesson->getKey())->where('membership_id', $learnerMembershipId)
                        ->where('revision_version', $revision->version_number)->where('block_id', $activityId)
                        ->where('state', 'submitted')->exists(), 409, 'A required learning activity is not submitted.');
                }
            }

            $progress->forceFill([
                'status' => 'completed', 'completed_at' => now(), 'instructor_confirmed_at' => now(),
                'instructor_confirmed_by' => $actor->getKey(), 'last_activity_at' => now(),
            ])->save();
            $this->audit->record('learning.delivery.lesson.instructor_completion_confirmed', $actor, (int) $workspace->tenant_id, $progress, [
                'workspace_id' => $workspace->getKey(), 'lesson_id' => $lesson->getKey(),
                'membership_id' => $learnerMembershipId, 'revision_version' => $progress->revision_version,
            ]);

            return $progress->refresh();
        });
    }

    private function assertCanPublish(User $actor, CourseDeliveryWorkspace $workspace): void
    {
        $membership = $this->context->membership();
        $this->assertCampusScope($workspace);
        abort_unless($membership->hasPermission('learning.delivery.publish'), 403);
        $institutionManager = $membership->hasPermission('learning.delivery.manage');
        $assignedPublisher = CourseDeliveryAssignment::query()->where('workspace_id', $workspace->getKey())
            ->where('membership_id', $membership->getKey())->effective()->where('assignment_role', 'publisher')->exists();
        abort_unless($institutionManager || $assignedPublisher, 403);
    }

    public function assertCanManage(User $actor, CourseDeliveryWorkspace $workspace): void
    {
        $membership = $this->context->membership();
        $this->assertCampusScope($workspace);
        abort_unless((int) $membership->user_id === (int) $actor->getKey(), 403);
        if ($membership->hasPermission('learning.delivery.manage')) {
            return;
        }
        $assigned = CourseDeliveryAssignment::query()->where('workspace_id', $workspace->getKey())->where('membership_id', $membership->getKey())
            ->effective()->whereIn('assignment_role', ['course_owner', 'coordinator', 'instructor', 'content_author'])->exists();
        abort_unless($assigned && $membership->hasPermission('learning.delivery.teach'), 403);
    }

    private function assertCampusScope(CourseDeliveryWorkspace $workspace): void
    {
        $campusId = $this->context->membership()->campus_id;
        if ($campusId !== null && (int) $campusId !== (int) $workspace->campus_id) {
            throw new NotFoundHttpException;
        }
    }

    private function assertDraftWorkspace(CourseDeliveryWorkspace $workspace): void
    {
        abort_unless($workspace->status === 'draft', 409, 'Published or archived workspaces cannot be changed.');
    }

    private function assertMutableLesson(CourseDeliveryLesson $lesson): void
    {
        abort_unless(in_array($lesson->workspace->status, ['draft', 'published'], true), 409, 'Archived workspaces cannot be changed.');
        abort_unless(in_array($lesson->status, ['draft', 'changes_requested'], true), 409, 'Only editable draft lessons can be changed.');
    }

    /**
     * @param  array<int, mixed>  $blocks
     * @return list<array{type: string, content: array<string, mixed>}>
     */
    private function validateBlocks(array $blocks): array
    {
        foreach (['release_policy', 'completion_policy'] as $uniqueType) {
            if (count(array_filter($blocks, static fn (mixed $block): bool => is_array($block) && ($block['type'] ?? null) === $uniqueType)) > 1) {
                throw ValidationException::withMessages(['blocks' => "A lesson can have only one {$uniqueType} block."]);
            }
        }
        $validator = Validator::make(['blocks' => $blocks], [
            'blocks' => ['present', 'array', 'max:50'],
            'blocks.*' => ['required', 'array:type,content'],
            'blocks.*.type' => ['required', 'string', 'in:text,heading,callout,code,divider,external_link,reflection,activity,release_policy,completion_policy'],
            'blocks.*.content' => ['required', 'array'],
            'blocks.*.content.text' => ['nullable', 'string', 'max:15000'],
            'blocks.*.content.title' => ['nullable', 'string', 'max:180'],
            'blocks.*.content.url' => ['nullable', 'url:https', 'max:2048'],
            'blocks.*.content.language' => ['nullable', 'string', 'max:48'],
            'blocks.*.content.progression_mode' => ['required_if:blocks.*.type,release_policy', 'nullable', 'in:free,sequential'],
            'blocks.*.content.release_at' => ['nullable', 'date_format:Y-m-d\\TH:i:s\\Z'],
            'blocks.*.content.available_until' => ['nullable', 'date_format:Y-m-d\\TH:i:s\\Z'],
            'blocks.*.content.drip_days_after_enrollment' => ['nullable', 'integer', 'min:0', 'max:3650'],
            'blocks.*.content.module_locked' => ['nullable', 'boolean'],
            'blocks.*.content.optional_path' => ['nullable', 'boolean'],
            'blocks.*.content.prerequisite_lesson_ids' => ['nullable', 'array', 'max:100'],
            'blocks.*.content.prerequisite_lesson_ids.*' => ['required', 'integer', 'min:1'],
            'blocks.*.content.prerequisite_module_ids' => ['nullable', 'array', 'max:50'],
            'blocks.*.content.prerequisite_module_ids.*' => ['required', 'integer', 'min:1'],
            'blocks.*.content.activity_type' => ['required_if:blocks.*.type,activity', 'nullable', 'in:reflection,reading_acknowledgement,checklist,guided_practice,self_evaluation,step_by_step,resource_exploration,lab_preparation'],
            'blocks.*.content.prompt' => ['required_if:blocks.*.type,activity', 'nullable', 'string', 'max:3000'],
            'blocks.*.content.required' => ['nullable', 'boolean'],
            'blocks.*.content.items' => ['nullable', 'array', 'max:30'],
            'blocks.*.content.items.*' => ['required', 'string', 'max:300'],
            'blocks.*.content.require_all_required_activities' => ['nullable', 'boolean'],
            'blocks.*.content.require_instructor_confirmation' => ['nullable', 'boolean'],
        ]);
        $validator->validate();
        $result = [];
        foreach ($blocks as $block) {
            $content = $block['type'] === 'release_policy'
                ? array_intersect_key($block['content'], array_flip(['progression_mode', 'release_at', 'available_until', 'drip_days_after_enrollment', 'module_locked', 'optional_path', 'prerequisite_lesson_ids', 'prerequisite_module_ids']))
                : array_intersect_key($block['content'], array_flip(['text', 'title', 'url', 'language']));
            if ($block['type'] === 'release_policy') {
                if (! in_array($content['progression_mode'] ?? null, ['free', 'sequential'], true)) {
                    throw ValidationException::withMessages(['blocks' => 'Choose a valid lesson progression policy.']);
                }
                if (isset($content['release_at'])) {
                    $releaseAt = CarbonImmutable::createFromFormat('!Y-m-d\\TH:i:s\\Z', (string) $content['release_at'], 'UTC');
                    if ($releaseAt === null) {
                        throw ValidationException::withMessages(['blocks' => 'Enter a valid UTC lesson release date and time.']);
                    }
                    $content['release_at'] = $releaseAt->toIso8601String();
                }
                if (isset($content['available_until'])) {
                    $availableUntil = CarbonImmutable::createFromFormat('!Y-m-d\\TH:i:s\\Z', (string) $content['available_until'], 'UTC');
                    if ($availableUntil === null || (isset($content['release_at']) && $availableUntil->lessThanOrEqualTo(CarbonImmutable::parse($content['release_at'])))) {
                        throw ValidationException::withMessages(['blocks' => 'The availability end must be later than the release time.']);
                    }
                    $content['available_until'] = $availableUntil->toIso8601String();
                }
                $content['drip_days_after_enrollment'] = (int) ($content['drip_days_after_enrollment'] ?? 0);
                $content['module_locked'] = (bool) ($content['module_locked'] ?? false);
                $content['optional_path'] = (bool) ($content['optional_path'] ?? false);
                $content['prerequisite_lesson_ids'] = array_values(array_unique(array_map('intval', $content['prerequisite_lesson_ids'] ?? [])));
                $content['prerequisite_module_ids'] = array_values(array_unique(array_map('intval', $content['prerequisite_module_ids'] ?? [])));
                $result[] = ['type' => 'release_policy', 'content' => $content];

                continue;
            }
            if ($block['type'] === 'completion_policy') {
                $result[] = ['type' => 'completion_policy', 'content' => [
                    'require_all_required_activities' => (bool) ($block['content']['require_all_required_activities'] ?? false),
                    'require_instructor_confirmation' => (bool) ($block['content']['require_instructor_confirmation'] ?? false),
                ]];

                continue;
            }
            if ($block['type'] === 'activity') {
                $activityType = (string) $block['content']['activity_type'];
                $items = array_values(array_unique(array_map('trim', $block['content']['items'] ?? [])));
                if (in_array($activityType, ['checklist', 'step_by_step', 'resource_exploration'], true) && $items === []) {
                    throw ValidationException::withMessages(['blocks' => 'This activity type requires at least one item.']);
                }
                if (! in_array($activityType, ['checklist', 'step_by_step', 'resource_exploration'], true) && $items !== []) {
                    throw ValidationException::withMessages(['blocks' => 'Items are only supported for checklists, steps and resource exploration.']);
                }
                $result[] = ['type' => 'activity', 'content' => [
                    'activity_type' => $activityType,
                    'prompt' => trim((string) $block['content']['prompt']),
                    'required' => (bool) ($block['content']['required'] ?? true),
                    'items' => $items,
                ]];

                continue;
            }
            if ($block['type'] !== 'divider' && trim((string) ($content['text'] ?? $content['title'] ?? '')) === '' && $block['type'] !== 'external_link') {
                throw ValidationException::withMessages(['blocks' => 'Each content block must contain readable text.']);
            }
            if ($block['type'] === 'external_link' && ! isset($content['url'])) {
                throw ValidationException::withMessages(['blocks' => 'External resource blocks require an HTTPS link.']);
            }
            $result[] = ['type' => $block['type'], 'content' => $content];
        }

        return $result;
    }

    /** @param list<array{type:string,content:array<string,mixed>}> $replacementBlocks */
    private function assertValidReleaseDependencies(CourseDeliveryLesson $lesson, array $replacementBlocks): void
    {
        $workspaceId = (int) $lesson->workspace_id;
        $policy = collect($replacementBlocks)->firstWhere('type', 'release_policy')['content'] ?? [];
        $lessonIds = array_map('intval', $policy['prerequisite_lesson_ids'] ?? []);
        $moduleIds = array_map('intval', $policy['prerequisite_module_ids'] ?? []);
        $moduleLessonIds = CourseDeliveryLesson::query()->where('workspace_id', $workspaceId)->whereIn('module_id', $moduleIds)->pluck('id')->map(fn ($id): int => (int) $id)->all();
        $targets = array_values(array_unique([...$lessonIds, ...$moduleLessonIds]));
        if (in_array((int) $lesson->getKey(), $targets, true)) {
            throw ValidationException::withMessages(['blocks' => 'A lesson cannot depend on itself or on a module that contains it.']);
        }
        $validLessonIds = CourseDeliveryLesson::query()->where('workspace_id', $workspaceId)->whereIn('id', $lessonIds)->pluck('id')->map(fn ($id): int => (int) $id)->all();
        $validModuleIds = CourseDeliveryModule::query()->where('workspace_id', $workspaceId)->whereIn('id', $moduleIds)->pluck('id')->map(fn ($id): int => (int) $id)->all();
        if (count($validLessonIds) !== count(array_unique($lessonIds)) || count($validModuleIds) !== count(array_unique($moduleIds))) {
            throw ValidationException::withMessages(['blocks' => 'Prerequisites must belong to this course delivery workspace.']);
        }

        $candidates = CourseDeliveryLesson::query()->where('workspace_id', $workspaceId)->get(['id', 'module_id']);
        $candidateIds = $candidates->map(fn (CourseDeliveryLesson $candidate): int => (int) $candidate->getKey());
        $blocksByLesson = CourseDeliveryBlock::query()->whereIn('lesson_id', $candidateIds)->get(['lesson_id', 'block_type', 'content'])->groupBy('lesson_id');
        $lessonIdsByModule = $candidates->groupBy('module_id')->map(fn ($rows) => $rows->map(fn (CourseDeliveryLesson $candidate): int => (int) $candidate->getKey())->all());
        $dependencies = [];
        foreach ($candidates as $candidate) {
            $blocks = (int) $candidate->getKey() === (int) $lesson->getKey()
                ? $replacementBlocks
                : ($blocksByLesson->get($candidate->getKey(), collect())->map(fn (CourseDeliveryBlock $block): array => ['type' => $block->block_type, 'content' => $block->content])->all());
            $release = collect($blocks)->firstWhere('type', 'release_policy')['content'] ?? [];
            $moduleDependencies = [];
            foreach (array_map('intval', $release['prerequisite_module_ids'] ?? []) as $prerequisiteModuleId) {
                $moduleDependencies = [...$moduleDependencies, ...$lessonIdsByModule->get($prerequisiteModuleId, [])];
            }
            $dependencies[(int) $candidate->getKey()] = array_values(array_unique([...array_map('intval', $release['prerequisite_lesson_ids'] ?? []), ...$moduleDependencies]));
        }
        try {
            CourseDeliveryReleasePolicy::assertAcyclic($dependencies);
        } catch (\RuntimeException $exception) {
            throw ValidationException::withMessages(['blocks' => $exception->getMessage()]);
        }
    }

    /**
     * @param  array<string, mixed>  $snapshot
     * @return array<string, mixed>
     */
    private function activityDefinition(array $snapshot, int $blockId): array
    {
        foreach (is_array($snapshot['blocks'] ?? null) ? $snapshot['blocks'] : [] as $block) {
            if (is_array($block) && ($block['type'] ?? null) === 'activity'
                && (int) ($block['id'] ?? 0) === $blockId && is_array($block['content'] ?? null)) {
                return $block['content'];
            }
        }
        abort(404);
    }

    /**
     * @param  array<string, mixed>  $snapshot
     * @return array<string, mixed>
     */
    private function completionPolicy(array $snapshot): array
    {
        foreach (is_array($snapshot['blocks'] ?? null) ? $snapshot['blocks'] : [] as $block) {
            if (is_array($block) && ($block['type'] ?? null) === 'completion_policy' && is_array($block['content'] ?? null)) {
                return $block['content'];
            }
        }

        return [];
    }

    /** Preserve response foreign keys while removing superseded activities from active lesson content. */
    private function archiveActivityBlocksWithResponses(CourseDeliveryLesson $lesson): void
    {
        $blockIds = DB::table('course_delivery_activity_responses')
            ->where('tenant_id', $lesson->tenant_id)
            ->where('lesson_id', $lesson->getKey())
            ->distinct()
            ->pluck('block_id');
        if ($blockIds->isEmpty()) {
            return;
        }

        $blocks = CourseDeliveryBlock::query()->where('lesson_id', $lesson->getKey())
            ->where('block_type', 'activity')->whereIn('id', $blockIds)->orderBy('id')->get();
        $sortOrder = (int) CourseDeliveryBlock::query()->where('lesson_id', $lesson->getKey())->max('sort_order') + 1000;
        foreach ($blocks as $index => $block) {
            $block->forceFill(['block_type' => 'archived_activity', 'sort_order' => $sortOrder + $index])->save();
        }
    }

    /**
     * @param  array<string, mixed>  $snapshot
     * @return list<int>
     */
    private function requiredActivityIds(array $snapshot): array
    {
        $ids = [];
        foreach (is_array($snapshot['blocks'] ?? null) ? $snapshot['blocks'] : [] as $block) {
            if (is_array($block) && ($block['type'] ?? null) === 'activity'
                && is_array($block['content'] ?? null) && ($block['content']['required'] ?? false) === true
                && (int) ($block['id'] ?? 0) > 0) {
                $ids[] = (int) $block['id'];
            }
        }

        return $ids;
    }

    /**
     * @param  array<string, mixed>  $definition
     * @param  array<string, mixed>  $response
     * @return array<string, mixed>
     */
    private function validateActivityResponse(array $definition, array $response, string $state): array
    {
        abort_unless(in_array($state, ['draft', 'submitted'], true), 422);
        $type = $definition['activity_type'] ?? '';
        $rules = match ($type) {
            'reflection', 'guided_practice', 'lab_preparation' => [
                'text' => [$state === 'submitted' ? 'required' : 'nullable', 'string', 'max:10000'],
            ],
            'reading_acknowledgement' => [
                'acknowledged' => [$state === 'submitted' ? 'accepted' : 'nullable'],
            ],
            'checklist', 'step_by_step', 'resource_exploration' => [
                'selected_items' => ['nullable', 'array', 'max:30'],
                'selected_items.*' => ['required', 'integer', 'min:0'],
            ],
            'self_evaluation' => [
                'rating' => [$state === 'submitted' ? 'required' : 'nullable', 'integer', 'min:1', 'max:5'],
                'notes' => ['nullable', 'string', 'max:5000'],
            ],
            default => [],
        };
        abort_if($rules === [], 422, 'Unsupported learner activity.');
        $validated = Validator::make($response, $rules)->validate();
        $items = is_array($definition['items'] ?? null) ? $definition['items'] : [];
        if (in_array($type, ['checklist', 'step_by_step', 'resource_exploration'], true)) {
            $selected = array_values(array_unique(array_map('intval', $validated['selected_items'] ?? [])));
            if (array_diff($selected, array_keys($items)) !== []) {
                throw ValidationException::withMessages(['response.selected_items' => 'The response includes an unknown activity item.']);
            }
            if ($state === 'submitted') {
                if (count($selected) !== count($items)) {
                    throw ValidationException::withMessages(['response.selected_items' => 'Complete each activity item before submitting.']);
                }
            }
            $validated['selected_items'] = $selected;
        }

        return $validated;
    }
}
