<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AssignmentRubricVersion extends AcademicRecord
{
    protected function casts(): array
    {
        return ['maximum_points' => 'decimal:2', 'published_at' => 'immutable_datetime'];
    }

    /** @return BelongsTo<AssignmentRubric, $this> */
    public function rubric(): BelongsTo
    {
        return $this->belongsTo(AssignmentRubric::class, 'rubric_id');
    }

    /** @return HasMany<AssignmentRubricCriterion, $this> */
    public function criteria(): HasMany
    {
        return $this->hasMany(AssignmentRubricCriterion::class, 'rubric_version_id')->orderBy('position');
    }
}
