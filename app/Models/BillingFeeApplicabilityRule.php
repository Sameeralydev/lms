<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/** @property int $tenant_id @property int $institution_id @property int|null $campus_id @property int|null $academic_program_id @property int|null $academic_period_id @property int $fee_structure_id @property Carbon $effective_from @property Carbon|null $effective_until @property string $status */
class BillingFeeApplicabilityRule extends AcademicRecord
{
    protected function casts(): array
    {
        return ['effective_from' => 'date', 'effective_until' => 'date'];
    }

    /** @return BelongsTo<BillingFeeStructure, $this> */
    public function feeStructure(): BelongsTo
    {
        return $this->belongsTo(BillingFeeStructure::class, 'fee_structure_id');
    }
}
