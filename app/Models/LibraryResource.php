<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;

final class LibraryResource extends AcademicRecord
{
    /** @return HasMany<LibraryCopy, $this> */
    public function copies(): HasMany
    {
        return $this->hasMany(LibraryCopy::class, 'resource_id');
    }
}
