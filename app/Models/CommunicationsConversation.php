<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

final class CommunicationsConversation extends AcademicRecord
{
    /** @return HasMany<CommunicationsConversationMember, $this> */
    public function members(): HasMany
    {
        return $this->hasMany(CommunicationsConversationMember::class, 'conversation_id');
    }

    /** @return HasMany<CommunicationsMessage, $this> */
    public function messages(): HasMany
    {
        return $this->hasMany(CommunicationsMessage::class, 'conversation_id');
    }

    /** @return HasOne<CommunicationsMessage, $this> */
    public function latestMessage(): HasOne
    {
        return $this->hasOne(CommunicationsMessage::class, 'conversation_id')->latestOfMany();
    }
}
