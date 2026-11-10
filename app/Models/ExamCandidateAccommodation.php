<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ExamCandidateAccommodation extends AcademicRecord
{
    protected function casts(): array
    {
        return ['confidential_details' => 'encrypted', 'authorized_at' => 'immutable_datetime', 'expires_at' => 'immutable_datetime'];
    }

    /** @return BelongsTo<ExamCandidate, $this> */
    public function candidate(): BelongsTo
    {
        return $this->belongsTo(ExamCandidate::class, 'candidate_id');
    }
}
