<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ExamPaper extends AcademicRecord
{
    protected function casts(): array
    {
        return ['published_at' => 'immutable_datetime'];
    }

    /** @return HasMany<ExamPaperVersion, $this> */
    public function versions(): HasMany
    {
        return $this->hasMany(ExamPaperVersion::class, 'paper_id')->orderByDesc('version_number');
    }

    /** @return BelongsTo<ExamSession, $this> */
    public function session(): BelongsTo
    {
        return $this->belongsTo(ExamSession::class, 'session_id');
    }
}
