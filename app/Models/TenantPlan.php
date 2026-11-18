<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TenantPlan extends Model
{
    protected $fillable = ['key', 'name', 'description', 'entitlements', 'limits', 'is_active'];

    protected function casts(): array
    {
        return ['entitlements' => 'array', 'limits' => 'array', 'is_active' => 'boolean'];
    }

    /** @return HasMany<TenantSubscription, $this> */
    public function subscriptions(): HasMany
    {
        return $this->hasMany(TenantSubscription::class);
    }
}
