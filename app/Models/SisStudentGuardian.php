<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/** @property int $student_id @property int|null $guardian_user_id @property int|null $admission_authorization_id @property string $guardian_name @property string|null $guardian_email @property string|null $guardian_phone @property string $relationship @property string $authority_status @property bool $is_primary_contact @property bool $is_emergency_contact @property bool $can_view_profile @property Carbon|null $verified_at @property Carbon|null $revoked_at @property-read SisStudent|null $student */
class SisStudentGuardian extends AcademicRecord
{
    protected function casts(): array
    {
        return [
            'is_primary_contact' => 'boolean',
            'is_emergency_contact' => 'boolean',
            'can_view_profile' => 'boolean',
            'verified_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<SisStudent, $this> */
    public function student(): BelongsTo
    {
        return $this->belongsTo(SisStudent::class, 'student_id');
    }

    /** @return BelongsTo<User, $this> */
    public function guardian(): BelongsTo
    {
        return $this->belongsTo(User::class, 'guardian_user_id');
    }

    /** @return BelongsTo<AdmissionGuardianAuthorization, $this> */
    public function admissionAuthorization(): BelongsTo
    {
        return $this->belongsTo(AdmissionGuardianAuthorization::class, 'admission_authorization_id');
    }
}
