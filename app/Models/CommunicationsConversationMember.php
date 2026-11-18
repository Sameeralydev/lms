<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class CommunicationsConversationMember extends AcademicRecord
{
    protected function casts(): array
    {
        return ['last_read_at' => 'immutable_datetime'];
    }

    /** @return BelongsTo<CommunicationsConversation, $this> */
    public function conversation(): BelongsTo
    {
        return $this->belongsTo(CommunicationsConversation::class, 'conversation_id');
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
