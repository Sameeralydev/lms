<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $tenant_id
 * @property int $fee_structure_id
 * @property int $fee_head_id
 * @property string $unit_amount
 * @property int $quantity
 * @property int $sort_order
 * @property-read BillingFeeHead|null $feeHead
 */
class BillingFeeStructureItem extends AcademicRecord
{
    protected function casts(): array
    {
        return ['unit_amount' => 'decimal:2'];
    }

    /** @return BelongsTo<BillingFeeHead, $this> */
    public function feeHead(): BelongsTo
    {
        return $this->belongsTo(BillingFeeHead::class, 'fee_head_id');
    }
}
