<?php

namespace App\Models;

use App\Models\Concerns\ScopedToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** @property int $id @property int $tenant_id @property string $display_name @property string $official_name @property string $type @property string $timezone @property string $locale @property string|null $website @property string|null $contact_email @property string|null $city */
class Institution extends Model
{
    use ScopedToTenant;

    protected $fillable = ['tenant_id', 'official_name', 'display_name', 'code', 'type', 'contact_email', 'contact_phone', 'website', 'country', 'region', 'city', 'address', 'timezone', 'locale', 'status'];

    /** @return BelongsTo<Tenant, $this> */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /** @return HasMany<Campus, $this> */
    public function campuses(): HasMany
    {
        return $this->hasMany(Campus::class);
    }
}
