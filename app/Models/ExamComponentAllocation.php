<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** @property int|null $component_id @property int $candidate_id @property int $examiner_id @property int $marking_round @property string $status */
class ExamComponentAllocation extends AcademicRecord
{
    protected function casts(): array
    {
        return ['assigned_at' => 'immutable_datetime', 'submitted_at' => 'immutable_datetime'];
    }

    /** @return BelongsTo<ExamComponent, $this> */
    public function component(): BelongsTo
    {
        return $this->belongsTo(ExamComponent::class, 'component_id');
    }

    /** @return HasMany<ExamComponentMarking, $this> */
    public function markings(): HasMany
    {
        return $this->hasMany(ExamComponentMarking::class, 'allocation_id')->orderByDesc('revision');
    }
}
