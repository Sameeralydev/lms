<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class AssignmentGroup extends AcademicRecord
{
    protected function casts(): array
    {
        return ['frozen_at' => 'immutable_datetime'];
    }

    /** @return BelongsTo<Assignment, $this> */
    public function assignment(): BelongsTo
    {
        return $this->belongsTo(Assignment::class);
    }

    /** @return HasMany<AssignmentGroupMember, $this> */
    public function members(): HasMany
    {
        return $this->hasMany(AssignmentGroupMember::class, 'group_id')->whereNull('left_at');
    }

    /** @return HasOne<AssignmentSubmission, $this> */
    public function submission(): HasOne
    {
        return $this->hasOne(AssignmentSubmission::class, 'group_id');
    }
}
