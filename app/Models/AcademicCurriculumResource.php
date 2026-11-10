<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AcademicCurriculumResource extends AcademicRecord
{
    protected function casts(): array
    {
        return [
            'captions_available' => 'boolean',
            'transcript_available' => 'boolean',
            'alternate_formats' => 'array',
            'is_required' => 'boolean',
            'reviewed_at' => 'datetime',
            'archived_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<AcademicCurriculumVersion, $this> */
    public function version(): BelongsTo
    {
        return $this->belongsTo(AcademicCurriculumVersion::class, 'curriculum_version_id');
    }

    /** @return BelongsTo<AcademicCurriculumNode, $this> */
    public function node(): BelongsTo
    {
        return $this->belongsTo(AcademicCurriculumNode::class, 'node_id');
    }

    /** @return BelongsTo<AcademicDocument, $this> */
    public function document(): BelongsTo
    {
        return $this->belongsTo(AcademicDocument::class, 'academic_document_id');
    }

    /** @return BelongsTo<AcademicCurriculumResource, $this> */
    public function basedOnResource(): BelongsTo
    {
        return $this->belongsTo(self::class, 'based_on_resource_id');
    }
}
