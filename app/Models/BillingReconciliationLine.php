<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * @property int $row_number
 * @property Carbon $transaction_date
 * @property string $reference
 * @property string $direction
 * @property string $amount
 * @property string $currency
 * @property string $match_status
 * @property int|null $suggested_payment_id
 * @property int|null $suggested_refund_id
 */
class BillingReconciliationLine extends AcademicRecord
{
    protected static function booted(): void
    {
        static::deleting(static fn (): never => throw new LogicException('Imported statement rows are retained as immutable reconciliation evidence.'));
    }

    protected function casts(): array
    {
        return ['transaction_date' => 'date', 'amount' => 'decimal:2', 'reviewed_at' => 'immutable_datetime'];
    }

    /** @return BelongsTo<BillingReconciliationBatch, $this> */
    public function batch(): BelongsTo
    {
        return $this->belongsTo(BillingReconciliationBatch::class, 'batch_id');
    }
}
