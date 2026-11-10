<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $tenant_id
 * @property int $institution_id
 * @property int|null $campus_id
 * @property int|null $academic_program_id
 * @property string $code
 * @property string $name
 * @property string $status
 * @property bool $is_public
 * @property int|null $capacity
 * @property Carbon|null $application_deadline
 * @property array<string, mixed>|null $eligibility_rules
 * @property int $applications_count
 * @property int $submitted_count
 */
class AdmissionIntake extends AdmissionsRecord
{
    protected function casts(): array
    {
        return [
            'is_public' => 'boolean', 'applications_open_at' => 'datetime', 'application_deadline' => 'datetime',
            'documents_deadline' => 'datetime', 'evaluation_starts_at' => 'datetime', 'evaluation_ends_at' => 'datetime',
            'offer_deadline' => 'datetime', 'application_fee_amount' => 'decimal:2',
            'eligibility_rules' => 'array',
        ];
    }

    /** @return HasMany<AdmissionFormTemplate, $this> */
    public function formTemplates(): HasMany
    {
        return $this->hasMany(AdmissionFormTemplate::class, 'intake_id');
    }

    /** @return HasMany<AdmissionApplication, $this> */
    public function applications(): HasMany
    {
        return $this->hasMany(AdmissionApplication::class, 'intake_id');
    }

    /** @return BelongsTo<Campus, $this> */
    public function campus(): BelongsTo
    {
        return $this->belongsTo(Campus::class);
    }

    /** @return BelongsTo<AcademicProgram, $this> */
    public function program(): BelongsTo
    {
        return $this->belongsTo(AcademicProgram::class, 'academic_program_id');
    }
}
