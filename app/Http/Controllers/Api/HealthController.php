<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Throwable;

/**
 * Public readiness probe for Better Stack (and similar) uptime monitors.
 * Returns 200 only when critical dependencies respond; 503 otherwise.
 */
class HealthController extends Controller
{
    public function __invoke(): JsonResponse
    {
        $checks = [
            'app' => true,
            'database' => false,
            'redis' => null,
        ];
        $errors = [];

        try {
            DB::connection()->getPdo();
            DB::select('select 1');
            $checks['database'] = true;
        } catch (Throwable $e) {
            $errors['database'] = 'unavailable';
        }

        $shouldCheckRedis = in_array((string) config('queue.default'), ['redis'], true)
            || in_array((string) config('cache.default'), ['redis'], true)
            || in_array((string) config('session.driver'), ['redis'], true);

        if ($shouldCheckRedis) {
            try {
                $pong = Redis::connection()->ping();
                $checks['redis'] = $pong === true || $pong === 'PONG' || $pong === '+PONG';
                if (! $checks['redis']) {
                    $errors['redis'] = 'unexpected_pong';
                }
            } catch (Throwable $e) {
                $checks['redis'] = false;
                $errors['redis'] = 'unavailable';
            }
        }

        $ok = $checks['app']
            && $checks['database'] === true
            && ($checks['redis'] === null || $checks['redis'] === true);

        return response()->json([
            'status' => $ok ? 'ok' : 'degraded',
            'timestamp' => now()->toIso8601String(),
            'checks' => $checks,
            'errors' => $errors === [] ? (object) [] : $errors,
        ], $ok ? 200 : 503);
    }
}
