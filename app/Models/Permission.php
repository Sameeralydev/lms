<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\Pivot;

class Permission extends Model
{
    protected $fillable = ['key', 'description', 'scope'];

    /** @return BelongsToMany<Role, $this, Pivot, 'pivot'> */
    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class);
    }
}
