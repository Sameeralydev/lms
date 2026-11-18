<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $tenant_id
 * @property int $institution_id
 * @property int|null $campus_id
 * @property int $student_id
 * @property string $payment_number
 * @property string $idempotency_key
 * @property string $method
 * @property string $status
 * @property string $currency
 * @property string $amount
 * @property string $unapplied_amount
 * @property string|null $reference
 * @property string|null $receipt_number
 * @property int $recorded_by
 * @property CarbonImmutable $recorded_at
 * @property CarbonImmutable|null $confirmed_at
 * @property int|null $confirmed_by
 * @property-read SisStudent $student
 * @property-read Collection<int, BillingPaymentAllocation> $allocations
 * @property-read Collection<int, BillingPaymentEvent> $events
 */
class BillingPayment extends AcademicRecord
{
    protected function casts(): array
    {
        return ['amount' => 'decimal:2', 'unapplied_amount' => 'decimal:2', 'recorded_at' => 'immutable_datetime', 'confirmed_at' => 'immutable_datetime', 'rejected_at' => 'immutable_datetime'];
    }

    /** @return BelongsTo<SisStudent, $this> */
    public function student(): BelongsTo
    {
        return $this->belongsTo(SisStudent::class, 'student_id');
    }

    /** @return HasMany<BillingPaymentAllocation, $this> */
    public function allocations(): HasMany
    {
        return $this->hasMany(BillingPaymentAllocation::class, 'payment_id')->orderBy('id');
    }

    /** @return HasMany<BillingPaymentEvent, $this> */
    public function events(): HasMany
    {
        return $this->hasMany(BillingPaymentEvent::class, 'payment_id')->orderBy('id');
    }

    /** @return HasMany<BillingPaymentRefund, $this> */
    public function refunds(): HasMany
    {
        return $this->hasMany(BillingPaymentRefund::class, 'payment_id')->orderBy('id');
    }

    /** @return HasMany<BillingPaymentReversal, $this> */
    public function reversals(): HasMany
    {
        return $this->hasMany(BillingPaymentReversal::class, 'payment_id')->orderBy('id');
    }

    /** @return BelongsTo<User, $this> */
    public function recordedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }

    /** @return BelongsTo<User, $this> */
    public function confirmedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'confirmed_by');
    }
}
