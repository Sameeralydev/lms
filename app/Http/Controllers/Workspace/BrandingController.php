<?php

namespace App\Http\Controllers\Workspace;

use App\Domain\Appearance\BrandColor;
use App\Domain\Appearance\ThemeRegistry;
use App\Domain\Audit\AuditRecorder;
use App\Domain\Tenancy\TenantContext;
use App\Http\Controllers\Controller;
use App\Models\Tenant;
use App\Models\TenantBranding;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

class BrandingController extends Controller
{
    public function show(TenantContext $context): Response
    {
        $branding = TenantBranding::query()->firstOrCreate(['tenant_id' => $context->tenant()->getKey()], ['display_name' => $context->tenant()->name]);

        return Inertia::render('workspace/branding/index', ['branding' => $branding, 'themes' => ThemeRegistry::all()]);
    }

    public function update(Request $request, TenantContext $context, AuditRecorder $audit): RedirectResponse
    {
        $data = $request->validate([
            'display_name' => ['required', 'string', 'max:140'],
            'primary_color' => [
                'required',
                'regex:/^#[0-9A-Fa-f]{6}$/',
                function (string $attribute, mixed $value, \Closure $fail): void {
                    if (! is_string($value) || ! BrandColor::supportsWhiteForeground($value)) {
                        $fail('Choose a primary brand color with sufficient contrast against white text.');
                    }
                },
            ],
            'accent_color' => ['required', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'theme' => ['required', Rule::in(array_keys(ThemeRegistry::all()))],
            'logo' => ['nullable', 'image', 'mimes:png,jpg,jpeg,webp', 'max:2048'],
            'favicon' => ['nullable', 'image', 'mimes:png,ico,jpg,jpeg,webp', 'max:1024'],
        ]);

        $branding = TenantBranding::query()->firstOrCreate(['tenant_id' => $context->tenant()->getKey()], ['display_name' => $context->tenant()->name]);
        $newPaths = [];
        $oldPaths = [];
        $filePaths = [];
        foreach (['logo' => 'logo_path', 'favicon' => 'favicon_path'] as $input => $column) {
            if ($request->hasFile($input)) {
                $oldPath = $branding->{$column};
                $path = $request->file($input)->store('branding/'.$context->tenant()->public_id, 'local');
                $newPaths[] = $path;
                $filePaths[$column] = $path;
                if ($oldPath && str_starts_with($oldPath, 'branding/'.$context->tenant()->public_id.'/')) {
                    $oldPaths[] = $oldPath;
                }
            }
        }
        try {
            DB::transaction(function () use ($branding, $data, $filePaths, $audit, $request, $context): void {
                $branding->fill([...$filePaths, 'display_name' => $data['display_name'], 'primary_color' => $data['primary_color'], 'accent_color' => $data['accent_color'], 'theme' => $data['theme']])->save();
                $audit->record('tenant.branding_updated', $request->user(), (int) $context->tenant()->getKey(), $branding, ['theme' => $branding->theme, 'logo_changed' => array_key_exists('logo_path', $filePaths), 'favicon_changed' => array_key_exists('favicon_path', $filePaths)]);
            });
        } catch (\Throwable $exception) {
            Storage::disk('local')->delete($newPaths);
            throw $exception;
        }
        Storage::disk('local')->delete($oldPaths);

        return back()->with('status', 'Branding saved.');
    }

    public function image(Tenant $tenant, string $kind): SymfonyResponse
    {
        abort_unless($tenant->status === 'active' && in_array($kind, ['logo', 'favicon'], true), 404);
        $branding = TenantBranding::query()->withoutGlobalScope('tenant')->where('tenant_id', $tenant->getKey())->firstOrFail();
        $path = $branding->{$kind === 'logo' ? 'logo_path' : 'favicon_path'};
        abort_unless(is_string($path) && str_starts_with($path, 'branding/'.$tenant->public_id.'/') && Storage::disk('local')->exists($path), 404);

        return Storage::disk('local')->response($path, null, ['Content-Disposition' => 'inline', 'X-Content-Type-Options' => 'nosniff', 'Cache-Control' => 'public, max-age=300']);
    }
}
