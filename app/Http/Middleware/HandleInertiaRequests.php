<?php

namespace App\Http\Middleware;

use App\Domain\Academic\AcademicDefaults;
use App\Domain\Appearance\ThemeRegistry;
use App\Domain\Tenancy\TenantContext;
use App\Models\AcademicConfiguration;
use App\Models\TenantMembership;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    protected $rootView = 'app';

    public function share(Request $request): array
    {
        $tenantContext = app(TenantContext::class);

        return array_merge(parent::share($request), [
            'appName' => config('app.name'),
            // Tenant route middleware runs after the global web middleware. Resolve
            // these props lazily so they observe only verified request context.
            'auth' => fn (): array => $this->authProps($request, $tenantContext),
            'theme' => fn (): string => $this->theme($request, $tenantContext),
            'branding' => fn (): ?array => $this->branding($tenantContext),
            'academicConfig' => fn (): ?array => $this->academicConfig($tenantContext),
            'workspaces' => fn (): array => $this->workspaces($request),
            'flash' => [
                'status' => fn () => $request->session()->get('status'),
                'sisImportMapping' => fn () => $request->session()->get('sisImportMapping'),
                'sisImportPreview' => fn () => $request->session()->get('sisImportPreview'),
                'sisPlacementPreview' => fn () => $request->session()->get('sisPlacementPreview'),
            ],
        ]);
    }

    /** @return array<string, mixed> */
    private function authProps(Request $request, TenantContext $tenantContext): array
    {
        $user = $request->user();
        $membership = $tenantContext->hasMembership() ? $tenantContext->membership()->loadMissing('roles.permissions') : null;
        $tenant = $membership ? $tenantContext->tenant() : null;

        return [
            'user' => $user ? ['id' => $user->getKey(), 'name' => $user->name, 'email' => $user->email] : null,
            'platformPermissions' => $user ? $user->platformRoles()->with('permissions')->get()->flatMap(fn ($role) => $role->permissions->pluck('key'))->unique()->values() : [],
            'tenant' => $tenant ? [
                'id' => $tenant->getKey(),
                'slug' => $tenant->slug,
                'name' => $tenant->name,
                'status' => $tenant->status,
                'membershipId' => $membership->getKey(),
                'campusId' => $membership->campus_id,
                'roles' => $membership->roles->pluck('name')->values(),
                'permissions' => $membership->roles->flatMap(fn ($role) => $role->permissions->pluck('key'))->unique()->values(),
            ] : null,
        ];
    }

    private function theme(Request $request, TenantContext $tenantContext): string
    {
        $theme = 'executive-navy';
        if ($tenantContext->hasMembership()) {
            $tenantTheme = $tenantContext->tenant()->branding()->value('theme');
            if (is_string($tenantTheme)) {
                $theme = $tenantTheme;
            }
        }
        $preference = $request->user()?->preferences()->where('key', 'theme')->first()?->value;

        if (is_array($preference) && isset($preference['theme']) && array_key_exists($preference['theme'], ThemeRegistry::all())) {
            return $preference['theme'];
        }

        return $theme;
    }

    /** @return array<string, string|null>|null */
    private function branding(TenantContext $tenantContext): ?array
    {
        if (! $tenantContext->hasMembership()) {
            return null;
        }

        $tenant = $tenantContext->tenant();
        $branding = $tenant->branding()->first();

        if (! $branding) {
            return null;
        }

        return [
            'displayName' => $branding->display_name,
            'primaryColor' => $branding->primary_color,
            'accentColor' => $branding->accent_color,
            'logoUrl' => $branding->logo_path ? route('tenant.assets.image', ['tenant' => $tenant->slug, 'kind' => 'logo']) : null,
        ];
    }

    /** @return array{operatingModel:string, enabledFeatures:array<string,bool>, terminology:array<string,string>}|null */
    private function academicConfig(TenantContext $tenantContext): ?array
    {
        if (! $tenantContext->hasMembership()) {
            return null;
        }

        $configuration = AcademicConfiguration::query()->first();
        if (! $configuration) {
            return null;
        }

        $academic = AcademicDefaults::present($configuration);

        return [
            'operatingModel' => (string) $academic['operating_model'],
            'enabledFeatures' => $academic['enabled_features'],
            'terminology' => $academic['terminology'],
        ];
    }

    /** @return array<int, array{slug: string, name: string, status: string}> */
    private function workspaces(Request $request): array
    {
        $user = $request->user();
        if (! $user) {
            return [];
        }

        return TenantMembership::query()->withoutGlobalScope('tenant')->with([
            'tenant:id,name,slug,status',
            'institution' => fn ($query) => $query->withoutGlobalScope('tenant'),
        ])->where('user_id', $user->getKey())->where('status', 'active')->get()
            ->map(fn ($row) => ['slug' => $row->tenant->slug, 'name' => $row->institution->display_name, 'status' => $row->tenant->status])
            ->all();
    }
}
