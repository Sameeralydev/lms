<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $quiz_id
 * @property int $quiz_version_id
 * @property int $student_id
 * @property int $membership_id
 * @property int $attempt_number
 * @property string $status
 * @property Carbon $started_at
 * @property Carbon|null $expires_at
 * @property Carbon|null $submitted_at
 * @property Carbon|null $graded_at
 * @property Carbon|null $result_released_at
 * @property list<array<string, mixed>> $question_snapshot
 * @property numeric-string|null $score_points
 * @property numeric-string|null $maximum_points
 * @property numeric-string|null $percentage
 * @property bool $manual_review_pending
 */
class QuizAttempt extends AcademicRecord
{
    protected function casts(): array
    {
        return ['started_at' => 'immutable_datetime', 'expires_at' => 'immutable_datetime', 'submitted_at' => 'immutable_datetime', 'graded_at' => 'immutable_datetime', 'result_released_at' => 'immutable_datetime', 'question_snapshot' => 'array', 'score_points' => 'decimal:2', 'maximum_points' => 'decimal:2', 'percentage' => 'decimal:2', 'manual_review_pending' => 'boolean'];
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

    /** @return BelongsTo<SisStudent, $this> */
    public function student(): BelongsTo
    {
        return $this->belongsTo(SisStudent::class, 'student_id');
    }

    /** @return BelongsTo<TenantMembership, $this> */
    public function membership(): BelongsTo
    {
        return $this->belongsTo(TenantMembership::class, 'membership_id');
    }

    /** @return HasMany<QuizAttemptResponse, $this> */
    public function responses(): HasMany
    {
        return $this->hasMany(QuizAttemptResponse::class, 'attempt_id');
    }

    /** @return HasMany<QuizRegradeRequest, $this> */
    public function regradeRequests(): HasMany
    {
        return $this->hasMany(QuizRegradeRequest::class, 'attempt_id')->orderByDesc('created_at');
    }
}
