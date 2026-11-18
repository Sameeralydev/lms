<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** @property int $tenant_id @property int $institution_id @property int|null $campus_id @property int $student_id @property string $code @property string $name @property string $kind @property string $value @property string $status @property string $reason */
class BillingConcession extends AcademicRecord
{
    protected function casts(): array
    {
        return ['value' => 'decimal:2', 'effective_from' => 'date', 'effective_until' => 'date', 'reviewed_at' => 'immutable_datetime', 'revoked_at' => 'immutable_datetime'];
    }

    /** @return BelongsTo<SisStudent, $this> */
    public function student(): BelongsTo
    {
        return $this->belongsTo(SisStudent::class);
    }
}
