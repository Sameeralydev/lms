<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $tenant_id
 * @property int $recipient_id
 * @property int $requested_by
 * @property string $status
 * @property CarbonImmutable $requested_due_at
 * @property CarbonImmutable|null $previous_due_at
 * @property CarbonImmutable|null $effective_due_at
 * @property CarbonImmutable|null $reviewed_at
 * @property CarbonImmutable|null $revoked_at
 * @property-read AssignmentRecipient $recipient
 */
class AssignmentExtension extends AcademicRecord
{
    protected function casts(): array
    {
        return ['requested_due_at' => 'immutable_datetime', 'previous_due_at' => 'immutable_datetime', 'effective_due_at' => 'immutable_datetime', 'reviewed_at' => 'immutable_datetime', 'revoked_at' => 'immutable_datetime'];
    }

    /** @return BelongsTo<AssignmentRecipient, $this> */
    public function recipient(): BelongsTo
    {
        return $this->belongsTo(AssignmentRecipient::class);
    }
}
