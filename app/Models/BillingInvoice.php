<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $tenant_id
 * @property int $institution_id
 * @property int|null $campus_id
 * @property int $student_id
 * @property int $fee_structure_id
 * @property string $invoice_number
 * @property string $currency
 * @property string $status
 * @property string $subtotal
 * @property string $discount_total
 * @property string $total
 * @property string $paid_amount
 * @property string $outstanding_amount
 * @property Carbon $period_start
 * @property Carbon $period_end
 * @property Carbon $due_on
 * @property CarbonImmutable $issued_at
 * @property-read SisStudent $student
 * @property-read BillingFeeStructure $feeStructure
 * @property-read Collection<int, BillingInvoiceItem> $items
 */
class BillingInvoice extends AcademicRecord
{
    protected function casts(): array
    {
        return [
            'period_start' => 'date', 'period_end' => 'date', 'due_on' => 'date',
            'subtotal' => 'decimal:2', 'discount_total' => 'decimal:2', 'total' => 'decimal:2',
            'paid_amount' => 'decimal:2', 'outstanding_amount' => 'decimal:2',
            'issued_at' => 'immutable_datetime', 'cancelled_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<SisStudent, $this> */
    public function student(): BelongsTo
    {
        return $this->belongsTo(SisStudent::class, 'student_id');
    }

    /** @return BelongsTo<BillingFeeStructure, $this> */
    public function feeStructure(): BelongsTo
    {
        return $this->belongsTo(BillingFeeStructure::class, 'fee_structure_id');
    }

    /** @return HasMany<BillingInvoiceItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(BillingInvoiceItem::class, 'invoice_id')->orderBy('id');
    }
}
