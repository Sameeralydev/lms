<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class CommunicationsMessage extends AcademicRecord
{
    /** @return BelongsTo<CommunicationsConversation, $this> */
    public function conversation(): BelongsTo
    {
        return $this->belongsTo(CommunicationsConversation::class, 'conversation_id');
    }

    /** @return BelongsTo<User, $this> */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sender_user_id');
    }
}
