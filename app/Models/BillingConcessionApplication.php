<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** @property string $subtotal @property string $discount_amount @property array<string, mixed> $calculation_snapshot */
class BillingConcessionApplication extends AcademicRecord
{
    protected function casts(): array
    {
        return ['subtotal' => 'decimal:2', 'discount_amount' => 'decimal:2', 'calculation_snapshot' => 'array', 'applied_at' => 'immutable_datetime'];
    }

    /** @return BelongsTo<BillingConcession, $this> */
    public function concession(): BelongsTo
    {
        return $this->belongsTo(BillingConcession::class);
    }

    /** @return BelongsTo<BillingInvoice, $this> */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(BillingInvoice::class);
    }
}
