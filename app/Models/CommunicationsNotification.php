<?php

namespace App\Models;

use Carbon\CarbonImmutable;

/** @property int $id
 * @property int $tenant_id
 * @property int $user_id
 * @property string $type
 * @property int $reference_id
 * @property string $title
 * @property string $summary
 * @property CarbonImmutable|null $read_at
 */
final class CommunicationsNotification extends AcademicRecord
{
    protected function casts(): array
    {
        return ['read_at' => 'immutable_datetime'];
    }
}
