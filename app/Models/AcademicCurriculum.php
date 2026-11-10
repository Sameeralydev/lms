<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class AcademicCurriculum extends AcademicRecord
{
    protected $table = 'academic_curricula';

    protected function casts(): array
    {
        return ['lock_version' => 'integer', 'current_version_number' => 'integer'];
    }

    /** @return BelongsTo<AcademicProgram, $this> */
    public function program(): BelongsTo
    {
        return $this->belongsTo(AcademicProgram::class, 'academic_program_id');
    }

    /** @return BelongsTo<AcademicCurriculumFramework, $this> */
    public function framework(): BelongsTo
    {
        return $this->belongsTo(AcademicCurriculumFramework::class, 'framework_id');
    }

    /** @return BelongsTo<AcademicYear, $this> */
    public function academicYear(): BelongsTo
    {
        return $this->belongsTo(AcademicYear::class);
    }

    /** @return HasMany<AcademicCurriculumVersion, $this> */
    public function versions(): HasMany
    {
        return $this->hasMany(AcademicCurriculumVersion::class, 'curriculum_id')->orderByDesc('version_number');
    }

    /** @return HasOne<AcademicCurriculumVersion, $this> */
    public function latestVersion(): HasOne
    {
        return $this->hasOne(AcademicCurriculumVersion::class, 'curriculum_id')->ofMany('version_number', 'max');
    }
}
