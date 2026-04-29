<?php

namespace App\Support\Tenancy\Jobs;

use App\Models\Tenant;
use App\Support\Tenancy\TenantContext;
use App\Support\Tenancy\TenantContextManager;

trait RunsInTenantContext
{
    /**
     * @template TReturn
     *
     * @param  callable(Tenant): TReturn  $callback
     * @return TReturn
     */
    protected function runInTenantContext(TenantContextManager $manager, int $tenantId, callable $callback): mixed
    {
        $tenant = Tenant::query()->findOrFail($tenantId);

        $manager->set(new TenantContext($tenant, 'job'));

        try {
            return $callback($tenant);
        } finally {
            $manager->clear();
        }
    }
}
