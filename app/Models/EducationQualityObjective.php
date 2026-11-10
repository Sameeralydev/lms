<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class EducationQualityObjective extends AcademicRecord
{
    protected function casts(): array
    {
        return ['target_value' => 'decimal:4', 'next_review_on' => 'date'];
    }

    /** @return HasMany<EducationQualityReview, $this> */
    public function reviews(): HasMany
    {
        return $this->hasMany(EducationQualityReview::class, 'quality_objective_id')->orderByDesc('review_number');
    }

    /** @return BelongsTo<AcademicUnit, $this> */
    public function academicUnit(): BelongsTo
    {
        return $this->belongsTo(AcademicUnit::class, 'academic_unit_id');
    }

    /** @return HasMany<EducationQualityEvidence, $this> */
    public function evidence(): HasMany
    {
        return $this->hasMany(EducationQualityEvidence::class, 'quality_objective_id');
    }

    /** @return HasMany<EducationCorrectiveAction, $this> */
    public function correctiveActions(): HasMany
    {
        return $this->hasMany(EducationCorrectiveAction::class, 'quality_objective_id');
    }
}
