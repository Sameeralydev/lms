<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** @property int $plan_id @property string $status @property int $attempts @property string|null $failure_code @property string|null $period_start @property string|null $period_end */
class BillingRecurringRun extends AcademicRecord
{
    /** @return BelongsTo<BillingRecurringPlan, $this> */
    public function plan(): BelongsTo
    {
        return $this->belongsTo(BillingRecurringPlan::class, 'plan_id');
    }
}
