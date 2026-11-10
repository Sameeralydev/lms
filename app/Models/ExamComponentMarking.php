<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** @property array<string, string> $rubric_marks @property numeric-string $total_marks @property numeric-string $maximum_marks @property int $revision */
class ExamComponentMarking extends AcademicRecord
{
    protected function casts(): array
    {
        return ['rubric_marks' => 'array', 'total_marks' => 'decimal:2', 'maximum_marks' => 'decimal:2', 'submitted_at' => 'immutable_datetime', 'private_notes' => 'encrypted'];
    }

    /** @return BelongsTo<ExamComponentAllocation, $this> */
    public function allocation(): BelongsTo
    {
        return $this->belongsTo(ExamComponentAllocation::class, 'allocation_id');
    }
}
