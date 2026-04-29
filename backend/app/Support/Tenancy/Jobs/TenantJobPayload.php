<?php

namespace App\Support\Tenancy\Jobs;

use App\Models\Tenant;

final readonly class TenantJobPayload
{
    public function __construct(
        public int $tenantId,
    ) {
    }

    public static function fromTenant(Tenant|int $tenant): self
    {
        return new self($tenant instanceof Tenant ? $tenant->getKey() : $tenant);
    }

    /**
     * @return array{tenant_id: int}
     */
    public function toArray(): array
    {
        return [
            'tenant_id' => $this->tenantId,
        ];
    }
}
