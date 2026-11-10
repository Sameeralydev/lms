<?php

namespace App\Models;

use App\Models\Concerns\ScopedToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** @property array<string, mixed>|null $value */
class TenantSetting extends Model
{
    use ScopedToTenant;

    /** @var array<string, string> */
    protected $casts = ['value' => 'array'];

    protected $fillable = ['tenant_id', 'key', 'value'];

    /** @return BelongsTo<Tenant, $this> */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }
}
