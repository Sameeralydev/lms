<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class LibraryLoan extends AcademicRecord
{
    protected function casts(): array { return ['checked_out_at' => 'datetime', 'due_at' => 'datetime', 'returned_at' => 'datetime']; }

    /** @return BelongsTo<LibraryCopy, $this> */
    public function copy(): BelongsTo { return $this->belongsTo(LibraryCopy::class, 'copy_id'); }

    /** @return BelongsTo<LibraryMembership, $this> */
    public function libraryMembership(): BelongsTo { return $this->belongsTo(LibraryMembership::class, 'library_membership_id'); }
}
