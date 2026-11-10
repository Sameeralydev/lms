<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** @property int $occupied_count @property int $confirmed_count @property int $waitlist_count @property string $section_code */
class CourseSection extends AcademicRecord
{
    /** @return BelongsTo<CourseOffering, $this> */
    public function offering(): BelongsTo
    {
        return $this->belongsTo(CourseOffering::class, 'offering_id');
    }

    /** @return HasMany<CourseEnrollment, $this> */
    public function enrollments(): HasMany
    {
        return $this->hasMany(CourseEnrollment::class, 'section_id');
    }

    /** @return HasMany<CourseWaitlistEntry, $this> */
    public function waitlistEntries(): HasMany
    {
        return $this->hasMany(CourseWaitlistEntry::class, 'section_id');
    }

    public function confirmedCount(): int
    {
        return (int) $this->enrollments()->whereIn('status', ['confirmed', 'offer_pending'])->count();
    }

    public function availableSeats(): int
    {
        return max(0, (int) $this->capacity - (int) $this->reserved_seats - $this->confirmedCount());
    }
}
