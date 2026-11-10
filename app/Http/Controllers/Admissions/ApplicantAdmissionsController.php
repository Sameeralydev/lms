<?php

namespace App\Http\Controllers\Admissions;

use App\Domain\Admissions\AdmissionApplicationWorkflow;
use App\Domain\Admissions\GuardianAuthorizationWorkflow;
use App\Domain\Audit\AuditRecorder;
use App\Domain\Tenancy\TenantContext;
use App\Http\Controllers\Controller;
use App\Jobs\ScanAdmissionDocument;
use App\Models\AdmissionApplication;
use App\Models\AdmissionDocument;
use App\Models\AdmissionDocumentRequirement;
use App\Models\AdmissionDocumentScanEvent;
use App\Models\AdmissionDocumentVersion;
use App\Models\AdmissionGuardianAuthorization;
use App\Models\AdmissionIntake;
use App\Models\AdmissionOffer;
use App\Models\AdmissionSetting;
use App\Models\Institution;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Auth\Events\Registered;
use Illuminate\Database\QueryException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class ApplicantAdmissionsController extends Controller
{
    public function registrationForm(Tenant $tenant, TenantContext $context): Response
    {
        return Inertia::render('public/admissions/register', ['tenant' => ['slug' => $context->tenant()->slug, 'name' => $tenant->name]]);
    }

    public function register(Request $request, Tenant $tenant): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:180'],
            'email' => ['required', 'email:rfc', 'max:254'],
            'password' => ['required', 'string', 'min:12', 'max:128', 'confirmed'],
        ]);
        $email = Str::lower(trim($data['email']));
        abort_unless(AdmissionSetting::query()->value('registration_mode') === 'open_registration', 404);
        $user = null;
        try {
            $user = DB::transaction(function () use ($data, $email): ?User {
                if (User::query()->where('email', $email)->exists()) {
                    return null;
                }

                return User::query()->create(['name' => $data['name'], 'email' => $email, 'password' => Hash::make($data['password'])]);
            });
        } catch (QueryException $exception) {
            // Only suppress the expected concurrent unique-email race. Surface infrastructure failures.
            if (! User::query()->where('email', $email)->exists()) {
                throw $exception;
            }
        }
        if ($user) {
            event(new Registered($user));
        }

        return to_route('login')->with('status', 'If registration can be completed, check your email. Existing account holders can sign in to continue.');
    }

    public function dashboard(Request $request, Tenant $tenant, TenantContext $context): Response
    {
        $applications = AdmissionApplication::query()->where('applicant_user_id', $request->user()->getKey())
            ->with(['intake:id,name,code,application_deadline', 'revisions' => fn ($query) => $query->limit(1)])
            ->orderByDesc('created_at')->paginate(15)->withQueryString();

        return Inertia::render('public/admissions/dashboard', [
            'tenant' => ['slug' => $context->tenant()->slug, 'name' => $tenant->name],
            'applications' => $applications->through(fn (AdmissionApplication $application): array => [
                'applicationNumber' => $application->application_number,
                'status' => $application->status,
                'intake' => $application->intake?->name,
                'intakeCode' => $application->intake?->code,
                'deadline' => $application->intake?->application_deadline,
                'submittedAt' => $application->submitted_at,
                'createdAt' => $application->created_at,
            ]),
        ]);
    }

    public function start(Request $request, Tenant $tenant, TenantContext $context, AdmissionApplicationWorkflow $workflow): RedirectResponse
    {
        $data = $request->validate([
            'intake_code' => ['required', 'string', 'max:48'],
            'given_name' => ['required', 'string', 'max:100'],
            'middle_name' => ['nullable', 'string', 'max:100'],
            'family_name' => ['nullable', 'string', 'max:100'],
            'birth_date' => ['required', 'date', 'before_or_equal:today'],
            'phone' => ['nullable', 'string', 'max:40', 'regex:/^[+0-9(). -]{4,40}$/'],
            'country' => ['nullable', 'string', 'size:2'],
            'region' => ['nullable', 'string', 'max:120'],
            'city' => ['nullable', 'string', 'max:120'],
            'address' => ['nullable', 'string', 'max:500'],
        ]);
        $intake = AdmissionIntake::query()->where('code', $data['intake_code'])->where('status', 'open')->where('is_public', true)->firstOrFail();
        $profile = [];
        foreach (['given_name', 'middle_name', 'family_name', 'birth_date', 'phone', 'country', 'region', 'city', 'address'] as $key) {
            if (array_key_exists($key, $data)) {
                $profile[$key] = $data[$key];
            }
        }
        $application = $workflow->createOrResume($intake, $request->user(), $profile);

        return to_route('public.admissions.applications.show', ['tenant' => $tenant->slug, 'applicationNumber' => $application->application_number])->with('status', 'Your application draft is ready. Your changes are saved only when you save the form.');
    }

    public function show(Request $request, Tenant $tenant, string $applicationNumber, TenantContext $context, GuardianAuthorizationWorkflow $guardians): Response
    {
        [$application, $canManage] = $this->viewableApplication($request, $tenant, $applicationNumber);
        $application->load(['intake', 'profile', 'formVersion', 'revisions']);
        $requirements = AdmissionDocumentRequirement::query()->where('intake_id', $application->intake_id)->orderBy('name')->get();
        $documents = AdmissionDocument::query()->where('application_id', $application->getKey())->with('versions')->get()->map(fn (AdmissionDocument $document): array => [
            'id' => $document->getKey(), 'requirementId' => $document->requirement_id, 'status' => $document->status,
            'version' => $document->current_version, 'reviewNote' => $document->review_note, 'scanStatus' => $document->scan_status,
            'downloadUrl' => $document->scan_status === 'clean' ? route('public.admissions.applications.documents.download', ['tenant' => $tenant->slug, 'applicationNumber' => $application->application_number, 'documentId' => $document->getKey()]) : null,
        ]);
        $events = DB::table('admission_lifecycle_events')->where('tenant_id', $application->tenant_id)->where('application_id', $application->getKey())->orderBy('occurred_at')->get(['from_status', 'to_status', 'reason_code', 'occurred_at']);
        $guardianRows = AdmissionGuardianAuthorization::query()->where('application_id', $application->getKey())
            ->when(! $canManage, fn ($query) => $query->where('guardian_user_id', $request->user()->getKey()))
            ->orderByDesc('id')->get();

        return Inertia::render('public/admissions/application', [
            'tenant' => ['slug' => $context->tenant()->slug, 'name' => $tenant->name],
            'canManage' => $canManage,
            'guardianRequired' => $guardians->requiresGuardian($application),
            'application' => [
                'number' => $application->application_number, 'status' => $application->status, 'currentStep' => $application->current_step,
                'answers' => $application->answers ?? [], 'submittedAt' => $application->submitted_at,
                'profile' => $application->profile?->only(['given_name', 'middle_name', 'family_name', 'birth_date', 'phone', 'country', 'region', 'city', 'address', ...($canManage ? ['guardian_name', 'guardian_email', 'guardian_relationship', 'guardian_consent_at'] : [])]),
                'intake' => $application->intake?->only(['code', 'name', 'application_deadline']),
                'form' => $application->formVersion?->definition,
                'revisionCount' => $application->revisions->count(),
            ],
            'requirements' => $requirements->map(fn (AdmissionDocumentRequirement $requirement): array => [
                'id' => $requirement->getKey(), 'code' => $requirement->code, 'name' => $requirement->name, 'instructions' => $requirement->instructions,
                'required' => $requirement->is_required, 'allowedMimeTypes' => array_values(array_intersect($requirement->allowed_mime_types ?? [], ['application/pdf', 'image/jpeg', 'image/png'])),
                'maximumBytes' => min($requirement->maximum_bytes, 10485760),
            ]),
            'documents' => $documents,
            'guardians' => $guardianRows->map(fn (AdmissionGuardianAuthorization $authorization): array => [
                'id' => $authorization->getKey(), 'name' => $authorization->guardian_name,
                'email' => $authorization->guardian_email, 'relationship' => $authorization->relationship,
                'status' => $authorization->status, 'expiresAt' => $authorization->expires_at,
            ]),
            'timeline' => $events,
            'offer' => ($offer = AdmissionOffer::query()->where('application_id', $application->getKey())->orderByDesc('version_number')->first())
                ? ['id' => $offer->getKey(), 'status' => $offer->status, 'acceptBy' => $offer->accept_by, 'conditions' => $offer->conditions]
                : null,
        ]);
    }

    public function inviteGuardian(Request $request, Tenant $tenant, string $applicationNumber, GuardianAuthorizationWorkflow $workflow): RedirectResponse
    {
        $data = $request->validate([
            'guardian_name' => ['required', 'string', 'max:180'],
            'guardian_email' => ['required', 'email:rfc', 'max:254'],
            'relationship' => ['required', Rule::in(['mother', 'father', 'parent', 'legal_guardian', 'other'])],
        ]);
        $application = $this->ownedApplication($request, $applicationNumber);
        $invitation = $workflow->invite($application, $request->user(), $data);
        $response = to_route('public.admissions.applications.show', [
            'tenant' => $tenant->slug, 'applicationNumber' => $application->application_number,
        ])->with('status', 'A guardian verification request was sent. Access remains unavailable until the guardian verifies their email and the institution confirms the relationship.');

        if (app()->environment('testing') && config('app.e2e_test_mode') && config('mail.default') === 'array'
            && in_array($request->ip(), ['127.0.0.1', '::1'], true)) {
            $response->headers->set('X-E2E-Guardian-Invitation-Url', $invitation->url);
            $response->headers->set('Cache-Control', 'no-store, private');
        }

        return $response;
    }

    public function showGuardianInvitation(Tenant $tenant): Response
    {
        return Inertia::render('public/admissions/guardian-invitation', [
            'tenant' => ['slug' => $tenant->slug, 'name' => Institution::query()->value('display_name')],
        ]);
    }

    public function acceptGuardianInvitation(Request $request, Tenant $tenant, GuardianAuthorizationWorkflow $workflow): RedirectResponse
    {
        $data = $request->validate(['token' => ['required', 'string', 'size:64', 'regex:/^[a-f0-9]+$/i']]);
        $authorization = $workflow->accept($tenant, $data['token'], $request->user());
        $application = $authorization->application()->firstOrFail();

        return to_route('public.admissions.applications.show', [
            'tenant' => $tenant->slug, 'applicationNumber' => $application->application_number,
        ])->with('status', 'Your email is verified for this request. The institution must confirm the relationship before application access is active.');
    }

    public function revokeGuardian(Request $request, Tenant $tenant, string $applicationNumber, int $authorizationId, GuardianAuthorizationWorkflow $workflow): RedirectResponse
    {
        $authorization = AdmissionGuardianAuthorization::query()->where('tenant_id', $tenant->getKey())->findOrFail($authorizationId);
        $isApplicant = AdmissionApplication::query()->where('tenant_id', $tenant->getKey())
            ->where('application_number', $applicationNumber)->where('applicant_user_id', $request->user()->getKey())
            ->whereKey($authorization->application_id)->exists();
        $isGuardian = $authorization->status === 'active'
            && $authorization->application()->where('application_number', $applicationNumber)->exists()
            && (int) $authorization->guardian_user_id === (int) $request->user()->getKey();
        abort_unless($isApplicant || $isGuardian, 404);
        $workflow->revoke($authorization, $request->user());

        return to_route('public.admissions.applications.show', [
            'tenant' => $tenant->slug, 'applicationNumber' => $applicationNumber,
        ])->with('status', 'Guardian access has been revoked.');
    }

    public function save(Request $request, Tenant $tenant, string $applicationNumber, AdmissionApplicationWorkflow $workflow): RedirectResponse
    {
        $data = $request->validate(['answers' => ['required', 'array'], 'step' => ['required', 'integer', 'min:1', 'max:20']]);
        $application = $this->ownedApplication($request, $applicationNumber);
        $workflow->saveDraft($application, $request->user(), $data['answers'], (int) $data['step']);

        return to_route('public.admissions.applications.show', [
            'tenant' => $tenant->slug, 'applicationNumber' => $application->application_number,
        ])->with('status', 'Draft saved.');
    }

    public function submit(Request $request, Tenant $tenant, string $applicationNumber, AdmissionApplicationWorkflow $workflow): RedirectResponse
    {
        $application = $this->ownedApplication($request, $applicationNumber);
        $workflow->submit($application, $request->user());

        return to_route('public.admissions.applications.show', [
            'tenant' => $tenant->slug, 'applicationNumber' => $application->application_number,
        ])->with('status', 'Application submitted. Your reference number is '.$application->application_number.'.');
    }

    public function upload(Request $request, Tenant $tenant, string $applicationNumber, string $requirementCode, AuditRecorder $audit): RedirectResponse
    {
        $application = $this->ownedApplication($request, $applicationNumber);
        abort_unless($application->status === 'draft', 404);
        $requirement = AdmissionDocumentRequirement::query()->where('intake_id', $application->intake_id)->where('code', $requirementCode)->firstOrFail();
        $allowed = array_values(array_intersect($requirement->allowed_mime_types ?? [], ['application/pdf', 'image/jpeg', 'image/png']));
        abort_if($allowed === [], 422, 'This requirement has no supported upload type configured.');
        $file = $request->validate([
            'file' => ['required', 'file', 'max:'.(int) floor(min($requirement->maximum_bytes, 10485760) / 1024), 'mimes:pdf,jpg,jpeg,png', 'mimetypes:application/pdf,image/jpeg,image/png'],
        ])['file'];
        if (! $file instanceof UploadedFile || ! in_array($file->getMimeType(), $allowed, true)) {
            abort(422, 'The file content does not match an allowed format.');
        }
        $this->assertFileSignature($file, (string) $file->getMimeType());
        $path = 'admissions/'.$application->tenant_id.'/'.$application->getKey().'/'.Str::uuid().'.'.$file->extension();
        $storedPath = Storage::disk('local')->putFileAs(dirname($path), $file, basename($path));
        abort_unless(is_string($storedPath), 500, 'The private document could not be stored.');

        try {
            [$document, $documentVersion, $scanEvent] = DB::transaction(function () use ($application, $requirement, $request, $file, $storedPath): array {
                AdmissionApplication::query()->lockForUpdate()->findOrFail($application->getKey());
                $existing = AdmissionDocument::query()->where('application_id', $application->getKey())
                    ->where('requirement_id', $requirement->getKey())->lockForUpdate()->first();
                $nextVersion = $existing ? ((int) $existing->current_version + 1) : 1;
                $document = $existing ?: AdmissionDocument::query()->create([
                    'application_id' => $application->getKey(), 'requirement_id' => $requirement->getKey(),
                    'uploaded_by' => $request->user()->getKey(), 'status' => 'pending', 'current_version' => 0,
                ]);
                $documentVersion = AdmissionDocumentVersion::query()->create([
                    'document_id' => $document->getKey(), 'version_number' => $nextVersion, 'private_path' => $storedPath,
                    'original_name' => $this->safeOriginalName((string) $file->getClientOriginalName(), (string) $file->extension()),
                    'mime_type' => (string) $file->getMimeType(), 'size_bytes' => (int) $file->getSize(),
                    'sha256' => hash_file('sha256', (string) $file->getRealPath()), 'uploaded_by' => $request->user()->getKey(),
                ]);
                $document->forceFill(['status' => 'pending', 'scan_status' => 'pending', 'current_version' => $documentVersion->version_number, 'review_note' => null, 'reviewed_at' => null, 'reviewed_by' => null])->save();

                $scanEvent = AdmissionDocumentScanEvent::query()->create([
                    'document_version_id' => $documentVersion->getKey(), 'attempt_number' => 1,
                    'status' => 'pending', 'scanner' => (string) config('admissions.document_scanner'),
                ]);

                return [$document, $documentVersion, $scanEvent];
            });
        } catch (\Throwable $exception) {
            Storage::disk('local')->delete($storedPath);
            throw $exception;
        }

        ScanAdmissionDocument::dispatch((int) $application->tenant_id, (int) $document->getKey(), (int) $documentVersion->getKey(), (int) $scanEvent->getKey())->afterCommit();
        $audit->record('admissions.document.uploaded', $request->user(), (int) $application->tenant_id, $document, [
            'document_id' => $document->getKey(), 'version' => $documentVersion->version_number,
            'mime_type' => $documentVersion->mime_type, 'size_bytes' => $documentVersion->size_bytes,
            'scan_status' => 'pending',
        ]);

        return to_route('public.admissions.applications.show', [
            'tenant' => $tenant->slug, 'applicationNumber' => $application->application_number,
        ])->with('status', 'Document uploaded to private quarantine. It is not available to reviewers until scanning marks it clean.');
    }

    public function download(Request $request, Tenant $tenant, string $applicationNumber, int $documentId): StreamedResponse
    {
        [$application] = $this->viewableApplication($request, $tenant, $applicationNumber);
        $document = AdmissionDocument::query()->where('application_id', $application->getKey())->findOrFail($documentId);
        abort_unless($document->scan_status === 'clean', 404);
        $version = AdmissionDocumentVersion::query()->where('document_id', $document->getKey())->where('version_number', $document->current_version)->firstOrFail();
        abort_unless(Storage::disk('local')->exists($version->private_path), 404);

        return Storage::disk('local')->download($version->private_path, basename($version->original_name));
    }

    public function respondToOffer(Request $request, Tenant $tenant, int $offerId, AdmissionApplicationWorkflow $workflow): RedirectResponse
    {
        $data = $request->validate(['response' => ['required', Rule::in(['accepted', 'declined'])]]);
        $offer = AdmissionOffer::query()->findOrFail($offerId);
        $application = AdmissionApplication::query()->where('id', $offer->application_id)->where('applicant_user_id', $request->user()->getKey())->firstOrFail();
        $workflow->respondToOffer($offer, $request->user(), $data['response']);

        if ($offer->fresh()?->status === 'expired') {
            return to_route('public.admissions.applications.show', ['tenant' => $tenant->slug, 'applicationNumber' => $application->application_number])->with('status', 'The offer has expired and the seat was released.');
        }

        return to_route('public.admissions.applications.show', ['tenant' => $tenant->slug, 'applicationNumber' => $application->application_number])->with('status', 'Your offer response was recorded.');
    }

    private function ownedApplication(Request $request, string $applicationNumber): AdmissionApplication
    {
        return AdmissionApplication::query()->where('applicant_user_id', $request->user()->getKey())->where('application_number', $applicationNumber)->firstOrFail();
    }

    /** @return array{AdmissionApplication, bool} */
    private function viewableApplication(Request $request, Tenant $tenant, string $applicationNumber): array
    {
        $owned = AdmissionApplication::query()->where('tenant_id', $tenant->getKey())
            ->where('applicant_user_id', $request->user()->getKey())->where('application_number', $applicationNumber)->first();
        if ($owned) {
            return [$owned, true];
        }

        $authorization = AdmissionGuardianAuthorization::query()->where('tenant_id', $tenant->getKey())
            ->where('guardian_user_id', $request->user()->getKey())
            ->where('guardian_email', Str::lower((string) $request->user()->email))
            ->whereNotNull('email_verified_at')->whereNotNull('relationship_verified_at')->where('status', 'active')
            ->whereHas('application', fn ($query) => $query->where('application_number', $applicationNumber))
            ->first();
        abort_unless($authorization !== null, 404);

        return [$authorization->application()->firstOrFail(), false];
    }

    private function safeOriginalName(string $clientName, string $extension): string
    {
        $name = basename(str_replace('\\', '/', $clientName));
        $name = preg_replace('/[\x00-\x1F\x7F]/u', '', $name) ?? '';
        $name = trim(Str::limit($name, 180, ''));

        return $name !== '' ? $name : 'document.'.$extension;
    }

    private function assertFileSignature(UploadedFile $file, string $mimeType): void
    {
        $path = (string) $file->getRealPath();
        if ($mimeType === 'application/pdf') {
            $signature = file_get_contents($path, false, null, 0, 5);
            abort_unless($signature === '%PDF-', 422, 'The uploaded file does not contain a PDF signature.');

            return;
        }

        $signature = file_get_contents($path, false, null, 0, 8);
        $matches = match ($mimeType) {
            'image/png' => $signature === "\x89PNG\r\n\x1a\n",
            'image/jpeg' => is_string($signature) && str_starts_with($signature, "\xFF\xD8\xFF"),
            default => false,
        };
        abort_unless($matches, 422, 'The uploaded image content does not match its declared format.');
    }
}
