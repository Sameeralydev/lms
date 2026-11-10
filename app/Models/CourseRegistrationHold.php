<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/** @property int $student_id @property string $hold_type @property string $reason_code @property string $reason @property Carbon $effective_at @property Carbon|null $expires_at @property Carbon|null $revoked_at */
class CourseRegistrationHold extends AcademicRecord
{
    protected function casts(): array
    {
        return ['effective_at' => 'immutable_datetime', 'expires_at' => 'immutable_datetime', 'revoked_at' => 'immutable_datetime'];
    }

    /** @return BelongsTo<SisStudent, $this> */
    public function student(): BelongsTo
    {
        return $this->belongsTo(SisStudent::class, 'student_id');
    }
}
