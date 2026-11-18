<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class TenantSubscriptionEvent extends Model
{
    public $timestamps = false;

    protected $fillable = ['tenant_id', 'tenant_subscription_id', 'tenant_plan_id', 'actor_user_id', 'event_type', 'details', 'occurred_at'];

    protected function casts(): array
    {
        return ['details' => 'array', 'occurred_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::updating(static function (): never { throw new LogicException('Subscription history is immutable.'); });
        static::deleting(static function (): never { throw new LogicException('Subscription history is immutable.'); });
    }

    /** @return BelongsTo<Tenant, $this> */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }
}
