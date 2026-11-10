<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** @property array<string, mixed> $content */
class CourseDeliveryBlock extends AcademicRecord
{
    protected function casts(): array
    {
        return ['content' => 'array'];
    }

    /** @return BelongsTo<CourseDeliveryLesson, $this> */
    public function lesson(): BelongsTo
    {
        return $this->belongsTo(CourseDeliveryLesson::class, 'lesson_id');
    }
}
