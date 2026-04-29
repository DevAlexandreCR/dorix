<?php

namespace App\Http\Middleware;

use App\Support\Tenancy\Contracts\TenantContextResolver;
use App\Support\Tenancy\Exceptions\MissingTenantContextException;
use App\Support\Tenancy\Exceptions\TenantNotFoundForContextException;
use App\Support\Tenancy\TenantContext;
use App\Support\Tenancy\TenantContextManager;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ResolveTenantContext
{
    public function handle(Request $request, Closure $next): Response
    {
        $resolver = app(TenantContextResolver::class);
        $manager = app(TenantContextManager::class);

        try {
            $context = $resolver->resolve($request);
        } catch (MissingTenantContextException $exception) {
            return $this->errorResponse(
                message: $exception->getMessage(),
                status: Response::HTTP_BAD_REQUEST,
            );
        } catch (TenantNotFoundForContextException $exception) {
            return $this->errorResponse(
                message: $exception->getMessage(),
                status: Response::HTTP_NOT_FOUND,
            );
        }

        $manager->set($context);
        $request->attributes->set(TenantContext::class, $context);

        try {
            return $next($request);
        } finally {
            $manager->clear();
        }
    }

    protected function errorResponse(string $message, int $status): JsonResponse
    {
        return response()->json([
            'message' => $message,
        ], $status);
    }
}
