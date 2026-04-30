<?php

namespace App\Http\Controllers\Api;

use App\Support\Observability\HealthCheckService;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

class HealthController
{
    public function __invoke(HealthCheckService $health): JsonResponse
    {
        $report = $health->report();

        return response()->json([
            'status' => $report['status'],
            'application' => config('app.name'),
            'environment' => app()->environment(),
            'timestamp' => now()->toIso8601String(),
            'stack' => [
                'api' => 'laravel',
                'frontend' => 'vue',
                'database' => config('database.default'),
                'queue' => config('queue.default'),
                'cache' => config('cache.default'),
            ],
            'checks' => $report['checks'],
        ], $report['status'] === 'ok' ? Response::HTTP_OK : Response::HTTP_SERVICE_UNAVAILABLE);
    }
}
