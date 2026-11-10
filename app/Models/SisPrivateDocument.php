<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SisPrivateDocument extends AcademicRecord
{
    protected function casts(): array
    {
        return ['current_version' => 'integer'];
    }

    /** @return BelongsTo<SisStudent, $this> */
    public function student(): BelongsTo
    {
        return $this->belongsTo(SisStudent::class, 'student_id');
    }

    /** @return HasMany<SisPrivateDocumentVersion, $this> */
    public function versions(): HasMany
    {
        return $this->hasMany(SisPrivateDocumentVersion::class, 'document_id')->orderByDesc('version_number');
    }
}
