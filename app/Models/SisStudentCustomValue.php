<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** @property int $student_id @property int $field_definition_id @property array<string, mixed>|null $value */
class SisStudentCustomValue extends AcademicRecord
{
    protected function casts(): array
    {
        return ['value' => 'array'];
    }

    /** @return BelongsTo<SisStudent, $this> */
    public function student(): BelongsTo
    {
        return $this->belongsTo(SisStudent::class, 'student_id');
    }

    /** @return BelongsTo<SisCustomFieldDefinition, $this> */
    public function definition(): BelongsTo
    {
        return $this->belongsTo(SisCustomFieldDefinition::class, 'field_definition_id');
    }
}
