<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $tenant_id
 * @property int $workspace_id
 * @property int|null $lesson_id
 * @property int $instructor_membership_id
 * @property string $title
 * @property string|null $description
 * @property string $delivery_type
 * @property string $timezone
 * @property CarbonImmutable $starts_at
 * @property CarbonImmutable $ends_at
 * @property CarbonImmutable|null $cancelled_at
 * @property string|null $meeting_url
 * @property string|null $venue
 * @property string|null $preparation_materials
 * @property string|null $post_session_resources
 * @property string $status
 * @property string|null $cancellation_reason
 */
final class CourseDeliveryLiveSession extends Model
{
    protected $fillable = [
        'tenant_id', 'workspace_id', 'lesson_id', 'instructor_membership_id', 'title', 'description',
        'delivery_type', 'timezone', 'starts_at', 'ends_at', 'meeting_url', 'venue', 'preparation_materials',
        'post_session_resources', 'status', 'cancellation_reason', 'created_by', 'updated_by', 'cancelled_at',
    ];

    protected function casts(): array
    {
        return ['starts_at' => 'immutable_datetime', 'ends_at' => 'immutable_datetime', 'cancelled_at' => 'immutable_datetime'];
    }

    /** @return BelongsTo<CourseDeliveryWorkspace, $this> */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(CourseDeliveryWorkspace::class, 'workspace_id');
    }

    /** @return BelongsTo<TenantMembership, $this> */
    public function instructor(): BelongsTo
    {
        return $this->belongsTo(TenantMembership::class, 'instructor_membership_id');
    }

    /** @return BelongsTo<CourseDeliveryLesson, $this> */
    public function lesson(): BelongsTo
    {
        return $this->belongsTo(CourseDeliveryLesson::class, 'lesson_id');
    }
}
