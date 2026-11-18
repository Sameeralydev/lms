<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** @property int $tenant_id @property int $payment_id @property string $reversal_number @property string $request_key @property string $status @property string $reason @property int $requested_by @property int|null $reviewed_by @property int|null $executed_by @property CarbonImmutable|null $executed_at */
class BillingPaymentReversal extends AcademicRecord
{
    protected function casts(): array
    {
        return ['requested_at' => 'immutable_datetime', 'reviewed_at' => 'immutable_datetime', 'executed_at' => 'immutable_datetime'];
    }

    /** @return BelongsTo<BillingPayment, $this> */
    public function payment(): BelongsTo
    {
        return $this->belongsTo(BillingPayment::class, 'payment_id');
    }
}
