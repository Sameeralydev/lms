<?php

namespace App\Domain\Sis;

use App\Domain\Audit\AuditRecorder;
use App\Domain\Tenancy\TenantContext;
use App\Models\AdmissionApplication;
use App\Models\AdmissionDocument;
use App\Models\AdmissionDocumentVersion;
use App\Models\AdmissionGuardianAuthorization;
use App\Models\AdmissionOffer;
use App\Models\AdmissionSisHandoff;
use App\Models\Institution;
use App\Models\Role;
use App\Models\SisStudent;
use App\Models\SisStudentDocument;
use App\Models\SisStudentGuardian;
use App\Models\SisStudentLifecycleEvent;
use App\Models\TenantMembership;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

final class AdmissionToSisHandoff
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly SisStudentRegistry $registry,
        private readonly AuditRecorder $audit,
    ) {}

    public function process(AdmissionSisHandoff $handoff, User $actor): SisStudent
    {
        return DB::transaction(function () use ($handoff, $actor): SisStudent {
            $lockedHandoff = AdmissionSisHandoff::query()->whereKey($handoff->getKey())->lockForUpdate()->firstOrFail();
            $existing = SisStudent::query()->where('admission_handoff_id', $lockedHandoff->getKey())->first();
            if ($existing) {
                return $existing;
            }
            if ($lockedHandoff->status !== 'pending') {
                throw ValidationException::withMessages(['handoff' => 'This admissions handoff is not pending SIS processing.']);
            }

            $snapshotAttribute = $lockedHandoff->getAttribute('approved_snapshot');
            $snapshot = is_array($snapshotAttribute) ? $snapshotAttribute : [];
            $tenantId = (int) $this->context->tenant()->getKey();
            if ((int) ($snapshot['tenant_id'] ?? 0) !== $tenantId) {
                throw ValidationException::withMessages(['handoff' => 'The approved handoff belongs to another institution workspace.']);
            }
            $application = AdmissionApplication::query()->whereKey($lockedHandoff->application_id)->lockForUpdate()->with('profile')->firstOrFail();
            $institution = Institution::query()->firstOrFail();
            $offer = AdmissionOffer::query()->whereKey($lockedHandoff->offer_id)->where('application_id', $application->getKey())->where('status', 'accepted')->firstOrFail();
            $approvedDecisionExists = DB::table('admission_decisions')->where('tenant_id', $tenantId)->where('application_id', $application->getKey())
                ->where('decision', 'admit')->whereNotNull('approved_at')->exists();
            if ($application->status !== 'ready_for_sis_handoff' || ! $approvedDecisionExists
                || (int) ($snapshot['institution_id'] ?? 0) !== (int) $institution->getKey()
                || (int) ($snapshot['offer_id'] ?? 0) !== (int) $offer->getKey()
                || (int) ($snapshot['applicant_user_id'] ?? 0) !== (int) $application->applicant_user_id) {
                throw ValidationException::withMessages(['handoff' => 'The admissions handoff could not be matched to an approved offer and institution record.']);
            }

            $applicant = User::query()->findOrFail($application->applicant_user_id);
            $membership = TenantMembership::query()->where('user_id', $applicant->getKey())->lockForUpdate()->first();
            if ($membership && ((int) $membership->institution_id !== (int) $institution->getKey() || $membership->status === 'revoked')) {
                throw ValidationException::withMessages(['identity' => 'The applicant has a conflicting or revoked institution membership and needs manual identity review.']);
            }
            $campusId = isset($snapshot['campus_id']) ? (int) $snapshot['campus_id'] : null;
            if (! $membership) {
                $membership = TenantMembership::query()->create([
                    'institution_id' => $institution->getKey(), 'campus_id' => $campusId,
                    'user_id' => $applicant->getKey(), 'status' => 'active', 'activated_at' => now(),
                ]);
            } elseif ($membership->status !== 'active') {
                throw ValidationException::withMessages(['identity' => 'The applicant membership is not active. Resolve it through membership administration before conversion.']);
            }
            $studentRole = Role::query()->where('tenant_id', $tenantId)->where('key', 'student')->where('scope', 'tenant')->firstOrFail();
            $membership->roles()->syncWithoutDetaching([$studentRole->getKey() => ['assigned_by' => $actor->getKey(), 'tenant_id' => $tenantId]]);

            $profile = $application->profile;
            if (! $profile) {
                throw ValidationException::withMessages(['profile' => 'The admissions applicant profile is missing.']);
            }
            $birthDate = $profile->getAttribute('birth_date');
            $student = $this->registry->create([
                'user_id' => $applicant->getKey(), 'admission_handoff_id' => $lockedHandoff->getKey(),
                'admission_application_id' => $application->getKey(), 'admission_offer_id' => $offer->getKey(),
                'home_campus_id' => $campusId, 'legal_first_name' => $profile->given_name,
                'legal_middle_name' => $profile->middle_name, 'legal_last_name' => $profile->family_name,
                'date_of_birth' => $birthDate ? Carbon::parse($birthDate)->toDateString() : null,
                'admission_date' => is_string($snapshot['admission_date'] ?? null) ? $snapshot['admission_date'] : now()->toDateString(), 'status' => 'admitted',
            ], $actor);

            $authorizations = AdmissionGuardianAuthorization::query()->where('application_id', $application->getKey())
                ->where('status', 'active')->whereNotNull('relationship_verified_at')->whereNull('revoked_at')->get();
            foreach ($authorizations as $authorization) {
                SisStudentGuardian::query()->firstOrCreate([
                    'student_id' => $student->getKey(), 'admission_authorization_id' => $authorization->getKey(),
                ], [
                    'guardian_user_id' => $authorization->guardian_user_id, 'guardian_name' => $authorization->guardian_name,
                    'guardian_email' => $authorization->guardian_email, 'relationship' => $authorization->relationship,
                    'authority_status' => 'verified', 'can_view_profile' => false,
                    'verified_at' => $authorization->relationship_verified_at,
                    'verified_by' => $authorization->relationship_verified_by,
                ]);
            }

            $cleanDocuments = AdmissionDocument::query()->where('application_id', $application->getKey())->where('scan_status', 'clean')->get();
            foreach ($cleanDocuments as $document) {
                $version = AdmissionDocumentVersion::query()->where('document_id', $document->getKey())->where('version_number', $document->current_version)->first();
                if (! $version || ! Storage::disk('local')->exists($version->private_path)) {
                    continue;
                }
                SisStudentDocument::query()->firstOrCreate([
                    'student_id' => $student->getKey(), 'admission_document_id' => $document->getKey(),
                ], [
                    'category' => (string) ($document->requirement()->value('code') ?: 'admissions'),
                    'linked_by' => $actor->getKey(),
                ]);
            }

            $lockedHandoff->forceFill(['status' => 'processed', 'processed_by' => $actor->getKey(), 'processed_at' => now(), 'error_code' => null, 'error_summary' => null])->save();
            $this->audit->record('sis.admissions_handoff.processed', $actor, $tenantId, $lockedHandoff, ['student_id' => $student->getKey(), 'application_id' => $application->getKey(), 'offer_id' => $offer->getKey()]);
            SisStudentLifecycleEvent::query()->create([
                'student_id' => $student->getKey(), 'event_type' => 'admission_handoff', 'to_status' => 'admitted',
                'effective_at' => now(), 'reason_code' => 'admissions.approved_offer', 'source_type' => 'admission_sis_handoff',
                'source_reference' => $lockedHandoff->correlation_id, 'actor_user_id' => $actor->getKey(),
                'safe_metadata' => ['application_id' => $application->getKey(), 'offer_id' => $offer->getKey()],
            ]);

            return $student;
        }, 3);
    }
}
