<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AssignmentRubricCriterion extends AcademicRecord
{
    protected function casts(): array
    {
        return ['maximum_points' => 'decimal:2', 'rating_levels' => 'array'];
    }

    /** @return BelongsTo<AssignmentRubricVersion, $this> */
    public function version(): BelongsTo
    {
        return $this->belongsTo(AssignmentRubricVersion::class, 'rubric_version_id');
    }
}
