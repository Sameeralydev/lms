<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** @property string $title @property string|null $summary @property string|null $learning_objectives @property int|null $estimated_minutes @property int $workspace_id @property int $module_id */
class CourseDeliveryLesson extends AcademicRecord
{
    protected function casts(): array
    {
        return ['submitted_at' => 'datetime', 'reviewed_at' => 'datetime', 'published_at' => 'datetime', 'lock_version' => 'integer'];
    }

    /** @return BelongsTo<CourseDeliveryWorkspace, $this> */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(CourseDeliveryWorkspace::class, 'workspace_id');
    }

    /** @return BelongsTo<CourseDeliveryModule, $this> */
    public function module(): BelongsTo
    {
        return $this->belongsTo(CourseDeliveryModule::class, 'module_id');
    }

    /** @return HasMany<CourseDeliveryBlock, $this> */
    public function blocks(): HasMany
    {
        return $this->hasMany(CourseDeliveryBlock::class, 'lesson_id')
            ->where('block_type', '!=', 'archived_activity')
            ->orderBy('sort_order');
    }

    /** @return HasMany<CourseDeliveryLessonRevision, $this> */
    public function revisions(): HasMany
    {
        return $this->hasMany(CourseDeliveryLessonRevision::class, 'lesson_id')->orderByDesc('version_number');
    }
}
