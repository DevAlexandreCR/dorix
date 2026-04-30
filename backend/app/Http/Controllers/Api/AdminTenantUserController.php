<?php

namespace App\Http\Controllers\Api;

use App\Enums\Permission;
use App\Http\Controllers\Api\Concerns\ResolvesTenantFromRequest;
use App\Http\Requests\Api\StoreTenantUserRequest;
use App\Http\Requests\Api\UpdateTenantUserRequest;
use App\Models\TenantUser;
use App\Models\User;
use App\Support\Admin\AdminPanelDataBuilder;
use App\Support\Audit\AuditEventRecorder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

class AdminTenantUserController
{
    use ResolvesTenantFromRequest;

    public function __construct(
        protected AdminPanelDataBuilder $builder,
        protected AuditEventRecorder $audit,
    ) {
    }

    public function store(StoreTenantUserRequest $request): JsonResponse
    {
        $tenant = $this->tenantFromRequest($request);
        Gate::forUser($request->user())->authorize(Permission::ManageTenantUsers->value, $tenant);

        $email = strtolower((string) $request->validated('email'));
        $role = $request->validated('role');
        $password = $request->validated('password');
        $name = trim((string) ($request->validated('name') ?? ''));

        $user = User::query()->where('email', $email)->first();

        if (! $user) {
            if (! is_string($password) || trim($password) === '') {
                throw ValidationException::withMessages([
                    'password' => [__('api.admin.password_required_new_user')],
                ]);
            }

            $user = User::query()->create([
                'name' => $name !== '' ? $name : $email,
                'email' => $email,
                'password' => $password,
            ]);
        }

        $existingMembership = TenantUser::query()
            ->forTenant($tenant->getKey())
            ->where('user_id', $user->getKey())
            ->first();

        if ($existingMembership) {
            throw ValidationException::withMessages([
                'email' => [__('api.admin.tenant_user_exists')],
            ]);
        }

        $membership = TenantUser::query()->create([
            'tenant_id' => $tenant->getKey(),
            'user_id' => $user->getKey(),
            'role' => $role,
        ])->load('user:id,name,email');

        $this->audit->record(
            tenantId: $tenant->getKey(),
            eventType: 'tenant_user_added',
            actorUserId: $request->user()?->getKey(),
            target: $membership,
            payload: [
                'user_id' => $user->getKey(),
                'role' => $role,
            ],
        );

        return response()->json([
            'data' => $this->builder->serializeTenantUser($membership),
        ], Response::HTTP_CREATED);
    }

    public function update(UpdateTenantUserRequest $request, TenantUser $tenantUser): JsonResponse
    {
        $tenant = $this->tenantFromRequest($request);
        Gate::forUser($request->user())->authorize(Permission::ManageTenantUsers->value, $tenant);

        $membership = TenantUser::query()
            ->forTenant($tenant->getKey())
            ->with('user:id,name,email')
            ->findOrFail($tenantUser->getKey());

        $membership->forceFill([
            'role' => $request->validated('role'),
        ])->save();

        $this->audit->record(
            tenantId: $tenant->getKey(),
            eventType: 'tenant_user_role_updated',
            actorUserId: $request->user()?->getKey(),
            target: $membership,
            payload: [
                'user_id' => $membership->user_id,
                'role' => $membership->role?->value,
            ],
        );

        return response()->json([
            'data' => $this->builder->serializeTenantUser($membership->fresh('user:id,name,email')),
        ]);
    }

    public function destroy(Request $request, TenantUser $tenantUser): JsonResponse
    {
        $tenant = $this->tenantFromRequest($request);
        Gate::forUser($request->user())->authorize(Permission::ManageTenantUsers->value, $tenant);

        $membership = TenantUser::query()
            ->forTenant($tenant->getKey())
            ->with('user:id,name,email')
            ->findOrFail($tenantUser->getKey());

        $this->audit->record(
            tenantId: $tenant->getKey(),
            eventType: 'tenant_user_removed',
            actorUserId: $request->user()?->getKey(),
            payload: [
                'user_id' => $membership->user_id,
                'role' => $membership->role?->value,
            ],
        );

        $membership->delete();

        return response()->json([], Response::HTTP_NO_CONTENT);
    }
}
