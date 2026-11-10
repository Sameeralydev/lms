<?php

namespace App\Http\Middleware;

use App\Domain\Tenancy\TenantContext;
use App\Models\AdmissionSetting;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class RequireAdmissionsEnabled
{
    public function __construct(private readonly TenantContext $context) {}

    public function handle(Request $request, Closure $next, string $capability = 'enabled'): Response
    {
        abort_unless($this->context->hasTenant(), 404);
        $settings = AdmissionSetting::query()->first();
        abort_unless($settings?->enabled, 404);
        if ($capability === 'public') {
            abort_unless($settings->public_applications_enabled, 404);
        } elseif ($capability === 'inquiries') {
            abort_unless($settings->inquiries_enabled, 404);
        }

        return $next($request);
    }
}
