<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use UnexpectedValueException;

class ExamPaperVersion extends AcademicRecord
{
    protected function casts(): array
    {
        return [
            'blueprint_snapshot' => 'encrypted:array',
            'question_snapshot' => 'encrypted:array',
            'instructions' => 'encrypted',
            'total_marks' => 'decimal:2',
            'submitted_at' => 'immutable_datetime',
            'approved_at' => 'immutable_datetime',
            'published_at' => 'immutable_datetime',
        ];
    }

    /** @return array<string, mixed> */
    public function decryptedBlueprint(): array
    {
        $blueprint = $this->getAttribute('blueprint_snapshot');
        if (! is_array($blueprint)) {
            throw new UnexpectedValueException('The encrypted examination paper blueprint is invalid.');
        }

        return $blueprint;
    }

    /** @return list<array<string, mixed>> */
    public function decryptedQuestions(): array
    {
        $questions = $this->getAttribute('question_snapshot');
        if (! is_array($questions) || ! array_is_list($questions)) {
            throw new UnexpectedValueException('The encrypted examination paper question snapshot is invalid.');
        }

        return $questions;
    }

    /** @return BelongsTo<ExamPaper, $this> */
    public function paper(): BelongsTo
    {
        return $this->belongsTo(ExamPaper::class, 'paper_id');
    }

    /** @return HasMany<ExamPaperReview, $this> */
    public function reviews(): HasMany
    {
        return $this->hasMany(ExamPaperReview::class, 'paper_version_id')->orderByDesc('decided_at');
    }
}
