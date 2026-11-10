<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $quiz_id
 * @property int $version_number
 * @property string $title
 * @property string $category
 * @property string $mode
 * @property string $instructions
 * @property array<string, mixed> $settings
 * @property numeric-string $total_points
 * @property string $status
 * @property int $created_by
 * @property Carbon|null $published_at
 */
class QuizVersion extends AcademicRecord
{
    protected function casts(): array
    {
        return ['settings' => 'array', 'published_at' => 'immutable_datetime', 'total_points' => 'decimal:2'];
    }

    /** @return BelongsTo<Quiz, $this> */
    public function quiz(): BelongsTo
    {
        return $this->belongsTo(Quiz::class);
    }

    /** @return BelongsToMany<QuizQuestionVersion, $this, QuizVersionQuestion> */
    public function questionVersions(): BelongsToMany
    {
        return $this->belongsToMany(QuizQuestionVersion::class, 'quiz_version_questions', 'quiz_version_id', 'question_version_id')->using(QuizVersionQuestion::class)->withPivot(['tenant_id', 'position', 'points', 'group_key'])->withTimestamps()->orderByPivot('position');
    }

    /** @return HasMany<QuizQuestionPool, $this> */
    public function pools(): HasMany
    {
        return $this->hasMany(QuizQuestionPool::class, 'quiz_version_id');
    }
}
