<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ExamCycle extends AcademicRecord
{
    protected function casts(): array
    {
        return ['registration_opens_on' => 'date', 'registration_closes_on' => 'date', 'starts_on' => 'date', 'ends_on' => 'date', 'published_at' => 'immutable_datetime', 'closed_at' => 'immutable_datetime'];
    }

    /** @return BelongsTo<AcademicPeriod, $this> */
    public function period(): BelongsTo
    {
        return $this->belongsTo(AcademicPeriod::class, 'academic_period_id');
    }

    /** @return HasMany<ExamSession, $this> */
    public function sessions(): HasMany
    {
        return $this->hasMany(ExamSession::class, 'cycle_id');
    }

    /** @return HasMany<ExamCandidate, $this> */
    public function candidates(): HasMany
    {
        return $this->hasMany(ExamCandidate::class, 'cycle_id');
    }

    /** @return BelongsTo<ExamCycle, $this> */
    public function sourceCycle(): BelongsTo
    {
        return $this->belongsTo(self::class, 'source_cycle_id');
    }
}
