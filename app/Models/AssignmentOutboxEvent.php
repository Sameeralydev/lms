<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\AsArrayObject;

class AssignmentOutboxEvent extends AcademicRecord
{
    protected function casts(): array
    {
        return ['safe_payload' => AsArrayObject::class, 'attempts' => 'integer', 'available_at' => 'immutable_datetime', 'locked_at' => 'immutable_datetime', 'delivered_at' => 'immutable_datetime'];
    }
}
