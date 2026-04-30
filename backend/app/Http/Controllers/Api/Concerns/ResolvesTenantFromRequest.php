<?php

namespace App\Http\Controllers\Api\Concerns;

use App\Models\Tenant;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\Request;

trait ResolvesTenantFromRequest
{
    protected function tenantFromRequest(Request $request): Tenant
    {
        /** @var TenantContext $context */
        $context = $request->attributes->get(TenantContext::class);

        return $context->tenant;
    }
}
