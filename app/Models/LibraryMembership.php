<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class LibraryMembership extends AcademicRecord
{
    protected function casts(): array { return ['expires_on' => 'date']; }

    /** @return BelongsTo<TenantMembership, $this> */
    public function account(): BelongsTo { return $this->belongsTo(TenantMembership::class, 'membership_id'); }

    /** @return BelongsTo<LibraryLocation, $this> */
    public function location(): BelongsTo { return $this->belongsTo(LibraryLocation::class, 'library_location_id'); }

    /** @return HasMany<LibraryLoan, $this> */
    public function loans(): HasMany { return $this->hasMany(LibraryLoan::class, 'library_membership_id'); }

    /** @return HasMany<LibraryReservation, $this> */
    public function reservations(): HasMany { return $this->hasMany(LibraryReservation::class, 'library_membership_id'); }
}
