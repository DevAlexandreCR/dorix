<?php

namespace App\Http\Controllers\Api;

use App\Enums\Permission;
use App\Http\Requests\Api\LoginRequest;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Auth\TenantAccess;
use App\Support\Http\ApiException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class AuthSessionController
{
    public function __construct(
        protected TenantAccess $tenantAccess,
    ) {
    }

    public function show(Request $request): JsonResponse
    {
        /** @var User|null $user */
        $user = $request->user();

        if (! $user) {
            return response()->json([
                'authenticated' => false,
                'user' => null,
                'memberships' => [],
            ]);
        }

        return response()->json($this->buildSessionPayload($user));
    }

    public function store(LoginRequest $request): JsonResponse
    {
        $credentials = $request->validated();

        if (! Auth::guard('web')->attempt($credentials, false)) {
            throw new ApiException(
                'invalid_credentials',
                'api.auth.invalid_credentials',
                status: 422,
            );
        }

        $request->session()->regenerate();

        /** @var User $user */
        $user = Auth::guard('web')->user();

        return response()->json($this->buildSessionPayload($user));
    }

    public function destroy(Request $request): JsonResponse
    {
        Auth::guard('web')->logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->json([
            'authenticated' => false,
            'user' => null,
            'memberships' => [],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    protected function buildSessionPayload(User $user): array
    {
        $memberships = $user->isPlatformAdmin()
            ? Tenant::query()->orderBy('name')->get()->map(
                fn (Tenant $tenant): array => $this->serializeMembership($user, $tenant, 'platform_admin')
            )
            : $user->tenantMemberships()
                ->with('tenant')
                ->get()
                ->sortBy(fn ($membership): string => strtolower((string) $membership->tenant?->name))
                ->values()
                ->map(fn ($membership): array => $this->serializeMembership($user, $membership->tenant, $membership->role?->value ?? 'unknown'));

        return [
            'authenticated' => true,
            'user' => [
                'id' => $user->getKey(),
                'name' => $user->name,
                'email' => $user->email,
                'platform_role' => $user->platform_role?->value,
            ],
            'memberships' => $memberships->all(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function serializeMembership(User $user, ?Tenant $tenant, string $role): array
    {
        if (! $tenant) {
            return [
                'tenant_id' => null,
                'tenant_name' => null,
                'tenant_slug' => null,
                'role' => $role,
                'permissions' => [],
            ];
        }

        $permissions = collect(Permission::cases())
            ->filter(fn (Permission $permission): bool => $this->tenantAccess->allows($user, $permission, $tenant))
            ->map(fn (Permission $permission): string => $permission->value)
            ->values()
            ->all();

        return [
            'tenant_id' => $tenant->getKey(),
            'tenant_name' => $tenant->name,
            'tenant_slug' => $tenant->slug,
            'role' => $role,
            'permissions' => $permissions,
        ];
    }
}
