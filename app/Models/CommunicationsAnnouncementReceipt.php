<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class CommunicationsAnnouncementReceipt extends AcademicRecord
{
    protected function casts(): array
    {
        return ['read_at' => 'immutable_datetime'];
    }

    /** @return BelongsTo<CommunicationsAnnouncement, $this> */
    public function announcement(): BelongsTo
    {
        return $this->belongsTo(CommunicationsAnnouncement::class, 'announcement_id');
    }
}
