<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class HrStaffRecord extends AcademicRecord
{
    protected function casts(): array
    {
        return [
            'joined_on' => 'date',
            'separated_on' => 'date',
            'personal_email' => 'encrypted',
            'personal_phone' => 'encrypted',
            'emergency_contact_name' => 'encrypted',
            'emergency_contact_phone' => 'encrypted',
        ];
    }

    /** @return BelongsTo<TenantMembership, $this> */
    public function membership(): BelongsTo
    {
        return $this->belongsTo(TenantMembership::class, 'membership_id');
    }

    /** @return BelongsTo<Campus, $this> */
    public function campus(): BelongsTo
    {
        return $this->belongsTo(Campus::class);
    }

    /** @return BelongsTo<AcademicUnit, $this> */
    public function academicUnit(): BelongsTo
    {
        return $this->belongsTo(AcademicUnit::class);
    }

    /** @return BelongsTo<HrStaffRecord, $this> */
    public function manager(): BelongsTo
    {
        return $this->belongsTo(self::class, 'manager_id');
    }

    /** @return HasMany<HrStaffRecord, $this> */
    public function reports(): HasMany
    {
        return $this->hasMany(self::class, 'manager_id');
    }

    /** @return HasMany<HrStaffEmploymentHistory, $this> */
    public function employmentHistory(): HasMany
    {
        return $this->hasMany(HrStaffEmploymentHistory::class, 'staff_record_id')->orderByDesc('effective_on')->orderByDesc('id');
    }
}
