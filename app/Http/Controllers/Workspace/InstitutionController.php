<?php

namespace App\Http\Controllers\Workspace;

use App\Domain\Audit\AuditRecorder;
use App\Domain\Tenancy\TenantContext;
use App\Http\Controllers\Controller;
use App\Models\Institution;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class InstitutionController extends Controller
{
    public function show(TenantContext $context): Response
    {
        return Inertia::render('workspace/institution/profile', [
            'institution' => Institution::query()->withCount('campuses')->firstOrFail(),
            'tenant' => $context->tenant()->only('name', 'slug', 'status'),
        ]);
    }

    public function update(Request $request, TenantContext $context, AuditRecorder $audit): RedirectResponse
    {
        $request->merge(['country' => $request->filled('country') ? Str::upper((string) $request->input('country')) : null]);
        $data = $request->validate([
            'official_name' => ['required', 'string', 'max:180'],
            'display_name' => ['required', 'string', 'max:140'],
            'code' => ['nullable', 'string', 'max:40', 'regex:/^[A-Za-z0-9._-]+$/'],
            'type' => ['required', Rule::in(['school', 'college', 'university', 'academy', 'corporate', 'online', 'other'])],
            'contact_email' => ['nullable', 'email', 'max:255'],
            'contact_phone' => ['nullable', 'string', 'max:40'],
            'website' => ['nullable', 'url', 'max:255'],
            'country' => ['nullable', 'alpha', 'size:2'],
            'region' => ['nullable', 'string', 'max:120'],
            'city' => ['nullable', 'string', 'max:120'],
            'address' => ['nullable', 'string', 'max:500'],
            'timezone' => ['required', 'timezone'],
            'locale' => ['required', 'string', 'max:16', 'regex:/^[a-z]{2,3}(-[A-Z]{2})?$/'],
        ]);
        DB::transaction(function () use ($data, $context, $audit, $request): void {
            $institution = Institution::query()->lockForUpdate()->firstOrFail();
            $institution->fill($data)->save();
            $context->tenant()->forceFill(['name' => $data['display_name']])->save();
            $audit->record('institution.updated', $request->user(), (int) $context->tenant()->getKey(), $institution, ['fields' => array_keys($data)]);
        });

        return back()->with('status', 'Institution profile saved.');
    }
}
