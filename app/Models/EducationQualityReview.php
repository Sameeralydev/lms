<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class EducationQualityReview extends AcademicRecord
{
    protected function casts(): array
    {
        return ['due_on' => 'date', 'measured_value' => 'decimal:4', 'reviewed_at' => 'datetime', 'approved_at' => 'datetime'];
    }

    /** @return BelongsTo<EducationQualityObjective, $this> */
    public function objective(): BelongsTo
    {
        return $this->belongsTo(EducationQualityObjective::class, 'quality_objective_id');
    }

    /** @return HasMany<EducationQualityEvidence, $this> */
    public function evidence(): HasMany
    {
        return $this->hasMany(EducationQualityEvidence::class, 'quality_review_id');
    }
}
