<?php

namespace App\Http\Controllers\Admissions;

use App\Domain\Tenancy\TenantContext;
use App\Http\Controllers\Controller;
use App\Models\AdmissionFormVersion;
use App\Models\AdmissionInquiry;
use App\Models\AdmissionIntake;
use App\Models\AdmissionSetting;
use App\Models\Institution;
use App\Models\Tenant;
use App\Models\TenantBranding;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

final class PublicAdmissionsController extends Controller
{
    public function index(Tenant $tenant, TenantContext $context): Response
    {
        $institution = Institution::query()->firstOrFail();
        $settings = AdmissionSetting::query()->firstOrFail();
        $intakes = AdmissionIntake::query()->when($settings->public_applications_enabled, fn ($query) => $query->where('status', 'open')->where('is_public', true)
            ->where(fn ($query) => $query->whereNull('application_deadline')->orWhere('application_deadline', '>=', now()))
            ->whereHas('formTemplates.versions', fn ($query) => $query->where('status', 'published')), fn ($query) => $query->whereRaw('1 = 0'))
            ->with(['campus:id,name', 'program:id,title,code', 'formTemplates.versions' => fn ($query) => $query->where('status', 'published')->orderByDesc('version_number')->limit(1)])
            ->orderBy('application_deadline')->paginate(12)->withQueryString();
        $branding = TenantBranding::query()->where('tenant_id', $tenant->getKey())->first();

        return Inertia::render('public/admissions/index', [
            'tenant' => ['slug' => $context->tenant()->slug, 'name' => $institution->display_name],
            'institution' => ['name' => $institution->display_name, 'officialName' => $institution->official_name, 'type' => $institution->type, 'website' => $institution->website, 'contactEmail' => $institution->contact_email, 'city' => $institution->city],
            'branding' => ['displayName' => data_get($branding, 'display_name'), 'primaryColor' => data_get($branding, 'primary_color', '#155EEF'), 'accentColor' => data_get($branding, 'accent_color', '#06B6D4'), 'logoUrl' => data_get($branding, 'logo_path') ? route('tenant.assets.image', ['tenant' => $tenant->slug, 'kind' => 'logo']) : null, 'theme' => data_get($branding, 'theme', 'executive-navy')],
            'intakes' => $intakes,
            'inquiriesEnabled' => $settings->inquiries_enabled,
            'authenticated' => request()->user() !== null,
        ]);
    }

    public function showIntake(Tenant $tenant, string $intakeCode, TenantContext $context): Response
    {
        $intake = AdmissionIntake::query()->where('code', $intakeCode)->where('status', 'open')->where('is_public', true)
            ->where(fn ($query) => $query->whereNull('application_deadline')->orWhere('application_deadline', '>=', now()))
            ->with(['campus:id,name,city', 'program:id,title,code,description', 'formTemplates.versions' => fn ($query) => $query->where('status', 'published')->orderByDesc('version_number')->limit(1)])
            ->firstOrFail();
        $template = $intake->formTemplates->first();
        /** @var AdmissionFormVersion|null $version */
        $version = $template?->versions->first();
        abort_unless($version !== null, 404);
        $policy = AdmissionSetting::query()->where('tenant_id', $context->tenant()->getKey())->value('policy');
        $configuredThreshold = is_array($policy) ? ($policy['guardian_age_threshold'] ?? 18) : 18;
        $guardianAgeThreshold = is_numeric($configuredThreshold) && (int) $configuredThreshold >= 1 && (int) $configuredThreshold <= 21
            ? (int) $configuredThreshold
            : 18;

        return Inertia::render('public/admissions/intake', [
            'tenant' => ['slug' => $context->tenant()->slug, 'name' => Institution::query()->value('display_name')],
            'intake' => $intake->only(['code', 'name', 'cycle', 'delivery_mode', 'locale', 'timezone', 'application_deadline', 'documents_deadline', 'offer_deadline', 'application_fee_amount', 'application_fee_currency', 'public_description']),
            'campus' => $intake->campus?->only(['name', 'city']),
            'program' => $intake->program?->only(['title', 'code', 'description']),
            'form' => ['title' => $version->definition['title'] ?? 'Application', 'instructions' => $version->definition['instructions'] ?? null, 'sections' => $version->definition['sections'] ?? []],
            'guardianAgeThreshold' => $guardianAgeThreshold,
            'authenticated' => request()->user() !== null,
        ]);
    }

    public function inquiry(Request $request, Tenant $tenant, TenantContext $context): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:180'],
            'email' => ['required', 'email:rfc', 'max:254'],
            'phone' => ['nullable', 'string', 'max:40'],
            'intake_code' => ['nullable', 'string', 'max:48'],
            'message' => ['nullable', 'string', 'max:2000'],
            'consent' => ['accepted'],
        ]);
        $institution = Institution::query()->firstOrFail();
        $intake = null;
        if (! empty($data['intake_code'])) {
            $intake = AdmissionIntake::query()->where('code', $data['intake_code'])->where('status', 'open')->where('is_public', true)->firstOrFail();
        }
        AdmissionInquiry::query()->create([
            'institution_id' => $institution->getKey(), 'intake_id' => $intake?->getKey(),
            'reference' => 'INQ-'.Str::upper(Str::random(12)), 'name' => $data['name'],
            'email' => Str::lower($data['email']), 'phone' => $data['phone'] ?? null,
            'status' => 'new', 'source' => 'public', 'message' => $data['message'] ?? null,
        ]);

        return to_route('public.admissions.index', ['tenant' => $tenant->slug])->with('status', 'Your inquiry was recorded. The admissions office can follow up using the contact details you supplied.');
    }
}
