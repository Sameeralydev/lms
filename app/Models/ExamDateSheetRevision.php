<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ExamDateSheetRevision extends AcademicRecord
{
    protected function casts(): array
    {
        return ['schedule_snapshot' => 'array', 'submitted_at' => 'immutable_datetime', 'reviewed_at' => 'immutable_datetime', 'published_at' => 'immutable_datetime'];
    }

    /** @return BelongsTo<ExamDateSheet, $this> */
    public function dateSheet(): BelongsTo
    {
        return $this->belongsTo(ExamDateSheet::class, 'date_sheet_id');
    }
}
