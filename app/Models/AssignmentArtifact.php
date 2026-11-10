<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $tenant_id
 * @property int $attempt_id
 * @property string $private_path
 * @property string $original_name
 * @property string $mime_type
 * @property int $size_bytes
 * @property string $sha256
 * @property string $scan_status
 * @property string|null $scanner
 * @property string|null $result_code
 * @property CarbonImmutable|null $scanned_at
 * @property-read AssignmentAttempt $attempt
 */
class AssignmentArtifact extends AcademicRecord
{
    protected function casts(): array
    {
        return ['scanned_at' => 'immutable_datetime', 'size_bytes' => 'integer'];
    }

    /** @return BelongsTo<AssignmentAttempt, $this> */
    public function attempt(): BelongsTo
    {
        return $this->belongsTo(AssignmentAttempt::class, 'attempt_id');
    }
}
