<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/** @property Carbon $effective_at @property Carbon|null $reviewed_at */
class SisStudentLifecycleRequest extends AcademicRecord
{
    protected function casts(): array
    {
        return ['effective_at' => 'datetime', 'reviewed_at' => 'datetime'];
    }

    /** @return BelongsTo<SisStudent, $this> */
    public function student(): BelongsTo
    {
        return $this->belongsTo(SisStudent::class, 'student_id');
    }

    /** @return BelongsTo<User, $this> */
    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }
}
