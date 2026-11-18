<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/** @property int $plan_id @property string $event_type @property int $version @property string|null $reason @property array<string,mixed> $snapshot @property CarbonImmutable $occurred_at */
class BillingInstallmentEvent extends AcademicRecord
{
    protected static function booted(): void
    {
        static::updating(static fn (): never => throw new LogicException('Installment history is append-only.'));
        static::deleting(static fn (): never => throw new LogicException('Installment history is append-only.'));
    }

    protected function casts(): array
    {
        return ['snapshot' => 'array', 'occurred_at' => 'immutable_datetime'];
    }

    /** @return BelongsTo<BillingInstallmentPlan, $this> */
    public function plan(): BelongsTo
    {
        return $this->belongsTo(BillingInstallmentPlan::class, 'plan_id');
    }
}
