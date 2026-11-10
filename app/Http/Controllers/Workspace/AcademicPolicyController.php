<?php

namespace App\Http\Controllers\Workspace;

use App\Domain\Audit\AuditRecorder;
use App\Domain\Tenancy\TenantContext;
use App\Http\Controllers\Controller;
use App\Models\AcademicConfiguration;
use App\Models\AcademicPolicyEvidence;
use App\Models\AcademicPolicyGap;
use App\Models\AcademicPolicyMapping;
use App\Models\AcademicPolicyProfile;
use App\Models\AcademicPolicyProfileVersion;
use App\Models\Institution;
use App\Models\Tenant;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class AcademicPolicyController extends Controller
{
    public function index(TenantContext $context): Response
    {
        $institution = Institution::query()->firstOrFail();

        return Inertia::render('workspace/academics/policies', [
            'tenant' => ['slug' => $context->tenant()->slug],
            'profiles' => AcademicPolicyProfile::query()->with(['versions' => fn ($query) => $query->with(['evidence', 'mappings', 'gaps'])->withCount(['evidence', 'mappings', 'gaps'])->orderByDesc('version_number')->limit(10)])->orderBy('name')->paginate(20),
            'activeVersionId' => AcademicConfiguration::query()->where('institution_id', $institution->getKey())->value('active_policy_profile_version_id'),
        ]);
    }

    public function storeProfile(Request $request, TenantContext $context, AuditRecorder $audit): RedirectResponse
    {
        $institution = Institution::query()->firstOrFail();
        $data = $request->validate([
            'code' => ['required', 'string', 'max:48', 'regex:/^[A-Za-z0-9._-]+$/', Rule::unique('academic_policy_profiles', 'code')->where(fn ($query) => $query->where('tenant_id', $context->tenant()->getKey())->where('institution_id', $institution->getKey()))],
            'name' => ['required', 'string', 'max:160'], 'country' => ['nullable', 'alpha', 'size:2'], 'region' => ['nullable', 'string', 'max:120'],
            'institution_type' => ['nullable', Rule::in(['school', 'college', 'university', 'academy', 'corporate', 'online', 'other'])],
            'academic_framework' => ['nullable', 'string', 'max:160'], 'authority_name' => ['nullable', 'string', 'max:160'], 'authority_reference' => ['nullable', 'string', 'max:255'],
            'calendar_model' => ['nullable', 'string', 'max:40'], 'grading_terminology' => ['nullable', 'string', 'max:80'], 'qualification_terminology' => ['nullable', 'string', 'max:80'],
            'credit_hours_applicable' => ['required', 'boolean'], 'effective_from' => ['nullable', 'date'], 'effective_until' => ['nullable', 'date', 'after_or_equal:effective_from'],
        ]);
        $profile = DB::transaction(function () use ($data, $institution, $request, $context, $audit): AcademicPolicyProfile {
            $profile = AcademicPolicyProfile::query()->create([
                'institution_id' => $institution->getKey(), 'code' => $data['code'], 'name' => $data['name'],
                'country' => isset($data['country']) ? Str::upper($data['country']) : null, 'region' => $data['region'] ?? null,
                'institution_type' => $data['institution_type'] ?? null, 'status' => 'draft', 'created_by' => $request->user()->getKey(),
            ]);
            $version = AcademicPolicyProfileVersion::query()->create([
                'academic_policy_profile_id' => $profile->getKey(), 'version_number' => 1,
                'academic_framework' => $data['academic_framework'] ?? null, 'authority_name' => $data['authority_name'] ?? null,
                'authority_reference' => $data['authority_reference'] ?? null, 'calendar_model' => $data['calendar_model'] ?? null,
                'grading_terminology' => $data['grading_terminology'] ?? null, 'qualification_terminology' => $data['qualification_terminology'] ?? null,
                'credit_hours_applicable' => $data['credit_hours_applicable'], 'effective_from' => $data['effective_from'] ?? null,
                'effective_until' => $data['effective_until'] ?? null, 'review_status' => 'draft',
            ]);
            $audit->record('academic.policy_profile.created', $request->user(), (int) $context->tenant()->getKey(), $profile, ['code' => $profile->code, 'version_id' => $version->getKey()]);

            return $profile;
        });

        return $this->policyRedirect($context, "Policy profile {$profile->name} created with a draft evidence version.");
    }

    public function newVersion(Request $request, Tenant $tenant, int $profileId, TenantContext $context, AuditRecorder $audit): RedirectResponse
    {
        $profile = AcademicPolicyProfile::query()->with('versions')->findOrFail($profileId);
        $source = $profile->versions->first();
        $data = $request->validate([
            'academic_framework' => ['nullable', 'string', 'max:160'], 'authority_name' => ['nullable', 'string', 'max:160'], 'authority_reference' => ['nullable', 'string', 'max:255'],
            'calendar_model' => ['nullable', 'string', 'max:40'], 'grading_terminology' => ['nullable', 'string', 'max:80'], 'qualification_terminology' => ['nullable', 'string', 'max:80'],
            'credit_hours_applicable' => ['required', 'boolean'], 'timezone' => ['nullable', 'timezone'], 'locale' => ['nullable', 'string', 'max:16'],
            'effective_from' => ['nullable', 'date'], 'effective_until' => ['nullable', 'date', 'after_or_equal:effective_from'],
        ]);
        $version = DB::transaction(function () use ($profile, $source, $data, $request, $context, $audit): AcademicPolicyProfileVersion {
            AcademicPolicyProfile::query()->lockForUpdate()->findOrFail($profile->getKey());
            $number = (int) $profile->versions->max('version_number') + 1;
            $version = AcademicPolicyProfileVersion::query()->create([
                ...($source?->only(['compulsory_structure', 'document_requirements', 'configuration']) ?? []),
                ...$data, 'academic_policy_profile_id' => $profile->getKey(), 'version_number' => $number, 'review_status' => 'draft',
            ]);
            $audit->record('academic.policy_profile.version_created', $request->user(), (int) $context->tenant()->getKey(), $version, ['profile_id' => $profile->getKey(), 'version' => $number]);

            return $version;
        });

        return $this->policyRedirect($context, "Policy profile version {$version->version_number} created.");
    }

    public function storeEvidence(Request $request, Tenant $tenant, int $versionId, TenantContext $context, AuditRecorder $audit): RedirectResponse
    {
        $version = AcademicPolicyProfileVersion::query()->findOrFail($versionId);
        $data = $request->validate([
            'source_title' => ['required', 'string', 'max:200'], 'source_authority' => ['required', 'string', 'max:160'],
            'source_url' => ['nullable', 'url', 'max:500', 'starts_with:https://'], 'source_published_on' => ['nullable', 'date'], 'retrieved_on' => ['required', 'date'],
            'mapped_claim' => ['nullable', 'string', 'max:5000'], 'file' => ['nullable', 'file', 'mimes:pdf,docx,xlsx,png,jpg,jpeg', 'max:20480'],
        ]);
        $path = null;
        $sha256 = null;
        if ($request->file('file')) {
            $file = $request->file('file');
            $name = Str::uuid().'.'.$file->extension();
            $path = $file->storeAs('academic/tenants/'.$context->tenant()->getKey().'/policy-evidence', $name, 'local');
            if (! $path) {
                throw ValidationException::withMessages(['file' => 'Evidence could not be stored securely.']);
            }
            $sha256 = hash_file('sha256', Storage::disk('local')->path($path)) ?: null;
        }
        $evidence = AcademicPolicyEvidence::query()->create([
            'policy_profile_version_id' => $version->getKey(), 'source_title' => $data['source_title'], 'source_authority' => $data['source_authority'],
            'source_url' => $data['source_url'] ?? null, 'source_published_on' => $data['source_published_on'] ?? null, 'retrieved_on' => $data['retrieved_on'],
            'private_file_path' => $path, 'file_sha256' => $sha256, 'mapped_claim' => $data['mapped_claim'] ?? null, 'created_by' => $request->user()->getKey(),
        ]);
        $audit->record('academic.policy_evidence.created', $request->user(), (int) $context->tenant()->getKey(), $evidence, ['profile_version_id' => $version->getKey(), 'file_attached' => $path !== null]);

        return $this->policyRedirect($context, 'Dated policy source evidence recorded in private storage.');
    }

    public function storeMapping(Request $request, Tenant $tenant, int $versionId, TenantContext $context, AuditRecorder $audit): RedirectResponse
    {
        $version = AcademicPolicyProfileVersion::query()->findOrFail($versionId);
        $data = $request->validate([
            'evidence_id' => ['nullable', 'integer', Rule::exists('academic_policy_evidence', 'id')->where(fn ($query) => $query->where('tenant_id', $context->tenant()->getKey())->where('policy_profile_version_id', $version->getKey()))],
            'requirement_reference' => ['nullable', 'string', 'max:100'], 'policy_area' => ['required', 'string', 'max:64'], 'requirement_summary' => ['required', 'string', 'max:5000'],
            'software_capability' => ['nullable', 'string', 'max:160'], 'mapping_status' => ['required', Rule::in(['unmapped', 'partial', 'mapped', 'gap'])], 'gap_note' => ['nullable', 'string', 'max:5000'],
        ]);
        if ($data['mapping_status'] === 'mapped' && empty($data['evidence_id'])) {
            throw ValidationException::withMessages(['evidence_id' => 'A mapped requirement needs evidence linked to this profile version.']);
        }
        $mapping = AcademicPolicyMapping::query()->create([...$data, 'policy_profile_version_id' => $version->getKey()]);
        $audit->record('academic.policy_mapping.created', $request->user(), (int) $context->tenant()->getKey(), $mapping, ['profile_version_id' => $version->getKey(), 'mapping_status' => $mapping->mapping_status, 'policy_area' => $mapping->policy_area]);

        return $this->policyRedirect($context, 'Policy requirement mapping recorded.');
    }

    public function storeGap(Request $request, Tenant $tenant, int $versionId, TenantContext $context, AuditRecorder $audit): RedirectResponse
    {
        $version = AcademicPolicyProfileVersion::query()->findOrFail($versionId);
        $data = $request->validate([
            'gap_key' => ['required', 'string', 'max:48', 'regex:/^[A-Za-z0-9._-]+$/', Rule::unique('academic_policy_gaps', 'gap_key')->where(fn ($query) => $query->where('tenant_id', $context->tenant()->getKey())->where('policy_profile_version_id', $version->getKey()))],
            'severity' => ['required', Rule::in(['informational', 'low', 'medium', 'high', 'critical'])], 'description' => ['required', 'string', 'max:5000'],
            'owner_user_id' => ['nullable', 'integer', Rule::exists('tenant_memberships', 'user_id')->where(fn ($query) => $query->where('tenant_id', $context->tenant()->getKey())->where('status', 'active'))], 'due_on' => ['nullable', 'date'],
        ]);
        $gap = AcademicPolicyGap::query()->create([...$data, 'policy_profile_version_id' => $version->getKey(), 'status' => 'open']);
        $audit->record('academic.policy_gap.created', $request->user(), (int) $context->tenant()->getKey(), $gap, ['profile_version_id' => $version->getKey(), 'severity' => $gap->severity]);

        return $this->policyRedirect($context, 'Policy gap recorded.');
    }

    public function resolveGap(Request $request, Tenant $tenant, int $gapId, TenantContext $context, AuditRecorder $audit): RedirectResponse
    {
        $gap = AcademicPolicyGap::query()->findOrFail($gapId);
        $data = $request->validate(['resolution_note' => ['required', 'string', 'max:5000']]);
        $gap->forceFill(['status' => 'resolved', 'resolution_note' => $data['resolution_note'], 'resolved_at' => now()])->save();
        $audit->record('academic.policy_gap.resolved', $request->user(), (int) $context->tenant()->getKey(), $gap, ['profile_version_id' => $gap->policy_profile_version_id]);

        return $this->policyRedirect($context, 'Policy gap marked resolved with a recorded note.');
    }

    public function verifyVersion(Request $request, Tenant $tenant, int $versionId, TenantContext $context, AuditRecorder $audit): RedirectResponse
    {
        $version = AcademicPolicyProfileVersion::query()->with(['evidence', 'mappings'])->findOrFail($versionId);
        $evidenceComplete = $version->evidence->contains(fn (AcademicPolicyEvidence $evidence): bool => filled($evidence->source_authority) && (filled($evidence->source_url) || filled($evidence->private_file_path)));
        $mappedRequirement = $version->mappings->contains(fn (AcademicPolicyMapping $mapping): bool => $mapping->mapping_status === 'mapped' && $mapping->evidence_id !== null);
        if (! $evidenceComplete || ! $mappedRequirement || blank($version->authority_name)) {
            throw ValidationException::withMessages(['version' => 'Review requires a named authority, dated source evidence, and at least one requirement mapped to that evidence. This review records evidence coverage, not legal or accreditation compliance.']);
        }
        DB::transaction(function () use ($version, $request, $context, $audit): void {
            $locked = AcademicPolicyProfileVersion::query()->lockForUpdate()->findOrFail($version->getKey());
            $locked->forceFill(['review_status' => 'verified', 'reviewed_by' => $request->user()->getKey(), 'reviewed_at' => now(), 'review_note' => 'Evidence and mapping coverage reviewed; this status does not attest legal compliance or accreditation.'])->save();
            AcademicConfiguration::query()->where('institution_id', $locked->profile->institution_id)->update(['active_policy_profile_version_id' => $locked->getKey(), 'updated_by' => $request->user()->getKey()]);
            $locked->profile()->update(['status' => 'active']);
            $audit->record('academic.policy_profile.evidence_reviewed', $request->user(), (int) $context->tenant()->getKey(), $locked, ['profile_id' => $locked->academic_policy_profile_id, 'version' => $locked->version_number]);
        });

        return $this->policyRedirect($context, 'Evidence coverage reviewed and selected for academic configuration. This is not a compliance certification.');
    }

    public function downloadEvidence(Tenant $tenant, int $evidenceId): mixed
    {
        $evidence = AcademicPolicyEvidence::query()->findOrFail($evidenceId);
        abort_unless($evidence->private_file_path && Storage::disk('local')->exists($evidence->private_file_path), 404);

        return Storage::disk('local')->download($evidence->private_file_path, basename($evidence->private_file_path), ['X-Content-Type-Options' => 'nosniff', 'Cache-Control' => 'private, no-store']);
    }

    private function policyRedirect(TenantContext $context, string $message): RedirectResponse
    {
        return to_route('tenant.academics.policies', ['tenant' => $context->tenant()])->with('status', $message);
    }
}
