<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AcademicCompetencyFrameworkReview extends AcademicRecord
{
    protected function casts(): array
    {
        return ['checklist_snapshot' => 'array', 'checklist_responses' => 'array', 'assigned_at' => 'datetime', 'decided_at' => 'datetime'];
    }

    /** @return BelongsTo<AcademicCompetencyFramework, $this> */
    public function framework(): BelongsTo
    {
        return $this->belongsTo(AcademicCompetencyFramework::class, 'framework_id');
    }

    /** @return BelongsTo<User, $this> */
    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewer_user_id');
    }
}
