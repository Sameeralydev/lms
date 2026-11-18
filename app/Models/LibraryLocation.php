<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;

final class LibraryLocation extends AcademicRecord
{
    protected function casts(): array
    {
        return ['allow_renewals' => 'boolean'];
    }

    /** @return HasMany<LibraryCopy, $this> */
    public function copies(): HasMany
    {
        return $this->hasMany(LibraryCopy::class, 'library_location_id');
    }
}
