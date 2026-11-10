<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $tenant_id
 * @property int $attempt_id
 * @property int $reported_by
 * @property string $status
 * @property string $case_type
 * @property string $concern_summary
 * @property string|null $learner_response
 * @property string|null $disposition
 * @property CarbonImmutable|null $response_due_at
 * @property CarbonImmutable|null $resolved_at
 */
class AssignmentIntegrityCase extends AcademicRecord
{
    protected function casts(): array
    {
        return ['response_due_at' => 'immutable_datetime', 'resolved_at' => 'immutable_datetime'];
    }

    /** @return BelongsTo<AssignmentAttempt, $this> */
    public function attempt(): BelongsTo
    {
        return $this->belongsTo(AssignmentAttempt::class, 'attempt_id');
    }
}
