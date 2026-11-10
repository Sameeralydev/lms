<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class QuizNotification extends AcademicRecord
{
    protected $hidden = ['tenant_id', 'user_id'];

    protected function casts(): array
    {
        return ['read_at' => 'immutable_datetime'];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
