<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** @property string $adjustment_number @property string $kind @property string $amount @property string $currency @property string $status @property string $reason */
class BillingAdjustment extends AcademicRecord
{
    protected function casts(): array
    {
        return ['amount' => 'decimal:2', 'reviewed_at' => 'immutable_datetime', 'posted_at' => 'immutable_datetime'];
    }

    /** @return BelongsTo<BillingInvoice, $this> */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(BillingInvoice::class);
    }
}
