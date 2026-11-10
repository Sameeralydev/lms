<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** @property string $component_type @property string $status @property numeric-string $maximum_marks */
class ExamComponent extends AcademicRecord
{
    protected function casts(): array
    {
        return ['starts_at_utc' => 'immutable_datetime', 'ends_at_utc' => 'immutable_datetime', 'maximum_marks' => 'decimal:2', 'moderation_difference_threshold' => 'decimal:2', 'second_marking_required' => 'boolean', 'blind_second_marking' => 'boolean'];
    }

    /** @return HasMany<ExamComponentRubric, $this> */
    public function rubric(): HasMany
    {
        return $this->hasMany(ExamComponentRubric::class, 'component_id')->orderBy('position');
    }

    /** @return HasMany<ExamComponentAllocation, $this> */
    public function allocations(): HasMany
    {
        return $this->hasMany(ExamComponentAllocation::class, 'component_id');
    }

    /** @return BelongsTo<ExamSession, $this> */
    public function session(): BelongsTo
    {
        return $this->belongsTo(ExamSession::class, 'session_id');
    }
}
