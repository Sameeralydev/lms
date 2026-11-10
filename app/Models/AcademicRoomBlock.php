<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class AcademicRoomBlock extends AcademicRecord
{
    protected function casts(): array
    {
        return [
            'starts_at_utc' => 'immutable_datetime',
            'ends_at_utc' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<AcademicRoom, $this> */
    public function room(): BelongsTo
    {
        return $this->belongsTo(AcademicRoom::class, 'room_id');
    }
}
