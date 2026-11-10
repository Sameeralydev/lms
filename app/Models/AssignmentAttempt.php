<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property int $tenant_id
 * @property int $submission_id
 * @property int $attempt_number
 * @property string $status
 * @property string|null $response_text
 * @property string|null $response_url
 * @property string|null $receipt_reference
 * @property array<string, mixed>|null $receipt_snapshot
 * @property bool $is_late
 * @property CarbonImmutable|null $submitted_at
 * @property CarbonImmutable|null $effective_due_at
 * @property-read AssignmentSubmission $submission
 * @property-read Collection<int, AssignmentGrade> $grades
 * @property-read Collection<int, AssignmentArtifact> $artifacts
 * @property-read Collection<int, AssignmentAttemptRevision> $revisions
 */
class AssignmentAttempt extends AcademicRecord
{
    protected function casts(): array
    {
        return ['receipt_snapshot' => 'array', 'is_late' => 'boolean', 'effective_due_at' => 'immutable_datetime', 'submitted_at' => 'immutable_datetime', 'withdrawn_at' => 'immutable_datetime'];
    }

    /** @return BelongsTo<AssignmentSubmission, $this> */
    public function submission(): BelongsTo
    {
        return $this->belongsTo(AssignmentSubmission::class);
    }

    /** @return HasMany<AssignmentArtifact, $this> */
    public function artifacts(): HasMany
    {
        return $this->hasMany(AssignmentArtifact::class, 'attempt_id');
    }

    /** @return HasMany<AssignmentGrade, $this> */
    public function grades(): HasMany
    {
        return $this->hasMany(AssignmentGrade::class, 'attempt_id');
    }

    /** @return HasMany<AssignmentIntegrityCase, $this> */
    public function integrityCases(): HasMany
    {
        return $this->hasMany(AssignmentIntegrityCase::class, 'attempt_id')->orderByDesc('created_at');
    }

    /** @return HasMany<AssignmentAttemptRevision, $this> */
    public function revisions(): HasMany
    {
        return $this->hasMany(AssignmentAttemptRevision::class, 'attempt_id')->orderBy('revision_number');
    }
}
