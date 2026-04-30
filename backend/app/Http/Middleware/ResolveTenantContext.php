<?php

namespace App\Http\Middleware;

use App\Support\Tenancy\Contracts\TenantContextResolver;
use App\Support\Tenancy\TenantContext;
use App\Support\Tenancy\TenantContextManager;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ResolveTenantContext
{
    public function handle(Request $request, Closure $next): Response
    {
        $resolver = app(TenantContextResolver::class);
        $manager = app(TenantContextManager::class);
        $context = $resolver->resolve($request);

        $manager->set($context);
        $request->attributes->set(TenantContext::class, $context);

        try {
            return $next($request);
        } finally {
            $manager->clear();
        }
    }
}
