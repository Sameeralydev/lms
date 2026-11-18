<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** @property array<string, mixed>|null $metadata */
class BillingAdjustmentEvent extends AcademicRecord
{
    protected function casts(): array
    {
        return ['metadata' => 'array', 'occurred_at' => 'immutable_datetime'];
    }

    /** @return BelongsTo<BillingAdjustment, $this> */
    public function adjustment(): BelongsTo
    {
        return $this->belongsTo(BillingAdjustment::class);
    }
}
