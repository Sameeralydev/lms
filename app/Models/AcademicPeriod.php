<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AcademicPeriod extends AcademicRecord
{
    protected function casts(): array
    {
        return [
            'starts_on' => 'date', 'ends_on' => 'date',
            'teaching_starts_on' => 'date', 'teaching_ends_on' => 'date',
            'assessment_starts_on' => 'date', 'assessment_ends_on' => 'date',
            'is_current' => 'boolean',
        ];
    }

    /** @return BelongsTo<AcademicYear, $this> */
    public function academicYear(): BelongsTo
    {
        return $this->belongsTo(AcademicYear::class);
    }
}
