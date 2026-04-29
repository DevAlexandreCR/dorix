<?php

namespace App\Support\Tenancy;

use App\Models\Tenant;

final readonly class TenantContext
{
    public function __construct(
        public Tenant $tenant,
        public string $source,
    ) {
    }

    public function tenantId(): int
    {
        return $this->tenant->getKey();
    }
}
