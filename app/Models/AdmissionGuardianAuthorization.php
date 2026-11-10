<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $tenant_id
 * @property int $application_id
 * @property int $applicant_user_id
 * @property int|null $guardian_user_id
 * @property string $status
 * @property Carbon|null $expires_at
 */
class AdmissionGuardianAuthorization extends AdmissionsRecord
{
    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'email_verified_at' => 'datetime',
            'relationship_verified_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<AdmissionApplication, $this> */
    public function application(): BelongsTo
    {
        return $this->belongsTo(AdmissionApplication::class, 'application_id');
    }

    /** @return BelongsTo<User, $this> */
    public function applicant(): BelongsTo
    {
        return $this->belongsTo(User::class, 'applicant_user_id');
    }

    /** @return BelongsTo<User, $this> */
    public function guardian(): BelongsTo
    {
        return $this->belongsTo(User::class, 'guardian_user_id');
    }
}
