<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class LibraryCopy extends AcademicRecord
{
    protected function casts(): array
    {
        return ['acquired_on' => 'date'];
    }

    /** @return BelongsTo<LibraryResource, $this> */
    public function resource(): BelongsTo { return $this->belongsTo(LibraryResource::class, 'resource_id'); }

    /** @return BelongsTo<LibraryLocation, $this> */
    public function location(): BelongsTo { return $this->belongsTo(LibraryLocation::class, 'library_location_id'); }

    /** @return HasMany<LibraryLoan, $this> */
    public function loans(): HasMany { return $this->hasMany(LibraryLoan::class, 'copy_id'); }
}
