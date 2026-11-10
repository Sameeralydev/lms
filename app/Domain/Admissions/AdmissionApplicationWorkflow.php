<?php

namespace App\Domain\Admissions;

use App\Domain\Audit\AuditRecorder;
use App\Domain\Tenancy\TenantContext;
use App\Models\AdmissionApplicantProfile;
use App\Models\AdmissionApplication;
use App\Models\AdmissionApplicationRevision;
use App\Models\AdmissionDecision;
use App\Models\AdmissionFeeObligation;
use App\Models\AdmissionFormVersion;
use App\Models\AdmissionIntake;
use App\Models\AdmissionLifecycleEvent;
use App\Models\AdmissionOffer;
use App\Models\AdmissionOutboxEvent;
use App\Models\AdmissionSeatReservation;
use App\Models\AdmissionSetting;
use App\Models\AdmissionSisHandoff;
use App\Models\Institution;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class AdmissionApplicationWorkflow
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly AdmissionFormDefinition $forms,
        private readonly AuditRecorder $audit,
        private readonly GuardianAuthorizationWorkflow $guardians,
    ) {}

    /** @param array<string, mixed> $profile */
    public function createOrResume(AdmissionIntake $intake, User $applicant, array $profile): AdmissionApplication
    {
        $this->assertOpen($intake);
        $version = $this->publishedVersion($intake);

        return DB::transaction(function () use ($intake, $applicant, $profile, $version): AdmissionApplication {
            $lockedIntake = AdmissionIntake::query()->lockForUpdate()->findOrFail($intake->getKey());
            $this->assertOpen($lockedIntake);
            $institution = Institution::query()->firstOrFail();
            $existing = AdmissionApplication::query()
                ->where('intake_id', $intake->getKey())
                ->where('applicant_user_id', $applicant->getKey())
                ->lockForUpdate()
                ->first();
            if ($existing) {
                if ($existing->status === 'draft') {
                    $profileModel = $existing->profile()->firstOrFail();
                    $profileModel->fill($profile);
                    $profileModel->save();
                }

                return $existing;
            }
            $profileModel = AdmissionApplicantProfile::query()->firstOrNew(['user_id' => $applicant->getKey()]);
            $profileModel->fill($profile + ['institution_id' => $institution->getKey()]);
            $profileModel->institution_id = $institution->getKey();
            $profileModel->save();

            $application = AdmissionApplication::query()->create([
                'institution_id' => $institution->getKey(),
                'intake_id' => $intake->getKey(),
                'applicant_profile_id' => $profileModel->getKey(),
                'form_version_id' => $version->getKey(),
                'applicant_user_id' => $applicant->getKey(),
                'application_number' => 'APP-'.Str::upper(Str::random(12)),
                'status' => 'draft',
                'current_step' => 1,
                'answers' => [],
                'idempotency_key' => hash('sha256', Str::uuid()->toString()),
            ]);
            $this->event($application, null, 'draft', $applicant, 'application.created');
            $this->audit->record('admissions.application.created', $applicant, (int) $this->context->tenant()->getKey(), $application, ['intake_id' => $intake->getKey()]);

            return $application;
        });
    }

    /** @param array<string, mixed> $answers */
    public function saveDraft(AdmissionApplication $application, User $applicant, array $answers, int $step): AdmissionApplication
    {
        return DB::transaction(function () use ($application, $applicant, $answers, $step): AdmissionApplication {
            $locked = AdmissionApplication::query()->lockForUpdate()->findOrFail($application->getKey());
            $this->assertApplicantOwns($locked, $applicant);
            if ($locked->status !== 'draft') {
                throw ValidationException::withMessages(['application' => 'Only draft applications can be edited.']);
            }
            $definition = AdmissionFormVersion::query()->findOrFail($locked->form_version_id)->definition;
            $cleanAnswers = $this->forms->validateAnswers($answers, $definition, false);
            $revision = (int) $locked->revisions()->max('revision_number') + 1;
            $locked->fill(['answers' => $cleanAnswers, 'current_step' => max(1, min(20, $step))])->save();
            AdmissionApplicationRevision::query()->create([
                'application_id' => $locked->getKey(), 'form_version_id' => $locked->form_version_id,
                'revision_number' => $revision, 'answers' => $cleanAnswers, 'saved_by' => $applicant->getKey(), 'submitted_snapshot' => false,
            ]);

            return $locked;
        });
    }

    public function submit(AdmissionApplication $application, User $applicant): AdmissionApplication
    {
        return DB::transaction(function () use ($application, $applicant): AdmissionApplication {
            $locked = AdmissionApplication::query()->lockForUpdate()->findOrFail($application->getKey());
            $this->assertApplicantOwns($locked, $applicant);
            if ($locked->status === 'submitted') {
                return $locked;
            }
            if ($locked->status !== 'draft') {
                throw ValidationException::withMessages(['application' => 'This application cannot be submitted in its current state.']);
            }
            if ($this->guardians->requiresGuardian($locked) && ! $this->guardians->hasActiveGuardian($locked)) {
                throw ValidationException::withMessages(['guardian' => 'A verified guardian must accept the request and the institution must verify the relationship before this application can be submitted.']);
            }
            $intake = AdmissionIntake::query()->lockForUpdate()->findOrFail($locked->intake_id);
            $this->assertOpen($intake);
            $formVersion = AdmissionFormVersion::query()->findOrFail($locked->form_version_id);
            $answers = $this->forms->validateAnswers($locked->answers ?? [], $formVersion->definition);
            $this->assertRequiredDocumentsPresent($locked, $formVersion->definition, $answers);

            $revision = (int) $locked->revisions()->max('revision_number') + 1;
            AdmissionApplicationRevision::query()->create([
                'application_id' => $locked->getKey(), 'form_version_id' => $locked->form_version_id,
                'revision_number' => $revision, 'answers' => $answers, 'saved_by' => $applicant->getKey(), 'submitted_snapshot' => true,
            ]);
            $locked->forceFill(['answers' => $answers, 'status' => 'submitted', 'submitted_at' => now(), 'submission_count' => 1])->save();
            $this->event($locked, 'draft', 'submitted', $applicant, 'application.submitted');
            $this->outbox($locked, 'application.received');
            $this->createFeeObligation($locked, $intake);
            $this->audit->record('admissions.application.submitted', $applicant, (int) $this->context->tenant()->getKey(), $locked, ['application_number' => $locked->application_number, 'intake_id' => $intake->getKey()]);

            return $locked;
        });
    }

    public function transition(AdmissionApplication $application, string $target, User $actor, ?string $reason = null): AdmissionApplication
    {
        $allowed = [
            'submitted' => ['under_review', 'withdrawn'],
            'under_review' => ['documents_required', 'assessment_pending', 'interview_pending', 'decision_pending', 'withdrawn'],
            'documents_required' => ['under_review', 'withdrawn'],
            'assessment_pending' => ['interview_pending', 'decision_pending', 'withdrawn'],
            'interview_pending' => ['decision_pending', 'withdrawn'],
            'decision_pending' => ['waitlisted', 'rejected'],
            'waitlisted' => ['rejected', 'withdrawn'],
            'offered' => ['offer_accepted', 'offer_declined', 'offer_expired'],
            'offer_accepted' => ['ready_for_sis_handoff'],
        ];

        return DB::transaction(function () use ($application, $target, $actor, $allowed, $reason): AdmissionApplication {
            $locked = AdmissionApplication::query()->lockForUpdate()->findOrFail($application->getKey());
            $from = (string) $locked->status;
            if (! in_array($target, $allowed[$from] ?? [], true)) {
                throw ValidationException::withMessages(['status' => 'The requested admissions lifecycle transition is not allowed.']);
            }
            if (in_array($target, ['under_review', 'documents_required', 'assessment_pending', 'interview_pending', 'decision_pending'], true) && ! $locked->submitted_at) {
                throw ValidationException::withMessages(['application' => 'An application must be submitted before staff review.']);
            }
            if (in_array($target, ['offer_accepted', 'offer_declined', 'offer_expired'], true)) {
                throw ValidationException::withMessages(['status' => 'Offer responses must be recorded through the offer workflow.']);
            }
            $locked->forceFill(['status' => $target, 'withdrawn_at' => $target === 'withdrawn' ? now() : $locked->withdrawn_at])->save();
            $this->event($locked, $from, $target, $actor, 'application.status_changed', $reason);
            $this->audit->record('admissions.application.status_changed', $actor, (int) $this->context->tenant()->getKey(), $locked, ['from' => $from, 'to' => $target, 'reason_code' => $reason]);
            if ($target === 'documents_required') {
                $this->outbox($locked, 'application.documents_required');
            }

            return $locked;
        });
    }

    public function recommendDecision(AdmissionApplication $application, string $decision, User $actor, ?string $publicReason, ?string $privateReason): AdmissionDecision
    {
        if (! in_array($decision, ['admit', 'waitlist', 'reject'], true)) {
            throw ValidationException::withMessages(['decision' => 'Select a supported admissions decision.']);
        }

        return DB::transaction(function () use ($application, $decision, $actor, $publicReason, $privateReason): AdmissionDecision {
            $locked = AdmissionApplication::query()->lockForUpdate()->findOrFail($application->getKey());
            $isWaitlistReview = $locked->status === 'waitlisted';
            if (! in_array($locked->status, ['decision_pending', 'waitlisted'], true)) {
                throw ValidationException::withMessages(['application' => 'The application is not ready for a final decision.']);
            }
            $latestApproved = AdmissionDecision::query()->where('application_id', $locked->getKey())
                ->whereNotNull('approved_at')->orderByDesc('version_number')->first();
            if ($latestApproved !== null && ! $isWaitlistReview) {
                throw ValidationException::withMessages(['decision' => 'A final decision has already been approved for this application.']);
            }
            if ($isWaitlistReview && ($latestApproved?->decision !== 'waitlist' || ! in_array($decision, ['admit', 'reject'], true))) {
                throw ValidationException::withMessages(['decision' => 'A waitlisted application needs a new admit or reject recommendation after review.']);
            }
            if (AdmissionDecision::query()->where('application_id', $locked->getKey())->whereNull('approved_at')->exists()) {
                throw ValidationException::withMessages(['decision' => 'An admissions decision is already awaiting approval.']);
            }
            $version = (int) AdmissionDecision::query()->where('application_id', $locked->getKey())->max('version_number') + 1;
            $row = AdmissionDecision::query()->create([
                'application_id' => $locked->getKey(), 'version_number' => $version, 'decision' => $decision,
                'applicant_reason' => $publicReason, 'private_reason' => $privateReason,
                'recommended_by' => $actor->getKey(),
            ]);
            $this->audit->record('admissions.decision.recommended', $actor, (int) $this->context->tenant()->getKey(), $row, ['application_id' => $locked->getKey(), 'decision' => $decision, 'version' => $version]);

            return $row;
        });
    }

    public function approveDecision(AdmissionApplication $application, AdmissionDecision $decision, User $actor): AdmissionDecision
    {
        return DB::transaction(function () use ($application, $decision, $actor): AdmissionDecision {
            $locked = AdmissionApplication::query()->lockForUpdate()->findOrFail($application->getKey());
            $isWaitlistReview = $locked->status === 'waitlisted';
            if (! in_array($locked->status, ['decision_pending', 'waitlisted'], true)) {
                throw ValidationException::withMessages(['application' => 'The application is not awaiting a final decision.']);
            }
            $pending = AdmissionDecision::query()->where('application_id', $locked->getKey())->lockForUpdate()->findOrFail($decision->getKey());
            if ($pending->approved_at !== null || $pending->approved_by !== null) {
                throw ValidationException::withMessages(['decision' => 'This decision has already been approved.']);
            }
            if ($pending->recommended_by === null || (int) $pending->recommended_by === (int) $actor->getKey()) {
                throw ValidationException::withMessages(['decision' => 'A different authorized user must approve this recommendation.']);
            }
            if ($isWaitlistReview && ! in_array($pending->decision, ['admit', 'reject'], true)) {
                throw ValidationException::withMessages(['decision' => 'A waitlisted application can only be promoted to admit or rejected after review.']);
            }
            $pending->forceFill(['approved_by' => $actor->getKey(), 'approved_at' => now()])->save();
            if ($pending->decision === 'waitlist') {
                $this->transition($locked, 'waitlisted', $actor, 'decision.waitlist');
            } elseif ($pending->decision === 'reject') {
                $this->transition($locked, 'rejected', $actor, 'decision.reject');
                $this->outbox($locked, 'application.decision_rejected');
            }
            $this->audit->record('admissions.decision.approved', $actor, (int) $this->context->tenant()->getKey(), $pending, ['application_id' => $locked->getKey(), 'decision' => $pending->decision, 'version' => $pending->version_number]);

            return $pending;
        });
    }

    public function issueOffer(AdmissionApplication $application, User $actor, ?string $conditions, ?Carbon $acceptBy): AdmissionOffer
    {
        return DB::transaction(function () use ($application, $actor, $conditions, $acceptBy): AdmissionOffer {
            $locked = AdmissionApplication::query()->lockForUpdate()->findOrFail($application->getKey());
            if (! in_array($locked->status, ['decision_pending', 'waitlisted'], true)) {
                throw ValidationException::withMessages(['application' => 'Only an approved or waitlisted application can receive an offer.']);
            }
            $decision = AdmissionDecision::query()->where('application_id', $locked->getKey())->where('decision', 'admit')->whereNotNull('approved_at')->orderByDesc('version_number')->first();
            if (! $decision) {
                throw ValidationException::withMessages(['decision' => 'An approved admission decision is required before issuing an offer.']);
            }
            $intake = AdmissionIntake::query()->lockForUpdate()->findOrFail($locked->intake_id);
            if ($acceptBy && $acceptBy->isPast()) {
                throw ValidationException::withMessages(['accept_by' => 'The offer response deadline must be in the future.']);
            }
            if ($intake->capacity !== null) {
                // This must be a current locking read: a consistent COUNT can retain a
                // repeatable-read snapshot taken before the intake lock was acquired.
                $activeSeatRows = AdmissionSeatReservation::query()
                    ->where('intake_id', $intake->getKey())
                    ->whereIn('status', ['pending', 'accepted'])
                    ->where(fn ($query) => $query->whereNull('expires_at')->orWhere('expires_at', '>', now()))
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->limit($intake->capacity)
                    ->get(['id']);
                if (count($activeSeatRows) >= $intake->capacity) {
                    throw ValidationException::withMessages(['application' => 'This intake has no unreserved seat available.']);
                }
            }

            $version = (int) AdmissionOffer::query()->where('application_id', $locked->getKey())->max('version_number') + 1;
            $offer = AdmissionOffer::query()->create([
                'application_id' => $locked->getKey(), 'decision_id' => $decision->getKey(), 'version_number' => $version,
                'offer_number' => 'OFR-'.Str::upper(Str::random(12)), 'status' => 'issued', 'conditions' => $conditions,
                'accept_by' => $acceptBy, 'issued_at' => now(), 'issued_by' => $actor->getKey(),
            ]);
            AdmissionSeatReservation::query()->updateOrCreate(
                ['application_id' => $locked->getKey()],
                ['intake_id' => $intake->getKey(), 'offer_id' => $offer->getKey(), 'status' => 'pending', 'reserved_at' => now(), 'expires_at' => $acceptBy],
            );
            $from = (string) $locked->status;
            $locked->forceFill(['status' => 'offered'])->save();
            $this->event($locked, $from, 'offered', $actor, 'offer.issued');
            $this->outbox($locked, 'offer.issued');
            $this->audit->record('admissions.offer.issued', $actor, (int) $this->context->tenant()->getKey(), $offer, ['application_id' => $locked->getKey(), 'version' => $version]);

            return $offer;
        });
    }

    public function respondToOffer(AdmissionOffer $offer, User $applicant, string $response): AdmissionOffer
    {
        if (! in_array($response, ['accepted', 'declined'], true)) {
            throw ValidationException::withMessages(['response' => 'Select accept or decline.']);
        }

        return DB::transaction(function () use ($offer, $applicant, $response): AdmissionOffer {
            $locked = AdmissionOffer::query()->lockForUpdate()->findOrFail($offer->getKey());
            $application = AdmissionApplication::query()->lockForUpdate()->findOrFail($locked->application_id);
            $this->assertApplicantOwns($application, $applicant);
            if ($locked->status === $response) {
                return $locked;
            }
            if ($locked->status !== 'issued') {
                throw ValidationException::withMessages(['offer' => 'This offer is no longer awaiting a response.']);
            }
            if ($locked->accept_by && $locked->accept_by->isPast()) {
                $locked->forceFill(['status' => 'expired'])->save();
                AdmissionSeatReservation::query()->where('application_id', $application->getKey())->update(['status' => 'released', 'released_at' => now()]);
                $application->forceFill(['status' => 'offer_expired'])->save();
                $this->event($application, 'offered', 'offer_expired', $applicant, 'offer.expired');
                $this->outbox($application, 'offer.expired');
                $this->audit->record('admissions.offer.expired', $applicant, (int) $this->context->tenant()->getKey(), $locked, ['application_id' => $application->getKey(), 'intake_id' => $application->intake_id]);

                return $locked;
            }
            $intake = AdmissionIntake::query()->lockForUpdate()->findOrFail($application->intake_id);
            $locked->forceFill(['status' => $response, 'responded_at' => now()])->save();
            $seat = AdmissionSeatReservation::query()->where('application_id', $application->getKey())->lockForUpdate()->firstOrFail();
            $seat->forceFill(['status' => $response === 'accepted' ? 'accepted' : 'released', 'released_at' => $response === 'accepted' ? null : now()])->save();
            $target = $response === 'accepted' ? 'offer_accepted' : 'offer_declined';
            $application->forceFill(['status' => $target])->save();
            $this->event($application, 'offered', $target, $applicant, 'offer.'.$response);
            $this->outbox($application, 'offer.'.$response);
            $this->audit->record('admissions.offer.'.$response, $applicant, (int) $this->context->tenant()->getKey(), $locked, ['application_id' => $application->getKey(), 'intake_id' => $intake->getKey()]);

            return $locked;
        });
    }

    public function createSisHandoff(AdmissionApplication $application, User $actor): AdmissionSisHandoff
    {
        return DB::transaction(function () use ($application, $actor): AdmissionSisHandoff {
            $locked = AdmissionApplication::query()->lockForUpdate()->with('profile')->findOrFail($application->getKey());
            $tenantId = (int) $this->context->tenant()->getKey();
            $existing = AdmissionSisHandoff::query()
                ->where('tenant_id', $tenantId)
                ->where('application_id', $locked->getKey())
                ->first();
            if ($existing) {
                return $existing;
            }
            if ($locked->status !== 'offer_accepted') {
                throw ValidationException::withMessages(['application' => 'Only an accepted offer can be handed off to the future SIS.']);
            }
            $offer = AdmissionOffer::query()->where('application_id', $locked->getKey())->where('status', 'accepted')->orderByDesc('version_number')->firstOrFail();
            $intake = AdmissionIntake::query()->findOrFail($locked->intake_id);
            $snapshot = [
                'application_number' => $locked->application_number,
                'applicant_user_id' => $locked->applicant_user_id,
                'applicant_profile' => $locked->profile?->only(['given_name', 'middle_name', 'family_name', 'birth_date', 'phone', 'country', 'region', 'city']),
                'tenant_id' => $this->context->tenant()->getKey(),
                'institution_id' => $locked->institution_id,
                'campus_id' => $intake->campus_id,
                'academic_year_id' => $intake->academic_year_id,
                'academic_program_id' => $intake->academic_program_id,
                'offer_id' => $offer->getKey(),
                'decision_id' => AdmissionDecision::query()->where('application_id', $locked->getKey())->where('decision', 'admit')->whereNotNull('approved_at')->orderByDesc('version_number')->value('id'),
                'admission_date' => now()->toDateString(),
                'student_record_creation' => 'phase_05_required',
            ];
            $handoff = AdmissionSisHandoff::query()->create([
                'application_id' => $locked->getKey(), 'offer_id' => $offer->getKey(),
                'correlation_id' => (string) Str::uuid(), 'status' => 'pending', 'approved_snapshot' => $snapshot,
                'requested_by' => $actor->getKey(),
            ]);
            $locked->forceFill(['status' => 'ready_for_sis_handoff'])->save();
            $this->event($locked, 'offer_accepted', 'ready_for_sis_handoff', $actor, 'sis.handoff.created');
            $this->outbox($locked, 'sis.handoff.created');
            $this->audit->record('admissions.sis_handoff.created', $actor, (int) $this->context->tenant()->getKey(), $handoff, ['application_id' => $locked->getKey(), 'correlation_id' => $handoff->correlation_id]);

            return $handoff;
        });
    }

    private function publishedVersion(AdmissionIntake $intake): AdmissionFormVersion
    {
        $template = $intake->formTemplates()->first();
        abort_unless($template !== null, 404);

        return AdmissionFormVersion::query()->where('form_template_id', $template->getKey())->where('status', 'published')->orderByDesc('version_number')->firstOrFail();
    }

    private function assertOpen(AdmissionIntake $intake): void
    {
        $settings = AdmissionSetting::query()->first();
        abort_unless($settings?->enabled && $settings->public_applications_enabled && $intake->status === 'open' && $intake->is_public, 404);
        $localNow = now($intake->timezone ?: $settings->timezone ?: 'UTC');
        if ($intake->applications_open_at && $localNow->lt($intake->applications_open_at)) {
            throw ValidationException::withMessages(['intake' => 'Applications are not open yet.']);
        }
        if ($intake->application_deadline && $localNow->gt($intake->application_deadline)) {
            throw ValidationException::withMessages(['intake' => 'The application deadline has passed.']);
        }
    }

    private function assertApplicantOwns(AdmissionApplication $application, User $applicant): void
    {
        if ((int) $application->applicant_user_id !== (int) $applicant->getKey()) {
            throw new NotFoundHttpException;
        }
    }

    /**
     * @param  array<string, mixed>  $definition
     * @param  array<string, mixed>  $answers
     */
    private function assertRequiredDocumentsPresent(AdmissionApplication $application, array $definition, array $answers): void
    {
        $requiredCodes = $this->forms->requiredDocumentRequirementCodes($definition, $answers);
        $requiredIds = DB::table('admission_document_requirements')
            ->where('tenant_id', $application->tenant_id)
            ->where('intake_id', $application->intake_id)
            ->where(fn ($query) => $query->where('is_required', true)->orWhereIn('code', $requiredCodes))
            ->pluck('id');
        if ($requiredIds->isEmpty()) {
            return;
        }
        $uploadedIds = DB::table('admission_documents')->where('tenant_id', $application->tenant_id)->where('application_id', $application->getKey())->whereIn('requirement_id', $requiredIds)->pluck('requirement_id')->unique();
        if ($requiredIds->diff($uploadedIds)->isNotEmpty()) {
            throw ValidationException::withMessages(['documents' => 'Upload each required document before submitting your application.']);
        }
    }

    private function createFeeObligation(AdmissionApplication $application, AdmissionIntake $intake): void
    {
        if ((float) $intake->application_fee_amount <= 0 || ! $intake->application_fee_currency) {
            return;
        }
        AdmissionFeeObligation::query()->firstOrCreate(['application_id' => $application->getKey()], [
            'amount' => $intake->application_fee_amount,
            'currency' => $intake->application_fee_currency,
            'status' => 'due',
        ]);
    }

    private function event(AdmissionApplication $application, ?string $from, string $to, ?User $actor, string $reason, ?string $note = null): void
    {
        AdmissionLifecycleEvent::query()->create([
            'application_id' => $application->getKey(), 'from_status' => $from, 'to_status' => $to,
            'actor_user_id' => $actor?->getKey(), 'reason_code' => $reason, 'reason' => $note,
            'safe_metadata' => [], 'occurred_at' => now(),
        ]);
    }

    private function outbox(AdmissionApplication $application, string $event): void
    {
        AdmissionOutboxEvent::query()->create([
            'application_id' => $application->getKey(), 'event_type' => $event, 'channel' => 'local',
            'safe_payload' => ['application_id' => $application->getKey(), 'application_number' => $application->application_number],
            'status' => 'queued', 'attempts' => 0, 'available_at' => now(),
        ]);
    }
}
