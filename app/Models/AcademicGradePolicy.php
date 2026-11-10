<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** @property CarbonImmutable|null $effective_from @property CarbonImmutable|null $effective_until */
class AcademicGradePolicy extends AcademicRecord
{
    protected function casts(): array
    {
        return ['effective_from' => 'immutable_date', 'effective_until' => 'immutable_date'];
    }

    /** @return HasMany<AcademicGradeCategory, $this> */
    public function categories(): HasMany
    {
        return $this->hasMany(AcademicGradeCategory::class, 'policy_id')->orderBy('id');
    }

    /** @return HasMany<AcademicGradeScaleBand, $this> */
    public function scaleBands(): HasMany
    {
        return $this->hasMany(AcademicGradeScaleBand::class, 'policy_id')->orderByDesc('minimum_percentage_basis_points');
    }

    /** @return BelongsTo<AcademicPeriod, $this> */
    public function period(): BelongsTo
    {
        return $this->belongsTo(AcademicPeriod::class, 'academic_period_id');
    }
}
