<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/** @property Carbon $effective_from @property Carbon|null $effective_until @property Carbon|null $verified_at */
class SisFamilyStudent extends AcademicRecord
{
    protected function casts(): array
    {
        return ['effective_from' => 'date', 'effective_until' => 'date', 'verified_at' => 'datetime'];
    }

    /** @return BelongsTo<SisFamilyGroup, $this> */
    public function family(): BelongsTo
    {
        return $this->belongsTo(SisFamilyGroup::class, 'family_group_id');
    }

    /** @return BelongsTo<SisStudent, $this> */
    public function student(): BelongsTo
    {
        return $this->belongsTo(SisStudent::class, 'student_id');
    }
}
