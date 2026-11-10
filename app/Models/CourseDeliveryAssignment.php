<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/** @property Carbon|null $effective_from
 * @property Carbon|null $effective_until
 */
class CourseDeliveryAssignment extends AcademicRecord
{
    protected function casts(): array
    {
        return ['effective_from' => 'date', 'effective_until' => 'date', 'revoked_at' => 'datetime'];
    }

    /**
     * @param  Builder<CourseDeliveryAssignment>  $query
     * @return Builder<CourseDeliveryAssignment>
     */
    public function scopeEffective(Builder $query): Builder
    {
        $today = now()->toDateString();

        return $query->where('status', 'active')
            ->where(fn (Builder $scope) => $scope->whereNull('effective_from')->orWhereDate('effective_from', '<=', $today))
            ->where(fn (Builder $scope) => $scope->whereNull('effective_until')->orWhereDate('effective_until', '>=', $today));
    }

    /** @return BelongsTo<CourseDeliveryWorkspace, $this> */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(CourseDeliveryWorkspace::class, 'workspace_id');
    }

    /** @return BelongsTo<TenantMembership, $this> */
    public function membership(): BelongsTo
    {
        return $this->belongsTo(TenantMembership::class, 'membership_id');
    }
}
