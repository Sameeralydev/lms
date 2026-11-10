<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $extra_time_seconds
 * @property Carbon|null $availability_ends_at
 * @property string|null $response_format
 */
class QuizLearnerAccommodation extends AcademicRecord
{
    protected $hidden = ['confidential_reason'];

    protected function casts(): array
    {
        return ['availability_ends_at' => 'immutable_datetime', 'confidential_reason' => 'encrypted'];
    }

    /** @return BelongsTo<Quiz, $this> */
    public function quiz(): BelongsTo
    {
        return $this->belongsTo(Quiz::class);
    }

    /** @return BelongsTo<TenantMembership, $this> */
    public function membership(): BelongsTo
    {
        return $this->belongsTo(TenantMembership::class, 'membership_id');
    }
}
