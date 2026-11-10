<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AssignmentGroupMember extends AcademicRecord
{
    protected function casts(): array
    {
        return ['joined_at' => 'immutable_datetime', 'left_at' => 'immutable_datetime'];
    }

    /** @return BelongsTo<AssignmentGroup, $this> */
    public function group(): BelongsTo
    {
        return $this->belongsTo(AssignmentGroup::class, 'group_id');
    }

    /** @return BelongsTo<TenantMembership, $this> */
    public function membership(): BelongsTo
    {
        return $this->belongsTo(TenantMembership::class);
    }
}
