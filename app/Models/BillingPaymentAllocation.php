<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

/** @property int $tenant_id @property int $payment_id @property int $invoice_id @property string $request_key @property string $amount */
class BillingPaymentAllocation extends AcademicRecord
{
    protected static function booted(): void
    {
        static::updating(static fn () => throw new LogicException('Payment allocations are immutable; record a compensating financial event.'));
        static::deleting(static fn () => throw new LogicException('Payment allocations are immutable; record a compensating financial event.'));
    }

    protected function casts(): array
    {
        return ['amount' => 'decimal:2', 'allocated_at' => 'immutable_datetime'];
    }

    /** @return BelongsTo<BillingInvoice, $this> */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(BillingInvoice::class, 'invoice_id');
    }

    /** @return BelongsTo<BillingPayment, $this> */
    public function payment(): BelongsTo
    {
        return $this->belongsTo(BillingPayment::class, 'payment_id');
    }

    /** @return HasMany<BillingPaymentRefundAllocation, $this> */
    public function refundAllocations(): HasMany
    {
        return $this->hasMany(BillingPaymentRefundAllocation::class, 'payment_allocation_id')->orderBy('id');
    }
}
