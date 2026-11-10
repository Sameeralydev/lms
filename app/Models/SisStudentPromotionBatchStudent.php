<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $promotion_batch_id
 * @property int $student_id
 * @property string $outcome_status
 * @property Carbon|null $decided_at
 * @property-read SisStudent|null $student
 */
class SisStudentPromotionBatchStudent extends AcademicRecord
{
    protected function casts(): array
    {
        return ['decided_at' => 'datetime'];
    }

    /** @return BelongsTo<SisStudentPromotionBatch, $this> */
    public function batch(): BelongsTo
    {
        return $this->belongsTo(SisStudentPromotionBatch::class, 'promotion_batch_id');
    }

    /** @return BelongsTo<SisStudent, $this> */
    public function student(): BelongsTo
    {
        return $this->belongsTo(SisStudent::class);
    }
}
