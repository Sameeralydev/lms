<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property Carbon|null $learner_confirmed_at
 * @property Carbon|null $instructor_confirmed_at
 * @property Carbon|null $completed_at
 * @property CourseDeliveryLesson $lesson
 * @property TenantMembership $membership
 */
class CourseDeliveryProgress extends AcademicRecord
{
    protected function casts(): array
    {
        return [
            'revision_version' => 'integer',
            'resume_seconds' => 'integer',
            'started_at' => 'datetime',
            'last_activity_at' => 'datetime',
            'completed_at' => 'datetime',
            'learner_confirmed_at' => 'datetime',
            'instructor_confirmed_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<CourseDeliveryWorkspace, $this> */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(CourseDeliveryWorkspace::class, 'workspace_id');
    }

    /** @return BelongsTo<CourseDeliveryLesson, $this> */
    public function lesson(): BelongsTo
    {
        return $this->belongsTo(CourseDeliveryLesson::class, 'lesson_id');
    }

    /** @return BelongsTo<TenantMembership, $this> */
    public function membership(): BelongsTo
    {
        return $this->belongsTo(TenantMembership::class, 'membership_id');
    }

    /** @return BelongsTo<User, $this> */
    public function instructorConfirmer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'instructor_confirmed_by');
    }
}
