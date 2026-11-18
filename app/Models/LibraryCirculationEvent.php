<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class LibraryCirculationEvent extends AcademicRecord
{
    protected function casts(): array { return ['details' => 'array', 'occurred_at' => 'datetime']; }

    /** @return BelongsTo<LibraryLoan, $this> */
    public function loan(): BelongsTo { return $this->belongsTo(LibraryLoan::class, 'loan_id'); }

    /** @return BelongsTo<LibraryReservation, $this> */
    public function reservation(): BelongsTo { return $this->belongsTo(LibraryReservation::class, 'reservation_id'); }

    /** @return BelongsTo<User, $this> */
    public function actor(): BelongsTo { return $this->belongsTo(User::class, 'actor_id'); }
}
