<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $tenant_id
 * @property int $institution_id
 * @property int|null $campus_id
 * @property int $student_id
 * @property int|null $course_enrollment_id
 * @property int $fee_structure_id
 * @property string $frequency
 * @property Carbon $period_start
 * @property Carbon $period_end
 * @property int $due_days_after_period
 * @property string $timezone
 * @property CarbonImmutable $next_run_at
 * @property string $status
 * @property int $created_by
 */
class BillingRecurringPlan extends AcademicRecord
{
    protected function casts(): array
    {
        return ['period_start' => 'date', 'period_end' => 'date', 'next_run_at' => 'immutable_datetime'];
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

    /** @return HasMany<BillingRecurringRun, $this> */
    public function runs(): HasMany
    {
        return $this->hasMany(BillingRecurringRun::class, 'plan_id')->latest('last_attempt_at');
    }
}
