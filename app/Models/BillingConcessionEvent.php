<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** @property array<string, mixed>|null $metadata */
class BillingConcessionEvent extends AcademicRecord
{
    protected function casts(): array
    {
        return ['metadata' => 'array', 'occurred_at' => 'immutable_datetime'];
    }

    /** @return BelongsTo<BillingConcession, $this> */
    public function concession(): BelongsTo
    {
        return $this->belongsTo(BillingConcession::class);
    }
}
