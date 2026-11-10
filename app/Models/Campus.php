<?php

namespace App\Models;

use App\Models\Concerns\ScopedToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** @property int $id @property string $name @property string $code @property string|null $city */
class Campus extends Model
{
    use ScopedToTenant;

    protected $fillable = ['tenant_id', 'institution_id', 'code', 'name', 'contact_email', 'contact_phone', 'city', 'address', 'status', 'is_primary'];

    protected function casts(): array
    {
        return ['is_primary' => 'boolean'];
    }

    /** @return BelongsTo<Institution, $this> */
    public function institution(): BelongsTo
    {
        return $this->belongsTo(Institution::class);
    }
}
