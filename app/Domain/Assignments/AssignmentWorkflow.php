<?php

namespace App\Domain\Assignments;

use App\Domain\Audit\AuditRecorder;
use App\Domain\Tenancy\TenantContext;
use App\Jobs\ScanAssignmentArtifact;
use App\Models\Assignment;
use App\Models\AssignmentArtifact;
use App\Models\AssignmentAttempt;
use App\Models\AssignmentAttemptRevision;
use App\Models\AssignmentExtension;
use App\Models\AssignmentGrade;
use App\Models\AssignmentGroup;
use App\Models\AssignmentGroupMember;
use App\Models\AssignmentIntegrityCase;
use App\Models\AssignmentRecipient;
use App\Models\AssignmentRubric;
use App\Models\AssignmentRubricCriterion;
use App\Models\AssignmentRubricVersion;
use App\Models\AssignmentSubmission;
use App\Models\AssignmentVersion;
use App\Models\CourseEnrollment;
use App\Models\CourseOffering;
use App\Models\CourseSection;
use App\Models\Institution;
use App\Models\SisStudent;
use App\Models\TenantMembership;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class AssignmentWorkflow
{
    public function __construct(private readonly TenantContext $context, private readonly AuditRecorder $audit) {}

    /** @param array<string,mixed> $data */
    public function createRubric(array $data, User $actor): AssignmentRubric
    {
        $this->authorize('assignments.rubrics.manage');
        $membership = $this->context->membership();
        $criteria = $this->validatedRubricCriteria($data);

        return DB::transaction(function () use ($data, $actor, $membership, $criteria): AssignmentRubric {
            $rubric = AssignmentRubric::query()->create(['institution_id' => $membership->institution_id, 'created_by' => $actor->getKey(), 'name' => trim((string) $data['name']), 'status' => 'draft']);
            $version = AssignmentRubricVersion::query()->create(['rubric_id' => $rubric->getKey(), 'version_number' => 1, 'maximum_points' => $data['maximum_points'], 'status' => 'draft', 'created_by' => $actor->getKey()]);
            $this->saveRubricCriteria($version, $criteria);
            $this->audit->record('assignments.rubric.created', $actor, (int) $rubric->tenant_id, $rubric, ['rubric_id' => $rubric->getKey(), 'criterion_count' => count($criteria)]);

            return $rubric->load('versions.criteria');
        }, 3);
    }

    /** @param array<string,mixed> $data */
    public function reviseRubric(AssignmentRubric $rubric, array $data, User $actor): AssignmentRubricVersion
    {
        $this->authorize('assignments.rubrics.manage');
        $this->assertOwned($rubric);
        abort_unless((int) $rubric->institution_id === (int) $this->context->membership()->institution_id, 404);
        $criteria = $this->validatedRubricCriteria($data);

        return DB::transaction(function () use ($rubric, $data, $actor, $criteria): AssignmentRubricVersion {
            $locked = AssignmentRubric::query()->lockForUpdate()->findOrFail($rubric->getKey());
            $latest = AssignmentRubricVersion::query()->where('rubric_id', $locked->getKey())->orderByDesc('version_number')->lockForUpdate()->first();
            if ($latest && $latest->status === 'draft') {
                throw ValidationException::withMessages(['rubric' => 'Publish or discard the current draft before creating another version.']);
            }
            $nextVersion = $latest !== null ? (int) $latest->version_number + 1 : 1;
            $version = AssignmentRubricVersion::query()->create(['rubric_id' => $locked->getKey(), 'version_number' => $nextVersion, 'maximum_points' => $data['maximum_points'], 'status' => 'draft', 'created_by' => $actor->getKey()]);
            $this->saveRubricCriteria($version, $criteria);
            $locked->forceFill(['status' => 'draft'])->save();
            $this->audit->record('assignments.rubric.version.created', $actor, (int) $locked->tenant_id, $version, ['rubric_id' => $locked->getKey(), 'version' => $version->version_number, 'criterion_count' => count($criteria)]);

            return $version->load('criteria');
        }, 3);
    }

    public function publishRubricVersion(AssignmentRubricVersion $version, User $actor): AssignmentRubricVersion
    {
        $this->authorize('assignments.rubrics.manage');
        $this->assertOwned($version);

        return DB::transaction(function () use ($version, $actor): AssignmentRubricVersion {
            $locked = AssignmentRubricVersion::query()->with('criteria', 'rubric')->lockForUpdate()->findOrFail($version->getKey());
            if ($locked->status !== 'draft' || $locked->criteria->isEmpty()) {
                throw ValidationException::withMessages(['rubric' => 'Only a non-empty draft rubric version can be published.']);
            }
            $criterionTotal = $locked->criteria->sum(static fn (AssignmentRubricCriterion $criterion): int => (int) round((float) $criterion->maximum_points * 100));
            $maximum = (int) round((float) $locked->maximum_points * 100);
            if ($criterionTotal !== $maximum) {
                throw ValidationException::withMessages(['criteria' => 'Criterion maximums must add up exactly to the rubric maximum.']);
            }
            $locked->forceFill(['status' => 'published', 'published_at' => now()])->save();
            $locked->rubric->forceFill(['status' => 'published'])->save();
            $this->audit->record('assignments.rubric.version.published', $actor, (int) $locked->tenant_id, $locked, ['rubric_id' => $locked->rubric_id, 'version' => $locked->version_number]);

            return $locked->refresh();
        }, 3);
    }

    public function reportIntegrityConcern(AssignmentAttempt $attempt, User $actor, string $caseType, string $summary, ?CarbonImmutable $responseDueAt = null): AssignmentIntegrityCase
    {
        $this->authorize('assignments.integrity.report');
        $this->assertOwned($attempt);
        $submission = $attempt->submission()->firstOrFail();
        $assignment = $submission->assignment()->firstOrFail();
        $sections = DB::table('assignment_sections')->where('tenant_id', $assignment->tenant_id)->where('assignment_id', $assignment->getKey())->pluck('section_id')->all();
        $this->assertSectionsAuthorized($sections, $actor);
        if ($attempt->status !== 'submitted') {
            throw ValidationException::withMessages(['attempt' => 'An integrity review can only be opened for submitted work.']);
        }

        return DB::transaction(function () use ($attempt, $actor, $caseType, $summary, $responseDueAt, $assignment): AssignmentIntegrityCase {
            $lockedAttempt = AssignmentAttempt::query()->lockForUpdate()->findOrFail($attempt->getKey());
            $openCase = AssignmentIntegrityCase::query()->where('attempt_id', $lockedAttempt->getKey())->whereIn('status', ['awaiting_learner', 'under_review'])->lockForUpdate()->exists();
            if ($openCase) {
                throw ValidationException::withMessages(['attempt' => 'An active integrity review already exists for this submission attempt.']);
            }
            $case = AssignmentIntegrityCase::query()->create([
                'attempt_id' => $lockedAttempt->getKey(), 'reported_by' => $actor->getKey(), 'case_type' => $caseType,
                'similarity_status' => 'not_configured', 'status' => 'awaiting_learner',
                'concern_summary' => trim($summary), 'response_due_at' => $responseDueAt ?? now()->addDays(7),
            ]);
            $this->outbox($assignment, 'assignment.integrity.concerned', 'integrity-concern:'.$case->getKey(), ['assignment_id' => $assignment->getKey(), 'attempt_id' => $lockedAttempt->getKey()]);
            $this->audit->record('assignments.integrity.reported', $actor, (int) $assignment->tenant_id, $case, ['attempt_id' => $lockedAttempt->getKey(), 'case_type' => $caseType, 'automated_similarity' => false]);

            return $case;
        }, 3);
    }

    public function respondToIntegrityConcern(AssignmentIntegrityCase $case, User $actor, string $response): AssignmentIntegrityCase
    {
        $this->authorize('assignments.submission.create');
        $this->assertOwned($case);
        $membership = $this->context->membership();
        $attempt = $case->attempt()->with('submission')->firstOrFail();
        $submission = $attempt->submission;
        $recipient = $submission->recipient;
        $snapshotIds = $attempt->receipt_snapshot['group_membership_ids'] ?? $submission->group_membership_snapshot ?? [];
        $isActiveGroupMember = $submission->group_id !== null && AssignmentGroupMember::query()->where('group_id', $submission->group_id)->where('membership_id', $membership->getKey())->whereNull('left_at')->exists();
        abort_unless(($recipient !== null && (int) $recipient->membership_id === (int) $membership->getKey()) || $isActiveGroupMember || in_array((int) $membership->getKey(), array_map('intval', $snapshotIds), true), 404);

        return DB::transaction(function () use ($case, $actor, $response): AssignmentIntegrityCase {
            $locked = AssignmentIntegrityCase::query()->lockForUpdate()->findOrFail($case->getKey());
            if ($locked->status !== 'awaiting_learner' || ($locked->response_due_at && $locked->response_due_at->isPast())) {
                throw ValidationException::withMessages(['response' => 'This case is no longer accepting a learner response.']);
            }
            $locked->forceFill(['learner_response' => trim($response), 'status' => 'under_review'])->save();
            $attempt = $locked->attempt()->with('submission.assignment')->firstOrFail();
            $this->outbox($attempt->submission->assignment, 'assignment.integrity.response', 'integrity-response:'.$locked->getKey(), ['assignment_id' => $attempt->submission->assignment_id, 'attempt_id' => $attempt->getKey()]);
            $this->audit->record('assignments.integrity.learner_responded', $actor, (int) $locked->tenant_id, $locked, ['case_id' => $locked->getKey(), 'response_length' => mb_strlen($response)]);

            return $locked->refresh();
        }, 3);
    }

    public function decideIntegrityCase(AssignmentIntegrityCase $case, User $actor, string $disposition, string $reviewNotes): AssignmentIntegrityCase
    {
        $this->authorize('assignments.integrity.manage');
        $this->assertOwned($case);
        if ((int) $case->reported_by === (int) $actor->getKey()) {
            throw ValidationException::withMessages(['case' => 'The person who reported a concern cannot independently decide it.']);
        }
        $attempt = $case->attempt()->with('submission.assignment')->firstOrFail();
        $assignment = $attempt->submission->assignment;
        $sections = DB::table('assignment_sections')->where('tenant_id', $assignment->tenant_id)->where('assignment_id', $assignment->getKey())->pluck('section_id')->all();
        $this->assertSectionsAuthorized($sections, $actor);

        return DB::transaction(function () use ($case, $actor, $disposition, $reviewNotes): AssignmentIntegrityCase {
            $locked = AssignmentIntegrityCase::query()->lockForUpdate()->findOrFail($case->getKey());
            if (! in_array($locked->status, ['awaiting_learner', 'under_review'], true)) {
                throw ValidationException::withMessages(['case' => 'Only an unresolved integrity case can be decided.']);
            }
            if ($disposition === 'substantiated' && blank($locked->learner_response) && (! $locked->response_due_at || $locked->response_due_at->isFuture())) {
                throw ValidationException::withMessages(['disposition' => 'A substantiated finding requires the learner response or an expired response deadline.']);
            }
            $locked->forceFill(['status' => 'resolved', 'disposition' => $disposition, 'review_notes' => trim($reviewNotes), 'resolved_at' => now()])->save();
            $attempt = $locked->attempt()->with('submission')->firstOrFail();
            $submission = $attempt->submission;
            $this->outbox($submission->assignment, 'assignment.integrity.decision', 'integrity-decision:'.$locked->getKey(), ['assignment_id' => $submission->assignment_id, 'attempt_id' => $attempt->getKey(), 'recipient_id' => $submission->recipient_id, 'group_id' => $submission->group_id]);
            $this->audit->record('assignments.integrity.decided', $actor, (int) $locked->tenant_id, $locked, ['case_id' => $locked->getKey(), 'disposition' => $disposition]);

            return $locked->refresh();
        }, 3);
    }

    public function processOverdueIntegrityResponses(): int
    {
        $tenantId = (int) $this->context->tenant()->getKey();
        $caseIds = AssignmentIntegrityCase::query()->where('tenant_id', $tenantId)->where('status', 'awaiting_learner')->whereNotNull('response_due_at')->where('response_due_at', '<=', now())->orderBy('id')->limit(200)->pluck('id')->all();
        $processed = 0;
        foreach ($caseIds as $caseId) {
            $changed = DB::transaction(function () use ($tenantId, $caseId): bool {
                $case = AssignmentIntegrityCase::query()->where('tenant_id', $tenantId)->lockForUpdate()->find($caseId);
                if (! $case || $case->status !== 'awaiting_learner' || ! $case->response_due_at || $case->response_due_at->isFuture()) {
                    return false;
                }
                $case->forceFill(['status' => 'under_review'])->save();
                $this->audit->record('assignments.integrity.response_deadline_expired', null, $tenantId, $case, ['case_id' => $case->getKey()]);

                return true;
            }, 3);
            $processed += $changed ? 1 : 0;
        }

        return $processed;
    }

    /** @param array<string,mixed> $data
     * @return list<array{title:string,description:?string,maximum_points:int|float|string,rating_levels:array<mixed>|null}>
     */
    private function validatedRubricCriteria(array $data): array
    {
        $rawCriteria = $data['criteria'] ?? null;
        if (! is_array($rawCriteria) || ! array_is_list($rawCriteria) || $rawCriteria === []) {
            throw ValidationException::withMessages(['criteria' => 'A rubric requires at least one criterion.']);
        }
        $sumCents = 0;
        $criteria = [];
        foreach ($rawCriteria as $criterion) {
            if (! is_array($criterion) || ! is_string($criterion['title'] ?? null) || ! is_numeric($criterion['maximum_points'] ?? null) || (float) $criterion['maximum_points'] <= 0) {
                throw ValidationException::withMessages(['criteria' => 'Every rubric criterion needs a positive point maximum.']);
            }
            $maximum = $criterion['maximum_points'];
            $description = $criterion['description'] ?? null;
            $levels = $criterion['rating_levels'] ?? null;
            if ($description !== null && ! is_string($description)) {
                throw ValidationException::withMessages(['criteria' => 'Criterion descriptions must be plain text.']);
            }
            if ($levels !== null && ! is_array($levels)) {
                throw ValidationException::withMessages(['criteria' => 'Criterion rating levels must be a structured list.']);
            }
            $sumCents += (int) round((float) $maximum * 100);
            $criteria[] = ['title' => trim($criterion['title']), 'description' => $description, 'maximum_points' => $maximum, 'rating_levels' => $levels];
        }
        if (! is_numeric($data['maximum_points'] ?? null) || $sumCents !== (int) round((float) $data['maximum_points'] * 100)) {
            throw ValidationException::withMessages(['maximum_points' => 'The rubric maximum must equal the sum of all criterion maximums.']);
        }

        return $criteria;
    }

    /** @param list<array{title:string,description:?string,maximum_points:int|float|string,rating_levels:array<mixed>|null}> $criteria */
    private function saveRubricCriteria(AssignmentRubricVersion $version, array $criteria): void
    {
        foreach ($criteria as $index => $criterion) {
            AssignmentRubricCriterion::query()->create([
                'rubric_version_id' => $version->getKey(), 'position' => $index + 1,
                'title' => trim((string) $criterion['title']), 'description' => $criterion['description'] ?? null,
                'maximum_points' => $criterion['maximum_points'], 'rating_levels' => $criterion['rating_levels'] ?? null,
            ]);
        }
    }

    /** @param array<string,mixed> $data */
    public function createDraft(array $data, User $actor): Assignment
    {
        $this->authorize('assignments.manage');
        $tenantId = (int) $this->context->tenant()->getKey();
        $membership = $this->context->membership();
        $offering = CourseOffering::query()->where('institution_id', $membership->institution_id)->find($data['offering_id'] ?? null);
        if (! $offering || in_array($offering->status, ['cancelled', 'completed'], true)) {
            throw ValidationException::withMessages(['offering_id' => 'Select an active course offering in this institution.']);
        }
        $sectionIds = array_values(array_unique(array_map('intval', $data['section_ids'] ?? [])));
        if ($sectionIds === []) {
            throw ValidationException::withMessages(['section_ids' => 'Choose at least one authorized section.']);
        }
        $sections = CourseSection::query()->where('offering_id', $offering->getKey())->whereIn('id', $sectionIds)->get();
        if ($sections->count() !== count($sectionIds)) {
            throw ValidationException::withMessages(['section_ids' => 'Every selected section must belong to the selected offering.']);
        }
        $this->assertSectionsAuthorized($sectionIds, $actor);
        $rubricVersion = null;
        if (filled($data['rubric_version_id'] ?? null)) {
            $rubricVersion = AssignmentRubricVersion::query()->with('rubric')->where('status', 'published')->whereHas('rubric', fn ($query) => $query->where('institution_id', $membership->institution_id))->find($data['rubric_version_id']);
            if (! $rubricVersion) {
                throw ValidationException::withMessages(['rubric_version_id' => 'Choose a published rubric belonging to this institution.']);
            }
            if (filled($data['maximum_points'] ?? null) && (int) round((float) $data['maximum_points'] * 100) !== (int) round((float) $rubricVersion->maximum_points * 100)) {
                throw ValidationException::withMessages(['maximum_points' => 'The assignment maximum must match the selected rubric.']);
            }
        }
        $timezone = (string) ($data['timezone'] ?? Institution::query()->findOrFail($membership->institution_id)->timezone ?: 'UTC');
        try {
            new \DateTimeZone($timezone);
        } catch (\Throwable) {
            throw ValidationException::withMessages(['timezone' => 'Select a valid IANA timezone.']);
        }
        $availableAt = $this->localInstant($data['available_at'] ?? null, $timezone);
        $dueAt = $this->localInstant($data['due_at'] ?? null, $timezone);
        $lateUntil = $this->localInstant($data['late_until'] ?? null, $timezone);
        if ($availableAt && $dueAt && $availableAt->greaterThanOrEqualTo($dueAt)) {
            throw ValidationException::withMessages(['due_at' => 'The due date must be after the opening date.']);
        }
        if ($lateUntil && $dueAt && $lateUntil->lessThan($dueAt)) {
            throw ValidationException::withMessages(['late_until' => 'The late cutoff cannot be before the due date.']);
        }

        return DB::transaction(function () use ($data, $actor, $tenantId, $membership, $offering, $sectionIds, $timezone, $availableAt, $dueAt, $lateUntil, $rubricVersion): Assignment {
            $assignment = Assignment::query()->create([
                'institution_id' => $membership->institution_id, 'offering_id' => $offering->getKey(), 'rubric_version_id' => $rubricVersion?->getKey(),
                'created_by' => $actor->getKey(), 'title' => trim((string) $data['title']),
                'assignment_type' => $data['assignment_type'], 'category' => $data['category'] ?? null,
                'summary' => $data['summary'] ?? null, 'instructions' => $data['instructions'] ?? null,
                'submission_mode' => $data['submission_mode'], 'collaboration_mode' => $data['collaboration_mode'] ?? 'individual', 'maximum_attempts' => (int) ($data['maximum_attempts'] ?? 1),
                'maximum_points' => $rubricVersion !== null ? $rubricVersion->maximum_points : ($data['maximum_points'] ?? null), 'timezone' => $timezone,
                'available_at' => $availableAt, 'due_at' => $dueAt, 'late_until' => $lateUntil,
                'status' => 'draft', 'review_required' => (bool) ($data['review_required'] ?? false), 'current_version' => 0,
            ]);
            foreach ($sectionIds as $sectionId) {
                DB::table('assignment_sections')->insert(['tenant_id' => $tenantId, 'assignment_id' => $assignment->getKey(), 'section_id' => $sectionId, 'created_at' => now(), 'updated_at' => now()]);
            }
            $this->audit->record('assignments.draft.created', $actor, $tenantId, $assignment, ['assignment_id' => $assignment->getKey(), 'section_count' => count($sectionIds)]);

            return $assignment;
        }, 3);
    }

    public function sendForReview(Assignment $assignment, User $actor): Assignment
    {
        $this->authorize('assignments.manage');
        $this->assertOwned($assignment);

        return DB::transaction(function () use ($assignment, $actor): Assignment {
            $locked = Assignment::query()->lockForUpdate()->findOrFail($assignment->getKey());
            if ($locked->status !== 'draft') {
                throw ValidationException::withMessages(['assignment' => 'Only a draft assignment can be sent for review.']);
            }
            $locked->status = $locked->review_required ? 'under_review' : 'approved';
            $locked->save();
            $this->audit->record('assignments.review.requested', $actor, (int) $locked->tenant_id, $locked, ['assignment_id' => $locked->getKey()]);

            return $locked;
        }, 3);
    }

    public function decideReview(Assignment $assignment, User $actor, bool $approve, ?string $reason = null): Assignment
    {
        $this->authorize('assignments.review');
        $this->assertOwned($assignment);
        if ((int) $assignment->created_by === (int) $actor->getKey()) {
            throw ValidationException::withMessages(['assignment' => 'The author cannot independently review their own assignment.']);
        }

        return DB::transaction(function () use ($assignment, $actor, $approve, $reason): Assignment {
            $locked = Assignment::query()->lockForUpdate()->findOrFail($assignment->getKey());
            if ($locked->status !== 'under_review') {
                throw ValidationException::withMessages(['assignment' => 'This assignment is not awaiting review.']);
            }
            $locked->status = $approve ? 'approved' : 'draft';
            $locked->reviewed_by = $actor->getKey();
            $locked->reviewed_at = now();
            $locked->save();
            $this->audit->record($approve ? 'assignments.review.approved' : 'assignments.review.returned', $actor, (int) $locked->tenant_id, $locked, ['assignment_id' => $locked->getKey(), 'reason_present' => filled($reason)]);

            return $locked;
        }, 3);
    }

    public function publish(Assignment $assignment, User $actor): Assignment
    {
        $this->authorize('assignments.publish');
        $this->assertOwned($assignment);

        return DB::transaction(function () use ($assignment, $actor): Assignment {
            $locked = Assignment::query()->lockForUpdate()->findOrFail($assignment->getKey());
            if ($locked->status !== 'approved') {
                throw ValidationException::withMessages(['assignment' => 'An approved assignment is required before publication.']);
            }
            if ($locked->available_at && $locked->available_at->isFuture()) {
                $locked->status = 'scheduled';
            } else {
                $locked->status = 'published';
                $locked->published_at = now();
            }
            $locked->current_version++;
            $locked->save();
            $sections = DB::table('assignment_sections')->where('tenant_id', $locked->tenant_id)->where('assignment_id', $locked->getKey())->pluck('section_id')->all();
            $snapshot = $this->snapshot($locked, $sections);
            AssignmentVersion::query()->create(['assignment_id' => $locked->getKey(), 'version_number' => $locked->current_version, 'snapshot' => $snapshot, 'status' => $locked->status, 'created_by' => $actor->getKey(), 'published_at' => $locked->published_at]);
            if ($locked->status === 'published') {
                $this->snapshotRecipients($locked, $sections);
                $this->outbox($locked, 'assignment.published', 'assignment-published:'.$locked->getKey().':'.$locked->current_version, ['assignment_id' => $locked->getKey(), 'version' => $locked->current_version]);
            }
            $this->audit->record('assignments.published', $actor, (int) $locked->tenant_id, $locked, ['assignment_id' => $locked->getKey(), 'version' => $locked->current_version, 'status' => $locked->status]);

            return $locked->refresh();
        }, 3);
    }

    public function cloneAsDraft(Assignment $source, User $actor): Assignment
    {
        $this->authorize('assignments.manage');
        $this->assertOwned($source);
        $sectionIds = DB::table('assignment_sections')->where('tenant_id', $source->tenant_id)->where('assignment_id', $source->getKey())
            ->orderBy('section_id')->pluck('section_id')->map(static fn ($id): int => (int) $id)->all();
        $this->assertSectionsAuthorized($sectionIds, $actor);

        $version = $source->versions()->where('version_number', $source->current_version)->first();
        $snapshot = $version->snapshot ?? $this->snapshot($source, $sectionIds);
        $title = mb_substr(trim((string) ($snapshot['title'] ?? $source->title)).' (copy)', 0, 200);
        $copy = $this->createDraft([
            'offering_id' => (int) $source->offering_id,
            'section_ids' => $sectionIds,
            'title' => $title,
            'assignment_type' => (string) ($snapshot['type'] ?? $source->assignment_type),
            'category' => $snapshot['category'] ?? $source->category,
            'summary' => $snapshot['summary'] ?? $source->summary,
            'instructions' => $snapshot['instructions'] ?? $source->instructions,
            'submission_mode' => (string) ($snapshot['submission_mode'] ?? $source->submission_mode),
            'collaboration_mode' => (string) ($snapshot['collaboration_mode'] ?? $source->collaboration_mode),
            'maximum_attempts' => (int) ($snapshot['maximum_attempts'] ?? $source->maximum_attempts),
            'maximum_points' => $snapshot['maximum_points'] ?? $source->maximum_points,
            'rubric_version_id' => $snapshot['rubric_version_id'] ?? $source->rubric_version_id,
            'timezone' => (string) ($snapshot['timezone'] ?? $source->timezone),
            'available_at' => null,
            'due_at' => null,
            'late_until' => null,
            'review_required' => (bool) $source->review_required,
        ], $actor);

        $this->audit->record('assignments.copied_as_draft', $actor, (int) $source->tenant_id, $copy, [
            'source_assignment_id' => (int) $source->getKey(),
            'source_version' => (int) $source->current_version,
            'section_count' => count($sectionIds),
            'deadlines_cleared' => true,
        ]);

        return $copy;
    }

    /** @return array{published:int, closed:int} */
    public function processAvailabilityTransitions(): array
    {
        $tenantId = (int) $this->context->tenant()->getKey();
        $counts = ['published' => 0, 'closed' => 0];
        $scheduledIds = Assignment::query()->where('tenant_id', $tenantId)->where('status', 'scheduled')->whereNotNull('available_at')->where('available_at', '<=', now())->orderBy('id')->limit(200)->pluck('id')->all();
        foreach ($scheduledIds as $assignmentId) {
            $changed = DB::transaction(function () use ($tenantId, $assignmentId): bool {
                $assignment = Assignment::query()->lockForUpdate()->find($assignmentId);
                if (! $assignment || $assignment->status !== 'scheduled' || ! $assignment->available_at || $assignment->available_at->isFuture()) {
                    return false;
                }
                $assignment->forceFill(['status' => 'published', 'published_at' => now()])->save();
                AssignmentVersion::query()->where('assignment_id', $assignment->getKey())->where('version_number', $assignment->current_version)->update(['status' => 'published', 'published_at' => now()]);
                $sections = DB::table('assignment_sections')->where('tenant_id', $tenantId)->where('assignment_id', $assignment->getKey())->pluck('section_id')->all();
                $this->snapshotRecipients($assignment, $sections);
                $this->outbox($assignment, 'assignment.published', 'assignment-published:'.$assignment->getKey().':'.$assignment->current_version, ['assignment_id' => $assignment->getKey(), 'version' => $assignment->current_version]);
                $this->audit->record('assignments.published', null, $tenantId, $assignment, ['assignment_id' => $assignment->getKey(), 'version' => $assignment->current_version, 'scheduled' => true]);

                return true;
            }, 3);
            if ($changed) {
                $counts['published']++;
            }
        }

        $closableIds = Assignment::query()->where('tenant_id', $tenantId)->where('status', 'published')
            ->whereRaw('COALESCE(late_until, due_at) IS NOT NULL AND COALESCE(late_until, due_at) <= ?', [now()->toDateTimeString()])
            ->orderBy('id')->limit(200)->pluck('id')->all();
        foreach ($closableIds as $assignmentId) {
            $changed = DB::transaction(function () use ($tenantId, $assignmentId): bool {
                $assignment = Assignment::query()->lockForUpdate()->find($assignmentId);
                if (! $assignment) {
                    return false;
                }
                $cutoff = $assignment->late_until ?? $assignment->due_at;
                if ($assignment->status !== 'published' || ! $cutoff || $cutoff->isFuture()) {
                    return false;
                }
                $assignment->forceFill(['status' => 'closed', 'closed_at' => now()])->save();
                $this->audit->record('assignments.closed', null, $tenantId, $assignment, ['assignment_id' => $assignment->getKey()]);

                return true;
            }, 3);
            if ($changed) {
                $counts['closed']++;
            }
        }

        return $counts;
    }

    /** @return array<string,mixed> */
    public function studentWorkspace(TenantMembership $membership): array
    {
        $this->authorize('assignments.view');
        $student = SisStudent::query()->where('institution_id', $membership->institution_id)->where('user_id', $membership->user_id)->where('status', 'active')->first();
        if (! $student) {
            return ['assignments' => [], 'metrics' => ['total' => 0, 'submitted' => 0, 'upcoming' => 0, 'graded' => 0]];
        }
        $recipients = AssignmentRecipient::query()->with(['assignment.offering.course', 'submission.attempts.grades'])
            ->where('membership_id', $membership->getKey())->where('student_id', $student->getKey())->where('status', 'eligible')
            ->whereHas('assignment', fn ($query) => $query->whereIn('status', ['published', 'closed'])->where(fn ($dates) => $dates->whereNull('available_at')->orWhere('available_at', '<=', now())))
            ->orderBy('effective_due_at')->paginate(30);
        $items = $recipients->getCollection()->map(function (AssignmentRecipient $recipient) use ($membership): array {
            $assignment = $recipient->assignment;
            $submission = $this->submissionForRecipient($recipient, $membership);
            $latestAttempt = $submission?->attempts->first();
            $grade = $latestAttempt?->grades->first();
            $dueAt = $recipient->effective_due_at ?? $assignment->due_at;
            $submissionCutoff = $recipient->effective_due_at ?? $assignment->late_until ?? $dueAt;
            $lateWindow = $dueAt !== null && now()->greaterThan($dueAt)
                && ($submissionCutoff === null || now()->lessThanOrEqualTo($submissionCutoff));
            $status = $submission->status ?? ($submissionCutoff !== null && now()->greaterThan($submissionCutoff) ? 'missing' : 'not_started');

            return [
                'recipient_id' => (int) $recipient->getKey(), 'assignment_id' => (int) $assignment->getKey(),
                'title' => $assignment->title, 'course' => $assignment->offering->course->title,
                'type' => $assignment->assignment_type, 'status' => $status,
                'due_at' => $dueAt?->toIso8601String(),
                'late' => $lateWindow,
                'receipt' => $latestAttempt?->receipt_reference,
                'grade_released' => $grade?->status === 'released',
                'score_points' => $grade?->status === 'released' ? (float) $grade->score_points : null,
                'feedback' => in_array($grade?->status, ['released', 'returned_for_revision'], true) ? $grade->learner_feedback : null,
            ];
        })->all();

        return ['assignments' => $items, 'pagination' => ['page' => $recipients->currentPage(), 'last_page' => $recipients->lastPage()], 'metrics' => [
            'total' => $recipients->total(),
            'submitted' => AssignmentSubmission::query()->where('tenant_id', $membership->tenant_id)->whereIn('status', ['submitted', 'under_review', 'graded', 'returned_for_revision', 'finalized'])->where(function ($query) use ($membership): void {
                $query->whereIn('recipient_id', AssignmentRecipient::query()->where('membership_id', $membership->getKey())->select('id'))
                    ->orWhereIn('group_id', AssignmentGroupMember::query()->where('membership_id', $membership->getKey())->whereNull('left_at')->select('group_id'));
            })->count(),
            'upcoming' => AssignmentRecipient::query()->where('membership_id', $membership->getKey())->where('status', 'eligible')->where('effective_due_at', '>', now())->count(),
            'graded' => AssignmentGrade::query()->where('tenant_id', $membership->tenant_id)->where('status', 'released')->whereHas('attempt.submission', fn ($query) => $query->where(function ($scope) use ($membership): void {
                $scope->whereIn('recipient_id', AssignmentRecipient::query()->where('membership_id', $membership->getKey())->select('id'))
                    ->orWhereIn('group_id', AssignmentGroupMember::query()->where('membership_id', $membership->getKey())->whereNull('left_at')->select('group_id'));
            }))->count(),
        ]];
    }

    public function canSubmit(AssignmentRecipient $recipient, ?AssignmentSubmission $submission = null): bool
    {
        $assignment = $recipient->assignment;
        if (! in_array($assignment->status, ['published', 'closed'], true)
            || ($assignment->available_at !== null && $assignment->available_at->isFuture())) {
            return false;
        }
        if ($submission !== null && ! in_array($submission->status, ['draft', 'returned_for_revision'], true)) {
            return false;
        }
        if ((int) ($submission->attempt_count ?? 0) >= (int) $assignment->maximum_attempts) {
            return false;
        }

        $dueAt = $recipient->effective_due_at ?? $assignment->due_at;
        $cutoff = $recipient->effective_due_at ?? $assignment->late_until ?? $dueAt;

        return $cutoff === null || now()->lessThanOrEqualTo($cutoff);
    }

    public function submissionForRecipient(AssignmentRecipient $recipient, TenantMembership $membership): ?AssignmentSubmission
    {
        $assignment = $recipient->assignment;
        if ($assignment->collaboration_mode !== 'group') {
            return $recipient->submission;
        }
        $groupId = AssignmentGroupMember::query()->where('membership_id', $membership->getKey())->whereNull('left_at')
            ->whereHas('group', fn ($query) => $query->where('assignment_id', $assignment->getKey())->where('section_id', $recipient->section_id))->value('group_id');
        if ($groupId === null) {
            return null;
        }

        return AssignmentSubmission::query()->where('assignment_id', $assignment->getKey())->where('group_id', $groupId)->first();
    }

    /** @param list<int> $membershipIds */
    public function createGroup(Assignment $assignment, User $actor, int $sectionId, string $name, array $membershipIds, ?int $leaderMembershipId = null): AssignmentGroup
    {
        $this->authorize('assignments.groups.manage');
        $this->assertOwned($assignment);
        if ($assignment->collaboration_mode !== 'group' || ! in_array($assignment->status, ['draft', 'approved', 'scheduled', 'published'], true)) {
            throw ValidationException::withMessages(['assignment' => 'Groups are available only for active group assignments before closure.']);
        }
        $sectionIds = DB::table('assignment_sections')->where('tenant_id', $assignment->tenant_id)->where('assignment_id', $assignment->getKey())->pluck('section_id')->map(static fn ($id): int => (int) $id)->all();
        abort_unless(in_array($sectionId, $sectionIds, true), 404);
        $this->assertSectionsAuthorized([$sectionId], $actor);
        $membershipIds = array_values(array_unique(array_map('intval', $membershipIds)));
        if (count($membershipIds) < 2 || count($membershipIds) > 8 || ($leaderMembershipId !== null && ! in_array($leaderMembershipId, $membershipIds, true))) {
            throw ValidationException::withMessages(['members' => 'Choose two to eight eligible learners and select a leader from that group.']);
        }

        return DB::transaction(function () use ($assignment, $actor, $sectionId, $name, $membershipIds, $leaderMembershipId): AssignmentGroup {
            Assignment::query()->lockForUpdate()->findOrFail($assignment->getKey());
            $eligible = AssignmentRecipient::query()->where('assignment_id', $assignment->getKey())->where('section_id', $sectionId)->where('status', 'eligible')->whereIn('membership_id', $membershipIds)->orderBy('membership_id')->lockForUpdate()->pluck('membership_id')->map(static fn ($id): int => (int) $id)->all();
            if (count($eligible) !== count($membershipIds)) {
                throw ValidationException::withMessages(['members' => 'Every group member must be an eligible learner in the selected assignment section.']);
            }
            $alreadyGrouped = AssignmentGroupMember::query()->whereIn('membership_id', $membershipIds)->whereNull('left_at')
                ->whereHas('group', fn ($query) => $query->where('assignment_id', $assignment->getKey())->where('section_id', $sectionId))->lockForUpdate()->exists();
            if ($alreadyGrouped) {
                throw ValidationException::withMessages(['members' => 'A selected learner is already in an active group for this assignment section.']);
            }
            $group = AssignmentGroup::query()->create(['assignment_id' => $assignment->getKey(), 'section_id' => $sectionId, 'name' => trim($name), 'leader_membership_id' => $leaderMembershipId]);
            foreach ($membershipIds as $membershipId) {
                AssignmentGroupMember::query()->create(['group_id' => $group->getKey(), 'membership_id' => $membershipId, 'joined_at' => now()]);
            }
            $this->audit->record('assignments.group.created', $actor, (int) $assignment->tenant_id, $group, ['assignment_id' => $assignment->getKey(), 'member_count' => count($membershipIds)]);

            return $group->load('members.membership.user');
        }, 3);
    }

    private function groupForRecipient(Assignment $assignment, AssignmentRecipient $recipient, bool $lock): ?AssignmentGroup
    {
        $query = AssignmentGroup::query()->where('assignment_id', $assignment->getKey())->where('section_id', $recipient->section_id)
            ->whereHas('members', fn ($members) => $members->where('membership_id', $this->context->membership()->getKey())->whereNull('left_at'));

        return $lock ? $query->lockForUpdate()->first() : $query->first();
    }

    /** @param array<string, mixed> $data */
    public function createOrUpdateDraft(AssignmentRecipient $recipient, User $actor, array $data, ?string $idempotencyKey = null): AssignmentAttempt
    {
        $this->authorize('assignments.submission.create');
        $this->assertOwned($recipient);
        if ((int) $recipient->membership_id !== (int) $this->context->membership()->getKey() || $recipient->status !== 'eligible') {
            throw new ModelNotFoundException;
        }
        $this->validateResponse($data);

        return DB::transaction(function () use ($recipient, $actor, $data, $idempotencyKey): AssignmentAttempt {
            $assignment = Assignment::query()->lockForUpdate()->findOrFail($recipient->assignment_id);
            if (! in_array($assignment->status, ['published', 'closed'], true) || ($assignment->available_at && $assignment->available_at->isFuture())) {
                throw ValidationException::withMessages(['assignment' => 'This assignment is not currently available.']);
            }
            $group = $assignment->collaboration_mode === 'group' ? $this->groupForRecipient($assignment, $recipient, true) : null;
            if ($assignment->collaboration_mode === 'group' && $group === null) {
                throw ValidationException::withMessages(['submission' => 'Join an instructor-approved group before working on this assignment.']);
            }
            if ($group?->frozen_at !== null) {
                throw ValidationException::withMessages(['submission' => 'The group membership was frozen when work was submitted.']);
            }
            $submissionQuery = AssignmentSubmission::query()->where('assignment_id', $assignment->getKey());
            $group ? $submissionQuery->where('group_id', $group->getKey()) : $submissionQuery->where('recipient_id', $recipient->getKey());
            $submission = $submissionQuery->lockForUpdate()->first();
            if (! $submission) {
                $submission = AssignmentSubmission::query()->create(['assignment_id' => $assignment->getKey(), 'recipient_id' => $group ? null : $recipient->getKey(), 'group_id' => $group?->getKey(), 'status' => 'draft', 'updated_by' => $actor->getKey()]);
            }
            if ($submission->status === 'submitted' || $submission->status === 'under_review' || $submission->status === 'graded' || $submission->status === 'finalized') {
                throw ValidationException::withMessages(['submission' => 'Submitted work is immutable. Request an authorized resubmission before creating another attempt.']);
            }
            if ($submission->attempt_count >= $assignment->maximum_attempts) {
                throw ValidationException::withMessages(['submission' => 'The maximum number of attempts has been reached.']);
            }
            $attempt = AssignmentAttempt::query()->where('submission_id', $submission->getKey())->where('status', 'draft')->lockForUpdate()->first();
            $attemptWasCreated = false;
            if (! $attempt) {
                $attempt = AssignmentAttempt::query()->create(['submission_id' => $submission->getKey(), 'attempt_number' => $submission->attempt_count + 1, 'status' => 'draft', 'idempotency_key' => $idempotencyKey]);
                $attemptWasCreated = true;
            }
            if (! $group && $attempt->idempotency_key && $idempotencyKey && $attempt->idempotency_key !== $idempotencyKey) {
                throw ValidationException::withMessages(['submission' => 'A draft attempt already exists with a different idempotency key.']);
            }
            $responseChanged = $attemptWasCreated;
            if (array_key_exists('response_text', $data)) {
                $attempt->response_text = $data['response_text'];
                $responseChanged = true;
            }
            if (array_key_exists('response_url', $data)) {
                $attempt->response_url = $data['response_url'];
                $responseChanged = true;
            }
            $attempt->idempotency_key = $attempt->idempotency_key ?? $idempotencyKey;
            $attempt->content_sha256 = hash('sha256', json_encode(['text' => $attempt->response_text, 'url' => $attempt->response_url], JSON_THROW_ON_ERROR));
            $attempt->save();
            if ($responseChanged) {
                $this->recordAttemptRevision($attempt, $actor, ['response_text' => $attempt->response_text, 'response_url' => $attempt->response_url]);
            }
            $submission->status = 'draft';
            $submission->updated_by = $actor->getKey();
            $submission->lock_version++;
            $submission->save();

            return $attempt->refresh();
        }, 3);
    }

    public function submit(AssignmentRecipient $recipient, User $actor, int $attemptId): AssignmentAttempt
    {
        $this->authorize('assignments.submission.create');
        $this->assertOwned($recipient);
        if ((int) $recipient->membership_id !== (int) $this->context->membership()->getKey() || $recipient->status !== 'eligible') {
            throw new ModelNotFoundException;
        }

        return DB::transaction(function () use ($recipient, $actor, $attemptId): AssignmentAttempt {
            $assignment = Assignment::query()->lockForUpdate()->findOrFail($recipient->assignment_id);
            $lockedRecipient = AssignmentRecipient::query()->lockForUpdate()->findOrFail($recipient->getKey());
            if ((int) $lockedRecipient->membership_id !== (int) $this->context->membership()->getKey() || $lockedRecipient->status !== 'eligible') {
                throw new ModelNotFoundException;
            }
            $group = $assignment->collaboration_mode === 'group' ? $this->groupForRecipient($assignment, $lockedRecipient, true) : null;
            if ($assignment->collaboration_mode === 'group' && $group === null) {
                throw new ModelNotFoundException;
            }
            $submissionQuery = AssignmentSubmission::query()->where('assignment_id', $assignment->getKey());
            $group ? $submissionQuery->where('group_id', $group->getKey()) : $submissionQuery->where('recipient_id', $lockedRecipient->getKey());
            $submission = $submissionQuery->lockForUpdate()->firstOrFail();
            $attempt = AssignmentAttempt::query()->where('submission_id', $submission->getKey())->lockForUpdate()->findOrFail($attemptId);
            if ($attempt->status === 'submitted' && $attempt->receipt_reference !== null) {
                return $attempt;
            }
            if ($attempt->status !== 'draft' || ! in_array($assignment->status, ['published', 'closed'], true)) {
                throw ValidationException::withMessages(['submission' => 'Only an open draft can be submitted.']);
            }
            if ($assignment->available_at && $assignment->available_at->isFuture()) {
                throw ValidationException::withMessages(['submission' => 'The assignment has not opened yet.']);
            }
            if ($attempt->response_text === null && $attempt->response_url === null) {
                if (! $attempt->artifacts()->where('scan_status', 'clean')->exists()) {
                    throw ValidationException::withMessages(['response_text' => 'Add a response or at least one safely scanned file before submitting.']);
                }
            }
            if ($attempt->response_url && ! $this->isSafeReferenceUrl($attempt->response_url)) {
                throw ValidationException::withMessages(['response_url' => 'Enter a public HTTP or HTTPS reference URL. Private and local-network targets are not accepted.']);
            }
            if ($attempt->artifacts()->where('scan_status', '!=', 'clean')->exists()) {
                throw ValidationException::withMessages(['artifacts' => 'All submission files must pass malware scanning before submission.']);
            }
            $dueAt = $lockedRecipient->effective_due_at ?? $assignment->due_at;
            $late = $dueAt !== null && now()->greaterThan($dueAt);
            $cutoff = $lockedRecipient->effective_due_at ?? $assignment->late_until ?? $dueAt;
            if ($cutoff !== null && now()->greaterThan($cutoff)) {
                throw ValidationException::withMessages(['submission' => 'The submission window has closed.']);
            }
            if ($submission->attempt_count >= $assignment->maximum_attempts) {
                throw ValidationException::withMessages(['submission' => 'The maximum number of attempts has been reached.']);
            }
            $submittedAt = now();
            $reference = (string) Str::uuid();
            $fileCount = $attempt->artifacts()->count();
            $revisionNumber = $this->latestAttemptRevisionNumber($attempt);
            $groupMembershipIds = $group ? AssignmentGroupMember::query()->where('group_id', $group->getKey())->whereNull('left_at')->orderBy('membership_id')->pluck('membership_id')->map(static fn ($id): int => (int) $id)->all() : [];
            $attempt->forceFill([
                'status' => 'submitted', 'submitted_at' => $submittedAt, 'is_late' => $late,
                'effective_due_at' => $dueAt, 'receipt_reference' => $reference,
                'receipt_snapshot' => ['assignment_id' => (int) $assignment->getKey(), 'attempt_number' => (int) $attempt->attempt_number, 'revision_number' => $revisionNumber, 'content_sha256' => $attempt->content_sha256, 'membership_id' => $group ? null : (int) $lockedRecipient->membership_id, 'group_id' => $group?->getKey(), 'group_membership_ids' => $groupMembershipIds, 'submitted_at' => $submittedAt->toIso8601String(), 'status' => 'submitted', 'file_count' => $fileCount, 'assignment_version' => (int) $assignment->current_version],
            ])->save();
            $submission->forceFill(['status' => 'submitted', 'attempt_count' => $submission->attempt_count + 1, 'submitted_at' => $submittedAt, 'updated_by' => $actor->getKey(), 'lock_version' => $submission->lock_version + 1, 'group_membership_snapshot' => $group ? $groupMembershipIds : null])->save();
            if ($group) {
                $group->forceFill(['frozen_at' => $submittedAt])->save();
            }
            $this->outbox($assignment, $late ? 'assignment.submission.late' : 'assignment.submission.received', 'submission-received:'.$attempt->getKey(), ['assignment_id' => $assignment->getKey(), 'attempt_id' => $attempt->getKey(), 'membership_id' => $group ? null : $lockedRecipient->membership_id, 'group_id' => $group?->getKey(), 'late' => $late]);
            $this->audit->record('assignments.submission.submitted', $actor, (int) $assignment->tenant_id, $attempt, ['assignment_id' => $assignment->getKey(), 'attempt' => $attempt->attempt_number, 'late' => $late, 'file_count' => $fileCount]);

            return $attempt->refresh();
        }, 3);
    }

    /** @param list<UploadedFile> $files */
    public function attachArtifacts(AssignmentRecipient $recipient, User $actor, array $files): AssignmentAttempt
    {
        $this->authorize('assignments.submission.create');
        $this->assertOwned($recipient);
        if ((int) $recipient->membership_id !== (int) $this->context->membership()->getKey() || $recipient->status !== 'eligible') {
            throw new ModelNotFoundException;
        }
        if ($files === [] || count($files) > 5) {
            throw ValidationException::withMessages(['files' => 'Upload between one and five files at a time.']);
        }
        $attempt = $this->createOrUpdateDraft($recipient, $actor, [], null);
        $tenantId = (int) $recipient->tenant_id;
        foreach ($files as $file) {
            $mime = (string) $file->getMimeType();
            $extension = match ($mime) {
                'application/pdf' => 'pdf',
                'application/msword' => 'doc',
                'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
                'application/vnd.oasis.opendocument.text' => 'odt',
                'text/plain' => 'txt',
                'image/png' => 'png',
                'image/jpeg' => 'jpg',
                default => null,
            };
            if ($extension === null) {
                throw ValidationException::withMessages(['files' => 'A file type is not supported. Use PDF, Word, ODT, plain text, PNG or JPEG.']);
            }
            $path = 'assignments/'.$tenantId.'/quarantine/'.Str::uuid().'.'.$extension;
            if (! Storage::disk('local')->putFileAs(dirname($path), $file, basename($path))) {
                throw new \RuntimeException('The private assignment quarantine could not store the upload.');
            }
            $absolutePath = Storage::disk('local')->path($path);
            $sha256 = hash_file('sha256', $absolutePath);
            if (! is_string($sha256)) {
                Storage::disk('local')->delete($path);
                throw new \RuntimeException('The private assignment upload could not be verified.');
            }
            try {
                $artifact = DB::transaction(function () use ($attempt, $actor, $path, $file, $mime, $sha256): AssignmentArtifact {
                    $artifact = AssignmentArtifact::query()->create([
                        'attempt_id' => $attempt->getKey(), 'private_path' => $path,
                        'original_name' => mb_substr(trim((string) preg_replace('/[\x00-\x1F\x7F\/\\\\]/u', '_', basename($file->getClientOriginalName()))), 0, 255),
                        'mime_type' => $mime, 'size_bytes' => (int) $file->getSize(), 'sha256' => $sha256,
                        'scan_status' => 'quarantined', 'uploaded_by' => $actor->getKey(),
                    ]);
                    $this->recordAttemptRevision($attempt, $actor, [
                        'response_text' => $attempt->response_text, 'response_url' => $attempt->response_url,
                        'artifact_ids' => $attempt->artifacts()->pluck('id')->map(static fn ($id): int => (int) $id)->all(),
                    ]);

                    return $artifact;
                }, 3);
            } catch (\Throwable $exception) {
                Storage::disk('local')->delete($path);
                throw $exception;
            }
            ScanAssignmentArtifact::dispatch($tenantId, (int) $artifact->getKey())->afterCommit();
            $this->audit->record('assignments.artifact.quarantined', $actor, $tenantId, $artifact, [
                'artifact_id' => $artifact->getKey(), 'mime_type' => $mime, 'size_bytes' => $artifact->size_bytes,
            ]);
        }

        return $attempt;
    }

    /** @param array<string, mixed> $snapshot */
    private function recordAttemptRevision(AssignmentAttempt $attempt, User $actor, array $snapshot): void
    {
        $revisionNumber = $this->nextAttemptRevisionNumber($attempt);
        $encoded = json_encode($snapshot, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        AssignmentAttemptRevision::query()->create([
            'attempt_id' => $attempt->getKey(), 'revision_number' => $revisionNumber,
            'content_snapshot' => $snapshot, 'content_sha256' => hash('sha256', $encoded),
            'saved_by' => $actor->getKey(), 'saved_at' => now(),
        ]);
    }

    private function nextAttemptRevisionNumber(AssignmentAttempt $attempt): int
    {
        return $this->latestAttemptRevisionNumber($attempt) + 1;
    }

    private function latestAttemptRevisionNumber(AssignmentAttempt $attempt): int
    {
        // A locking read is required here: MySQL REPEATABLE READ can otherwise
        // return a transaction's older MAX() snapshot after the attempt lock was
        // acquired, causing concurrent saves to choose the same unique revision.
        $latest = AssignmentAttemptRevision::query()
            ->where('attempt_id', $attempt->getKey())
            ->orderByDesc('revision_number')
            ->lockForUpdate()
            ->value('revision_number');

        return (int) $latest;
    }

    public function authorizeArtifactDownload(AssignmentArtifact $artifact, User $actor): string
    {
        $this->authorize('assignments.view');
        $this->assertOwned($artifact);
        abort_unless($artifact->scan_status === 'clean', 404);
        $attempt = $artifact->attempt()->firstOrFail();
        $submission = $attempt->submission()->firstOrFail();
        $recipient = $submission->recipient;
        $membership = $this->context->membership();
        $snapshotMembershipIds = $attempt->receipt_snapshot['group_membership_ids'] ?? $submission->group_membership_snapshot ?? [];
        $isActiveGroupMember = $submission->group_id !== null && AssignmentGroupMember::query()->where('group_id', $submission->group_id)->where('membership_id', $membership->getKey())->whereNull('left_at')->exists();
        $isLearner = ($recipient !== null && (int) $recipient->membership_id === (int) $membership->getKey()) || $isActiveGroupMember || in_array((int) $membership->getKey(), array_map('intval', $snapshotMembershipIds), true);
        if (! $isLearner) {
            abort_unless($membership->hasPermission('assignments.submission.view'), 404);
            $assignment = $submission->assignment()->firstOrFail();
            $sections = DB::table('assignment_sections')->where('tenant_id', $artifact->tenant_id)->where('assignment_id', $assignment->getKey())->pluck('section_id')->all();
            $this->assertSectionsAuthorized($sections, $actor);
        }
        $expectedPrefix = 'assignments/'.$artifact->tenant_id.'/quarantine/';
        abort_unless(str_starts_with($artifact->private_path, $expectedPrefix) && Storage::disk('local')->exists($artifact->private_path), 404);
        $absolutePath = Storage::disk('local')->path($artifact->private_path);
        $actualHash = hash_file('sha256', $absolutePath);
        if (! is_string($actualHash) || ! hash_equals($artifact->sha256, $actualHash)) {
            $artifact->forceFill(['scan_status' => 'rejected', 'result_code' => 'content_hash_mismatch'])->save();
            $this->audit->record('assignments.artifact.download_blocked', $actor, (int) $artifact->tenant_id, $artifact, ['artifact_id' => $artifact->getKey(), 'result_code' => 'content_hash_mismatch']);
            abort(404);
        }
        $this->audit->record('assignments.artifact.downloaded', $actor, (int) $artifact->tenant_id, $artifact, ['artifact_id' => $artifact->getKey()]);

        return $artifact->private_path;
    }

    /** @param array<string, mixed> $data */
    public function grade(AssignmentAttempt $attempt, User $actor, array $data): AssignmentGrade
    {
        $this->authorize('assignments.submission.grade');
        $this->assertOwned($attempt);
        $submission = $attempt->submission()->firstOrFail();
        $assignment = $submission->assignment()->firstOrFail();
        $this->assertSectionsAuthorized(DB::table('assignment_sections')->where('tenant_id', $assignment->tenant_id)->where('assignment_id', $assignment->getKey())->pluck('section_id')->all(), $actor);
        if ($attempt->status !== 'submitted') {
            throw ValidationException::withMessages(['attempt' => 'Only submitted work can be graded.']);
        }
        $rubricVersion = $assignment->rubricVersion()->with('criteria')->first();
        $criterionScores = null;
        if ($rubricVersion !== null) {
            $submittedScores = $data['criterion_scores'] ?? null;
            if (! is_array($submittedScores)) {
                throw ValidationException::withMessages(['criterion_scores' => 'Score every rubric criterion before saving the grade.']);
            }
            $expectedIds = $rubricVersion->criteria->map(static fn (AssignmentRubricCriterion $criterion): string => (string) $criterion->getKey())->sort()->values()->all();
            $receivedIds = array_map('strval', array_keys($submittedScores));
            sort($receivedIds);
            if ($expectedIds !== $receivedIds) {
                throw ValidationException::withMessages(['criterion_scores' => 'The submitted criterion set does not match the published rubric.']);
            }
            $scoreCents = 0;
            $criterionScores = [];
            foreach ($rubricVersion->criteria as $criterion) {
                $criterionId = (string) $criterion->getKey();
                $rawScore = $submittedScores[$criterionId] ?? $submittedScores[(int) $criterionId] ?? null;
                if (! is_numeric($rawScore) || (float) $rawScore < 0 || (float) $rawScore > (float) $criterion->maximum_points) {
                    throw ValidationException::withMessages(['criterion_scores.'.$criterionId => 'Each criterion score must be within its published maximum.']);
                }
                $scoreCents += (int) round((float) $rawScore * 100);
                $criterionScores[$criterionId] = number_format((float) $rawScore, 2, '.', '');
            }
            $data['score_points'] = number_format($scoreCents / 100, 2, '.', '');
            $data['maximum_points'] = $rubricVersion->maximum_points;
        } elseif (! is_numeric($data['score_points'] ?? null)) {
            throw ValidationException::withMessages(['score_points' => 'Enter a valid score for this assignment.']);
        }
        if ($assignment->maximum_points !== null && (float) $data['score_points'] > (float) $assignment->maximum_points) {
            throw ValidationException::withMessages(['score_points' => 'The score cannot exceed the assignment maximum.']);
        }

        return DB::transaction(function () use ($attempt, $actor, $data, $assignment, $rubricVersion, $criterionScores): AssignmentGrade {
            $lockedAttempt = AssignmentAttempt::query()->lockForUpdate()->findOrFail($attempt->getKey());
            if ($lockedAttempt->status !== 'submitted') {
                throw ValidationException::withMessages(['attempt' => 'This attempt is no longer awaiting grading.']);
            }
            $grade = AssignmentGrade::query()->where('attempt_id', $lockedAttempt->getKey())->lockForUpdate()->first();
            $expectedVersion = (int) ($data['expected_version'] ?? -1);
            $currentVersion = $grade === null ? 0 : (int) $grade->lock_version;
            if ($expectedVersion !== $currentVersion) {
                throw ValidationException::withMessages(['grade' => 'This draft grade changed after you opened it. Reload the submission before saving again.']);
            }
            if (! $grade) {
                $grade = new AssignmentGrade(['attempt_id' => $lockedAttempt->getKey(), 'marked_by' => $actor->getKey(), 'status' => 'draft', 'lock_version' => 1]);
            }
            if ($grade->status === 'released') {
                throw ValidationException::withMessages(['grade' => 'Released grades are immutable; use the controlled amendment workflow.']);
            }
            $grade->fill(['rubric_version_id' => $rubricVersion?->getKey(), 'criterion_scores' => $criterionScores, 'score_points' => $data['score_points'], 'maximum_points' => $rubricVersion !== null ? $rubricVersion->maximum_points : $assignment->maximum_points, 'learner_feedback' => $data['learner_feedback'] ?? null, 'private_notes' => $data['private_notes'] ?? null, 'marked_by' => $actor->getKey(), 'status' => 'draft', 'lock_version' => $currentVersion === 0 ? 1 : $currentVersion + 1])->save();
            $this->audit->record('assignments.grade.drafted', $actor, (int) $assignment->tenant_id, $grade, ['assignment_id' => $assignment->getKey(), 'attempt_id' => $lockedAttempt->getKey(), 'rubric_version_id' => $rubricVersion?->getKey(), 'criterion_count' => count($criterionScores ?? [])]);

            return $grade;
        }, 3);
    }

    public function releaseGrade(AssignmentGrade $grade, User $actor): AssignmentGrade
    {
        $this->authorize('assignments.grade.release');
        $this->assertOwned($grade);

        return DB::transaction(function () use ($grade, $actor): AssignmentGrade {
            $locked = AssignmentGrade::query()->lockForUpdate()->findOrFail($grade->getKey());
            if ($locked->status !== 'draft' || $locked->score_points === null) {
                throw ValidationException::withMessages(['grade' => 'Only a complete draft grade can be released.']);
            }
            if ((int) $locked->marked_by === (int) $actor->getKey()) {
                throw ValidationException::withMessages(['grade' => 'The marker cannot release their own grade. A separate authorized reviewer must release it.']);
            }
            $locked->forceFill(['status' => 'released', 'released_by' => $actor->getKey(), 'released_at' => now()])->save();
            $submission = $locked->attempt->submission;
            $submission->forceFill(['status' => 'graded', 'updated_by' => $actor->getKey()])->save();
            $this->outbox($submission->assignment, 'assignment.grade.released', 'grade-released:'.$locked->getKey(), ['assignment_id' => $submission->assignment_id, 'attempt_id' => $locked->attempt_id, 'recipient_id' => $submission->recipient_id, 'group_id' => $submission->group_id]);
            $this->audit->record('assignments.grade.released', $actor, (int) $locked->tenant_id, $locked, ['attempt_id' => $locked->attempt_id]);

            return $locked->refresh();
        }, 3);
    }

    public function returnForRevision(AssignmentAttempt $attempt, User $actor, string $feedback): AssignmentGrade
    {
        $this->authorize('assignments.submission.grade');
        $this->assertOwned($attempt);
        $submission = $attempt->submission()->firstOrFail();
        $assignment = $submission->assignment()->firstOrFail();
        $this->assertSectionsAuthorized(DB::table('assignment_sections')->where('tenant_id', $assignment->tenant_id)->where('assignment_id', $assignment->getKey())->pluck('section_id')->all(), $actor);
        if ($attempt->status !== 'submitted') {
            throw ValidationException::withMessages(['attempt' => 'Only a submitted attempt can be returned for revision.']);
        }

        return DB::transaction(function () use ($attempt, $actor, $feedback, $assignment): AssignmentGrade {
            $lockedAttempt = AssignmentAttempt::query()->lockForUpdate()->findOrFail($attempt->getKey());
            $lockedSubmission = AssignmentSubmission::query()->lockForUpdate()->findOrFail($lockedAttempt->submission_id);
            if ($lockedAttempt->status !== 'submitted' || $lockedSubmission->attempt_count >= $assignment->maximum_attempts) {
                throw ValidationException::withMessages(['attempt' => 'The attempt cannot be returned or no attempts remain.']);
            }
            $grade = AssignmentGrade::query()->where('attempt_id', $lockedAttempt->getKey())->lockForUpdate()->first() ?? new AssignmentGrade(['attempt_id' => $lockedAttempt->getKey()]);
            if ($grade->status === 'released') {
                throw ValidationException::withMessages(['grade' => 'A released grade cannot be changed through the revision workflow.']);
            }
            $grade->forceFill(['learner_feedback' => $feedback, 'private_notes' => null, 'marked_by' => $actor->getKey(), 'status' => 'returned_for_revision', 'lock_version' => ((int) $grade->lock_version) + 1])->save();
            $lockedSubmission->forceFill(['status' => 'returned_for_revision', 'updated_by' => $actor->getKey(), 'lock_version' => $lockedSubmission->lock_version + 1])->save();
            $recipient = $lockedSubmission->recipient;
            $this->outbox($assignment, 'assignment.resubmission.requested', 'resubmission-requested:'.$lockedAttempt->getKey().':'.$lockedSubmission->lock_version, ['assignment_id' => $assignment->getKey(), 'recipient_id' => $recipient?->getKey(), 'group_id' => $lockedSubmission->group_id]);
            $this->audit->record('assignments.submission.returned_for_revision', $actor, (int) $assignment->tenant_id, $lockedAttempt, ['assignment_id' => $assignment->getKey(), 'attempt_id' => $lockedAttempt->getKey()]);

            return $grade->refresh();
        }, 3);
    }

    public function requestExtension(AssignmentRecipient $recipient, User $actor, string $reason, CarbonImmutable $requestedDueAt): AssignmentExtension
    {
        $this->authorize('assignments.extension.request');
        $this->assertOwned($recipient);
        if ((int) $recipient->membership_id !== (int) $this->context->membership()->getKey() || $recipient->status !== 'eligible') {
            throw new ModelNotFoundException;
        }

        return DB::transaction(function () use ($recipient, $actor, $reason, $requestedDueAt): AssignmentExtension {
            $lockedRecipient = AssignmentRecipient::query()->lockForUpdate()->findOrFail($recipient->getKey());
            $baseline = $lockedRecipient->effective_due_at ?? $lockedRecipient->assignment->due_at;
            if ($baseline && $requestedDueAt->lessThanOrEqualTo($baseline)) {
                throw ValidationException::withMessages(['requested_due_at' => 'The requested deadline must extend the current deadline.']);
            }
            if (AssignmentExtension::query()->where('recipient_id', $lockedRecipient->getKey())->where('status', 'pending')->lockForUpdate()->exists()) {
                throw ValidationException::withMessages(['extension' => 'An extension request is already awaiting review.']);
            }
            $extension = AssignmentExtension::query()->create(['recipient_id' => $lockedRecipient->getKey(), 'requested_by' => $actor->getKey(), 'reason' => $reason, 'previous_due_at' => $baseline, 'requested_due_at' => $requestedDueAt, 'status' => 'pending']);
            $this->audit->record('assignments.extension.requested', $actor, (int) $lockedRecipient->tenant_id, $extension, ['recipient_id' => $lockedRecipient->getKey()]);

            return $extension;
        }, 3);
    }

    public function decideExtension(AssignmentExtension $extension, User $actor, bool $approve, ?CarbonImmutable $effectiveDueAt = null): AssignmentExtension
    {
        $this->authorize('assignments.extension.review');
        $this->assertOwned($extension);
        if ((int) $extension->requested_by === (int) $actor->getKey()) {
            throw ValidationException::withMessages(['extension' => 'The requester cannot approve their own extension.']);
        }

        return DB::transaction(function () use ($extension, $actor, $approve, $effectiveDueAt): AssignmentExtension {
            $locked = AssignmentExtension::query()->lockForUpdate()->findOrFail($extension->getKey());
            if ($locked->status !== 'pending') {
                throw ValidationException::withMessages(['extension' => 'This extension has already been decided.']);
            }
            $recipient = AssignmentRecipient::query()->lockForUpdate()->findOrFail($locked->recipient_id);
            $assignment = $recipient->assignment()->firstOrFail();
            $this->assertSectionsAuthorized([(int) $recipient->section_id], $actor);
            $effective = $effectiveDueAt ?? $locked->requested_due_at;
            if ($approve && (($locked->previous_due_at && $effective->lessThanOrEqualTo($locked->previous_due_at)) || $effective->greaterThan($locked->requested_due_at))) {
                throw ValidationException::withMessages(['effective_due_at' => 'The approved deadline must extend the previous deadline and cannot exceed the requested deadline.']);
            }
            $locked->forceFill(['status' => $approve ? 'approved' : 'rejected', 'reviewed_by' => $actor->getKey(), 'reviewed_at' => now(), 'effective_due_at' => $approve ? $effective : null])->save();
            if ($approve) {
                $recipient->forceFill(['effective_due_at' => $locked->effective_due_at])->save();
            }
            $this->outbox($assignment, 'assignment.extension.'.$locked->status, 'extension-decision:'.$locked->getKey(), ['assignment_id' => $assignment->getKey(), 'recipient_id' => $recipient->getKey()]);
            $this->audit->record('assignments.extension.'.$locked->status, $actor, (int) $locked->tenant_id, $locked, ['recipient_id' => $locked->recipient_id]);

            return $locked->refresh();
        }, 3);
    }

    public function revokeExtension(AssignmentExtension $extension, User $actor, string $reason): AssignmentExtension
    {
        $this->authorize('assignments.extension.review');
        $this->assertOwned($extension);

        return DB::transaction(function () use ($extension, $actor, $reason): AssignmentExtension {
            $locked = AssignmentExtension::query()->lockForUpdate()->findOrFail($extension->getKey());
            if ($locked->status !== 'approved') {
                throw ValidationException::withMessages(['extension' => 'Only an approved extension can be revoked.']);
            }
            $recipient = AssignmentRecipient::query()->lockForUpdate()->findOrFail($locked->recipient_id);
            $assignment = $recipient->assignment()->firstOrFail();
            $this->assertSectionsAuthorized([(int) $recipient->section_id], $actor);
            $submittedAfterPreviousDeadline = AssignmentAttempt::query()->whereHas('submission', fn ($query) => $query->where('recipient_id', $recipient->getKey()))
                ->whereNotNull('submitted_at')->when($locked->previous_due_at !== null, fn ($query) => $query->where('submitted_at', '>', $locked->previous_due_at))->exists();
            if ($submittedAfterPreviousDeadline) {
                throw ValidationException::withMessages(['extension' => 'An extension cannot be revoked after the learner has submitted work relying on it.']);
            }
            $locked->forceFill(['status' => 'revoked', 'revoked_at' => now(), 'revocation_reason' => trim($reason), 'reviewed_by' => $actor->getKey(), 'reviewed_at' => now()])->save();
            $recipient->forceFill(['effective_due_at' => $locked->previous_due_at])->save();
            $this->outbox($assignment, 'assignment.extension.revoked', 'extension-revoked:'.$locked->getKey(), ['assignment_id' => $assignment->getKey(), 'recipient_id' => $recipient->getKey()]);
            $this->audit->record('assignments.extension.revoked', $actor, (int) $locked->tenant_id, $locked, ['recipient_id' => $recipient->getKey(), 'reason_present' => true]);

            return $locked->refresh();
        }, 3);
    }

    /** @param list<int|string> $sectionIds
     * @return array<string, mixed>
     */
    private function snapshot(Assignment $assignment, array $sectionIds): array
    {
        return ['id' => (int) $assignment->getKey(), 'title' => $assignment->title, 'type' => $assignment->assignment_type, 'category' => $assignment->category, 'summary' => $assignment->summary, 'instructions' => $assignment->instructions, 'submission_mode' => $assignment->submission_mode, 'collaboration_mode' => $assignment->collaboration_mode, 'maximum_attempts' => (int) $assignment->maximum_attempts, 'maximum_points' => $assignment->maximum_points, 'rubric_version_id' => $assignment->rubric_version_id === null ? null : (int) $assignment->rubric_version_id, 'available_at' => $assignment->available_at?->toIso8601String(), 'due_at' => $assignment->due_at?->toIso8601String(), 'late_until' => $assignment->late_until?->toIso8601String(), 'timezone' => $assignment->timezone, 'section_ids' => array_map('intval', $sectionIds)];
    }

    /** @param list<int|string> $sectionIds */
    private function snapshotRecipients(Assignment $assignment, array $sectionIds): void
    {
        $tenantId = (int) $assignment->tenant_id;
        $enrollments = CourseEnrollment::query()->where('offering_id', $assignment->offering_id)->whereIn('section_id', $sectionIds)
            ->where('status', 'confirmed')->whereNotNull('confirmed_at')->where('confirmed_at', '<=', now())
            ->where(fn ($query) => $query->whereNull('withdrawn_effective_at')->orWhere('withdrawn_effective_at', '>', now()))
            ->with('student')->get();
        foreach ($enrollments as $enrollment) {
            $student = $enrollment->student;
            if (! $student || $student->status !== 'active' || ! $student->user_id) {
                continue;
            }
            $member = TenantMembership::query()->where('tenant_id', $tenantId)->where('institution_id', $assignment->institution_id)->where('user_id', $student->user_id)->where('status', 'active')->first();
            if (! $member) {
                continue;
            }
            AssignmentRecipient::query()->firstOrCreate([
                'assignment_id' => $assignment->getKey(), 'membership_id' => $member->getKey(),
            ], ['student_id' => $student->getKey(), 'section_id' => $enrollment->section_id, 'eligible_from' => $enrollment->confirmed_at, 'effective_due_at' => $assignment->due_at, 'status' => 'eligible']);
        }
    }

    /** @param array<string, mixed> $payload */
    private function outbox(Assignment $assignment, string $type, string $key, array $payload): void
    {
        DB::table('assignment_outbox_events')->insertOrIgnore(['tenant_id' => $assignment->tenant_id, 'event_key' => $key, 'event_type' => $type, 'safe_payload' => json_encode($payload, JSON_THROW_ON_ERROR), 'status' => 'queued', 'attempts' => 0, 'available_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
    }

    /** @param array<string, mixed> $data */
    private function validateResponse(array $data): void
    {
        if (array_key_exists('response_url', $data) && $data['response_url'] !== null && ! $this->isSafeReferenceUrl((string) $data['response_url'])) {
            throw ValidationException::withMessages(['response_url' => 'Enter a public HTTP or HTTPS reference URL. Private and local-network targets are not accepted.']);
        }
    }

    private function isSafeReferenceUrl(string $url): bool
    {
        $parts = parse_url($url);
        if (! is_array($parts) || ! in_array(strtolower((string) ($parts['scheme'] ?? '')), ['http', 'https'], true) || empty($parts['host']) || isset($parts['user']) || isset($parts['pass'])) {
            return false;
        }
        $host = strtolower(rtrim((string) $parts['host'], '.'));
        if ($host === 'localhost' || str_ends_with($host, '.localhost') || str_ends_with($host, '.local') || str_ends_with($host, '.internal')) {
            return false;
        }
        if (filter_var($host, FILTER_VALIDATE_IP) !== false && filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
            return false;
        }

        return true;
    }

    private function localInstant(?string $value, string $timezone): ?CarbonImmutable
    {
        if ($value === null || $value === '') {
            return null;
        }
        try {
            $zone = new \DateTimeZone($timezone);
            if (! preg_match('/(?:Z|[+-]\d{2}:?\d{2})\z/i', $value)) {
                $wall = substr(str_replace('T', ' ', $value), 0, 19);
                $wallTime = \DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', str_pad($wall, 19, ':00'), new \DateTimeZone('UTC'));
                if ($wallTime instanceof \DateTimeImmutable) {
                    $wallTimestamp = $wallTime->getTimestamp();
                    $transitions = $zone->getTransitions($wallTimestamp - 86400, $wallTimestamp + 86400);
                    $previousOffset = (int) ($transitions[0]['offset'] ?? 0);
                    foreach (array_slice($transitions, 1) as $transition) {
                        $instant = (int) $transition['ts'];
                        $currentOffset = (int) $transition['offset'];
                        $lower = $instant + min($previousOffset, $currentOffset);
                        $upper = $instant + max($previousOffset, $currentOffset);
                        if ($wallTimestamp >= $lower && $wallTimestamp < $upper) {
                            throw ValidationException::withMessages(['due_at' => 'This local time is ambiguous or does not exist because of a daylight-saving transition. Select a valid time or include an explicit UTC offset.']);
                        }
                        $previousOffset = $currentOffset;
                    }
                }
            }

            return CarbonImmutable::parse($value, $zone)->utc();
        } catch (ValidationException $exception) {
            throw $exception;
        } catch (\Throwable) {
            throw ValidationException::withMessages(['due_at' => 'Enter a valid date and time for the selected timezone.']);
        }
    }

    public function parseLocalInstant(string $value, string $timezone): CarbonImmutable
    {
        return $this->localInstant($value, $timezone) ?? throw ValidationException::withMessages(['due_at' => 'A date and time is required.']);
    }

    private function authorize(string $permission): void
    {
        abort_unless($this->context->membership()->hasPermission($permission), 403);
    }

    /** @param list<int|string> $sectionIds */
    public function assertInstructorScope(array $sectionIds, User $actor): void
    {
        $this->assertSectionsAuthorized($sectionIds, $actor);
    }

    private function assertOwned(object $model): void
    {
        abort_unless((int) $model->tenant_id === (int) $this->context->tenant()->getKey(), 404);
    }

    /** @param list<int|string> $sectionIds */
    private function assertSectionsAuthorized(array $sectionIds, User $actor): void
    {
        $membership = $this->context->membership();
        if ($membership->hasPermission('enrollment.registrar.manage') || $membership->hasPermission('tenant.view')) {
            return;
        }
        $assigned = DB::table('course_section_instructors')->where('tenant_id', $membership->tenant_id)->where('user_id', $actor->getKey())->whereIn('section_id', $sectionIds)->pluck('section_id')->map(static fn ($id): int => (int) $id)->all();
        abort_unless(count(array_unique($assigned)) === count(array_unique(array_map('intval', $sectionIds))), 404);
    }
}
