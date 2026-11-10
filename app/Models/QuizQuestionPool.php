<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class QuizQuestionPool extends AcademicRecord
{
    protected function casts(): array
    {
        return ['required' => 'boolean', 'points_per_question' => 'decimal:2'];
    }

    /** @return BelongsTo<QuizVersion, $this> */
    public function quizVersion(): BelongsTo
    {
        return $this->belongsTo(QuizVersion::class, 'quiz_version_id');
    }

    /** @return BelongsToMany<QuizQuestionVersion, $this> */
    public function questionVersions(): BelongsToMany
    {
        return $this->belongsToMany(QuizQuestionVersion::class, 'quiz_pool_question_versions', 'pool_id', 'question_version_id')->withPivot('tenant_id')->withTimestamps();
    }
}
