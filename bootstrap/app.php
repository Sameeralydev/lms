<?php

use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\AuthenticateIntegrationToken;
use App\Http\Middleware\AssignRequestCorrelationId;
use App\Http\Middleware\RequireAcademicFeature;
use App\Http\Middleware\RequireAcademicScope;
use App\Http\Middleware\RequireAdmissionsEnabled;
use App\Http\Middleware\RequirePlatformPermission;
use App\Http\Middleware\RequireTenantPermission;
use App\Http\Middleware\RequireTenantEntitlement;
use App\Http\Middleware\ResolveAdmissionsTenant;
use App\Http\Middleware\ResolveTenantWorkspace;
use App\Http\Middleware\SecurityHeaders;
use App\Http\Middleware\ValidateTrustedHost;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->web(append: [HandleInertiaRequests::class]);
        $middleware->append(AssignRequestCorrelationId::class);
        $middleware->append(SecurityHeaders::class);
        $middleware->append(ValidateTrustedHost::class);
        $middleware->alias([
            'platform.permission' => RequirePlatformPermission::class,
            'tenant.permission' => RequireTenantPermission::class,
            'tenant.entitlement' => RequireTenantEntitlement::class,
            'tenant.academic.scope' => RequireAcademicScope::class,
            'tenant.academic.feature' => RequireAcademicFeature::class,
            'tenant.admissions.enabled' => RequireAdmissionsEnabled::class,
            'admissions.enabled' => RequireAdmissionsEnabled::class,
            'admissions.tenant' => ResolveAdmissionsTenant::class,
            'tenant.workspace' => ResolveTenantWorkspace::class,
            'integration.token' => AuthenticateIntegrationToken::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
