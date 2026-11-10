<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;

/** @property array<string, mixed> $configuration */
class AcademicRecordTemplate extends AcademicRecord
{
    protected function casts(): array
    {
        return ['version' => 'integer', 'configuration' => 'array'];
    }

    /** @return HasMany<AcademicRecordIssue, $this> */
    public function issues(): HasMany
    {
        return $this->hasMany(AcademicRecordIssue::class, 'template_id');
    }
}
