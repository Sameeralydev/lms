<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** @property string $status @property int $installment_count @property int $version */
class BillingInstallmentPlan extends AcademicRecord
{
    protected function casts(): array
    {
        return ['published_at' => 'immutable_datetime'];
    }

    /** @return BelongsTo<BillingInvoice, $this> */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(BillingInvoice::class);
    }

    /** @return HasMany<BillingInstallment, $this> */
    public function installments(): HasMany
    {
        return $this->hasMany(BillingInstallment::class, 'plan_id')->orderBy('sequence');
    }
}
