<?php

namespace App\Domain\Learning;

use App\Domain\Audit\AuditRecorder;
use App\Domain\Tenancy\TenantContext;
use App\Models\AcademicYear;
use App\Models\CourseDeliveryBlock;
use App\Models\CourseDeliveryCopyOperation;
use App\Models\CourseDeliveryLesson;
use App\Models\CourseDeliveryModule;
use App\Models\CourseDeliveryTemplate;
use App\Models\CourseDeliveryTemplateShare;
use App\Models\CourseDeliveryTemplateVersion;
use App\Models\CourseDeliveryWorkspace;
use App\Models\Institution;
use App\Models\User;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Creates immutable, institution-scoped learning templates and idempotent copies.
 *
 * @phpstan-type TemplateBlock array{type:string,content:array<string,mixed>}
 * @phpstan-type TemplateLesson array{source_id:int,title:string,summary:?string,learning_objectives:?string,estimated_minutes:int|null,blocks:list<TemplateBlock>}
 * @phpstan-type TemplateModule array{source_id:int,title:string,summary:?string,lessons:list<TemplateLesson>}
 * @phpstan-type TemplateSnapshot array{schema_version:1,provenance:array<string,mixed>,modules:list<TemplateModule>,lesson_count:int}
 */
final class CourseDeliveryTemplateWorkflow
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly CourseDeliveryWorkflow $delivery,
        private readonly CourseDeliveryMediaWorkflow $media,
        private readonly AuditRecorder $audit,
    ) {}

    public function createFromWorkspace(User $actor, CourseDeliveryWorkspace $workspace, string $name, ?string $description): CourseDeliveryTemplate
    {
        $workspace = CourseDeliveryWorkspace::query()->with(['modules.lessons.blocks', 'courseVersion', 'curriculumVersion'])
            ->findOrFail($workspace->getKey());
        $this->delivery->assertCanManage($actor, $workspace);
        $this->assertTemplateInstitution($workspace);
        $snapshot = $this->snapshot($workspace);
        $tenantId = (int) $this->context->tenant()->getKey();
        $institutionId = (int) $workspace->institution_id;

        return DB::transaction(function () use ($actor, $name, $description, $snapshot, $tenantId, $institutionId, $workspace): CourseDeliveryTemplate {
            $slugBase = Str::slug($name) ?: 'course-template';
            $template = CourseDeliveryTemplate::query()->create([
                'institution_id' => $institutionId,
                'name' => trim($name),
                'slug' => mb_substr($slugBase.'-'.Str::lower(Str::random(8)), 0, 200),
                'description' => $description,
                'current_version' => 1,
                'status' => 'published',
                'created_by' => $actor->getKey(),
            ]);
            $this->createVersion($actor, $template, $workspace, $snapshot, 1);
            $this->audit->record('learning.delivery.template.created', $actor, $tenantId, $template, [
                'source_workspace_id' => $workspace->getKey(), 'version' => 1,
                'module_count' => count($snapshot['modules']), 'lesson_count' => $snapshot['lesson_count'],
            ]);

            return $template->load('versions');
        });
    }

    public function reviseFromWorkspace(User $actor, CourseDeliveryTemplate $template, CourseDeliveryWorkspace $workspace): CourseDeliveryTemplate
    {
        $template = CourseDeliveryTemplate::query()->lockForUpdate()->findOrFail($template->getKey());
        $workspace = CourseDeliveryWorkspace::query()->with(['modules.lessons.blocks', 'courseVersion', 'curriculumVersion'])
            ->findOrFail($workspace->getKey());
        $this->delivery->assertCanManage($actor, $workspace);
        $this->assertTemplateInstitution($workspace);
        abort_unless((int) $template->institution_id === (int) $workspace->institution_id, 404);
        abort_unless((int) $template->tenant_id === (int) $workspace->tenant_id, 404);
        $snapshot = $this->snapshot($workspace);

        return DB::transaction(function () use ($actor, $template, $workspace, $snapshot): CourseDeliveryTemplate {
            $locked = CourseDeliveryTemplate::query()->lockForUpdate()->findOrFail($template->getKey());
            $version = (int) $locked->current_version + 1;
            $this->createVersion($actor, $locked, $workspace, $snapshot, $version);
            $locked->forceFill(['current_version' => $version, 'status' => 'published'])->save();
            $this->audit->record('learning.delivery.template.version_published', $actor, (int) $locked->tenant_id, $locked, [
                'source_workspace_id' => $workspace->getKey(), 'version' => $version,
                'module_count' => count($snapshot['modules']), 'lesson_count' => $snapshot['lesson_count'],
            ]);

            return $locked->fresh('versions');
        });
    }

    public function share(User $actor, CourseDeliveryTemplate $template, int $institutionId): CourseDeliveryTemplateShare
    {
        $template = CourseDeliveryTemplate::query()->findOrFail($template->getKey());
        $this->assertTemplateManager();
        abort_unless((int) $template->institution_id === (int) $this->context->membership()->institution_id, 404);
        abort_unless($template->status === 'published', 409, 'Only a published template can be shared.');
        $institution = Institution::query()->where('tenant_id', $this->context->tenant()->getKey())->findOrFail($institutionId);
        abort_unless((int) $institution->getKey() !== (int) $template->institution_id, 422, 'Choose another institution in this tenant.');

        return DB::transaction(function () use ($actor, $template, $institution): CourseDeliveryTemplateShare {
            $share = CourseDeliveryTemplateShare::query()->firstOrCreate([
                'template_id' => $template->getKey(), 'institution_id' => $institution->getKey(),
            ], ['shared_by' => $actor->getKey(), 'created_at' => now()]);
            $this->audit->record('learning.delivery.template.shared', $actor, (int) $template->tenant_id, $share, [
                'template_id' => $template->getKey(), 'institution_id' => $institution->getKey(),
            ]);

            return $share;
        });
    }

    /** @return array{templates:LengthAwarePaginator<int, CourseDeliveryTemplate>,institution_id:int} */
    public function availableTemplates(): array
    {
        $institutionId = (int) $this->context->membership()->institution_id;
        $templates = CourseDeliveryTemplate::query()
            ->where('status', 'published')
            ->where(function ($query) use ($institutionId): void {
                $query->where('institution_id', $institutionId)
                    ->orWhereHas('shares', fn ($shares) => $shares->where('institution_id', $institutionId));
            })
            ->orderBy('name')->paginate(20)->withQueryString();

        return ['templates' => $templates, 'institution_id' => $institutionId];
    }

    /**
     * @param  list<int>  $moduleIds
     * @param  array<int,list<int>>  $lessonIdsByModule
     */
    public function copyToWorkspace(
        User $actor,
        CourseDeliveryTemplate $template,
        int $versionNumber,
        CourseDeliveryWorkspace $destination,
        array $moduleIds,
        array $lessonIdsByModule,
        string $idempotencyKey,
    ): CourseDeliveryCopyOperation {
        $template = CourseDeliveryTemplate::query()->where('status', 'published')->findOrFail($template->getKey());
        $version = CourseDeliveryTemplateVersion::query()->where('template_id', $template->getKey())
            ->where('version_number', $versionNumber)->whereNotNull('published_at')->firstOrFail();
        $snapshot = $version->content_snapshot;
        $snapshotJson = json_encode($this->canonicalize($snapshot), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        abort_unless(hash_equals($version->content_checksum, hash('sha256', $snapshotJson)), 409, 'The template version checksum is invalid.');
        $selection = $this->validatedSelection($snapshot, $moduleIds, $lessonIdsByModule);
        $tenantId = (int) $this->context->tenant()->getKey();

        return DB::transaction(function () use ($actor, $template, $version, $destination, $selection, $idempotencyKey, $tenantId, $snapshot): CourseDeliveryCopyOperation {
            // Lock the destination before checking the idempotency key. Two workers
            // replaying the same request then serialize and the second observes the
            // first committed operation instead of attempting a duplicate copy.
            $destination = CourseDeliveryWorkspace::query()->lockForUpdate()->findOrFail($destination->getKey());
            $this->delivery->assertCanManage($actor, $destination);
            $this->assertCanAdopt($template, $destination);
            abort_unless($destination->status === 'draft', 409, 'Templates can only be copied into a draft workspace.');

            $existing = CourseDeliveryCopyOperation::query()->where('idempotency_key', $idempotencyKey)->first();
            if ($existing !== null) {
                $matches = (int) $existing->template_id === (int) $template->getKey()
                    && (int) $existing->template_version === (int) $version->version_number
                    && (int) $existing->destination_workspace_id === (int) $destination->getKey()
                    && $this->normalizeSelection($existing->selection) === $selection;
                abort_unless($matches, 409, 'This copy request key has already been used for a different operation.');

                return $existing;
            }
            $moduleMap = [];
            $lessonMap = [];
            $copyModuleRows = collect($snapshot['modules'])->keyBy('source_id');
            foreach ($selection['module_ids'] as $moduleId) {
                $sourceModule = $copyModuleRows->get($moduleId);
                if (! is_array($sourceModule)) {
                    throw ValidationException::withMessages(['module_ids' => 'A selected module is no longer present in this template version.']);
                }
                $module = CourseDeliveryModule::query()->create([
                    'workspace_id' => $destination->getKey(), 'title' => $sourceModule['title'],
                    'summary' => $sourceModule['summary'], 'sort_order' => (int) CourseDeliveryModule::query()
                        ->where('workspace_id', $destination->getKey())->max('sort_order') + 1,
                    'status' => 'draft',
                ]);
                $moduleMap[(string) $moduleId] = (int) $module->getKey();
                $selectedLessons = $selection['lesson_ids'][$moduleId] ?? array_column($sourceModule['lessons'], 'source_id');
                $lessonRows = collect($sourceModule['lessons'])->keyBy('source_id');
                foreach ($selectedLessons as $lessonId) {
                    $sourceLesson = $lessonRows->get($lessonId);
                    if (! is_array($sourceLesson)) {
                        throw ValidationException::withMessages(['lesson_ids' => 'A selected lesson is no longer present in this template version.']);
                    }
                    $lesson = CourseDeliveryLesson::query()->create([
                        'workspace_id' => $destination->getKey(), 'module_id' => $module->getKey(),
                        'title' => $sourceLesson['title'], 'summary' => $sourceLesson['summary'],
                        'learning_objectives' => $sourceLesson['learning_objectives'],
                        'estimated_minutes' => $sourceLesson['estimated_minutes'],
                        'sort_order' => (int) CourseDeliveryLesson::query()->where('module_id', $module->getKey())->max('sort_order') + 1,
                        'status' => 'draft', 'lock_version' => 1,
                        'created_by' => $actor->getKey(), 'draft_author_user_id' => $actor->getKey(),
                    ]);
                    $lessonMap[(string) $lessonId] = (int) $lesson->getKey();
                    foreach ($sourceLesson['blocks'] as $position => $block) {
                        if ($block['type'] === 'media_asset') {
                            $assetId = (int) ($block['content']['asset_id'] ?? 0);
                            if ($assetId < 1) {
                                throw ValidationException::withMessages(['template' => 'A template media reference is invalid.']);
                            }
                            $this->media->reuseTemplateAsset(
                                $actor, $assetId, (int) $snapshot['provenance']['source_workspace_id'], $lesson, $block['content'],
                            );

                            continue;
                        }
                        CourseDeliveryBlock::query()->create([
                            'lesson_id' => $lesson->getKey(), 'sort_order' => $position + 1,
                            'block_type' => $block['type'], 'content' => $block['content'],
                        ]);
                    }
                }
            }
            $operation = CourseDeliveryCopyOperation::query()->create([
                'idempotency_key' => $idempotencyKey,
                'template_id' => $template->getKey(), 'template_version' => $version->version_number,
                'destination_workspace_id' => $destination->getKey(), 'actor_user_id' => $actor->getKey(),
                'selection' => $selection, 'result_map' => ['modules' => $moduleMap, 'lessons' => $lessonMap],
                'status' => 'completed',
            ]);
            $this->audit->record('learning.delivery.template.copied', $actor, $tenantId, $operation, [
                'template_id' => $template->getKey(), 'template_version' => $version->version_number,
                'destination_workspace_id' => $destination->getKey(), 'module_count' => count($moduleMap),
                'lesson_count' => count($lessonMap), 'learner_records_copied' => false,
            ]);

            return $operation;
        });
    }

    /**
     * @param  list<int>  $moduleIds
     * @param  array<int, list<int>>  $lessonIdsByModule
     */
    public function rollOverToAcademicYear(
        User $actor,
        CourseDeliveryTemplate $template,
        int $versionNumber,
        AcademicYear $academicYear,
        string $code,
        string $title,
        array $moduleIds,
        array $lessonIdsByModule,
        string $idempotencyKey,
    ): CourseDeliveryWorkspace {
        $this->assertTemplateManager();
        $template = CourseDeliveryTemplate::query()->where('status', 'published')->findOrFail($template->getKey());
        abort_unless((int) $template->institution_id === (int) $this->context->membership()->institution_id, 404);
        $version = CourseDeliveryTemplateVersion::query()->where('template_id', $template->getKey())
            ->where('version_number', $versionNumber)->whereNotNull('published_at')->firstOrFail();
        $snapshot = $version->content_snapshot;
        $snapshotJson = json_encode($this->canonicalize($snapshot), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        abort_unless(hash_equals($version->content_checksum, hash('sha256', $snapshotJson)), 409, 'The template version checksum is invalid.');
        $source = CourseDeliveryWorkspace::query()->where('institution_id', $template->institution_id)
            ->findOrFail((int) $snapshot['provenance']['source_workspace_id']);
        abort_unless((int) $academicYear->institution_id === (int) $source->institution_id, 404);
        abort_unless($source->academic_year_id === null || (int) $source->academic_year_id !== (int) $academicYear->getKey(), 422, 'Choose a different academic year for rollover.');
        $selection = $this->validatedSelection($snapshot, $moduleIds, $lessonIdsByModule);

        return DB::transaction(function () use ($actor, $template, $versionNumber, $academicYear, $code, $title, $selection, $idempotencyKey, $source): CourseDeliveryWorkspace {
            $existing = CourseDeliveryCopyOperation::query()->where('idempotency_key', $idempotencyKey)->first();
            if ($existing !== null) {
                $destination = CourseDeliveryWorkspace::query()->findOrFail($existing->destination_workspace_id);
                abort_unless((int) $existing->template_id === (int) $template->getKey()
                    && (int) $existing->template_version === $versionNumber
                    && (int) $destination->academic_year_id === (int) $academicYear->getKey()
                    && $destination->code === $code && $destination->title === $title, 409,
                    'This rollover request key has already been used for a different operation.');
                $this->copyToWorkspace($actor, $template, $versionNumber, $destination,
                    $selection['module_ids'], $selection['lesson_ids'], $idempotencyKey);

                return $destination;
            }
            $destination = $this->delivery->createWorkspace($actor, [
                'academic_course_id' => $source->academic_course_id,
                'academic_course_version_id' => $source->academic_course_version_id,
                'academic_program_id' => $source->academic_program_id,
                'academic_curriculum_version_id' => $source->academic_curriculum_version_id,
                'academic_year_id' => $academicYear->getKey(), 'campus_id' => $source->campus_id,
                'code' => $code, 'title' => $title, 'description' => $source->description,
                'delivery_mode' => $source->delivery_mode,
            ]);
            $this->copyToWorkspace($actor, $template, $versionNumber, $destination,
                $selection['module_ids'], $selection['lesson_ids'], $idempotencyKey);
            $this->audit->record('learning.delivery.academic_session.rolled_over', $actor, (int) $destination->tenant_id, $destination, [
                'source_workspace_id' => $source->getKey(), 'destination_workspace_id' => $destination->getKey(),
                'academic_year_id' => $academicYear->getKey(), 'template_id' => $template->getKey(),
                'template_version' => $versionNumber, 'learner_records_copied' => false,
            ]);

            return $destination;
        });
    }

    /** @param TemplateSnapshot $snapshot */
    private function createVersion(User $actor, CourseDeliveryTemplate $template, CourseDeliveryWorkspace $workspace, array $snapshot, int $version): CourseDeliveryTemplateVersion
    {
        $json = json_encode($this->canonicalize($snapshot), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

        return CourseDeliveryTemplateVersion::query()->create([
            'template_id' => $template->getKey(), 'version_number' => $version,
            'source_workspace_id' => $workspace->getKey(), 'content_snapshot' => $snapshot,
            'content_checksum' => hash('sha256', $json), 'authored_by' => $actor->getKey(),
            'published_by' => $actor->getKey(), 'published_at' => now(),
        ]);
    }

    /** @return TemplateSnapshot */
    private function snapshot(CourseDeliveryWorkspace $workspace): array
    {
        $modules = $workspace->modules->map(fn (CourseDeliveryModule $module): array => [
            'source_id' => (int) $module->getKey(), 'title' => $module->title, 'summary' => $module->summary,
            'lessons' => $module->lessons->map(fn (CourseDeliveryLesson $lesson): array => [
                'source_id' => (int) $lesson->getKey(), 'title' => $lesson->title, 'summary' => $lesson->summary,
                'learning_objectives' => $lesson->learning_objectives, 'estimated_minutes' => $lesson->estimated_minutes,
                'blocks' => $lesson->blocks->map(fn (CourseDeliveryBlock $block): array => [
                    'type' => $block->block_type,
                    'content' => $block->block_type === 'media_asset'
                        ? ['asset_id' => (int) $block->getKey(), ...array_intersect_key($block->content, array_flip(['media_type', 'mime_type', 'original_name', 'size_bytes', 'sha256', 'alt_text', 'license_label', 'attribution', 'transcript', 'scan_status']))]
                        : $block->content,
                ])->values()->all(),
            ])->values()->all(),
        ])->values()->all();

        return [
            'schema_version' => 1,
            'provenance' => [
                'source_workspace_id' => (int) $workspace->getKey(), 'source_institution_id' => (int) $workspace->institution_id,
                'course_version_id' => (int) $workspace->academic_course_version_id,
                'curriculum_version_id' => $workspace->academic_curriculum_version_id === null ? null : (int) $workspace->academic_curriculum_version_id,
                'captured_at' => now()->toIso8601String(),
            ],
            'modules' => $modules,
            'lesson_count' => array_sum(array_map(static fn (array $module): int => count($module['lessons']), $modules)),
        ];
    }

    /** @param TemplateSnapshot $snapshot
     * @param  list<int>  $moduleIds
     * @param  array<int,list<int>>  $lessonIdsByModule
     * @return array{module_ids:list<int>,lesson_ids:array<int,list<int>>}
     */
    private function validatedSelection(array $snapshot, array $moduleIds, array $lessonIdsByModule): array
    {
        $knownModules = collect($snapshot['modules'])->keyBy('source_id');
        $selected = array_values(array_unique(array_map('intval', $moduleIds)));
        sort($selected);
        if ($selected === [] || array_diff($selected, $knownModules->keys()->map(static fn ($id): int => (int) $id)->all()) !== []) {
            throw ValidationException::withMessages(['module_ids' => 'Select one or more modules from the chosen template version.']);
        }
        $lessons = [];
        foreach ($lessonIdsByModule as $moduleId => $ids) {
            $moduleId = (int) $moduleId;
            if (! in_array($moduleId, $selected, true)) {
                throw ValidationException::withMessages(['lesson_ids' => 'Lesson selections must belong to a selected module.']);
            }
            $available = array_map('intval', array_column($knownModules->get($moduleId)['lessons'], 'source_id'));
            $selectedLessons = array_values(array_unique(array_map('intval', $ids)));
            if (array_diff($selectedLessons, $available) !== []) {
                throw ValidationException::withMessages(['lesson_ids' => 'Select lessons from the selected module only.']);
            }
            sort($selectedLessons);
            $lessons[$moduleId] = $selectedLessons;
        }
        ksort($lessons);

        return ['module_ids' => $selected, 'lesson_ids' => $lessons];
    }

    /** @param array{module_ids:list<int>,lesson_ids:array<int,list<int>>} $selection
     * @return array{module_ids:list<int>,lesson_ids:array<int,list<int>>}
     */
    private function normalizeSelection(array $selection): array
    {
        $moduleIds = array_values(array_unique(array_map('intval', $selection['module_ids'])));
        sort($moduleIds);
        $lessonIds = [];
        foreach ($selection['lesson_ids'] as $moduleId => $ids) {
            $normalizedIds = array_values(array_unique(array_map('intval', $ids)));
            sort($normalizedIds);
            $lessonIds[(int) $moduleId] = $normalizedIds;
        }
        ksort($lessonIds);

        return ['module_ids' => $moduleIds, 'lesson_ids' => $lessonIds];
    }

    private function assertCanAdopt(CourseDeliveryTemplate $template, CourseDeliveryWorkspace $destination): void
    {
        $institutionId = (int) $destination->institution_id;
        $isOwner = (int) $template->institution_id === $institutionId;
        $isShared = CourseDeliveryTemplateShare::query()->where('template_id', $template->getKey())->where('institution_id', $institutionId)->exists();
        if ((int) $template->tenant_id !== (int) $destination->tenant_id || (! $isOwner && ! $isShared)) {
            throw new NotFoundHttpException;
        }
    }

    private function assertTemplateInstitution(CourseDeliveryWorkspace $workspace): void
    {
        abort_unless((int) $workspace->tenant_id === (int) $this->context->tenant()->getKey(), 404);
        abort_unless((int) $workspace->institution_id === (int) $this->context->membership()->institution_id, 404);
    }

    private function assertTemplateManager(): void
    {
        abort_unless($this->context->membership()->hasPermission('learning.delivery.manage'), 403);
    }

    /** @param array<string|int, mixed> $value
     * @return array<string|int, mixed>
     */
    private function canonicalize(array $value): array
    {
        foreach ($value as $key => $item) {
            if (is_array($item)) {
                $value[$key] = $this->canonicalize($item);
            }
        }
        if (! array_is_list($value)) {
            ksort($value);
        }

        return $value;
    }
}
