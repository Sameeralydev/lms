<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/** @property int $tenant_id @property int $refund_id @property int $payment_allocation_id @property int $invoice_id @property string $amount */
class BillingPaymentRefundAllocation extends AcademicRecord
{
    protected static function booted(): void
    {
        static::updating(static fn () => throw new LogicException('Refund allocation history is immutable.'));
        static::deleting(static fn () => throw new LogicException('Refund allocation history is immutable.'));
    }

    protected function casts(): array
    {
        return ['amount' => 'decimal:2', 'reversed_at' => 'immutable_datetime'];
    }

    /** @return BelongsTo<BillingPaymentRefund, $this> */
    public function refund(): BelongsTo
    {
        return $this->belongsTo(BillingPaymentRefund::class, 'refund_id');
    }
}
