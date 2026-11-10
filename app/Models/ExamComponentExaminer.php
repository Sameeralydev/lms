<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ExamComponentExaminer extends AcademicRecord
{
    protected function casts(): array
    {
        return ['assigned_at' => 'immutable_datetime', 'revoked_at' => 'immutable_datetime'];
    }

    /** @return BelongsTo<ExamComponent, $this> */
    public function component(): BelongsTo
    {
        return $this->belongsTo(ExamComponent::class, 'component_id');
    }
}
