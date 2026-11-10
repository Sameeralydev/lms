<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** @property array<string, mixed> $response_payload */
class CourseDeliveryActivityResponse extends AcademicRecord
{
    protected $table = 'course_delivery_activity_responses';

    protected function casts(): array
    {
        return [
            'response_payload' => 'encrypted:array',
            'submitted_at' => 'datetime',
            'retention_until' => 'datetime',
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

    /** @return BelongsTo<CourseDeliveryBlock, $this> */
    public function block(): BelongsTo
    {
        return $this->belongsTo(CourseDeliveryBlock::class, 'block_id');
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
