<?php

namespace App\Models;

/**
 * @property int $tenant_id
 * @property int $invoice_id
 * @property string $fee_head_code
 * @property string $fee_head_name
 * @property int $quantity
 * @property string $unit_amount
 * @property string $line_total
 */
class BillingInvoiceItem extends AcademicRecord
{
    protected function casts(): array
    {
        return ['unit_amount' => 'decimal:2', 'line_total' => 'decimal:2'];
    }
}
