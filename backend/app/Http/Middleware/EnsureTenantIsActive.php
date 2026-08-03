<?php

namespace App\Http\Middleware;

use App\Enums\TenantStatus;
use App\Support\Tenancy\Exceptions\TenantPausedException;
use App\Support\Tenancy\TenantContextManager;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureTenantIsActive
{
    public function handle(Request $request, Closure $next): Response
    {
        $manager = app(TenantContextManager::class);
        $context = $manager->current();

        // No tenant in context: this middleware must run after `tenant.context`,
        // which already errors when a tenant is required. Pass through instead
        // of crashing so route ordering mistakes fail via that resolver, not here.
        if (! $context) {
            return $next($request);
        }

        if ($context->tenant->status === TenantStatus::Paused) {
            throw new TenantPausedException(
                'tenant_paused',
                'api.errors.tenant_paused',
                status: 403,
            );
        }

        return $next($request);
    }
}
