<?php

namespace App\Support\Tenancy\Contracts;

use App\Support\Tenancy\TenantContext;
use Illuminate\Http\Request;

interface TenantContextResolver
{
    public function resolve(Request $request): TenantContext;
}
