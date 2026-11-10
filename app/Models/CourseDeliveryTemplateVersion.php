<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @phpstan-import-type TemplateSnapshot from \App\Domain\Learning\CourseDeliveryTemplateWorkflow
 *
 * @property TemplateSnapshot $content_snapshot
 * @property int $version_number
 * @property Carbon|null $published_at
 */
class CourseDeliveryTemplateVersion extends AcademicRecord
{
    protected function casts(): array
    {
        return ['content_snapshot' => 'array', 'published_at' => 'datetime', 'version_number' => 'integer'];
    }

    /** @return BelongsTo<CourseDeliveryTemplate, $this> */
    public function template(): BelongsTo
    {
        return $this->belongsTo(CourseDeliveryTemplate::class, 'template_id');
    }
}
