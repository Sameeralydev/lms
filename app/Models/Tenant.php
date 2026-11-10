<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

class Tenant extends Model
{
    use SoftDeletes;

    protected $fillable = ['public_id', 'name', 'slug', 'status', 'suspension_reason', 'created_by'];

    /** @return HasOne<Institution, $this> */
    public function institution(): HasOne
    {
        return $this->hasOne(Institution::class);
    }

    /** @return HasMany<Campus, $this> */
    public function campuses(): HasMany
    {
        return $this->hasMany(Campus::class);
    }

    /** @return HasMany<TenantMembership, $this> */
    public function memberships(): HasMany
    {
        return $this->hasMany(TenantMembership::class);
    }

    /** @return HasMany<TenantDomain, $this> */
    public function domains(): HasMany
    {
        return $this->hasMany(TenantDomain::class);
    }

    /** @return HasMany<TenantInvitation, $this> */
    public function invitations(): HasMany
    {
        return $this->hasMany(TenantInvitation::class);
    }

    /** @return HasOne<TenantBranding, $this> */
    public function branding(): HasOne
    {
        return $this->hasOne(TenantBranding::class);
    }

    /** @return HasMany<TenantSetting, $this> */
    public function settings(): HasMany
    {
        return $this->hasMany(TenantSetting::class);
    }
}
