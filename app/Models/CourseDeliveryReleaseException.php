<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/** @property Carbon $expires_at */
class CourseDeliveryReleaseException extends AcademicRecord
{
    protected function casts(): array
    {
        return ['expires_at' => 'datetime', 'approved_at' => 'datetime', 'revoked_at' => 'datetime'];
    }

    /** @return BelongsTo<CourseDeliveryWorkspace, $this> */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(CourseDeliveryWorkspace::class, 'workspace_id');
    }
}
