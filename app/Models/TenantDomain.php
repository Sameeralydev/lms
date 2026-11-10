<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TenantDomain extends Model
{
    protected $fillable = ['tenant_id', 'domain', 'verification_token', 'verified_at', 'is_primary', 'status'];

    protected $hidden = ['verification_token'];

    protected function casts(): array
    {
        return ['verified_at' => 'datetime', 'is_primary' => 'boolean'];
    }

    /** @return BelongsTo<Tenant, $this> */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }
}
