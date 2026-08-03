<?php

use App\Http\Middleware\EnsureTenantIsActive;
use App\Http\Middleware\SetRequestLocale;
use App\Support\Http\ApiException;
use App\Http\Middleware\ResolveTenantContext;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        api: __DIR__.'/../routes/api.php',
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->append(SetRequestLocale::class);
        $middleware->statefulApi();
        $middleware->alias([
            'tenant.context' => ResolveTenantContext::class,
            'tenant.active' => EnsureTenantIsActive::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $isApiRequest = static fn (Request $request): bool => $request->expectsJson() || $request->is('api/*');

        $exceptions->render(function (ValidationException $exception, Request $request) use ($isApiRequest): ?JsonResponse {
            if (! $isApiRequest($request)) {
                return null;
            }

            return response()->json([
                'code' => 'validation_failed',
                'message' => __('api.errors.validation_failed'),
                'errors' => $exception->errors(),
            ], $exception->status);
        });

        $exceptions->render(function (ApiException $exception, Request $request) use ($isApiRequest): ?JsonResponse {
            if (! $isApiRequest($request)) {
                return null;
            }

            return response()->json([
                'code' => $exception->codeName(),
                'message' => $exception->translatedMessage(),
            ], $exception->status());
        });

        $exceptions->render(function (AuthenticationException $exception, Request $request) use ($isApiRequest): ?JsonResponse {
            if (! $isApiRequest($request)) {
                return null;
            }

            return response()->json([
                'code' => 'unauthenticated',
                'message' => __('api.errors.unauthenticated'),
            ], Response::HTTP_UNAUTHORIZED);
        });

        $exceptions->render(function (AuthorizationException $exception, Request $request) use ($isApiRequest): ?JsonResponse {
            if (! $isApiRequest($request)) {
                return null;
            }

            return response()->json([
                'code' => 'forbidden',
                'message' => __('api.errors.forbidden'),
            ], Response::HTTP_FORBIDDEN);
        });

        $exceptions->render(function (HttpExceptionInterface $exception, Request $request) use ($isApiRequest): ?JsonResponse {
            if (! $isApiRequest($request)) {
                return null;
            }

            return match ($exception->getStatusCode()) {
                Response::HTTP_UNAUTHORIZED => response()->json([
                    'code' => 'unauthenticated',
                    'message' => __('api.errors.unauthenticated'),
                ], Response::HTTP_UNAUTHORIZED),
                Response::HTTP_FORBIDDEN => response()->json([
                    'code' => 'forbidden',
                    'message' => __('api.errors.forbidden'),
                ], Response::HTTP_FORBIDDEN),
                Response::HTTP_NOT_FOUND => response()->json([
                    'code' => 'not_found',
                    'message' => __('api.errors.not_found'),
                ], Response::HTTP_NOT_FOUND),
                default => response()->json([
                    'code' => 'http_error',
                    'message' => $exception->getMessage() !== ''
                        ? $exception->getMessage()
                        : __('api.errors.internal_error'),
                ], $exception->getStatusCode()),
            };
        });

        $exceptions->render(function (ModelNotFoundException|NotFoundHttpException $exception, Request $request) use ($isApiRequest): ?JsonResponse {
            if (! $isApiRequest($request)) {
                return null;
            }

            return response()->json([
                'code' => 'not_found',
                'message' => __('api.errors.not_found'),
            ], Response::HTTP_NOT_FOUND);
        });

        $exceptions->render(function (\Throwable $exception, Request $request) use ($isApiRequest): ?JsonResponse {
            if (! $isApiRequest($request)) {
                return null;
            }

            return response()->json([
                'code' => 'internal_error',
                'message' => __('api.errors.internal_error'),
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        });
    })->create();
