<?php

namespace App\Http\Controllers\Api;

use Illuminate\Http\JsonResponse;

class MetaController
{
    public function __invoke(): JsonResponse
    {
        return response()->json([
            'name' => config('app.name'),
            'api' => [
                'version' => 'v1',
                'base_path' => '/api/v1',
                'health_path' => '/api/health',
                'auth' => 'sanctum',
                'tenancy' => [
                    'mode' => 'single_database_multi_tenant',
                    'resolver' => 'explicit',
                    'route_parameter' => 'tenant',
                    'headers' => ['X-Tenant-Id', 'X-Tenant-Slug'],
                    'job_payload_key' => 'tenant_id',
                ],
            ],
            'frontend' => [
                'framework' => 'vue-3',
                'module_root' => 'frontend/src/modules',
            ],
            'backend' => [
                'domain_root' => 'backend/app/Domain',
                'tenant_support_root' => 'backend/app/Support/Tenancy',
            ],
        ]);
    }
}
