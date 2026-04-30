<?php

namespace App\Http\Controllers\Api;

use App\Enums\Permission;
use App\Enums\TenantRole;
use App\Http\Controllers\Api\Concerns\ResolvesTenantFromRequest;
use App\Http\Requests\Api\UpsertTenantRequest;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Admin\AdminPanelDataBuilder;
use App\Support\Audit\AuditEventRecorder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\Response;

class AdminTenantController
{
    use ResolvesTenantFromRequest;

    public function __construct(
        protected AdminPanelDataBuilder $builder,
        protected AuditEventRecorder $audit,
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $tenants = $user->isPlatformAdmin()
            ? Tenant::query()->orderBy('name')->get()
            : Tenant::query()
                ->whereHas('tenantUsers', function ($query) use ($user): void {
                    $query
                        ->where('user_id', $user->getKey())
                        ->where('role', TenantRole::TenantAdmin->value);
                })
                ->orderBy('name')
                ->get();

        return response()->json([
            'data' => $tenants->map(fn (Tenant $tenant): array => $this->builder->serializeTenant($tenant))->all(),
        ]);
    }

    public function overview(Request $request): JsonResponse
    {
        $tenant = $this->tenantFromRequest($request);
        Gate::forUser($request->user())->authorize(Permission::ManageTenant->value, $tenant);

        return response()->json([
            'data' => $this->builder->overview($tenant),
        ]);
    }

    public function store(UpsertTenantRequest $request): JsonResponse
    {
        Gate::forUser($request->user())->authorize(Permission::ManagePlatform->value);

        $tenant = Tenant::query()->create($request->validated());

        $this->audit->record(
            tenantId: $tenant->getKey(),
            eventType: 'tenant_created',
            actorUserId: $request->user()?->getKey(),
            target: $tenant,
            payload: [
                'tenant_id' => $tenant->getKey(),
                'slug' => $tenant->slug,
            ],
        );

        return response()->json([
            'data' => $this->builder->serializeTenant($tenant),
        ], Response::HTTP_CREATED);
    }

    public function update(UpsertTenantRequest $request, Tenant $tenant): JsonResponse
    {
        Gate::forUser($request->user())->authorize(Permission::ManageTenant->value, $tenant);

        $tenant->fill($request->validated());
        $tenant->save();

        $this->audit->record(
            tenantId: $tenant->getKey(),
            eventType: 'tenant_updated',
            actorUserId: $request->user()?->getKey(),
            target: $tenant,
            payload: [
                'tenant_id' => $tenant->getKey(),
            ],
        );

        return response()->json([
            'data' => $this->builder->serializeTenant($tenant->fresh()),
        ]);
    }

    public function destroy(Request $request, Tenant $tenant): JsonResponse
    {
        Gate::forUser($request->user())->authorize(Permission::ManagePlatform->value);

        $tenantId = $tenant->getKey();
        $tenantSlug = $tenant->slug;

        $this->audit->record(
            tenantId: $tenantId,
            eventType: 'tenant_deleted',
            actorUserId: $request->user()?->getKey(),
            payload: [
                'tenant_id' => $tenantId,
                'slug' => $tenantSlug,
            ],
        );

        $tenant->delete();

        return response()->json([], Response::HTTP_NO_CONTENT);
    }
}
