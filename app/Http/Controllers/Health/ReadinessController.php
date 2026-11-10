<?php

namespace App\Http\Controllers\Health;

use App\Security\HealthCheckToken;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

class ReadinessController
{
    public function __invoke(Request $request, HealthCheckToken $token): JsonResponse
    {
        $expectedToken = config('health.diagnostics_token');

        if (! is_string($expectedToken) || ! $token->matches($expectedToken, $request->bearerToken())) {
            abort(404);
        }

        $checks = [
            'database' => $this->databaseStatus(),
            'cache' => $this->cacheStatus(),
        ];

        $ready = collect($checks)->every(fn (string $status): bool => $status === 'ok');

        return response()->json([
            'status' => $ready ? 'ready' : 'unavailable',
            'checks' => $checks,
        ], $ready ? 200 : 503)->header('Cache-Control', 'no-store, private');
    }

    private function databaseStatus(): string
    {
        try {
            DB::connection()->select('select 1');

            return 'ok';
        } catch (Throwable) {
            return 'unavailable';
        }
    }

    private function cacheStatus(): string
    {
        $key = 'readiness:'.Str::random(40);

        try {
            Cache::put($key, 'ok', 10);

            return Cache::get($key) === 'ok' ? 'ok' : 'unavailable';
        } catch (Throwable) {
            return 'unavailable';
        } finally {
            try {
                Cache::forget($key);
            } catch (Throwable) {
                // A failed cache check must not expose infrastructure details or mask the response.
            }
        }
    }
}
