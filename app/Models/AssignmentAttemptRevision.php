<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** @property int $id
 * @property int $tenant_id
 * @property int $attempt_id
 * @property int $revision_number
 * @property array<string, mixed> $content_snapshot
 * @property string $content_sha256
 * @property int $saved_by
 * @property CarbonImmutable $saved_at
 */
final class AssignmentAttemptRevision extends AcademicRecord
{
    protected function casts(): array
    {
        return ['content_snapshot' => 'array', 'saved_at' => 'immutable_datetime'];
    }

    /** @return BelongsTo<AssignmentAttempt, $this> */
    public function attempt(): BelongsTo
    {
        return $this->belongsTo(AssignmentAttempt::class, 'attempt_id');
    }
}
