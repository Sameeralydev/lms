<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AdmissionInquiry extends AdmissionsRecord
{
    /** @return BelongsTo<AdmissionIntake, $this> */
    public function intake(): BelongsTo
    {
        return $this->belongsTo(AdmissionIntake::class, 'intake_id');
    }
}
