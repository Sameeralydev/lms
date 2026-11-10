<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $institution_id
 * @property int $requested_by
 * @property string $status
 * @property Carbon $effective_from
 * @property Carbon $requested_at
 * @property-read Collection<int, SisStudentPromotionBatchStudent> $outcomes
 * @property-read User|null $requester
 */
class SisStudentPromotionBatch extends AcademicRecord
{
    protected function casts(): array
    {
        return ['effective_from' => 'date', 'requested_at' => 'datetime', 'reviewed_at' => 'datetime'];
    }

    /** @return HasMany<SisStudentPromotionBatchStudent, $this> */
    public function outcomes(): HasMany
    {
        return $this->hasMany(SisStudentPromotionBatchStudent::class, 'promotion_batch_id');
    }

    /** @return BelongsTo<AcademicClass, $this> */
    public function destinationClass(): BelongsTo
    {
        return $this->belongsTo(AcademicClass::class, 'academic_class_id');
    }

    /** @return BelongsTo<User, $this> */
    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    /** @return BelongsTo<User, $this> */
    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }
}
