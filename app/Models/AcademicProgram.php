<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/** @property int $id @property string $title @property string $code @property string|null $description */
class AcademicProgram extends AcademicRecord
{
    protected function casts(): array
    {
        return [
            'duration_amount' => 'decimal:2', 'required_credits' => 'decimal:3',
            'credit_bearing' => 'boolean', 'effective_from' => 'date', 'effective_until' => 'date',
        ];
    }

    /** @return BelongsTo<AcademicLevel, $this> */
    public function level(): BelongsTo
    {
        return $this->belongsTo(AcademicLevel::class, 'academic_level_id');
    }

    /** @return BelongsTo<AcademicQualificationType, $this> */
    public function qualificationType(): BelongsTo
    {
        return $this->belongsTo(AcademicQualificationType::class);
    }

    /** @return BelongsTo<AcademicUnit, $this> */
    public function academicUnit(): BelongsTo
    {
        return $this->belongsTo(AcademicUnit::class);
    }

    /** @return BelongsTo<AcademicCreditPolicy, $this> */
    public function creditPolicy(): BelongsTo
    {
        return $this->belongsTo(AcademicCreditPolicy::class, 'credit_policy_id');
    }

    /** @return BelongsToMany<Campus, $this> */
    public function campuses(): BelongsToMany
    {
        return $this->belongsToMany(Campus::class, 'academic_program_campuses')->withPivot('tenant_id')->withTimestamps();
    }
}
