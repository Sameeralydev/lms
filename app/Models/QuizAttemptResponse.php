<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $attempt_id
 * @property int $question_version_id
 * @property array<string, mixed> $response_payload
 * @property string $grading_status
 * @property numeric-string|null $points_awarded
 * @property string|null $feedback
 * @property bool $marked_for_review
 * @property int $version
 * @property Carbon|null $saved_at
 * @property Carbon|null $graded_at
 */
class QuizAttemptResponse extends AcademicRecord
{
    protected function casts(): array
    {
        return ['response_payload' => 'encrypted:array', 'saved_at' => 'immutable_datetime', 'graded_at' => 'immutable_datetime', 'points_awarded' => 'decimal:2', 'marked_for_review' => 'boolean'];
    }

    /** @return BelongsTo<QuizAttempt, $this> */
    public function attempt(): BelongsTo
    {
        return $this->belongsTo(QuizAttempt::class, 'attempt_id');
    }

    /** @return BelongsTo<QuizQuestionVersion, $this> */
    public function questionVersion(): BelongsTo
    {
        return $this->belongsTo(QuizQuestionVersion::class, 'question_version_id');
    }

    /** @return HasMany<QuizGradingReview, $this> */
    public function gradingReviews(): HasMany
    {
        return $this->hasMany(QuizGradingReview::class, 'response_id')->orderBy('revision_number');
    }
}
