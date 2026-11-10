<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EducationCorrectiveAction extends AcademicRecord
{
    protected function casts(): array
    {
        return ['due_on' => 'date', 'closed_at' => 'datetime'];
    }

    /** @return BelongsTo<EducationQualityObjective, $this> */
    public function objective(): BelongsTo
    {
        return $this->belongsTo(EducationQualityObjective::class, 'quality_objective_id');
    }

    /** @return BelongsTo<EducationQualityReview, $this> */
    public function review(): BelongsTo
    {
        return $this->belongsTo(EducationQualityReview::class, 'quality_review_id');
    }
}
