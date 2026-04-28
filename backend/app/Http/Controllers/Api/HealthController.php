<?php

namespace App\Http\Controllers\Api;

use Illuminate\Http\JsonResponse;

class HealthController
{
    public function __invoke(): JsonResponse
    {
        return response()->json([
            'status' => 'ok',
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
        ]);
    }
}

