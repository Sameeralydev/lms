<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** @property array<string, mixed> $lesson_snapshot */
class CourseDeliveryLessonRevision extends AcademicRecord
{
    protected function casts(): array
    {
        return ['lesson_snapshot' => 'array', 'published_at' => 'datetime'];
    }

    /** @return BelongsTo<CourseDeliveryLesson, $this> */
    public function lesson(): BelongsTo
    {
        return $this->belongsTo(CourseDeliveryLesson::class, 'lesson_id');
    }
}
