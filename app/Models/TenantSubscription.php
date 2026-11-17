<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TenantSubscription extends Model
{
    protected $fillable = ['tenant_id', 'tenant_plan_id', 'status', 'started_at', 'ended_at', 'assigned_by'];

    protected function casts(): array
    {
        return ['started_at' => 'datetime', 'ended_at' => 'datetime'];
    }

    /** @return BelongsTo<Tenant, $this> */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /** @return BelongsTo<TenantPlan, $this> */
    public function plan(): BelongsTo
    {
        return $this->belongsTo(TenantPlan::class, 'tenant_plan_id');
    }

    /** @return HasMany<TenantSubscriptionEvent, $this> */
    public function events(): HasMany
    {
        return $this->hasMany(TenantSubscriptionEvent::class);
    }
}
