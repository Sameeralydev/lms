<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AcademicGpaStandingBand extends AcademicRecord
{
    protected function casts(): array
    {
        return ['minimum_gpa' => 'decimal:3', 'sort_order' => 'integer'];
    }

    /** @return BelongsTo<AcademicGpaPolicy, $this> */
    public function policy(): BelongsTo
    {
        return $this->belongsTo(AcademicGpaPolicy::class, 'policy_id');
    }
}
