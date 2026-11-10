<?php

namespace App\Http\Controllers\Workspace;

use App\Domain\Audit\AuditRecorder;
use App\Domain\Tenancy\TenantContext;
use App\Http\Controllers\Controller;
use App\Models\Institution;
use App\Models\TenantSetting;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class SettingsController extends Controller
{
    public function show(TenantContext $context): Response
    {
        $institution = Institution::query()->firstOrFail();

        return Inertia::render('workspace/settings/index', [
            'settings' => ['timezone' => $institution->timezone, 'locale' => $institution->locale],
            'dateFormat' => TenantSetting::query()->where('key', 'date_format')->firstOrFail()->value,
            'tenant' => $context->tenant()->only('id', 'name', 'slug'),
        ]);
    }

    public function update(Request $request, TenantContext $context, AuditRecorder $audit): RedirectResponse
    {
        $data = $request->validate([
            'timezone' => ['required', 'timezone'],
            'locale' => ['required', 'string', 'max:16', 'regex:/^[a-z]{2,3}(-[A-Z]{2})?$/'],
            'date_format' => ['required', 'in:Y-m-d,d/m/Y,m/d/Y,d M Y'],
        ]);
        DB::transaction(function () use ($data, $context, $audit, $request): void {
            $institution = Institution::query()->lockForUpdate()->firstOrFail();
            $institution->forceFill(['timezone' => $data['timezone'], 'locale' => $data['locale']])->save();
            TenantSetting::query()->updateOrCreate(['key' => 'date_format'], ['tenant_id' => $context->tenant()->getKey(), 'value' => ['value' => $data['date_format']]]);
            $audit->record('tenant.settings_updated', $request->user(), (int) $context->tenant()->getKey(), $institution, ['fields' => ['timezone', 'locale', 'date_format']]);
        });

        return back()->with('status', 'Institution settings saved.');
    }
}
