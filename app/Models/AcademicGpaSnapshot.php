<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AcademicGpaSnapshot extends AcademicRecord
{
    protected function casts(): array
    {
        return ['gpa' => 'decimal:3', 'quality_points' => 'decimal:3', 'attempted_credits' => 'decimal:3', 'snapshot_number' => 'integer', 'calculated_at' => 'immutable_datetime'];
    }

    /** @return BelongsTo<SisStudent, $this> */
    public function student(): BelongsTo
    {
        return $this->belongsTo(SisStudent::class, 'student_id');
    }

    /** @return BelongsTo<AcademicGpaPolicy, $this> */
    public function policy(): BelongsTo
    {
        return $this->belongsTo(AcademicGpaPolicy::class, 'policy_id');
    }
}
