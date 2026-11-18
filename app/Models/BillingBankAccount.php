<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property string $display_name
 * @property string $currency
 */
class BillingBankAccount extends AcademicRecord
{
    /** @return HasMany<BillingReconciliationBatch, $this> */
    public function batches(): HasMany
    {
        return $this->hasMany(BillingReconciliationBatch::class, 'bank_account_id');
    }
}
