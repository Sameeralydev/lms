<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class LibraryReservation extends AcademicRecord
{
    protected function casts(): array { return ['placed_at' => 'datetime', 'fulfilled_at' => 'datetime', 'expires_at' => 'datetime']; }

    /** @return BelongsTo<LibraryResource, $this> */
    public function resource(): BelongsTo { return $this->belongsTo(LibraryResource::class, 'resource_id'); }

    /** @return BelongsTo<LibraryMembership, $this> */
    public function libraryMembership(): BelongsTo { return $this->belongsTo(LibraryMembership::class, 'library_membership_id'); }

    /** @return BelongsTo<LibraryCopy, $this> */
    public function copy(): BelongsTo { return $this->belongsTo(LibraryCopy::class, 'copy_id'); }
}
