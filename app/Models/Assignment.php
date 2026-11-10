<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property int $tenant_id
 * @property int $institution_id
 * @property int $offering_id
 * @property string $title
 * @property string $assignment_type
 * @property string $submission_mode
 * @property string $collaboration_mode
 * @property string $status
 * @property string $timezone
 * @property int $maximum_attempts
 * @property int $current_version
 * @property numeric-string|null $maximum_points
 * @property bool $review_required
 * @property CarbonImmutable|null $available_at
 * @property CarbonImmutable|null $due_at
 * @property CarbonImmutable|null $late_until
 * @property CarbonImmutable|null $published_at
 * @property-read CourseOffering $offering
 * @property-read Collection<int, AssignmentRecipient> $recipients
 * @property-read Collection<int, AssignmentSubmission> $submissions
 */
class Assignment extends AcademicRecord
{
    protected function casts(): array
    {
        return ['available_at' => 'immutable_datetime', 'due_at' => 'immutable_datetime', 'late_until' => 'immutable_datetime', 'review_required' => 'boolean', 'reviewed_at' => 'immutable_datetime', 'published_at' => 'immutable_datetime', 'closed_at' => 'immutable_datetime', 'archived_at' => 'immutable_datetime'];
    }

    /** @return BelongsTo<Institution, $this> */
    public function institution(): BelongsTo
    {
        return $this->belongsTo(Institution::class);
    }

    /** @return BelongsTo<CourseOffering, $this> */
    public function offering(): BelongsTo
    {
        return $this->belongsTo(CourseOffering::class, 'offering_id');
    }

    /** @return BelongsTo<AssignmentRubricVersion, $this> */
    public function rubricVersion(): BelongsTo
    {
        return $this->belongsTo(AssignmentRubricVersion::class, 'rubric_version_id');
    }

    /** @return HasMany<AssignmentVersion, $this> */
    public function versions(): HasMany
    {
        return $this->hasMany(AssignmentVersion::class)->orderByDesc('version_number');
    }

    /** @return HasMany<AssignmentRecipient, $this> */
    public function recipients(): HasMany
    {
        return $this->hasMany(AssignmentRecipient::class);
    }

    /** @return HasMany<AssignmentSubmission, $this> */
    public function submissions(): HasMany
    {
        return $this->hasMany(AssignmentSubmission::class);
    }

    /** @return BelongsToMany<CourseSection, $this> */
    public function sections(): BelongsToMany
    {
        return $this->belongsToMany(CourseSection::class, 'assignment_sections', 'assignment_id', 'section_id')->withPivot('tenant_id')->withTimestamps();
    }
}
