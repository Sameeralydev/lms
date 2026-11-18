<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** @property int $tenant_id @property int $payment_id @property string $refund_number @property string $request_key @property string $currency @property string $amount @property string $status @property string $reason @property CarbonImmutable|null $executed_at @property string|null $payout_reference @property-read BillingPayment $payment */
class BillingPaymentRefund extends AcademicRecord
{
    protected function casts(): array
    {
        return ['amount' => 'decimal:2', 'unapplied_refund_amount' => 'decimal:2', 'requested_at' => 'immutable_datetime', 'reviewed_at' => 'immutable_datetime', 'executed_at' => 'immutable_datetime'];
    }

    public function executedAtDateTime(): ?CarbonImmutable
    {
        $value = $this->getAttribute('executed_at');

        return $value === null ? null : CarbonImmutable::parse($value);
    }

    /** @return BelongsTo<BillingPayment, $this> */
    public function payment(): BelongsTo
    {
        return $this->belongsTo(BillingPayment::class, 'payment_id');
    }

    /** @return HasMany<BillingPaymentRefundAllocation, $this> */
    public function allocations(): HasMany
    {
        return $this->hasMany(BillingPaymentRefundAllocation::class, 'refund_id')->orderBy('id');
    }
}
