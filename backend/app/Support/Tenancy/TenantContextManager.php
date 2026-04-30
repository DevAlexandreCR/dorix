<?php

namespace App\Support\Tenancy;

use App\Models\Tenant;
use App\Support\Tenancy\Exceptions\MissingTenantContextException;

class TenantContextManager
{
    protected ?TenantContext $context = null;

    public function set(TenantContext $context): void
    {
        $this->context = $context;
    }

    public function clear(): void
    {
        $this->context = null;
    }

    public function current(): ?TenantContext
    {
        return $this->context;
    }

    public function tenant(): ?Tenant
    {
        return $this->context?->tenant;
    }

    public function require(): TenantContext
    {
        if (! $this->context) {
            throw new MissingTenantContextException(
                'tenant_context_missing_runtime',
                'api.errors.tenant_context_missing_runtime',
                status: 400,
            );
        }

        return $this->context;
    }
}
