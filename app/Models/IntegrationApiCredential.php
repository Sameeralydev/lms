<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class IntegrationApiCredential extends Model
{
    protected $fillable = ['tenant_id', 'name', 'token_hash', 'token_prefix', 'scopes', 'last_used_at', 'expires_at', 'revoked_at', 'created_by'];

    protected function casts(): array
    {
        return ['scopes' => 'array', 'last_used_at' => 'datetime', 'expires_at' => 'datetime', 'revoked_at' => 'datetime'];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

}
