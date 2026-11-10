<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $bank_id
 * @property string $status
 * @property int $current_version
 * @property int $created_by
 */
class QuizQuestion extends AcademicRecord
{
    /** @return BelongsTo<QuizQuestionBank, $this> */
    public function bank(): BelongsTo
    {
        return $this->belongsTo(QuizQuestionBank::class, 'bank_id');
    }

    /** @return HasMany<QuizQuestionVersion, $this> */
    public function versions(): HasMany
    {
        return $this->hasMany(QuizQuestionVersion::class, 'question_id')->orderByDesc('version_number');
    }

    /** @return BelongsToMany<QuizQuestionTag, $this> */
    public function tags(): BelongsToMany
    {
        return $this->belongsToMany(QuizQuestionTag::class, 'quiz_question_tag_links', 'question_id', 'tag_id')->withPivot('tenant_id')->withTimestamps();
    }
}
