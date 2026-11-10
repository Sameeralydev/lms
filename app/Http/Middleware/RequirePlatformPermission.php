<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RequirePlatformPermission
{
    public function handle(Request $request, Closure $next, string $permission): Response
    {
        abort_if($request->attributes->has('verifiedTenantDomain'), 404);
        abort_unless($request->user()?->hasPlatformPermission($permission), 403);

        return $next($request);
    }
}
