<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** @property array<string, mixed>|null $value */
class UserPreference extends Model
{
    /** @var array<string, string> */
    protected $casts = ['value' => 'array'];

    protected $fillable = ['user_id', 'key', 'value'];

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
