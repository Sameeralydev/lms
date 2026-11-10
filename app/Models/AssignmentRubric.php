<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;

class AssignmentRubric extends AcademicRecord
{
    /** @return HasMany<AssignmentRubricVersion, $this> */
    public function versions(): HasMany
    {
        return $this->hasMany(AssignmentRubricVersion::class, 'rubric_id')->orderByDesc('version_number');
    }
}
