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
                throw new MissingTenantContextException(
                    'tenant_context_header_invalid',
                    'api.errors.tenant_context_header_invalid',
                    status: 400,
                );
            }

            return $this->resolveById((int) $headerTenantId, 'header:id');
        }

        $headerTenantSlug = $request->header('X-Tenant-Slug');

        if (is_string($headerTenantSlug) && $headerTenantSlug !== '') {
            return $this->resolveBySlug($headerTenantSlug, 'header:slug');
        }

        throw new MissingTenantContextException(
            'tenant_context_required',
            'api.errors.tenant_context_required',
            status: 400,
        );
    }

    protected function resolveById(int $tenantId, string $source): TenantContext
    {
        $tenant = Tenant::query()->find($tenantId);

        if (! $tenant) {
            throw new TenantNotFoundForContextException(
                'tenant_not_found',
                'api.errors.tenant_not_found_id',
                [
                    'tenant_id' => $tenantId,
                    'source' => $source,
                ],
                404,
            );
        }

        return new TenantContext($tenant, $source);
    }

    protected function resolveBySlug(string $slug, string $source): TenantContext
    {
        $tenant = Tenant::query()->where('slug', $slug)->first();

        if (! $tenant) {
            throw new TenantNotFoundForContextException(
                'tenant_not_found',
                'api.errors.tenant_not_found_slug',
                [
                    'slug' => $slug,
                    'source' => $source,
                ],
                404,
            );
        }

        return new TenantContext($tenant, $source);
    }
}
