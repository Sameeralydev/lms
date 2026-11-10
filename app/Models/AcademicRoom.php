<?php

namespace App\Models;

/** @property array<string, mixed>|null $features */
class AcademicRoom extends AcademicRecord
{
    protected function casts(): array
    {
        return ['features' => 'array'];
    }
}
