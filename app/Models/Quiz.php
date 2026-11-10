<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $institution_id
 * @property int $offering_id
 * @property int $created_by
 * @property string $status
 * @property int $current_version
 * @property Carbon|null $reviewed_at
 * @property Carbon|null $published_at
 * @property Carbon|null $closed_at
 * @property Carbon|null $archived_at
 */
class Quiz extends AcademicRecord
{
    protected function casts(): array
    {
        return ['reviewed_at' => 'immutable_datetime', 'published_at' => 'immutable_datetime', 'closed_at' => 'immutable_datetime', 'archived_at' => 'immutable_datetime'];
    }

    /** @return BelongsTo<CourseOffering, $this> */
    public function offering(): BelongsTo
    {
        return $this->belongsTo(CourseOffering::class, 'offering_id');
    }

    /** @return BelongsToMany<CourseSection, $this> */
    public function sections(): BelongsToMany
    {
        return $this->belongsToMany(CourseSection::class, 'quiz_sections', 'quiz_id', 'section_id')->withPivot('tenant_id')->withTimestamps();
    }

    /** @return HasMany<QuizVersion, $this> */
    public function versions(): HasMany
    {
        return $this->hasMany(QuizVersion::class)->orderByDesc('version_number');
    }

    /** @return HasMany<QuizAttempt, $this> */
    public function attempts(): HasMany
    {
        return $this->hasMany(QuizAttempt::class);
    }
}
