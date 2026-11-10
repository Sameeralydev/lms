<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ExamDateSheet extends AcademicRecord
{
    /** @return BelongsTo<ExamCycle, $this> */
    public function cycle(): BelongsTo
    {
        return $this->belongsTo(ExamCycle::class, 'cycle_id');
    }

    /** @return HasMany<ExamDateSheetRevision, $this> */
    public function revisions(): HasMany
    {
        return $this->hasMany(ExamDateSheetRevision::class, 'date_sheet_id')->orderByDesc('revision');
    }
}
