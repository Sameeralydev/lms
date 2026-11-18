<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $tenant_id
 * @property int $institution_id
 * @property string $code
 * @property string $name
 * @property string $category
 * @property string $status
 */
class BillingFeeHead extends AcademicRecord
{
    protected function casts(): array
    {
        return [];
    }

    /** @return HasMany<BillingFeeStructureItem, $this> */
    public function structureItems(): HasMany
    {
        return $this->hasMany(BillingFeeStructureItem::class, 'fee_head_id');
    }
}
