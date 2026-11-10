<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ExamInvigilatorAssignment extends AcademicRecord
{
    protected function casts(): array
    {
        return ['accepted_at' => 'immutable_datetime'];
    }

    /** @return BelongsTo<ExamSession, $this> */
    public function session(): BelongsTo
    {
        return $this->belongsTo(ExamSession::class, 'session_id');
    }
}
