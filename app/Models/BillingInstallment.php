<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** @property int $sequence @property string $amount @property string $status */
class BillingInstallment extends AcademicRecord
{
    protected function casts(): array
    {
        return ['due_on' => 'date', 'amount' => 'decimal:2'];
    }

    /** @return BelongsTo<BillingInstallmentPlan, $this> */
    public function plan(): BelongsTo
    {
        return $this->belongsTo(BillingInstallmentPlan::class, 'plan_id');
    }
}
