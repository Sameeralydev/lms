<?php

namespace App\Models;

final class CommunicationsNotificationPreference extends AcademicRecord
{
    protected function casts(): array
    {
        return ['in_app_enabled' => 'boolean'];
    }

    public static function allows(int $tenantId, int $userId, string $eventType): bool
    {
        return (bool) (self::query()->where('tenant_id', $tenantId)->where('user_id', $userId)
            ->where('event_type', $eventType)->value('in_app_enabled') ?? true);
    }
}
