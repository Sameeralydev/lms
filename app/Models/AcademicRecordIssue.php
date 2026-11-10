<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** @property array<string, mixed> $record_snapshot @property CarbonImmutable|null $issued_at */
class AcademicRecordIssue extends AcademicRecord
{
    protected function casts(): array
    {
        return ['record_snapshot' => 'array', 'approved_at' => 'immutable_datetime', 'issued_at' => 'immutable_datetime', 'revoked_at' => 'immutable_datetime', 'issue_number' => 'integer'];
    }

    /** @return BelongsTo<SisStudent, $this> */
    public function student(): BelongsTo
    {
        return $this->belongsTo(SisStudent::class, 'student_id');
    }

    /** @return BelongsTo<AcademicRecordTemplate, $this> */
    public function template(): BelongsTo
    {
        return $this->belongsTo(AcademicRecordTemplate::class, 'template_id');
    }
}
