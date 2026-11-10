<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AcademicCurriculumFrameworkAdministrator extends AcademicRecord
{
    protected function casts(): array
    {
        return ['effective_from' => 'date', 'effective_until' => 'date', 'revoked_at' => 'datetime'];
    }

    /** @return BelongsTo<AcademicCurriculumFramework, $this> */
    public function framework(): BelongsTo
    {
        return $this->belongsTo(AcademicCurriculumFramework::class, 'framework_id');
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
