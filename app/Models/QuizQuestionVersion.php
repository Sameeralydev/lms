<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Carbon;

/**
 * @property int $question_id
 * @property int $version_number
 * @property string $question_type
 * @property array<string, mixed> $content
 * @property array<string, mixed>|null $answer_key
 * @property array<string, mixed> $scoring_rules
 * @property array<string, mixed> $hints
 * @property string|null $topic
 * @property string|null $subtopic
 * @property string $difficulty
 * @property int $estimated_seconds
 * @property numeric-string $default_points
 * @property string $status
 * @property int $created_by
 * @property Carbon|null $reviewed_at
 * @property Carbon|null $activated_at
 * @property-read QuizVersionQuestion $pivot
 */
class QuizQuestionVersion extends AcademicRecord
{
    protected $hidden = ['answer_key'];

    protected function casts(): array
    {
        return ['content' => 'array', 'answer_key' => 'encrypted:array', 'scoring_rules' => 'array', 'hints' => 'array', 'reviewed_at' => 'immutable_datetime', 'activated_at' => 'immutable_datetime', 'default_points' => 'decimal:2'];
    }

    /** @return BelongsTo<QuizQuestion, $this> */
    public function question(): BelongsTo
    {
        return $this->belongsTo(QuizQuestion::class, 'question_id');
    }

    /** @return BelongsToMany<AcademicLearningOutcome, $this> */
    public function outcomes(): BelongsToMany
    {
        return $this->belongsToMany(AcademicLearningOutcome::class, 'quiz_question_outcomes', 'question_version_id', 'learning_outcome_id')->withPivot('tenant_id')->withTimestamps();
    }
}
