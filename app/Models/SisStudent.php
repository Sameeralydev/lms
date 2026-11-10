<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $tenant_id
 * @property int $institution_id
 * @property int|null $home_campus_id
 * @property int|null $user_id
 * @property int|null $admission_handoff_id
 * @property int|null $admission_application_id
 * @property int|null $admission_offer_id
 * @property int|null $import_batch_id
 * @property int|null $import_row_number
 * @property string $student_number
 * @property string $registration_reference
 * @property string $status
 * @property Carbon $admission_date
 * @property Carbon|null $date_of_birth
 * @property Carbon|null $archived_at
 * @property array<string, mixed>|null $localized_names
 * @property-read Institution $institution
 * @property-read Campus|null $homeCampus
 * @property-read User|null $user
 * @property-read AdmissionSisHandoff|null $admissionHandoff
 * @property-read Collection<int, SisStudentLifecycleEvent> $lifecycleEvents
 * @property-read Collection<int, SisStudentPlacement> $placements
 * @property-read Collection<int, SisStudentGuardian> $guardians
 * @property-read Collection<int, SisStudentCustomValue> $customValues
 * @property-read Collection<int, SisStudentDocument> $documents
 */
class SisStudent extends AcademicRecord
{
    protected function casts(): array
    {
        return [
            'localized_names' => 'array',
            'date_of_birth' => 'date',
            'admission_date' => 'date',
            'archived_at' => 'datetime',
            'government_identifier' => 'encrypted',
        ];
    }

    /** @return BelongsTo<Institution, $this> */
    public function institution(): BelongsTo
    {
        return $this->belongsTo(Institution::class);
    }

    /** @return BelongsTo<Campus, $this> */
    public function homeCampus(): BelongsTo
    {
        return $this->belongsTo(Campus::class, 'home_campus_id');
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<AdmissionSisHandoff, $this> */
    public function admissionHandoff(): BelongsTo
    {
        return $this->belongsTo(AdmissionSisHandoff::class, 'admission_handoff_id');
    }

    /** @return HasMany<SisStudentLifecycleEvent, $this> */
    public function lifecycleEvents(): HasMany
    {
        return $this->hasMany(SisStudentLifecycleEvent::class, 'student_id')->orderBy('effective_at')->orderBy('id');
    }

    /** @return HasMany<SisStudentPlacement, $this> */
    public function placements(): HasMany
    {
        return $this->hasMany(SisStudentPlacement::class, 'student_id')->orderByDesc('effective_from')->orderByDesc('id');
    }

    /** @return HasMany<SisStudentGuardian, $this> */
    public function guardians(): HasMany
    {
        return $this->hasMany(SisStudentGuardian::class, 'student_id');
    }

    /** @return HasMany<SisStudentCustomValue, $this> */
    public function customValues(): HasMany
    {
        return $this->hasMany(SisStudentCustomValue::class, 'student_id');
    }

    /** @return HasMany<SisStudentDocument, $this> */
    public function documents(): HasMany
    {
        return $this->hasMany(SisStudentDocument::class, 'student_id');
    }
}
