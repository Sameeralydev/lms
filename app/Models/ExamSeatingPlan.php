<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ExamSeatingPlan extends AcademicRecord
{
    /** @return BelongsTo<ExamSession, $this> */
    public function session(): BelongsTo
    {
        return $this->belongsTo(ExamSession::class, 'session_id');
    }

    /** @return HasMany<ExamSeatingPlanRevision, $this> */
    public function revisions(): HasMany
    {
        return $this->hasMany(ExamSeatingPlanRevision::class, 'plan_id')->orderByDesc('revision');
    }
}
