<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/** @property int $student_id @property string $event_type @property string|null $from_status @property string|null $to_status @property Carbon $effective_at @property array<string, mixed>|null $safe_metadata */
class SisStudentLifecycleEvent extends AcademicRecord
{
    protected function casts(): array
    {
        return ['effective_at' => 'datetime', 'safe_metadata' => 'array'];
    }

    /** @return BelongsTo<SisStudent, $this> */
    public function student(): BelongsTo
    {
        return $this->belongsTo(SisStudent::class, 'student_id');
    }
}
