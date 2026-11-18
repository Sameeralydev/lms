<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/** @property int $tenant_id @property int $institution_id @property int|null $campus_id @property int $student_id @property int $fee_structure_id @property int|null $course_enrollment_id @property int|null $academic_period_id @property Carbon $period_start @property Carbon $period_end @property Carbon $effective_from @property Carbon|null $effective_until @property string $source @property string $status @property string $idempotency_key */
class BillingFeeAssignment extends AcademicRecord
{
    protected function casts(): array
    {
        return ['period_start' => 'date', 'period_end' => 'date', 'effective_from' => 'date', 'effective_until' => 'date', 'context_snapshot' => 'array'];
    }

    /** @return BelongsTo<SisStudent, $this> */
    public function student(): BelongsTo
    {
        return $this->belongsTo(SisStudent::class);
    }

    /** @return BelongsTo<BillingFeeStructure, $this> */
    public function feeStructure(): BelongsTo
    {
        return $this->belongsTo(BillingFeeStructure::class, 'fee_structure_id');
    }
}
