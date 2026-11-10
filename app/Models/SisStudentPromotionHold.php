<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/** @property int $institution_id @property int $student_id @property string $status @property string $reason_code @property string $reason @property Carbon|null $released_at */
class SisStudentPromotionHold extends AcademicRecord
{
    protected function casts(): array
    {
        return ['released_at' => 'datetime'];
    }

    /** @return BelongsTo<SisStudent, $this> */
    public function student(): BelongsTo
    {
        return $this->belongsTo(SisStudent::class);
    }
}
