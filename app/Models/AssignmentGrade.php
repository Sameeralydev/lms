<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $tenant_id
 * @property int $attempt_id
 * @property numeric-string|null $score_points
 * @property numeric-string|null $maximum_points
 * @property string $status
 * @property string|null $learner_feedback
 * @property string|null $private_notes
 * @property array<string, string|int|float>|null $criterion_scores
 * @property CarbonImmutable|null $released_at
 * @property-read AssignmentAttempt $attempt
 */
class AssignmentGrade extends AcademicRecord
{
    protected function casts(): array
    {
        return ['score_points' => 'decimal:2', 'maximum_points' => 'decimal:2', 'criterion_scores' => 'array', 'released_at' => 'immutable_datetime'];
    }

    /** @return BelongsTo<AssignmentAttempt, $this> */
    public function attempt(): BelongsTo
    {
        return $this->belongsTo(AssignmentAttempt::class, 'attempt_id');
    }
}
