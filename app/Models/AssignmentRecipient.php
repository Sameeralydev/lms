<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * @property int $id
 * @property int $tenant_id
 * @property int $assignment_id
 * @property int $membership_id
 * @property int $student_id
 * @property int $section_id
 * @property string $status
 * @property CarbonImmutable|null $eligible_from
 * @property CarbonImmutable|null $effective_due_at
 * @property-read Assignment $assignment
 * @property-read AssignmentSubmission|null $submission
 */
class AssignmentRecipient extends AcademicRecord
{
    protected function casts(): array
    {
        return ['eligible_from' => 'immutable_datetime', 'effective_due_at' => 'immutable_datetime'];
    }

    /** @return BelongsTo<Assignment, $this> */
    public function assignment(): BelongsTo
    {
        return $this->belongsTo(Assignment::class);
    }

    /** @return BelongsTo<TenantMembership, $this> */
    public function membership(): BelongsTo
    {
        return $this->belongsTo(TenantMembership::class);
    }

    /** @return BelongsTo<SisStudent, $this> */
    public function student(): BelongsTo
    {
        return $this->belongsTo(SisStudent::class);
    }

    /** @return HasOne<AssignmentSubmission, $this> */
    public function submission(): HasOne
    {
        return $this->hasOne(AssignmentSubmission::class, 'recipient_id');
    }
}
