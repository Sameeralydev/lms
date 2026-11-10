<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AcademicGradeCategory extends AcademicRecord
{
    protected function casts(): array
    {
        return ['weight_basis_points' => 'integer', 'required' => 'boolean'];
    }

    /** @return BelongsTo<AcademicGradePolicy, $this> */
    public function policy(): BelongsTo
    {
        return $this->belongsTo(AcademicGradePolicy::class, 'policy_id');
    }
}
