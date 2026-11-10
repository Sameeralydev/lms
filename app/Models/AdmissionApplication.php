<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $tenant_id
 * @property int $institution_id
 * @property int $intake_id
 * @property int $applicant_profile_id
 * @property int $form_version_id
 * @property int $applicant_user_id
 * @property string $application_number
 * @property string $status
 * @property string $eligibility_status
 * @property array<string, mixed>|null $answers
 * @property Carbon|null $submitted_at
 */
class AdmissionApplication extends AdmissionsRecord
{
    protected function casts(): array
    {
        return ['answers' => 'array', 'submitted_at' => 'datetime', 'withdrawn_at' => 'datetime'];
    }

    /** @return BelongsTo<AdmissionIntake, $this> */
    public function intake(): BelongsTo
    {
        return $this->belongsTo(AdmissionIntake::class, 'intake_id');
    }

    /** @return BelongsTo<AdmissionFormVersion, $this> */
    public function formVersion(): BelongsTo
    {
        return $this->belongsTo(AdmissionFormVersion::class, 'form_version_id');
    }

    /** @return BelongsTo<AdmissionApplicantProfile, $this> */
    public function profile(): BelongsTo
    {
        return $this->belongsTo(AdmissionApplicantProfile::class, 'applicant_profile_id');
    }

    /** @return BelongsTo<User, $this> */
    public function applicant(): BelongsTo
    {
        return $this->belongsTo(User::class, 'applicant_user_id');
    }

    /** @return HasMany<AdmissionApplicationRevision, $this> */
    public function revisions(): HasMany
    {
        return $this->hasMany(AdmissionApplicationRevision::class, 'application_id')->orderByDesc('revision_number');
    }

    /** @return HasMany<AdmissionLifecycleEvent, $this> */
    public function lifecycleEvents(): HasMany
    {
        return $this->hasMany(AdmissionLifecycleEvent::class, 'application_id');
    }

    /** @return HasMany<AdmissionOutboxEvent, $this> */
    public function outboxEvents(): HasMany
    {
        return $this->hasMany(AdmissionOutboxEvent::class, 'application_id');
    }
}
