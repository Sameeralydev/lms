<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property array<string,mixed> $config_snapshot
 * @property Carbon $effective_from
 * @property int $version
 * @property-read AttendancePolicy|null $policy
 * @property-read Collection<int,AttendanceStatusDefinition> $statuses
 */
class AttendancePolicyVersion extends AcademicRecord
{
    protected function casts(): array
    {
        return ['config_snapshot' => 'array', 'effective_from' => 'immutable_datetime', 'version' => 'integer'];
    }

    /** @return HasMany<AttendanceStatusDefinition, $this> */
    public function statuses(): HasMany
    {
        return $this->hasMany(AttendanceStatusDefinition::class, 'policy_version_id')->orderBy('id');
    }

    /** @return BelongsTo<AttendancePolicy, $this> */
    public function policy(): BelongsTo
    {
        return $this->belongsTo(AttendancePolicy::class, 'policy_id');
    }
}
