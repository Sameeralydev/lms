<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** @property int $id */
class AdmissionDocument extends AdmissionsRecord
{
    protected function casts(): array
    {
        return ['reviewed_at' => 'datetime'];
    }

    /** @return HasMany<AdmissionDocumentVersion, $this> */
    public function versions(): HasMany
    {
        return $this->hasMany(AdmissionDocumentVersion::class, 'document_id')->orderByDesc('version_number');
    }

    /** @return BelongsTo<AdmissionDocumentRequirement, $this> */
    public function requirement(): BelongsTo
    {
        return $this->belongsTo(AdmissionDocumentRequirement::class, 'requirement_id');
    }
}
