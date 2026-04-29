<?php

namespace App\Support\Tenancy;

use App\Models\Tenant;
use App\Support\Tenancy\Contracts\TenantContextResolver;
use App\Support\Tenancy\Exceptions\MissingTenantContextException;
use App\Support\Tenancy\Exceptions\TenantNotFoundForContextException;
use Illuminate\Http\Request;

class RequestTenantContextResolver implements TenantContextResolver
{
    public function resolve(Request $request): TenantContext
    {
        $routeTenant = $request->route('tenant');

        if ($routeTenant instanceof Tenant) {
            return new TenantContext($routeTenant, 'route');
        }

        if (is_string($routeTenant) && $routeTenant !== '') {
            return ctype_digit($routeTenant)
                ? $this->resolveById((int) $routeTenant, 'route')
                : $this->resolveBySlug($routeTenant, 'route');
        }

        if (is_int($routeTenant)) {
            return $this->resolveById($routeTenant, 'route');
        }

        $headerTenantId = $request->header('X-Tenant-Id');

        if (is_string($headerTenantId) && $headerTenantId !== '') {
            if (! ctype_digit($headerTenantId)) {
                throw new MissingTenantContextException('The X-Tenant-Id header must be a valid integer tenant identifier.');
            }

            return $this->resolveById((int) $headerTenantId, 'header:id');
        }

        $headerTenantSlug = $request->header('X-Tenant-Slug');

        if (is_string($headerTenantSlug) && $headerTenantSlug !== '') {
            return $this->resolveBySlug($headerTenantSlug, 'header:slug');
        }

        throw new MissingTenantContextException(
            'Tenant context is required. Provide the {tenant} route parameter or the X-Tenant-Id / X-Tenant-Slug headers.'
        );
    }

    protected function resolveById(int $tenantId, string $source): TenantContext
    {
        $tenant = Tenant::query()->find($tenantId);

        if (! $tenant) {
            throw new TenantNotFoundForContextException("Tenant {$tenantId} could not be resolved from the {$source} context.");
        }

        return new TenantContext($tenant, $source);
    }

    protected function resolveBySlug(string $slug, string $source): TenantContext
    {
        $tenant = Tenant::query()->where('slug', $slug)->first();

        if (! $tenant) {
            throw new TenantNotFoundForContextException("Tenant slug [{$slug}] could not be resolved from the {$source} context.");
        }

        return new TenantContext($tenant, $source);
    }
}
