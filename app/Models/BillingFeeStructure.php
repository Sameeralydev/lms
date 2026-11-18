<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $tenant_id
 * @property int $institution_id
 * @property int|null $campus_id
 * @property string $code
 * @property string $name
 * @property string $currency
 * @property string $frequency
 * @property string $status
 * @property Carbon $effective_from
 * @property Carbon|null $effective_until
 * @property-read Collection<int, BillingFeeStructureItem> $items
 */
class BillingFeeStructure extends AcademicRecord
{
    protected function casts(): array
    {
        return ['effective_from' => 'date', 'effective_until' => 'date', 'published_at' => 'immutable_datetime'];
    }

    /** @return BelongsTo<Institution, $this> */
    public function institution(): BelongsTo
    {
        return $this->belongsTo(Institution::class);
    }

    /** @return BelongsTo<Campus, $this> */
    public function campus(): BelongsTo
    {
        return $this->belongsTo(Campus::class);
    }

    /** @return HasMany<BillingFeeStructureItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(BillingFeeStructureItem::class, 'fee_structure_id')->orderBy('sort_order')->orderBy('id');
    }
}
