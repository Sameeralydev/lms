<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class CommunicationsAnnouncement extends AcademicRecord
{
    protected function casts(): array
    {
        return ['audience_roles' => 'array', 'published_at' => 'immutable_datetime'];
    }

    /** @return BelongsTo<User, $this> */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** @return HasMany<CommunicationsAnnouncementReceipt, $this> */
    public function receipts(): HasMany
    {
        return $this->hasMany(CommunicationsAnnouncementReceipt::class, 'announcement_id');
    }
}
