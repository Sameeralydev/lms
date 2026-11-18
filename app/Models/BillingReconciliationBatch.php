<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property Carbon $period_start
 * @property Carbon $period_end
 * @property string $batch_number
 * @property string $currency
 * @property string $status
 * @property int $line_count
 * @property-read BillingBankAccount $bankAccount
 * @property-read Collection<int, BillingReconciliationLine> $lines
 */
class BillingReconciliationBatch extends AcademicRecord
{
    protected function casts(): array
    {
        return ['period_start' => 'date', 'period_end' => 'date', 'closed_at' => 'immutable_datetime'];
    }

    /** @return BelongsTo<BillingBankAccount, $this> */
    public function bankAccount(): BelongsTo
    {
        return $this->belongsTo(BillingBankAccount::class, 'bank_account_id');
    }

    /** @return HasMany<BillingReconciliationLine, $this> */
    public function lines(): HasMany
    {
        return $this->hasMany(BillingReconciliationLine::class, 'batch_id')->orderBy('row_number');
    }
}
