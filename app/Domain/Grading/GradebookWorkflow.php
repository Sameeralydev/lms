<?php

namespace App\Domain\Grading;

use App\Domain\Academic\AcademicAccess;
use App\Domain\Audit\AuditRecorder;
use App\Domain\Tenancy\TenantContext;
use App\Models\AcademicGradebook;
use App\Models\AcademicGradebookItem;
use App\Models\AcademicGradeCategory;
use App\Models\AcademicGradeEntry;
use App\Models\AcademicGradeOverrideRequest;
use App\Models\AcademicGradePolicy;
use App\Models\AcademicGradePublication;
use App\Models\AcademicGradePublicationEntry;
use App\Models\AcademicGradeScaleBand;
use App\Models\CourseEnrollment;
use App\Models\CourseSection;
use App\Models\SisStudent;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class GradebookWorkflow
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly AcademicAccess $academicAccess,
        private readonly AuditRecorder $audit,
    ) {}

    /** @param array{name:string,policy_key:string,academic_period_id:int,campus_id?:?int,scale_type:string,rounding_mode:string,precision:int,effective_from:?string,effective_until:?string,categories:list<array{category_key:string,name:string,weight_basis_points:int,required:bool}>,scale_bands?:list<array{band_key:string,label:string,minimum_percentage_basis_points:int,grade_points:?string,passing:?bool}>} $data */
    public function createPolicy(array $data, User $actor): AcademicGradePolicy
    {
        $membership = $this->context->membership();
        abort_unless($membership->hasPermission('academic.grades.policy.manage'), 403);
        if (! in_array($data['scale_type'], ['percentage', 'numeric', 'letter', 'pass_fail'], true)) {
            throw ValidationException::withMessages(['scale_type' => 'Choose percentage, numeric, letter, or pass/fail grading.']);
        }
        if ((int) $data['precision'] !== 2) {
            throw ValidationException::withMessages(['precision' => 'Published grade snapshots currently retain two decimal places. Choose a two-decimal policy for an auditable result.']);
        }
        $scaleBands = $data['scale_bands'] ?? [];
        try {
            if (in_array($data['scale_type'], ['letter', 'pass_fail'], true)) {
                (new GradeScaleResolver)->assertValidBands($data['scale_type'], array_map(static fn (array $band): array => [
                    'label' => $band['label'],
                    'minimum_percentage_basis_points' => (int) $band['minimum_percentage_basis_points'],
                    'grade_points' => $band['grade_points'] ?? null,
                    'passing' => isset($band['passing']) ? (bool) $band['passing'] : null,
                ], $scaleBands));
            } elseif ($scaleBands !== []) {
                throw new InvalidArgumentException('Percentage and numeric policies do not accept letter bands.');
            }
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages(['scale_bands' => $exception->getMessage()]);
        }
        $campusId = isset($data['campus_id']) ? (int) $data['campus_id'] : null;
        $this->academicAccess->assertCampus($campusId);
        $period = DB::table('academic_periods')->where('tenant_id', $membership->tenant_id)->where('id', $data['academic_period_id'])->first();
        abort_unless($period !== null, 404);
        $year = DB::table('academic_years')->where('tenant_id', $membership->tenant_id)->where('id', $period->academic_year_id)->first();
        abort_unless($year !== null && (int) $year->institution_id === (int) $membership->institution_id, 404);
        if ($campusId !== null) {
            abort_unless(DB::table('campuses')->where('tenant_id', $membership->tenant_id)->where('institution_id', $membership->institution_id)->where('id', $campusId)->exists(), 404);
            abort_unless($year->campus_id === null || (int) $year->campus_id === $campusId, 404);
        }
        if ($membership->campus_id !== null && $campusId !== (int) $membership->campus_id) {
            throw new NotFoundHttpException;
        }

        return DB::transaction(function () use ($data, $scaleBands, $actor, $membership, $campusId): AcademicGradePolicy {
            $version = (int) AcademicGradePolicy::query()
                ->where('institution_id', $membership->institution_id)
                ->where('academic_period_id', $data['academic_period_id'])
                ->where('policy_key', $data['policy_key'])
                ->lockForUpdate()
                ->max('version') + 1;
            $policy = AcademicGradePolicy::query()->create([
                'institution_id' => $membership->institution_id,
                'campus_id' => $campusId,
                'academic_period_id' => $data['academic_period_id'],
                'policy_key' => $data['policy_key'],
                'name' => $data['name'],
                'scale_type' => $data['scale_type'],
                'status' => 'draft',
                'rounding_mode' => $data['rounding_mode'],
                'precision' => $data['precision'],
                'version' => $version,
                'effective_from' => $data['effective_from'],
                'effective_until' => $data['effective_until'],
                'created_by' => $actor->getKey(),
            ]);
            foreach ($data['categories'] as $category) {
                $policy->categories()->create($category);
            }
            foreach ($scaleBands as $index => $band) {
                $policy->scaleBands()->create([...$band, 'sort_order' => $index + 1]);
            }
            $this->audit->record('academic.grade_policy.created', $actor, (int) $membership->tenant_id, $policy, ['policy_key' => $policy->policy_key, 'category_count' => count($data['categories'])]);

            return $policy->load(['categories', 'scaleBands']);
        });
    }

    public function activatePolicy(int $policyId, User $actor): AcademicGradePolicy
    {
        $membership = $this->context->membership();
        abort_unless($membership->hasPermission('academic.grades.policy.manage'), 403);

        return DB::transaction(function () use ($policyId, $actor, $membership): AcademicGradePolicy {
            $policy = AcademicGradePolicy::query()->with(['categories', 'scaleBands'])->lockForUpdate()->findOrFail($policyId);
            $this->academicAccess->assertCampus($policy->campus_id === null ? null : (int) $policy->campus_id);
            abort_unless((int) $policy->institution_id === (int) $membership->institution_id, 404);
            if ($policy->status !== 'draft') {
                throw new ConflictHttpException('Only a draft grading policy can be activated.');
            }
            if ($policy->categories->isEmpty() || $policy->categories->sum('weight_basis_points') !== 10000) {
                throw ValidationException::withMessages(['categories' => 'Category weights must total exactly 100.00%.']);
            }
            try {
                if (in_array($policy->scale_type, ['letter', 'pass_fail'], true)) {
                    (new GradeScaleResolver)->assertValidBands($policy->scale_type, $policy->scaleBands->map(static fn (AcademicGradeScaleBand $band): array => [
                        'label' => $band->label,
                        'minimum_percentage_basis_points' => (int) $band->minimum_percentage_basis_points,
                        'grade_points' => $band->grade_points === null ? null : (string) $band->grade_points,
                        'passing' => $band->passing,
                    ])->all());
                }
            } catch (InvalidArgumentException $exception) {
                throw ValidationException::withMessages(['scale_bands' => $exception->getMessage()]);
            }
            if ($policy->effective_from === null || ($policy->effective_until !== null && (string) $policy->effective_until < (string) $policy->effective_from)) {
                throw ValidationException::withMessages(['effective_from' => 'A valid effective date range is required before activation.']);
            }
            $policy->forceFill(['status' => 'active'])->save();
            $this->audit->record('academic.grade_policy.activated', $actor, (int) $membership->tenant_id, $policy, ['version' => (int) $policy->version]);

            return $policy->refresh()->load('categories');
        });
    }

    public function createGradebook(int $sectionId, int $policyId, User $actor): AcademicGradebook
    {
        $membership = $this->context->membership();
        abort_unless($membership->hasPermission('academic.grades.book.manage'), 403);
        $section = CourseSection::query()->with('offering')->findOrFail($sectionId);
        $this->assertSectionAccess($section, $actor);
        $offering = $section->offering;
        $policy = AcademicGradePolicy::query()->where('status', 'active')->findOrFail($policyId);
        $period = DB::table('academic_periods')->where('tenant_id', $membership->tenant_id)->where('id', $offering->academic_period_id)->first();
        abort_unless($period !== null, 404);
        $this->academicAccess->assertCampus($section->campus_id === null ? null : (int) $section->campus_id);
        abort_unless((int) $offering->institution_id === (int) $membership->institution_id
            && (int) $policy->institution_id === (int) $membership->institution_id
            && (int) $offering->academic_period_id === (int) $policy->academic_period_id
            && $policy->effective_from !== null
            && (string) $policy->effective_from <= (string) $period->ends_on
            && ($policy->effective_until === null || (string) $policy->effective_until >= (string) $period->starts_on)
            && ($policy->campus_id === null || (int) $policy->campus_id === (int) $section->campus_id), 404);

        return DB::transaction(function () use ($section, $offering, $policy, $actor, $membership): AcademicGradebook {
            $gradebook = AcademicGradebook::query()->firstOrCreate(
                ['section_id' => $section->getKey(), 'academic_period_id' => $offering->academic_period_id],
                ['institution_id' => $membership->institution_id, 'policy_id' => $policy->getKey(), 'status' => 'draft', 'version' => 1, 'created_by' => $actor->getKey()],
            );
            if ((int) $gradebook->policy_id !== (int) $policy->getKey()) {
                throw new ConflictHttpException('A gradebook already exists for this section and academic period with a different policy.');
            }
            $this->audit->record('academic.gradebook.created', $actor, (int) $membership->tenant_id, $gradebook, ['section_id' => (int) $section->getKey(), 'policy_id' => (int) $policy->getKey()]);

            return $gradebook->load(['section.offering.course', 'policy.categories', 'items']);
        });
    }

    /** @param array{item_key:string,title:string,category_id:int,maximum_points:string,source_type:string,source_key:?string} $data */
    public function createItem(int $gradebookId, array $data, User $actor): AcademicGradebookItem
    {
        $membership = $this->context->membership();
        abort_unless($membership->hasPermission('academic.grades.book.manage'), 403);
        $gradebook = AcademicGradebook::query()->with('policy.categories')->findOrFail($gradebookId);
        $section = $gradebook->section()->firstOrFail();
        $this->assertSectionAccess($section, $actor);
        abort_unless($gradebook->status === 'draft', 409);
        if (! in_array($data['source_type'], ['manual', 'assignment', 'quiz', 'examination'], true)) {
            throw ValidationException::withMessages(['source_type' => 'Choose a supported manual, assignment, quiz, or examination source.']);
        }
        if ($data['source_type'] === 'manual' && $data['source_key'] !== null) {
            throw ValidationException::withMessages(['source_key' => 'Manual grade items cannot include an external source identifier.']);
        }
        if ($data['source_type'] !== 'manual' && (! is_string($data['source_key']) || ! ctype_digit($data['source_key']) || (int) $data['source_key'] < 1)) {
            throw ValidationException::withMessages(['source_key' => 'Enter the numeric ID of the source assignment, quiz, or examination session.']);
        }
        $category = $gradebook->policy->categories->firstWhere('id', $data['category_id']);
        abort_unless($category !== null, 404);
        if (! $this->validDecimal($data['maximum_points']) || $this->decimalToCents($data['maximum_points']) <= 0) {
            throw ValidationException::withMessages(['maximum_points' => 'Maximum points must be a positive decimal with at most two fractional digits.']);
        }

        return DB::transaction(function () use ($gradebook, $category, $data, $actor, $membership): AcademicGradebookItem {
            $item = AcademicGradebookItem::query()->create([
                'gradebook_id' => $gradebook->getKey(), 'category_id' => $category->getKey(),
                'item_key' => $data['item_key'], 'title' => $data['title'], 'maximum_points' => $data['maximum_points'],
                'source_type' => $data['source_type'], 'source_key' => $data['source_key'], 'status' => 'active',
            ]);
            $this->audit->record('academic.gradebook_item.created', $actor, (int) $membership->tenant_id, $item, ['gradebook_id' => (int) $gradebook->getKey(), 'source_type' => $item->source_type]);

            return $item;
        });
    }

    /** @param array{earned_points:?string,entry_status:string,feedback:?string,expected_lock_version:?int,reason:?string} $data */
    public function saveEntry(int $gradebookId, int $itemId, int $enrollmentId, array $data, User $actor): AcademicGradeEntry
    {
        $membership = $this->context->membership();
        abort_unless($membership->hasPermission('academic.grades.book.manage'), 403);

        return DB::transaction(function () use ($gradebookId, $itemId, $enrollmentId, $data, $actor, $membership): AcademicGradeEntry {
            $gradebook = AcademicGradebook::query()->lockForUpdate()->findOrFail($gradebookId);
            abort_unless($gradebook->status === 'draft', 409);
            $section = CourseSection::query()->with('offering')->findOrFail($gradebook->section_id);
            $this->assertSectionAccess($section, $actor);
            $item = AcademicGradebookItem::query()->where('gradebook_id', $gradebook->getKey())->findOrFail($itemId);
            $enrollment = CourseEnrollment::query()->findOrFail($enrollmentId);
            abort_unless((int) $enrollment->section_id === (int) $section->getKey()
                && (int) $enrollment->offering_id === (int) $section->offering_id
                && $enrollment->status === 'confirmed', 404);
            if ($data['entry_status'] === 'graded') {
                if ($data['earned_points'] === null || ! $this->validDecimal($data['earned_points'])) {
                    throw ValidationException::withMessages(['earned_points' => 'Enter a valid non-negative score with at most two decimal places.']);
                }
                if ($this->decimalToCents($data['earned_points']) > $this->decimalToCents((string) $item->maximum_points)) {
                    throw ValidationException::withMessages(['earned_points' => 'The score cannot exceed the maximum points.']);
                }
            } elseif (! in_array($data['entry_status'], ['missing', 'incomplete', 'excused'], true)) {
                throw ValidationException::withMessages(['entry_status' => 'Choose a supported grade-entry state.']);
            }

            $entry = AcademicGradeEntry::query()->where('item_id', $item->getKey())->where('enrollment_id', $enrollment->getKey())->lockForUpdate()->first();
            if ($entry && $data['expected_lock_version'] !== null && (int) $entry->lock_version !== $data['expected_lock_version']) {
                throw new ConflictHttpException('This grade changed in another session. Reload before saving.');
            }
            $beforePoints = $entry?->earned_points;
            $beforeStatus = $entry?->entry_status;
            $revision = $entry ? (int) $entry->lock_version + 1 : 1;
            $entry ??= new AcademicGradeEntry(['gradebook_id' => $gradebook->getKey(), 'item_id' => $item->getKey(), 'enrollment_id' => $enrollment->getKey()]);
            $entry->forceFill([
                'earned_points' => $data['entry_status'] === 'graded' ? $data['earned_points'] : null,
                'entry_status' => $data['entry_status'], 'feedback' => $data['feedback'] ?? null, 'lock_version' => $revision,
                'updated_by' => $actor->getKey(),
            ])->save();
            $entry->revisions()->create([
                'revision_number' => $revision, 'previous_points' => $beforePoints,
                'new_points' => $entry->earned_points, 'previous_status' => $beforeStatus,
                'new_status' => $entry->entry_status, 'reason' => $data['reason'], 'actor_id' => $actor->getKey(),
            ]);
            $this->audit->record('academic.grade_entry.saved', $actor, (int) $membership->tenant_id, $entry, ['revision' => $revision, 'gradebook_id' => (int) $gradebook->getKey(), 'entry_status' => $entry->entry_status]);

            return $entry->refresh();
        });
    }

    public function requestOverride(int $gradebookId, int $entryId, string $proposedPoints, string $reason, User $actor): AcademicGradeOverrideRequest
    {
        $membership = $this->context->membership();
        abort_unless($membership->hasPermission('academic.grades.book.manage'), 403);
        if (! $this->validDecimal($proposedPoints) || trim($reason) === '' || mb_strlen($reason) > 500) {
            throw ValidationException::withMessages(['override' => 'Provide a valid score and a reason of no more than 500 characters.']);
        }

        return DB::transaction(function () use ($gradebookId, $entryId, $proposedPoints, $reason, $actor, $membership): AcademicGradeOverrideRequest {
            $gradebook = AcademicGradebook::query()->lockForUpdate()->findOrFail($gradebookId);
            abort_unless(in_array($gradebook->status, ['draft', 'submitted', 'published'], true), 409);
            $section = CourseSection::query()->with('offering')->findOrFail($gradebook->section_id);
            $this->assertSectionAccess($section, $actor);
            abort_unless((int) $gradebook->institution_id === (int) $membership->institution_id, 404);
            $entry = AcademicGradeEntry::query()->where('gradebook_id', $gradebook->getKey())->lockForUpdate()->findOrFail($entryId);
            abort_unless($entry->entry_status === 'graded' && $entry->earned_points !== null, 409);
            $item = AcademicGradebookItem::query()->where('gradebook_id', $gradebook->getKey())->findOrFail($entry->item_id);
            if ($this->decimalToCents($proposedPoints) > $this->decimalToCents((string) $item->maximum_points)) {
                throw ValidationException::withMessages(['proposed_points' => 'An adjusted score cannot exceed the assessment maximum.']);
            }
            if ($this->decimalToCents($proposedPoints) === $this->decimalToCents((string) $entry->earned_points)) {
                throw ValidationException::withMessages(['proposed_points' => 'The adjusted score must differ from the current score.']);
            }
            if (AcademicGradeOverrideRequest::query()->where('entry_id', $entry->getKey())->where('status', 'pending')->exists()) {
                throw new ConflictHttpException('A grade override request is already pending for this entry.');
            }

            $override = AcademicGradeOverrideRequest::query()->create([
                'tenant_id' => $membership->tenant_id,
                'institution_id' => $membership->institution_id,
                'gradebook_id' => $gradebook->getKey(),
                'entry_id' => $entry->getKey(),
                'original_points' => $entry->earned_points,
                'proposed_points' => $proposedPoints,
                'reason' => trim($reason),
                'status' => 'pending',
                'requested_by' => $actor->getKey(),
            ]);
            $this->audit->record('academic.grade_override.requested', $actor, (int) $membership->tenant_id, $override, ['gradebook_id' => (int) $gradebook->getKey(), 'entry_id' => (int) $entry->getKey()]);
            $this->queueScopedAcademicEvent((int) $membership->tenant_id, 'academic.grade_override.requested', (int) $override->getKey(), 'override_id');

            return $override;
        }, 3);
    }

    public function decideOverride(int $overrideId, bool $approve, string $decisionReason, User $actor): AcademicGradeOverrideRequest
    {
        $membership = $this->context->membership();
        abort_unless($membership->hasPermission('academic.grades.review'), 403);
        if (trim($decisionReason) === '' || mb_strlen($decisionReason) > 500) {
            throw ValidationException::withMessages(['decision_reason' => 'A decision reason is required and must not exceed 500 characters.']);
        }

        return DB::transaction(function () use ($overrideId, $approve, $decisionReason, $actor, $membership): AcademicGradeOverrideRequest {
            $override = AcademicGradeOverrideRequest::query()->lockForUpdate()->findOrFail($overrideId);
            abort_unless((int) $override->tenant_id === (int) $membership->tenant_id && (int) $override->institution_id === (int) $membership->institution_id, 404);
            abort_unless($override->status === 'pending', 409);
            abort_unless((int) $override->requested_by !== (int) $actor->getKey(), 403);
            $gradebook = AcademicGradebook::query()->with(['items.entries', 'policy.categories', 'policy.scaleBands'])->lockForUpdate()->findOrFail($override->gradebook_id);
            abort_unless(in_array($gradebook->status, ['draft', 'submitted', 'published'], true), 409);
            $section = CourseSection::query()->with('offering')->findOrFail($gradebook->section_id);
            $this->assertSectionAccess($section, $actor);
            $entry = AcademicGradeEntry::query()->where('gradebook_id', $gradebook->getKey())->lockForUpdate()->findOrFail($override->entry_id);

            if ($approve) {
                if ($entry->entry_status !== 'graded' || $entry->earned_points === null
                    || $this->decimalToCents((string) $entry->earned_points) !== $this->decimalToCents((string) $override->original_points)) {
                    throw new ConflictHttpException('The grade changed after this request. Create a new review request for the current value.');
                }
                $item = AcademicGradebookItem::query()->where('gradebook_id', $gradebook->getKey())->findOrFail($entry->item_id);
                if ($this->decimalToCents((string) $override->proposed_points) > $this->decimalToCents((string) $item->maximum_points)) {
                    throw new ConflictHttpException('The requested score no longer satisfies the assessment maximum.');
                }
                $revision = (int) $entry->lock_version + 1;
                $entry->forceFill(['earned_points' => $override->proposed_points, 'lock_version' => $revision, 'updated_by' => $actor->getKey()])->save();
                $entry->revisions()->create([
                    'revision_number' => $revision,
                    'previous_points' => $override->original_points,
                    'new_points' => $override->proposed_points,
                    'previous_status' => 'graded',
                    'new_status' => 'graded',
                    'reason' => 'Approved override #'.$override->getKey().': '.$override->reason,
                    'actor_id' => $actor->getKey(),
                ]);
                if ($gradebook->status === 'published') {
                    $gradebook->load('items.entries');
                    $enrollments = CourseEnrollment::query()->where('section_id', $section->getKey())->where('status', 'confirmed')->orderBy('id')->get();
                    $publicationNumber = (int) AcademicGradePublication::query()->where('gradebook_id', $gradebook->getKey())->max('publication_number') + 1;
                    $publication = AcademicGradePublication::query()->create([
                        'gradebook_id' => $gradebook->getKey(),
                        'publication_number' => $publicationNumber,
                        'published_by' => $actor->getKey(),
                        'published_at' => now(),
                    ]);
                    foreach ($enrollments as $enrollment) {
                        $calculation = (new GradeCalculationEngine)->calculate($this->calculationInputs($gradebook, $enrollment), $gradebook->policy->rounding_mode);
                        if ($calculation['status'] !== 'complete' || $calculation['percentage_basis_points'] === null || $calculation['percentage'] === null) {
                            throw new ConflictHttpException('The corrected publication cannot be issued because a learner result is incomplete.');
                        }
                        $publication->entries()->create([
                            'gradebook_id' => $gradebook->getKey(),
                            'enrollment_id' => $enrollment->getKey(),
                            'percentage_basis_points' => $calculation['percentage_basis_points'],
                            'percentage' => $calculation['percentage'],
                            ...$this->resolveScaleResult($gradebook->policy, $calculation['percentage_basis_points']),
                            'created_at' => now(),
                        ]);
                    }
                    $gradebook->forceFill(['version' => (int) $gradebook->version + 1])->save();
                    $this->audit->record('academic.grade_publication.corrected', $actor, (int) $membership->tenant_id, $publication, ['gradebook_id' => (int) $gradebook->getKey(), 'publication_number' => $publicationNumber, 'learner_count' => $enrollments->count(), 'override_id' => (int) $override->getKey()]);
                    $this->queueAcademicNotification((int) $membership->tenant_id, 'academic.grade.published', (int) $publication->getKey());
                }
            }

            $override->forceFill([
                'status' => $approve ? 'approved' : 'rejected',
                'decided_by' => $actor->getKey(),
                'decision_reason' => trim($decisionReason),
                'decided_at' => now(),
            ])->save();
            $this->audit->record('academic.grade_override.'.($approve ? 'approved' : 'rejected'), $actor, (int) $membership->tenant_id, $override, ['gradebook_id' => (int) $gradebook->getKey(), 'entry_id' => (int) $entry->getKey(), 'score_changed' => $approve]);
            $this->queueScopedAcademicEvent((int) $membership->tenant_id, 'academic.grade_override.decided', (int) $override->getKey(), 'override_id');

            return $override->refresh();
        }, 3);
    }

    public function transition(int $gradebookId, string $action, User $actor): AcademicGradebook
    {
        $membership = $this->context->membership();
        $permission = match ($action) {
            'submit' => 'academic.grades.submit',
            'approve' => 'academic.grades.review',
            'publish' => 'academic.grades.publish',
            default => null,
        };
        abort_unless($permission !== null && $membership->hasPermission($permission), 403);

        return DB::transaction(function () use ($gradebookId, $action, $actor, $membership): AcademicGradebook {
            $gradebook = AcademicGradebook::query()->with(['items.entries', 'policy.categories', 'policy.scaleBands'])->lockForUpdate()->findOrFail($gradebookId);
            $section = CourseSection::query()->with('offering')->findOrFail($gradebook->section_id);
            $this->academicAccess->assertCampus($section->campus_id === null ? null : (int) $section->campus_id);
            abort_unless((int) $gradebook->institution_id === (int) $membership->institution_id, 404);
            $allowed = ['submit' => ['draft', 'submitted'], 'approve' => ['submitted', 'approved'], 'publish' => ['approved']];
            $expected = ['submit' => 'draft', 'approve' => 'submitted', 'publish' => 'approved'];
            abort_unless(in_array($gradebook->status, $allowed[$action], true), 409);
            if ($action === 'approve') {
                abort_unless((int) $gradebook->created_by !== (int) $actor->getKey(), 403);
            }
            if ($action === 'submit') {
                $pendingOverrides = AcademicGradeOverrideRequest::query()->where('gradebook_id', $gradebook->getKey())->where('status', 'pending')->exists();
                if ($pendingOverrides) {
                    throw ValidationException::withMessages(['gradebook' => 'Resolve pending grade override requests before submission.']);
                }
                $activeEnrollments = CourseEnrollment::query()->where('section_id', $section->getKey())->where('status', 'confirmed')->count();
                if ($activeEnrollments < 1 || $gradebook->items->isEmpty()) {
                    throw ValidationException::withMessages(['gradebook' => 'A gradebook needs enrolled learners and assessment items before submission.']);
                }
                foreach ($gradebook->items as $item) {
                    $graded = $item->entries->whereIn('entry_status', ['graded', 'excused'])->count();
                    if ($graded < $activeEnrollments) {
                        throw ValidationException::withMessages(['gradebook' => 'Every active learner must have a graded or excused entry for each item before submission.']);
                    }
                }
            }
            if ($action === 'publish') {
                $enrollments = CourseEnrollment::query()->where('section_id', $section->getKey())->where('status', 'confirmed')->orderBy('id')->get();
                $publicationNumber = (int) AcademicGradePublication::query()->where('gradebook_id', $gradebook->getKey())->max('publication_number') + 1;
                $publication = AcademicGradePublication::query()->create([
                    'gradebook_id' => $gradebook->getKey(),
                    'publication_number' => $publicationNumber,
                    'published_by' => $actor->getKey(),
                    'published_at' => now(),
                ]);
                foreach ($enrollments as $enrollment) {
                    $calculation = (new GradeCalculationEngine)->calculate($this->calculationInputs($gradebook, $enrollment), $gradebook->policy->rounding_mode);
                    if ($calculation['status'] !== 'complete' || $calculation['percentage_basis_points'] === null || $calculation['percentage'] === null) {
                        throw ValidationException::withMessages(['gradebook' => 'Every published learner result must have a complete weighted calculation. Check missing, excused-only, and empty assessment categories.']);
                    }
                    $publication->entries()->create([
                        'gradebook_id' => $gradebook->getKey(),
                        'enrollment_id' => $enrollment->getKey(),
                        'percentage_basis_points' => $calculation['percentage_basis_points'],
                        'percentage' => $calculation['percentage'],
                        ...$this->resolveScaleResult($gradebook->policy, $calculation['percentage_basis_points']),
                        'created_at' => now(),
                    ]);
                }
                $this->audit->record('academic.grade_publication.created', $actor, (int) $membership->tenant_id, $publication, ['gradebook_id' => (int) $gradebook->getKey(), 'publication_number' => $publicationNumber, 'learner_count' => $enrollments->count()]);
                $this->queueAcademicNotification((int) $membership->tenant_id, 'academic.grade.published', (int) $publication->getKey());
            }
            $to = ['submit' => 'submitted', 'approve' => 'approved', 'publish' => 'published'][$action];
            $gradebook->forceFill(['status' => $to, 'version' => (int) $gradebook->version + 1])->save();
            $this->audit->record('academic.gradebook.'.$to, $actor, (int) $membership->tenant_id, $gradebook, ['from' => $expected[$action], 'to' => $to, 'version' => (int) $gradebook->version]);
            if ($action === 'submit') {
                $this->queueAcademicEvent((int) $membership->tenant_id, 'academic.gradebook.submitted', (int) $gradebook->getKey());
            } elseif ($action === 'approve') {
                $this->queueAcademicEvent((int) $membership->tenant_id, 'academic.gradebook.reviewed', (int) $gradebook->getKey());
            }

            return $gradebook->refresh();
        });
    }

    /** @return array<string, mixed> */
    public function instructorWorkspace(User $actor): array
    {
        $membership = $this->context->membership();
        abort_unless($membership->hasPermission('academic.grades.book.view'), 403);
        $sections = CourseSection::query()->with('offering.course')->whereHas('offering', fn ($query) => $query->where('institution_id', $membership->institution_id))
            ->when($membership->campus_id !== null, fn ($query) => $query->where('campus_id', $membership->campus_id))
            ->when(! $membership->hasPermission('academic.grades.book.manage-all'), fn ($query) => $query->whereIn('id', DB::table('course_section_instructors')->where('tenant_id', $membership->tenant_id)->where('user_id', $actor->getKey())->select('section_id')))
            ->orderBy('section_code')->get();
        $gradebooks = AcademicGradebook::query()->with(['section.offering.course', 'policy'])->whereIn('section_id', $sections->modelKeys())->orderByDesc('updated_at')->get();
        $policies = AcademicGradePolicy::query()->with('period')->where('institution_id', $membership->institution_id)->when($membership->campus_id !== null, fn ($query) => $query->where(fn ($scope) => $scope->whereNull('campus_id')->orWhere('campus_id', $membership->campus_id)))->orderBy('name')->get();

        return ['sections' => $sections, 'gradebooks' => $gradebooks, 'policies' => $policies];
    }

    /** @return array<string, mixed> */
    public function gradebookWorkspace(int $gradebookId, User $actor): array
    {
        $membership = $this->context->membership();
        abort_unless($membership->hasPermission('academic.grades.book.view'), 403);
        $gradebook = AcademicGradebook::query()->with(['section.offering.course', 'policy.categories', 'items.category', 'items.entries.enrollment.student'])->findOrFail($gradebookId);
        $this->assertSectionAccess($gradebook->section, $actor);
        $enrollments = CourseEnrollment::query()->with('student')->where('section_id', $gradebook->section_id)->where('status', 'confirmed')->orderBy('id')->get();
        $engine = new GradeCalculationEngine;
        $roster = $enrollments->map(function (CourseEnrollment $enrollment) use ($gradebook, $engine): array {
            $categoryInputs = $this->calculationInputs($gradebook, $enrollment);

            return [
                'enrollment' => ['id' => (int) $enrollment->getKey(), 'student_id' => (int) $enrollment->student_id],
                'student' => ['number' => $enrollment->student?->student_number, 'first_name' => $enrollment->student?->legal_first_name, 'last_name' => $enrollment->student?->legal_last_name],
                'entries' => $gradebook->items->map(function (AcademicGradebookItem $item) use ($enrollment): array {
                    $entry = $item->entries->firstWhere('enrollment_id', $enrollment->getKey());

                    return ['item_id' => (int) $item->getKey(), 'entry_id' => $entry ? (int) $entry->getKey() : null, 'earned_points' => $entry?->earned_points, 'entry_status' => $entry ? $entry->entry_status : 'missing', 'lock_version' => $entry ? (int) $entry->lock_version : 0];
                })->values()->all(),
                'calculation' => $engine->calculate($categoryInputs, $gradebook->policy->rounding_mode),
            ];
        })->values()->all();

        return ['gradebook' => $gradebook, 'roster' => $roster, 'campus_scope' => $this->academicAccess->campusId()];
    }

    public function assertSectionAccess(CourseSection $section, User $actor): void
    {
        $membership = $this->context->membership();
        $this->academicAccess->assertCampus($section->campus_id === null ? null : (int) $section->campus_id);
        abort_unless((int) $section->offering()->value('institution_id') === (int) $membership->institution_id, 404);
        if (! $membership->hasPermission('academic.grades.book.manage-all')
            && ! DB::table('course_section_instructors')->where('tenant_id', $membership->tenant_id)->where('section_id', $section->getKey())->where('user_id', $actor->getKey())->exists()) {
            throw new NotFoundHttpException;
        }
    }

    /** @return array<string, mixed> */
    public function studentWorkspace(User $actor): array
    {
        $membership = $this->context->membership();
        abort_unless($membership->hasPermission('academic.grades.view-own'), 403);
        $student = SisStudent::query()->where('institution_id', $membership->institution_id)->where('user_id', $actor->getKey())->firstOrFail();
        $entries = AcademicGradeEntry::query()->with(['item.category', 'gradebook.section.offering.course', 'gradebook.policy'])
            ->whereHas('gradebook', fn ($query) => $query->where('status', 'published'))
            ->whereHas('gradebook', fn ($query) => $query->where('tenant_id', $membership->tenant_id)->where('institution_id', $membership->institution_id))
            ->whereHas('enrollment', fn ($query) => $query->where('student_id', $student->getKey()))->orderBy('gradebook_id')->get();

        $results = AcademicGradePublicationEntry::query()->with(['publication', 'gradebook.section.offering.course'])
            ->whereIn('publication_id', AcademicGradePublication::query()->selectRaw('MAX(id)')->groupBy('gradebook_id'))
            ->whereHas('gradebook', fn ($query) => $query->where('tenant_id', $membership->tenant_id)->where('institution_id', $membership->institution_id))
            ->whereHas('enrollment', fn ($query) => $query->where('student_id', $student->getKey()))
            ->orderByDesc('id')->get();

        return ['student' => $student, 'entries' => $entries, 'results' => $results];
    }

    /** @return list<array{weight_basis_points:int,items:list<array{earned_points:?string,maximum_points:string,excluded?:bool}>}> */
    private function calculationInputs(AcademicGradebook $gradebook, CourseEnrollment $enrollment): array
    {
        return $gradebook->policy->categories->map(function (AcademicGradeCategory $category) use ($gradebook, $enrollment): array {
            $items = $gradebook->items->where('category_id', $category->getKey())->map(function (AcademicGradebookItem $item) use ($enrollment): array {
                $entry = $item->entries->firstWhere('enrollment_id', $enrollment->getKey());

                return [
                    'earned_points' => $entry?->entry_status === 'graded' ? (string) $entry->earned_points : null,
                    'maximum_points' => (string) $item->maximum_points,
                    'excluded' => $entry?->entry_status === 'excused',
                ];
            })->values()->all();

            return ['weight_basis_points' => (int) $category->weight_basis_points, 'items' => $items];
        })->values()->all();
    }

    private function validDecimal(string $value): bool
    {
        return preg_match('/^(?:0|[1-9]\d{0,6})(?:\.\d{1,2})?$/D', $value) === 1;
    }

    private function queueAcademicNotification(int $tenantId, string $eventType, int $publicationId): void
    {
        DB::table('quiz_outbox_events')->insertOrIgnore([
            'tenant_id' => $tenantId,
            'event_key' => $eventType.':'.$publicationId,
            'event_type' => $eventType,
            'safe_payload' => json_encode(['publication_id' => $publicationId], JSON_THROW_ON_ERROR),
            'status' => 'queued', 'attempts' => 0, 'available_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function queueAcademicEvent(int $tenantId, string $eventType, int $gradebookId): void
    {
        DB::table('quiz_outbox_events')->insertOrIgnore([
            'tenant_id' => $tenantId,
            'event_key' => $eventType.':'.$gradebookId,
            'event_type' => $eventType,
            'safe_payload' => json_encode(['gradebook_id' => $gradebookId], JSON_THROW_ON_ERROR),
            'status' => 'queued', 'attempts' => 0, 'available_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function queueScopedAcademicEvent(int $tenantId, string $eventType, int $overrideId, string $payloadKey): void
    {
        DB::table('quiz_outbox_events')->insertOrIgnore([
            'tenant_id' => $tenantId,
            'event_key' => $eventType.':'.$overrideId,
            'event_type' => $eventType,
            'safe_payload' => json_encode([$payloadKey => $overrideId], JSON_THROW_ON_ERROR),
            'status' => 'queued', 'attempts' => 0, 'available_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function decimalToCents(string $value): int
    {
        if (! $this->validDecimal($value)) {
            return -1;
        }
        [$whole, $fraction] = array_pad(explode('.', $value, 2), 2, '');

        return ((int) $whole * 100) + (int) str_pad($fraction, 2, '0');
    }

    /** @return array{scale_result:string,grade_points:?string,passed:?bool} */
    private function resolveScaleResult(AcademicGradePolicy $policy, int $percentageBasisPoints): array
    {
        $bands = $policy->scaleBands->map(static fn (AcademicGradeScaleBand $band): array => [
            'label' => $band->label,
            'minimum_percentage_basis_points' => (int) $band->minimum_percentage_basis_points,
            'grade_points' => $band->grade_points === null ? null : (string) $band->grade_points,
            'passing' => $band->passing,
        ])->all();

        return (new GradeScaleResolver)->resolve($percentageBasisPoints, $policy->scale_type, $bands);
    }
}
