<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * Liveness/readiness probe for load balancers and Docker. Checks local
 * dependencies only: the railway provider is never called, so probes do not
 * spend the RailRadar request quota.
 */
class HealthController extends Controller
{
    public function __invoke(): JsonResponse
    {
        $checks = [
            'database' => $this->check(fn () => DB::connection()->getPdo()->query('select 1')),
            'cache' => $this->check(function () {
                $key = 'health:'.Str::random(8);
                Cache::put($key, true, 10);
                throw_unless(Cache::pull($key) === true, RuntimeException::class, 'Cache read-back failed.');
            }),
        ];

        $healthy = collect($checks)->every(fn (array $check) => $check['status'] === 'ok');

        return response()->json([
            'status' => $healthy ? 'ok' : 'error',
            'app' => config('app.name'),
            'version' => config('app.version'),
            'environment' => app()->environment(),
            'railway_provider' => config('railway.provider'),
            'checks' => $checks,
            'timestamp' => now()->toIso8601String(),
        ], $healthy ? 200 : 503)->header('Cache-Control', 'no-store');
    }

    /** @return array{status: string, latency_ms: float} */
    private function check(callable $probe): array
    {
        $start = hrtime(true);

        try {
            $probe();
            $status = 'ok';
        } catch (Throwable $e) {
            // Keep driver details (hosts, paths) out of the public response.
            Log::warning('Health check failed: '.$e->getMessage());
            $status = 'error';
        }

        return ['status' => $status, 'latency_ms' => round((hrtime(true) - $start) / 1e6, 2)];
    }
}
