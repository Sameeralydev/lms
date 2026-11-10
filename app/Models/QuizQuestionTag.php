<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class QuizQuestionTag extends AcademicRecord
{
    /** @return BelongsToMany<QuizQuestion, $this> */
    public function questions(): BelongsToMany
    {
        return $this->belongsToMany(QuizQuestion::class, 'quiz_question_tag_links', 'tag_id', 'question_id')->withPivot('tenant_id')->withTimestamps();
    }
}
