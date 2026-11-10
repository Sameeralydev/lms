<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class QuizResponseRevision extends AcademicRecord
{
    public $timestamps = false;

    protected function casts(): array
    {
        return ['response_payload' => 'encrypted:array', 'created_at' => 'immutable_datetime'];
    }

    /** @return BelongsTo<QuizAttemptResponse, $this> */
    public function response(): BelongsTo
    {
        return $this->belongsTo(QuizAttemptResponse::class, 'response_id');
    }
}
