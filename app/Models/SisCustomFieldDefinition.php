<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SisCustomFieldDefinition extends AcademicRecord
{
    protected function casts(): array
    {
        return ['is_required' => 'boolean', 'is_sensitive' => 'boolean', 'options' => 'array'];
    }

    /** @return BelongsTo<Institution, $this> */
    public function institution(): BelongsTo
    {
        return $this->belongsTo(Institution::class);
    }
}
