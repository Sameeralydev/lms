<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/** @property int $id
 * @property int $tenant_id
 * @property int $user_id
 * @property string $event_key
 * @property string $event_type
 * @property string $title
 * @property string $message
 * @property CarbonImmutable|null $read_at
 */
final class AssignmentNotification extends Model
{
    protected $guarded = ['id', 'tenant_id', 'user_id'];

    protected function casts(): array
    {
        return ['read_at' => 'immutable_datetime'];
    }
}
