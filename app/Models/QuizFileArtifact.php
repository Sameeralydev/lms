<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class QuizFileArtifact extends AcademicRecord
{
    protected function casts(): array
    {
        return ['size_bytes' => 'integer', 'scanned_at' => 'immutable_datetime'];
    }

    /** @return BelongsTo<QuizQuestionVersion, $this> */
    public function questionVersion(): BelongsTo
    {
        return $this->belongsTo(QuizQuestionVersion::class, 'question_version_id');
    }

    /** @return BelongsTo<QuizAttempt, $this> */
    public function attempt(): BelongsTo
    {
        return $this->belongsTo(QuizAttempt::class, 'attempt_id');
    }
}
