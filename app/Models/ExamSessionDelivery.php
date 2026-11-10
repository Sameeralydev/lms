<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ExamSessionDelivery extends AcademicRecord
{
    protected function casts(): array
    {
        return ['configured_at' => 'immutable_datetime'];
    }

    /** @return BelongsTo<ExamSession, $this> */
    public function session(): BelongsTo
    {
        return $this->belongsTo(ExamSession::class, 'session_id');
    }

    /** @return BelongsTo<Quiz, $this> */
    public function quiz(): BelongsTo
    {
        return $this->belongsTo(Quiz::class);
    }

    /** @return BelongsTo<QuizVersion, $this> */
    public function quizVersion(): BelongsTo
    {
        return $this->belongsTo(QuizVersion::class, 'quiz_version_id');
    }

    /** @return BelongsTo<ExamPaperVersion, $this> */
    public function paperVersion(): BelongsTo
    {
        return $this->belongsTo(ExamPaperVersion::class, 'paper_version_id');
    }
}
