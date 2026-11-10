<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ExamPaperReview extends AcademicRecord
{
    protected function casts(): array
    {
        return ['notes' => 'encrypted', 'decided_at' => 'immutable_datetime'];
    }

    /** @return BelongsTo<ExamPaperVersion, $this> */
    public function paperVersion(): BelongsTo
    {
        return $this->belongsTo(ExamPaperVersion::class, 'paper_version_id');
    }
}
