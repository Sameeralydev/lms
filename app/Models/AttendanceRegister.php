<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property Carbon $occurrence_date
 * @property Carbon|null $locked_at
 * @property int $version
 * @property-read AttendancePolicyVersion|null $policyVersion
 * @property-read Collection<int,AttendanceEntry> $entries
 */
class AttendanceRegister extends AcademicRecord
{
    protected function casts(): array
    {
        return ['occurrence_date' => 'date', 'roster_snapshotted_at' => 'immutable_datetime', 'submitted_at' => 'immutable_datetime', 'finalized_at' => 'immutable_datetime', 'locked_at' => 'immutable_datetime', 'reopened_at' => 'immutable_datetime', 'version' => 'integer'];
    }

    /** @return HasMany<AttendanceEntry, $this> */
    public function entries(): HasMany
    {
        return $this->hasMany(AttendanceEntry::class, 'register_id')->orderBy('student_id');
    }

    /** @return BelongsTo<AttendancePolicyVersion, $this> */
    public function policyVersion(): BelongsTo
    {
        return $this->belongsTo(AttendancePolicyVersion::class, 'policy_version_id');
    }
}
