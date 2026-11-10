<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property int $tenant_id
 * @property int $assignment_id
 * @property int $recipient_id
 * @property int|null $group_id
 * @property int $attempt_count
 * @property int $lock_version
 * @property array<int, int>|null $group_membership_snapshot
 * @property string $status
 * @property CarbonImmutable|null $submitted_at
 * @property-read AssignmentRecipient|null $recipient
 * @property-read Collection<int, AssignmentAttempt> $attempts
 */
class AssignmentSubmission extends AcademicRecord
{
    protected function casts(): array
    {
        return ['submitted_at' => 'immutable_datetime', 'finalized_at' => 'immutable_datetime', 'group_membership_snapshot' => 'array'];
    }

    /** @return BelongsTo<Assignment, $this> */
    public function assignment(): BelongsTo
    {
        return $this->belongsTo(Assignment::class);
    }

    /** @return BelongsTo<AssignmentRecipient, $this> */
    public function recipient(): BelongsTo
    {
        return $this->belongsTo(AssignmentRecipient::class);
    }

    /** @return BelongsTo<AssignmentGroup, $this> */
    public function group(): BelongsTo
    {
        return $this->belongsTo(AssignmentGroup::class, 'group_id');
    }

    /** @return HasMany<AssignmentAttempt, $this> */
    public function attempts(): HasMany
    {
        return $this->hasMany(AssignmentAttempt::class, 'submission_id')->orderByDesc('attempt_number');
    }
}
