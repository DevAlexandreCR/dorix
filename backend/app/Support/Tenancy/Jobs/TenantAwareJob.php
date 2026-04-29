<?php

namespace App\Support\Tenancy\Jobs;

interface TenantAwareJob
{
    public function tenantId(): int;
}
