<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AcademicGradeScaleBand extends AcademicRecord
{
    protected function casts(): array
    {
        return [
            'minimum_percentage_basis_points' => 'integer',
            'grade_points' => 'decimal:3',
            'passing' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    /** @return BelongsTo<AcademicGradePolicy, $this> */
    public function policy(): BelongsTo
    {
        return $this->belongsTo(AcademicGradePolicy::class, 'policy_id');
    }
}
