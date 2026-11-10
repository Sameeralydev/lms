<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;

class AcademicDocument extends AcademicRecord
{
    protected function casts(): array
    {
        return ['file_size' => 'integer', 'effective_from' => 'date', 'effective_until' => 'date'];
    }

    /** @return HasMany<AcademicDocumentApproval, $this> */
    public function approvals(): HasMany
    {
        return $this->hasMany(AcademicDocumentApproval::class)->orderBy('occurred_at');
    }
}
