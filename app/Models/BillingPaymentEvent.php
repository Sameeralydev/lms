<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/** @property int $tenant_id @property int $payment_id @property string $event_type @property array<string,mixed>|null $metadata */
class BillingPaymentEvent extends AcademicRecord
{
    protected static function booted(): void
    {
        static::updating(static fn () => throw new LogicException('Payment history is immutable.'));
        static::deleting(static fn () => throw new LogicException('Payment history is immutable.'));
    }

    protected function casts(): array
    {
        return ['metadata' => 'array', 'occurred_at' => 'immutable_datetime'];
    }

    /** @return array<string, mixed> */
    public function metadataArray(): array
    {
        $metadata = $this->getAttribute('metadata');

        return is_array($metadata) ? $metadata : [];
    }

    /** @return BelongsTo<BillingPayment, $this> */
    public function payment(): BelongsTo
    {
        return $this->belongsTo(BillingPayment::class, 'payment_id');
    }
}
