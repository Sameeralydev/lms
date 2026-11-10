<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class QuizGradingReview extends AcademicRecord
{
    protected $hidden = ['private_notes'];

    protected function casts(): array
    {
        return ['points_awarded' => 'decimal:2', 'private_notes' => 'encrypted'];
    }

    /** @return BelongsTo<QuizAttemptResponse, $this> */
    public function response(): BelongsTo
    {
        return $this->belongsTo(QuizAttemptResponse::class, 'response_id');
    }
}
