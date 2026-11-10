<?php

namespace App\Domain\Academic\Curriculum;

use App\Domain\Admissions\Documents\AdmissionDocumentScanner;
use App\Domain\Audit\AuditRecorder;
use App\Domain\Tenancy\TenantContext;
use App\Models\AcademicCompetency;
use App\Models\AcademicCompetencyFramework;
use App\Models\AcademicCurriculumChecklistItem;
use App\Models\AcademicCurriculumChecklistTemplate;
use App\Models\AcademicCurriculumFramework;
use App\Models\AcademicCurriculumFrameworkAdministrator;
use App\Models\AcademicCurriculumGovernancePolicy;
use App\Models\AcademicCurriculumResource;
use App\Models\AcademicCurriculumVersion;
use App\Models\AcademicDocument;
use App\Models\AcademicProgram;
use App\Models\AcademicProgramCompetencyRequirement;
use App\Models\Institution;
use App\Models\TenantMembership;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/** Transactional governance operations for Phase 06 curriculum structures. */
final class CurriculumGovernanceService
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly AuditRecorder $audit,
    ) {}

    /** @param array<string,mixed> $data */
    public function assignAdministrator(AcademicCurriculumFramework $framework, array $data, User $actor): AcademicCurriculumFrameworkAdministrator
    {
        return DB::transaction(function () use ($framework, $data, $actor): AcademicCurriculumFrameworkAdministrator {
            $locked = $this->framework($framework->getKey(), true);
            $this->assertFrameworkAdministrationAccess($locked, $actor);
            $this->assertMember((int) $data['user_id'], (int) $locked->institution_id, 'academic.curriculum.manage', $data['campus_id'] ?? null);
            $this->assertOptionalScope($data['campus_id'] ?? null, $data['academic_program_id'] ?? null, (int) $locked->institution_id);
            $from = $data['effective_from'] ?? null;
            $until = $data['effective_until'] ?? null;
            if ($from && $until && $until < $from) {
                throw ValidationException::withMessages(['effective_until' => 'The end date must be on or after the start date.']);
            }
            $duplicate = AcademicCurriculumFrameworkAdministrator::query()
                ->where('framework_id', $locked->getKey())->where('user_id', $data['user_id'])
                ->where('responsibility', $data['responsibility'])->whereNull('revoked_at')
                ->where('campus_id', $data['campus_id'] ?? null)->where('academic_program_id', $data['academic_program_id'] ?? null)->exists();
            if ($duplicate) {
                throw ValidationException::withMessages(['user_id' => 'This active framework assignment already exists.']);
            }
            $assignment = AcademicCurriculumFrameworkAdministrator::query()->create([
                'framework_id' => $locked->getKey(), 'user_id' => $data['user_id'], 'responsibility' => $data['responsibility'],
                'campus_id' => $data['campus_id'] ?? null, 'academic_program_id' => $data['academic_program_id'] ?? null,
                'effective_from' => $from, 'effective_until' => $until, 'assigned_by' => $actor->getKey(),
                'assignment_reason' => trim($data['reason']),
            ]);
            $this->audit->record('academic.curriculum.framework_administrator.assigned', $actor, $this->tenantId(), $assignment, [
                'framework_id' => $locked->getKey(), 'user_id' => $assignment->user_id, 'responsibility' => $assignment->responsibility,
                'campus_id' => $assignment->campus_id, 'academic_program_id' => $assignment->academic_program_id,
            ]);

            return $assignment;
        }, 3);
    }

    public function revokeAdministrator(AcademicCurriculumFrameworkAdministrator $assignment, string $reason, User $actor): void
    {
        DB::transaction(function () use ($assignment, $reason, $actor): void {
            $locked = AcademicCurriculumFrameworkAdministrator::query()->lockForUpdate()->findOrFail($assignment->getKey());
            $framework = $this->framework($locked->framework_id, true);
            $this->assertFrameworkAdministrationAccess($framework, $actor);
            if ($locked->revoked_at !== null) {
                throw new ConflictHttpException('This framework assignment has already been revoked.');
            }
            if ($locked->responsibility === 'administrator' && $this->effectiveAdministratorCount((int) $locked->framework_id) <= 1) {
                throw ValidationException::withMessages(['assignment' => 'At least one active framework administrator must remain.']);
            }
            $locked->forceFill(['revoked_at' => now(), 'revoked_by' => $actor->getKey(), 'revocation_reason' => trim($reason)])->save();
            $this->audit->record('academic.curriculum.framework_administrator.revoked', $actor, $this->tenantId(), $locked, [
                'framework_id' => $locked->framework_id, 'user_id' => $locked->user_id, 'responsibility' => $locked->responsibility,
                'reason' => trim($reason),
            ]);
        }, 3);
    }

    public function designateOwner(AcademicCurriculumFramework $framework, int $userId, string $reason, User $actor): AcademicCurriculumFramework
    {
        return DB::transaction(function () use ($framework, $userId, $reason, $actor): AcademicCurriculumFramework {
            $locked = $this->framework($framework->getKey(), true);
            $this->assertFrameworkAdministrationAccess($locked, $actor);
            $this->assertMember($userId, (int) $locked->institution_id, 'academic.curriculum.manage');
            $before = $locked->owner_user_id;
            if ((int) $before === $userId) {
                throw ValidationException::withMessages(['user_id' => 'This member is already the framework owner.']);
            }
            $locked->forceFill(['owner_user_id' => $userId, 'lock_version' => (int) $locked->lock_version + 1])->save();
            $this->audit->record('academic.curriculum.framework.owner_changed', $actor, $this->tenantId(), $locked, [
                'previous_owner_user_id' => $before, 'owner_user_id' => $userId, 'reason' => trim($reason),
            ]);

            return $locked;
        }, 3);
    }

    /**
     * @param  array<string,mixed>  $data
     *
     * @phpstan-return array{template: AcademicCurriculumChecklistTemplate, policy: AcademicCurriculumGovernancePolicy}
     */
    public function publishChecklistAndPolicy(array $data, User $actor): array
    {
        return DB::transaction(function () use ($data, $actor): array {
            $institution = Institution::query()->lockForUpdate()->firstOrFail();
            $scope = $data['scope'];
            $frameworkId = $data['framework_id'] ?? null;
            $competencyFrameworkId = $data['competency_framework_id'] ?? null;
            if ($scope === 'curriculum' && $competencyFrameworkId !== null || $scope === 'competency' && $frameworkId !== null) {
                throw ValidationException::withMessages(['scope' => 'Choose a policy scope that matches the selected framework.']);
            }
            if ($scope === 'competency' && $data['reviewer_eligibility'] === 'framework_assignees') {
                throw ValidationException::withMessages(['reviewer_eligibility' => 'Framework-assignee eligibility applies only to curriculum frameworks.']);
            }
            if ($scope === 'curriculum' && $data['reviewer_eligibility'] === 'framework_assignees' && $frameworkId === null) {
                throw ValidationException::withMessages(['reviewer_eligibility' => 'Framework-assignee eligibility requires a specific curriculum framework.']);
            }
            if ($frameworkId !== null) {
                $this->framework($frameworkId);
            }
            if ($competencyFrameworkId !== null) {
                $this->competencyFramework($competencyFrameworkId);
            }
            $checkItems = $data['checklist_items'] ?? [];
            if ($data['require_checklist_completion'] && $checkItems === []) {
                throw ValidationException::withMessages(['checklist_items' => 'A required checklist must contain at least one item.']);
            }
            $template = null;
            if ($checkItems !== []) {
                $templateCode = Str::upper(trim($data['checklist_code']));
                $templateVersion = (int) AcademicCurriculumChecklistTemplate::query()->where('institution_id', $institution->getKey())->where('code', $templateCode)->max('version_number') + 1;
                $template = AcademicCurriculumChecklistTemplate::query()->create([
                    'institution_id' => $institution->getKey(), 'framework_id' => $frameworkId, 'code' => $templateCode,
                    'title' => trim($data['checklist_title']), 'version_number' => $templateVersion, 'status' => 'published',
                    'created_by' => $actor->getKey(), 'published_at' => now(),
                ]);
                foreach (array_values($checkItems) as $order => $item) {
                    AcademicCurriculumChecklistItem::query()->create([
                        'template_id' => $template->getKey(), 'category' => trim($item['category']), 'prompt' => trim($item['prompt']),
                        'is_required' => (bool) ($item['is_required'] ?? true), 'requires_note' => (bool) ($item['requires_note'] ?? false), 'sort_order' => $order,
                    ]);
                }
            }
            $keyColumn = $scope === 'curriculum' ? 'framework_id' : 'competency_framework_id';
            $keyValue = $scope === 'curriculum' ? $frameworkId : $competencyFrameworkId;
            $previous = AcademicCurriculumGovernancePolicy::query()->where('institution_id', $institution->getKey())->where('scope', $scope)
                ->when($keyValue === null, fn ($query) => $query->whereNull($keyColumn), fn ($query) => $query->where($keyColumn, $keyValue))
                ->where('status', 'active')->orderByDesc('version_number')->lockForUpdate()->first();
            $effectiveFrom = $data['effective_from'];
            if ($previous && $effectiveFrom <= Carbon::parse((string) $previous->effective_from)->toDateString()) {
                throw ValidationException::withMessages(['effective_from' => 'A new policy version must begin after the current version.']);
            }
            if ($previous) {
                $previous->forceFill(['status' => 'superseded', 'effective_until' => date('Y-m-d', strtotime($effectiveFrom.' -1 day'))])->save();
            }
            $version = (int) AcademicCurriculumGovernancePolicy::query()->where('institution_id', $institution->getKey())->where('scope', $scope)
                ->when($keyValue === null, fn ($query) => $query->whereNull($keyColumn), fn ($query) => $query->where($keyColumn, $keyValue))
                ->max('version_number') + 1;
            $policy = AcademicCurriculumGovernancePolicy::query()->create([
                'institution_id' => $institution->getKey(), 'framework_id' => $frameworkId, 'competency_framework_id' => $competencyFrameworkId,
                'checklist_template_id' => $template?->getKey(), 'version_number' => $version, 'scope' => $scope, 'status' => $effectiveFrom > today()->format('Y-m-d') ? 'scheduled' : 'active',
                'reviewer_eligibility' => $data['reviewer_eligibility'], 'approval_authority' => 'curriculum_review',
                'publication_authority' => 'curriculum_publish', 'minimum_reviewers' => $data['minimum_reviewers'],
                'require_independent_reviewer' => (bool) $data['require_independent_reviewer'], 'require_separate_publisher' => (bool) $data['require_separate_publisher'],
                'require_changes_note' => (bool) $data['require_changes_note'], 'require_rejection_note' => (bool) $data['require_rejection_note'],
                'require_checklist_completion' => (bool) $data['require_checklist_completion'], 'allow_resubmission' => (bool) $data['allow_resubmission'],
                'effective_from' => $effectiveFrom, 'effective_until' => $data['effective_until'] ?? null, 'created_by' => $actor->getKey(),
            ]);
            $this->audit->record('academic.curriculum.governance_policy.published', $actor, $this->tenantId(), $policy, [
                'scope' => $scope, 'policy_version' => $version, 'framework_id' => $frameworkId,
                'competency_framework_id' => $competencyFrameworkId, 'checklist_version' => $template?->version_number,
            ]);

            return ['template' => $template, 'policy' => $policy];
        }, 3);
    }

    /** @param array<string,mixed> $data */
    public function createResource(array $data, User $actor): AcademicCurriculumResource
    {
        return DB::transaction(function () use ($data, $actor): AcademicCurriculumResource {
            $institution = Institution::query()->lockForUpdate()->firstOrFail();
            $version = isset($data['curriculum_version_id']) ? AcademicCurriculumVersion::query()->with('curriculum')->findOrFail($data['curriculum_version_id']) : null;
            if ($version && (int) $version->curriculum->institution_id !== (int) $institution->getKey()) {
                throw ValidationException::withMessages(['curriculum_version_id' => 'Choose a curriculum version from this institution.']);
            }
            if ($version && $version->status === 'published' && $data['status'] !== 'draft') {
                throw ValidationException::withMessages(['status' => 'Resources linked to a published curriculum begin as drafts for a new controlled revision.']);
            }
            if (isset($data['node_id']) && (! $version || ! $version->nodes()->whereKey($data['node_id'])->exists())) {
                throw ValidationException::withMessages(['node_id' => 'Choose a node from the selected curriculum version.']);
            }
            if (isset($data['learning_outcome_id']) && ! DB::table('academic_learning_outcomes')->where('tenant_id', $this->tenantId())->where('institution_id', $institution->getKey())->where('id', $data['learning_outcome_id'])->exists()) {
                throw ValidationException::withMessages(['learning_outcome_id' => 'Choose an outcome from this institution.']);
            }
            $document = isset($data['academic_document_id']) ? AcademicDocument::query()->where('institution_id', $institution->getKey())->where('status', 'approved')->findOrFail($data['academic_document_id']) : null;
            $type = $data['resource_type'];
            if ($type === 'private_document' && ! $document) {
                throw ValidationException::withMessages(['academic_document_id' => 'Select an approved private academic document.']);
            }
            if ($type !== 'private_document' && $document) {
                throw ValidationException::withMessages(['academic_document_id' => 'A private document may only be attached to a private document resource.']);
            }
            if ($type === 'external_link' && ! $this->safeHttpsUrl($data['source_url'] ?? null)) {
                throw ValidationException::withMessages(['source_url' => 'Use an HTTPS link without embedded credentials or a local/private host.']);
            }
            if ($type === 'learning_reference' && empty($data['node_id']) && empty($data['learning_outcome_id']) && empty($data['curriculum_version_id'])) {
                throw ValidationException::withMessages(['curriculum_version_id' => 'An internal learning reference must point to a curriculum version, node or outcome.']);
            }
            if ($type !== 'external_link' && ! in_array($type, ['private_document', 'learning_reference'], true)) {
                throw ValidationException::withMessages(['resource_type' => 'Choose a supported curriculum resource type.']);
            }
            $code = Str::upper(trim($data['code']));
            $replacement = empty($data['replace_resource_id']) ? null : AcademicCurriculumResource::query()->where('institution_id', $institution->getKey())->findOrFail($data['replace_resource_id']);
            if ($replacement && $replacement->code !== $code) {
                throw ValidationException::withMessages(['replace_resource_id' => 'A replacement must retain the source resource code.']);
            }
            $resourceVersion = (int) AcademicCurriculumResource::query()->where('institution_id', $institution->getKey())->where('code', $code)->max('version_number') + 1;
            $resource = AcademicCurriculumResource::query()->create([
                ...collect($data)->except(['replace_resource_id', 'based_on_resource_id'])->all(), 'institution_id' => $institution->getKey(), 'code' => $code,
                'version_number' => $resourceVersion, 'created_by' => $actor->getKey(), 'status' => 'draft',
            ]);
            if ($replacement) {
                $resource->forceFill(['based_on_resource_id' => $replacement->getKey()])->save();
            }
            $this->audit->record('academic.curriculum.resource.created', $actor, $this->tenantId(), $resource, ['code' => $code, 'version' => $resourceVersion, 'resource_type' => $type]);

            return $resource;
        }, 3);
    }

    public function transitionResource(AcademicCurriculumResource $resource, string $status, User $actor): void
    {
        if (! in_array($status, ['in_review', 'approved', 'rejected', 'archived'], true)) {
            throw ValidationException::withMessages(['status' => 'Choose a supported resource review state.']);
        }
        DB::transaction(function () use ($resource, $status, $actor): void {
            $locked = AcademicCurriculumResource::query()->lockForUpdate()->findOrFail($resource->getKey());
            $allowed = match ($status) {
                'in_review' => $locked->status === 'draft' || $locked->status === 'rejected',
                'approved' => $locked->status === 'in_review' && (int) $locked->created_by !== (int) $actor->getKey(),
                'rejected' => $locked->status === 'in_review' && (int) $locked->created_by !== (int) $actor->getKey(),
                'archived' => in_array($locked->status, ['draft', 'approved', 'rejected'], true),
            };
            if (! $allowed) {
                throw new ConflictHttpException('This resource review transition is no longer available.');
            }
            $locked->forceFill(['status' => $status, 'reviewed_by' => in_array($status, ['approved', 'rejected'], true) ? $actor->getKey() : $locked->reviewed_by,
                'reviewed_at' => in_array($status, ['approved', 'rejected'], true) ? now() : $locked->reviewed_at,
                'archived_at' => $status === 'archived' ? now() : null])->save();
            $this->audit->record('academic.curriculum.resource.'.$status, $actor, $this->tenantId(), $locked, ['code' => $locked->code, 'version' => $locked->version_number]);
        }, 3);
    }

    /** Re-check integrity and current scanner signatures before every private resource release. */
    public function verifyResourceDownload(AcademicCurriculumResource $resource, AdmissionDocumentScanner $scanner, User $actor): string
    {
        abort_unless($resource->status === 'approved' && $resource->resource_type === 'private_document' && $resource->academic_document_id !== null, 404);
        $document = AcademicDocument::query()->where('status', 'approved')->findOrFail($resource->academic_document_id);
        $tenantId = $this->tenantId();
        $expectedPrefix = 'academic/tenants/'.$tenantId.'/documents/';
        $relative = (string) $document->private_file_path;
        abort_unless(str_starts_with($relative, $expectedPrefix)
            && preg_match('/^academic\/tenants\/'.preg_quote((string) $tenantId, '/').'\/documents\/[0-9a-fA-F-]{36}\.(pdf|docx|xlsx)$/D', $relative) === 1, 404);
        $disk = Storage::disk('local');
        abort_unless($disk->exists($relative), 404);
        $absolute = $disk->path($relative);
        abort_unless(is_file($absolute) && ! is_link($absolute) && is_readable($absolute), 404);
        $digest = hash_file('sha256', $absolute);
        abort_unless(is_string($digest) && hash_equals((string) $document->file_sha256, $digest), 409, 'The controlled document integrity check failed.');
        $result = $scanner->scan($absolute);
        if ($result->status !== 'clean') {
            $this->audit->record('academic.curriculum.resource.download_blocked', $actor, $tenantId, $resource, ['document_id' => $document->getKey(), 'scan_status' => $result->status, 'result_code' => $result->code]);
            abort($result->status === 'unavailable' ? 503 : 404, 'This document is not available for download.');
        }
        $this->audit->record('academic.curriculum.resource.downloaded', $actor, $tenantId, $resource, ['document_id' => $document->getKey(), 'scanner' => $scanner->name()]);

        return $relative;
    }

    /** @param array<string,mixed> $data */
    public function createCompetencyFramework(array $data, User $actor): AcademicCompetencyFramework
    {
        $institution = Institution::query()->firstOrFail();
        $ownerId = (int) ($data['owner_user_id'] ?? $actor->getKey());
        $this->assertMember($ownerId, (int) $institution->getKey(), 'academic.curriculum.manage');
        $framework = AcademicCompetencyFramework::query()->create([
            ...collect($data)->except(['owner_user_id'])->all(), 'institution_id' => $institution->getKey(), 'owner_user_id' => $ownerId,
            'status' => 'draft', 'lock_version' => 1,
        ]);
        $this->audit->record('academic.curriculum.competency_framework.created', $actor, $this->tenantId(), $framework, ['code' => $framework->code, 'owner_user_id' => $ownerId]);

        return $framework;
    }

    /** @param array<string,mixed> $data */
    public function addCompetency(int $frameworkId, array $data, User $actor): AcademicCompetency
    {
        return DB::transaction(function () use ($frameworkId, $data, $actor): AcademicCompetency {
            $framework = $this->competencyFramework($frameworkId, true);
            $this->assertCompetencyEditor($framework, $actor);
            if ($framework->status !== 'draft' && $framework->status !== 'changes_requested') {
                throw new ConflictHttpException('Only a draft competency framework can be edited.');
            }
            $parentId = $data['parent_id'] ?? null;
            if ($parentId !== null) {
                AcademicCompetency::query()->where('framework_id', $framework->getKey())->findOrFail($parentId);
            }
            $competency = AcademicCompetency::query()->create([
                ...$data, 'framework_id' => $framework->getKey(), 'status' => 'active',
                'mastery_levels' => $data['mastery_levels'] ?? null, 'evidence_requirements' => $data['evidence_requirements'] ?? null,
            ]);
            $framework->increment('lock_version');
            $this->audit->record('academic.curriculum.competency.created', $actor, $this->tenantId(), $competency, ['framework_id' => $framework->getKey()]);

            return $competency;
        }, 3);
    }

    /** @param list<int> $reviewerIds */
    public function submitCompetencyFramework(AcademicCompetencyFramework $framework, array $reviewerIds, User $actor): void
    {
        DB::transaction(function () use ($framework, $reviewerIds, $actor): void {
            $locked = $this->competencyFramework($framework->getKey(), true);
            $this->assertCompetencyEditor($locked, $actor);
            if (! in_array($locked->status, ['draft', 'changes_requested', 'approved'], true)) {
                throw new ConflictHttpException('Only a draft, revision-requested, or stale-approved competency framework can be submitted.');
            }
            $competencies = AcademicCompetency::query()->where('framework_id', $locked->getKey())->count();
            if ($competencies === 0) {
                throw ValidationException::withMessages(['framework' => 'Add at least one competency before submitting this framework.']);
            }
            $policy = $this->activePolicy('competency', (int) $locked->institution_id, null, (int) $locked->getKey());
            if ($locked->status === 'approved') {
                $priorPolicyId = DB::table('academic_competency_framework_reviews')->where('framework_id', $locked->getKey())->where('review_round', $locked->review_round)->where('decision', 'approve')->orderByDesc('id')->value('policy_version_id');
                if ($priorPolicyId === ($policy?->getKey())) {
                    throw new ConflictHttpException('This competency framework has no stale approval that requires resubmission.');
                }
            }
            $reviewerIds = array_values(array_unique(array_map('intval', $reviewerIds)));
            $minimum = (int) ($policy->minimum_reviewers ?? 1);
            if (count($reviewerIds) < $minimum || count($reviewerIds) > 3) {
                throw ValidationException::withMessages(['reviewer_user_ids' => "Select between {$minimum} and 3 distinct authorized reviewers."]);
            }
            if (($policy->require_independent_reviewer ?? true) && in_array((int) $actor->getKey(), $reviewerIds, true)) {
                throw ValidationException::withMessages(['reviewer_user_ids' => 'The framework owner cannot review their own submission.']);
            }
            $items = $policy?->checklistTemplate->items ?? collect();
            $snapshot = $items->map(fn (AcademicCurriculumChecklistItem $item): array => [
                'id' => $item->getKey(), 'category' => $item->category, 'prompt' => $item->prompt,
                'is_required' => (bool) $item->is_required, 'requires_note' => (bool) $item->requires_note,
            ])->values()->all();
            foreach ($reviewerIds as $reviewerId) {
                $this->assertEligibleReviewer($reviewerId, (int) $locked->institution_id, $policy, null, $locked);
            }
            $round = (int) $locked->review_round + 1;
            $locked->forceFill(['status' => 'in_review', 'submitted_by' => $actor->getKey(), 'submitted_at' => now(), 'reviewer_user_id' => $reviewerIds[0], 'review_round' => $round, 'lock_version' => (int) $locked->lock_version + 1])->save();
            foreach ($reviewerIds as $reviewerId) {
                DB::table('academic_competency_framework_reviews')->insert([
                    'tenant_id' => $this->tenantId(), 'framework_id' => $locked->getKey(), 'reviewer_user_id' => $reviewerId,
                    'policy_version_id' => $policy?->getKey(), 'decision' => 'assigned', 'review_round' => $round, 'checklist_snapshot' => json_encode($snapshot),
                    'assigned_at' => now(), 'created_at' => now(), 'updated_at' => now(),
                ]);
            }
            $this->audit->record('academic.curriculum.competency_framework.submitted', $actor, $this->tenantId(), $locked, ['reviewer_count' => count($reviewerIds), 'policy_version' => $policy?->version_number]);
        }, 3);
    }

    /** @param array<int|string,array<string,mixed>> $responses */
    public function decideCompetencyFramework(AcademicCompetencyFramework $framework, string $decision, ?string $note, array $responses, User $actor): void
    {
        if (! in_array($decision, ['approve', 'request_changes', 'reject'], true)) {
            throw ValidationException::withMessages(['decision' => 'Choose an available review decision.']);
        }
        DB::transaction(function () use ($framework, $decision, $note, $responses, $actor): void {
            $locked = $this->competencyFramework($framework->getKey(), true);
            if ($locked->status !== 'in_review' || (int) $locked->submitted_by === (int) $actor->getKey()) {
                throw new ConflictHttpException('This competency framework is no longer available for this review.');
            }
            $membership = TenantMembership::query()->where('tenant_id', $this->tenantId())->where('institution_id', $locked->institution_id)->where('user_id', $actor->getKey())->where('status', 'active')->first();
            if (! $membership || ! $membership->hasPermission('academic.curriculum.review')) {
                throw ValidationException::withMessages(['framework' => 'An active curriculum reviewer membership is required.']);
            }
            $review = DB::table('academic_competency_framework_reviews')->where('tenant_id', $this->tenantId())->where('framework_id', $locked->getKey())->where('review_round', $locked->review_round)->where('reviewer_user_id', $actor->getKey())->where('decision', 'assigned')->lockForUpdate()->first();
            if (! $review) {
                throw new ConflictHttpException('This user has no active review assignment for the competency framework.');
            }
            $policy = $review->policy_version_id ? AcademicCurriculumGovernancePolicy::query()->find($review->policy_version_id) : null;
            $normalized = $this->validateChecklistResponses((array) json_decode((string) $review->checklist_snapshot, true), $responses, $policy->require_checklist_completion ?? false);
            $this->validateDecisionNote($decision, $note, $policy);
            DB::table('academic_competency_framework_reviews')->where('id', $review->id)->update([
                'decision' => $decision, 'decided_by' => $actor->getKey(), 'review_note' => $note,
                'checklist_responses' => json_encode($normalized), 'decided_at' => now(), 'updated_at' => now(),
            ]);
            if ($decision !== 'approve') {
                DB::table('academic_competency_framework_reviews')->where('framework_id', $locked->getKey())->where('review_round', $locked->review_round)->where('decision', 'assigned')->update(['decision' => 'cancelled', 'updated_at' => now()]);
                $next = $decision === 'request_changes' ? 'changes_requested' : 'rejected';
            } else {
                $pending = DB::table('academic_competency_framework_reviews')->where('framework_id', $locked->getKey())->where('review_round', $locked->review_round)->where('decision', 'assigned')->exists();
                $approved = DB::table('academic_competency_framework_reviews')->where('framework_id', $locked->getKey())->where('review_round', $locked->review_round)->where('decision', 'approve')->count();
                $next = $approved >= (int) ($policy->minimum_reviewers ?? 1) && ! $pending ? 'approved' : 'in_review';
            }
            $locked->forceFill(['status' => $next, 'reviewer_user_id' => $actor->getKey(), 'lock_version' => (int) $locked->lock_version + 1])->save();
            $this->audit->record('academic.curriculum.competency_framework.review_decided', $actor, $this->tenantId(), $locked, ['decision' => $decision, 'resulting_status' => $next]);
        }, 3);
    }

    public function publishCompetencyFramework(AcademicCompetencyFramework $framework, User $actor): void
    {
        DB::transaction(function () use ($framework, $actor): void {
            $locked = $this->competencyFramework($framework->getKey(), true);
            if ($locked->status !== 'approved') {
                throw new ConflictHttpException('Only an approved competency framework can be published.');
            }
            $publisherMembership = TenantMembership::query()->where('tenant_id', $this->tenantId())->where('institution_id', $locked->institution_id)->where('user_id', $actor->getKey())->where('status', 'active')->first();
            if (! $publisherMembership || ! $publisherMembership->hasPermission('academic.curriculum.publish')) {
                throw ValidationException::withMessages(['framework' => 'An active member with competency publication permission is required.']);
            }
            $policy = $this->activePolicy('competency', (int) $locked->institution_id, null, (int) $locked->getKey());
            $reviewPolicyId = DB::table('academic_competency_framework_reviews')->where('framework_id', $locked->getKey())->where('review_round', $locked->review_round)->where('decision', 'approve')->orderByDesc('id')->value('policy_version_id');
            if ($reviewPolicyId !== ($policy?->getKey())) {
                throw new ConflictHttpException('The competency approval is stale under the current policy; resubmit before publishing.');
            }
            $reviewers = DB::table('academic_competency_framework_reviews')->where('framework_id', $locked->getKey())->where('review_round', $locked->review_round)->where('decision', 'approve')->pluck('reviewer_user_id');
            if ($reviewers->count() < (int) ($policy->minimum_reviewers ?? 1)) {
                throw new ConflictHttpException('The required number of independent competency approvals has not been recorded.');
            }
            if (($policy->require_separate_publisher ?? true) && ($reviewers->contains($actor->getKey()) || (int) $locked->submitted_by === (int) $actor->getKey())) {
                throw ValidationException::withMessages(['framework' => 'The author and approving reviewers cannot publish this framework.']);
            }
            AcademicCompetencyFramework::query()->where('institution_id', $locked->institution_id)->where('code', $locked->code)->where('status', 'published')->whereKeyNot($locked->getKey())->update(['status' => 'retired', 'updated_at' => now()]);
            $locked->forceFill(['status' => 'published', 'published_by' => $actor->getKey(), 'published_at' => now(), 'effective_from' => $locked->effective_from ?? today(), 'is_shared_template' => (bool) $locked->is_shared_template, 'lock_version' => (int) $locked->lock_version + 1])->save();
            $this->audit->record('academic.curriculum.competency_framework.published', $actor, $this->tenantId(), $locked, ['code' => $locked->code, 'version' => $locked->version_number]);
        }, 3);
    }

    public function createCompetencyRevision(AcademicCompetencyFramework $framework, string $note, User $actor): AcademicCompetencyFramework
    {
        return DB::transaction(function () use ($framework, $note, $actor): AcademicCompetencyFramework {
            $source = $this->competencyFramework($framework->getKey(), true);
            $this->assertCompetencyEditor($source, $actor);
            if ($source->status !== 'published' && $source->status !== 'retired') {
                throw new ConflictHttpException('Only a published framework can be revised.');
            }
            $version = (int) AcademicCompetencyFramework::query()->where('institution_id', $source->institution_id)->where('code', $source->code)->max('version_number') + 1;
            $revision = $source->replicate(['id', 'created_at', 'updated_at', 'tenant_id']);
            $revision->forceFill(['tenant_id' => $this->tenantId(), 'version_number' => $version, 'based_on_framework_id' => $source->getKey(), 'status' => 'draft',
                'submitted_by' => null, 'reviewer_user_id' => null, 'published_by' => null, 'submitted_at' => null, 'published_at' => null, 'review_round' => 0,
                'effective_from' => null, 'effective_until' => null, 'lock_version' => 1, 'owner_user_id' => $actor->getKey()])->save();
            $map = [];
            foreach (AcademicCompetency::query()->where('framework_id', $source->getKey())->orderBy('sort_order')->get() as $competency) {
                $copy = $competency->replicate(['id', 'created_at', 'updated_at', 'tenant_id', 'framework_id', 'parent_id']);
                $copy->forceFill(['tenant_id' => $this->tenantId(), 'framework_id' => $revision->getKey(), 'parent_id' => $competency->parent_id ? ($map[$competency->parent_id] ?? null) : null])->save();
                $map[$competency->getKey()] = $copy->getKey();
            }
            $this->audit->record('academic.curriculum.competency_framework.revision_created', $actor, $this->tenantId(), $revision, ['based_on_framework_id' => $source->getKey(), 'version' => $version, 'revision_note' => trim($note)]);

            return $revision;
        }, 3);
    }

    /** @param array<string,mixed> $data */
    public function mapProgramRequirement(int $programId, int $competencyId, array $data, User $actor): AcademicProgramCompetencyRequirement
    {
        $institution = Institution::query()->firstOrFail();
        $program = AcademicProgram::query()->where('institution_id', $institution->getKey())->findOrFail($programId);
        $competency = AcademicCompetency::query()->with('framework')->whereHas('framework', fn ($query) => $query->where('institution_id', $institution->getKey())->where('status', 'published'))->findOrFail($competencyId);
        $requirement = AcademicProgramCompetencyRequirement::query()->updateOrCreate(
            ['academic_program_id' => $program->getKey(), 'competency_id' => $competency->getKey()],
            ['institution_id' => $institution->getKey(), 'expected_level' => $data['expected_level'] ?? null, 'evidence_requirements' => $data['evidence_requirements'] ?? null,
                'is_required' => (bool) ($data['is_required'] ?? true), 'status' => 'active', 'created_by' => $actor->getKey()],
        );
        $this->audit->record('academic.curriculum.competency.program_requirement_mapped', $actor, $this->tenantId(), $requirement, ['program_id' => $program->getKey(), 'competency_id' => $competency->getKey()]);

        return $requirement;
    }

    /** @return list<array{competency_id:int,title:string,expected_level:?string}> */
    public function competencyGaps(AcademicCurriculumVersion $version): array
    {
        $version->loadMissing('curriculum');
        $requirements = AcademicProgramCompetencyRequirement::query()->where('academic_program_id', $version->curriculum->academic_program_id)->where('status', 'active')->where('is_required', true)->with('competency')->get();
        $mapped = DB::table('academic_curriculum_node_competencies')->where('curriculum_version_id', $version->getKey())->pluck('competency_id')->map(fn ($id): int => (int) $id)->unique();

        return $requirements->reject(fn (AcademicProgramCompetencyRequirement $requirement): bool => $mapped->contains((int) $requirement->competency_id))
            ->map(fn (AcademicProgramCompetencyRequirement $requirement): array => ['competency_id' => (int) $requirement->competency_id, 'title' => (string) $requirement->competency->title, 'expected_level' => $requirement->expected_level])->values()->all();
    }

    private function activePolicy(string $scope, int $institutionId, ?int $frameworkId, ?int $competencyFrameworkId): ?AcademicCurriculumGovernancePolicy
    {
        return AcademicCurriculumGovernancePolicy::query()->with('checklistTemplate.items')->where('scope', $scope)->where('institution_id', $institutionId)
            ->where('effective_from', '<=', today())->where(fn ($query) => $query->whereNull('effective_until')->orWhere('effective_until', '>=', today()))
            ->whereIn('status', ['active', 'scheduled', 'superseded'])
            ->when($scope === 'curriculum', fn ($query) => $query->where(fn ($q) => $q->where('framework_id', $frameworkId)->orWhereNull('framework_id')))
            ->when($scope === 'competency', fn ($query) => $query->where(fn ($q) => $q->where('competency_framework_id', $competencyFrameworkId)->orWhereNull('competency_framework_id')))
            ->orderByRaw($scope === 'curriculum'
                ? 'CASE WHEN framework_id IS NULL THEN 1 ELSE 0 END'
                : 'CASE WHEN competency_framework_id IS NULL THEN 1 ELSE 0 END')
            ->orderByDesc('version_number')->first();
    }

    private function validateDecisionNote(string $decision, ?string $note, ?AcademicCurriculumGovernancePolicy $policy): void
    {
        $required = match ($decision) {
            'request_changes' => $policy->require_changes_note ?? true,
            'reject' => $policy->require_rejection_note ?? true,
            default => false,
        };
        if ($required && trim((string) $note) === '') {
            throw ValidationException::withMessages(['review_note' => 'Add a note explaining this decision.']);
        }
    }

    /**
     * @param  list<array<string,mixed>>  $snapshot
     *
     * @phpstan-param array<int|string,mixed> $responses
     *
     * @phpstan-return list<array<string,mixed>>
     */
    private function validateChecklistResponses(array $snapshot, array $responses, bool $required): array
    {
        $byId = [];
        foreach ($snapshot as $item) {
            $id = (string) ($item['id'] ?? '');
            if ($id !== '') {
                $byId[$id] = $item;
            }
        }
        foreach ($responses as $id => $response) {
            if (! isset($byId[(string) $id]) || ! is_array($response)) {
                throw ValidationException::withMessages(['checklist_responses' => 'The checklist response does not belong to this review snapshot.']);
            }
        }
        $result = [];
        foreach ($byId as $id => $item) {
            $response = $responses[$id] ?? $responses[(int) $id] ?? [];
            $complete = (bool) ($response['complete'] ?? false);
            $note = trim((string) ($response['note'] ?? ''));
            $evidence = trim((string) ($response['evidence_reference'] ?? ''));
            if (($required && ($item['is_required'] ?? false) && ! $complete)
                || (($item['requires_note'] ?? false) && $complete && $note === '')) {
                throw ValidationException::withMessages(['checklist_responses' => 'Complete all required checklist items and notes before deciding.']);
            }
            if (mb_strlen($note) > 2000 || mb_strlen($evidence) > 500) {
                throw ValidationException::withMessages(['checklist_responses' => 'Checklist notes or evidence references exceed the allowed length.']);
            }
            $result[] = ['item_id' => (int) $id, 'complete' => $complete, 'note' => $note ?: null, 'evidence_reference' => $evidence ?: null];
        }

        return $result;
    }

    private function assertEligibleReviewer(int $userId, int $institutionId, ?AcademicCurriculumGovernancePolicy $policy, ?AcademicCurriculumFramework $framework, ?AcademicCompetencyFramework $competencyFramework): void
    {
        $membership = TenantMembership::query()->where('tenant_id', $this->tenantId())->where('institution_id', $institutionId)->where('user_id', $userId)->where('status', 'active')->first();
        if (! $membership || ! $membership->hasPermission('academic.curriculum.review')) {
            throw ValidationException::withMessages(['reviewer_user_ids' => 'Each reviewer must be an active institution member with curriculum review permission.']);
        }
        if ($policy?->reviewer_eligibility === 'institution_wide' && $membership->campus_id !== null) {
            throw ValidationException::withMessages(['reviewer_user_ids' => 'This policy requires institution-wide reviewers.']);
        }
        if ($policy?->reviewer_eligibility === 'framework_assignees') {
            $frameworkId = $framework?->getKey();
            if ($frameworkId === null) {
                throw ValidationException::withMessages(['reviewer_user_ids' => 'This reviewer policy requires a curriculum framework assignment.']);
            }
            $assigned = AcademicCurriculumFrameworkAdministrator::query()->where('framework_id', $frameworkId)->where('user_id', $userId)->whereNull('revoked_at')->where(fn ($query) => $query->whereNull('effective_from')->orWhere('effective_from', '<=', today()))->where(fn ($query) => $query->whereNull('effective_until')->orWhere('effective_until', '>=', today()))->exists();
            if (! $assigned) {
                throw ValidationException::withMessages(['reviewer_user_ids' => 'The reviewer must have an active framework administration assignment.']);
            }
        }
        if ($policy?->reviewer_eligibility === 'program_scope' && $membership->campus_id !== null) {
            throw ValidationException::withMessages(['reviewer_user_ids' => 'This reviewer policy requires an institution-wide eligible reviewer.']);
        }
    }

    private function assertMember(int $userId, int $institutionId, string $permission, int|string|null $campusId = null): TenantMembership
    {
        $membership = TenantMembership::query()->where('tenant_id', $this->tenantId())->where('institution_id', $institutionId)
            ->where('user_id', $userId)->where('status', 'active')
            ->when($campusId !== null, fn ($query) => $query->where(fn ($q) => $q->whereNull('campus_id')->orWhere('campus_id', $campusId)))
            ->first();
        if (! $membership || ! $membership->hasPermission($permission)) {
            throw ValidationException::withMessages(['user_id' => 'Choose an active institution member who already has the required permission. Assignments do not grant permissions.']);
        }

        return $membership;
    }

    public function assertFrameworkAdministrationAccess(AcademicCurriculumFramework $framework, User $actor, ?int $programId = null): void
    {
        $membership = TenantMembership::query()->where('tenant_id', $this->tenantId())->where('institution_id', $framework->institution_id)->where('user_id', $actor->getKey())->where('status', 'active')->first();
        if (! $membership || ! $membership->hasPermission('academic.curriculum.manage')) {
            abort(403);
        }
        if ((int) $framework->owner_user_id === (int) $actor->getKey()) {
            return;
        }
        $assignment = AcademicCurriculumFrameworkAdministrator::query()->where('framework_id', $framework->getKey())
            ->where('user_id', $actor->getKey())->whereNull('revoked_at')
            ->where(fn ($query) => $query->whereNull('effective_from')->orWhere('effective_from', '<=', today()))
            ->where(fn ($query) => $query->whereNull('effective_until')->orWhere('effective_until', '>=', today()))
            ->where(fn ($query) => $query->whereNull('campus_id')->orWhere('campus_id', $membership->campus_id))
            ->where(fn ($query) => $programId === null ? $query->whereNull('academic_program_id') : $query->whereNull('academic_program_id')->orWhere('academic_program_id', $programId))
            ->exists();
        abort_unless($assignment, 403);
    }

    public function assertCurriculumAdministrationAccess(AcademicCurriculumVersion $version, User $actor): void
    {
        $version->loadMissing('curriculum');
        $curriculum = $version->curriculum;
        if ($curriculum->framework_id === null) {
            $this->assertMember((int) $actor->getKey(), (int) $curriculum->institution_id, 'academic.curriculum.manage');

            return;
        }
        $framework = AcademicCurriculumFramework::query()->where('institution_id', $curriculum->institution_id)->findOrFail($curriculum->framework_id);
        $this->assertFrameworkAdministrationAccess($framework, $actor, (int) $curriculum->academic_program_id);
    }

    private function assertOptionalScope(int|string|null $campusId, int|string|null $programId, int $institutionId): void
    {
        if ($campusId !== null && ! DB::table('campuses')->where('tenant_id', $this->tenantId())->where('institution_id', $institutionId)->where('id', $campusId)->exists()) {
            throw ValidationException::withMessages(['campus_id' => 'Choose a campus from this institution.']);
        }
        if ($programId !== null && ! DB::table('academic_programs')->where('tenant_id', $this->tenantId())->where('institution_id', $institutionId)->where('id', $programId)->exists()) {
            throw ValidationException::withMessages(['academic_program_id' => 'Choose a program from this institution.']);
        }
    }

    private function assertCompetencyEditor(AcademicCompetencyFramework $framework, User $actor): void
    {
        $membership = TenantMembership::query()->where('tenant_id', $this->tenantId())->where('institution_id', $framework->institution_id)->where('user_id', $actor->getKey())->where('status', 'active')->first();
        if (! $membership || ! $membership->hasPermission('academic.curriculum.manage')) {
            throw ValidationException::withMessages(['framework' => 'An active institution membership with curriculum management permission is required.']);
        }
    }

    private function framework(int $id, bool $lock = false): AcademicCurriculumFramework
    {
        $query = AcademicCurriculumFramework::query()->where('institution_id', Institution::query()->value('id'));
        if ($lock) {
            $query->lockForUpdate();
        }

        return $query->findOrFail($id);
    }

    private function competencyFramework(int $id, bool $lock = false): AcademicCompetencyFramework
    {
        $query = AcademicCompetencyFramework::query()->where('institution_id', Institution::query()->value('id'));
        if ($lock) {
            $query->lockForUpdate();
        }

        return $query->findOrFail($id);
    }

    private function effectiveAdministratorCount(int $frameworkId): int
    {
        $framework = AcademicCurriculumFramework::query()->findOrFail($frameworkId);
        $assignmentCount = AcademicCurriculumFrameworkAdministrator::query()->where('framework_id', $frameworkId)->where('responsibility', 'administrator')->whereNull('revoked_at')
            ->where(fn ($query) => $query->whereNull('effective_from')->orWhere('effective_from', '<=', today()))
            ->where(fn ($query) => $query->whereNull('effective_until')->orWhere('effective_until', '>=', today()))->count();
        $ownerActive = $framework->owner_user_id !== null && TenantMembership::query()->where('tenant_id', $this->tenantId())->where('institution_id', $framework->institution_id)->where('user_id', $framework->owner_user_id)->where('status', 'active')->exists();

        return $assignmentCount + (int) $ownerActive;
    }

    private function safeHttpsUrl(?string $url): bool
    {
        if (! $url || ! filter_var($url, FILTER_VALIDATE_URL)) {
            return false;
        }
        $parts = parse_url($url);
        if (! is_array($parts) || strtolower((string) ($parts['scheme'] ?? '')) !== 'https' || isset($parts['user']) || isset($parts['pass'])) {
            return false;
        }
        $host = strtolower(rtrim((string) ($parts['host'] ?? ''), '.'));
        if ($host === '' || $host === 'localhost' || str_ends_with($host, '.localhost') || str_ends_with($host, '.local') || filter_var($host, FILTER_VALIDATE_IP)) {
            return false;
        }

        return true;
    }

    private function tenantId(): int
    {
        return (int) $this->context->tenant()->getKey();
    }
}
