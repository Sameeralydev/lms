<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/** Assigns a server-generated request identifier for safe audit/log correlation. */
class AssignRequestCorrelationId
{
    public function handle(Request $request, Closure $next): Response
    {
        // Never use a caller-supplied value as the audit identifier: a client could
        // otherwise deliberately collide with or impersonate another request.
        $requestId = (string) Str::uuid();
        $request->attributes->set('request_id', $requestId);

        /** @var Response $response */
        $response = $next($request);
        $response->headers->set('X-Request-Id', $requestId);

        return $response;
    }
}
