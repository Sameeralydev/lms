<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $session_id
 * @property int $candidate_id
 * @property int $attempt_id
 * @property Carbon $started_at
 * @property Carbon $expires_at
 * @property Carbon|null $submitted_at
 * @property string|null $receipt_reference
 */
class ExamAttemptBinding extends AcademicRecord
{
    protected function casts(): array
    {
        return ['started_at' => 'immutable_datetime', 'expires_at' => 'immutable_datetime', 'submitted_at' => 'immutable_datetime'];
    }

    /** @return BelongsTo<ExamSession, $this> */
    public function session(): BelongsTo
    {
        return $this->belongsTo(ExamSession::class, 'session_id');
    }

    /** @return BelongsTo<ExamCandidate, $this> */
    public function candidate(): BelongsTo
    {
        return $this->belongsTo(ExamCandidate::class, 'candidate_id');
    }

    /** @return BelongsTo<QuizAttempt, $this> */
    public function attempt(): BelongsTo
    {
        return $this->belongsTo(QuizAttempt::class, 'attempt_id');
    }
}
