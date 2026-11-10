<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property bool $require_prerequisites
 * @property bool $require_withdrawal_approval
 * @property bool $allow_section_transfer
 * @property bool $allow_late_registration
 * @property string $waitlist_ordering
 */
class EnrollmentPolicy extends AcademicRecord
{
    protected function casts(): array
    {
        return [
            'requires_approval' => 'boolean', 'waitlist_enabled' => 'boolean',
            'require_prerequisites' => 'boolean', 'require_withdrawal_approval' => 'boolean',
            'allow_section_transfer' => 'boolean', 'allow_late_registration' => 'boolean',
        ];
    }

    /** @return BelongsTo<Institution, $this> */
    public function institution(): BelongsTo
    {
        return $this->belongsTo(Institution::class);
    }

    /** @return HasMany<CourseOffering, $this> */
    public function offerings(): HasMany
    {
        return $this->hasMany(CourseOffering::class, 'policy_id');
    }
}
