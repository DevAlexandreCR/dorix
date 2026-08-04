<?php

namespace App\Http\Controllers\Api;

use App\Enums\Permission;
use App\Http\Controllers\Api\Concerns\ResolvesTenantFromRequest;
use App\Http\Requests\Api\UpsertCatalogItemRequest;
use App\Models\CatalogItem;
use App\Support\Admin\AdminPanelDataBuilder;
use App\Support\Audit\AuditEventRecorder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\Response;

class AdminCatalogItemController
{
    use ResolvesTenantFromRequest;

    public function __construct(
        protected AdminPanelDataBuilder $builder,
        protected AuditEventRecorder $audit,
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        $tenant = $this->tenantFromRequest($request);
        Gate::forUser($request->user())->authorize(Permission::ManageAgentConfig->value, $tenant);

        $items = CatalogItem::query()
            ->forTenant($tenant->getKey())
            ->orderBy('name')
            ->get();

        return response()->json([
            'data' => $items->map(fn (CatalogItem $item): array => $this->builder->serializeCatalogItem($item))->all(),
        ]);
    }

    public function store(UpsertCatalogItemRequest $request): JsonResponse
    {
        $tenant = $this->tenantFromRequest($request);
        Gate::forUser($request->user())->authorize(Permission::ManageAgentConfig->value, $tenant);

        $item = CatalogItem::query()->create([
            ...$request->validated(),
            'tenant_id' => $tenant->getKey(),
        ]);

        $this->audit->record(
            tenantId: $tenant->getKey(),
            eventType: 'catalog_item_created',
            actorUserId: $request->user()?->getKey(),
            target: $item,
            payload: [
                'catalog_item_id' => $item->getKey(),
                'kind' => $item->kind,
            ],
        );

        return response()->json([
            'data' => $this->builder->serializeCatalogItem($item),
        ], Response::HTTP_CREATED);
    }

    public function update(UpsertCatalogItemRequest $request, CatalogItem $catalogItem): JsonResponse
    {
        $tenant = $this->tenantFromRequest($request);
        Gate::forUser($request->user())->authorize(Permission::ManageAgentConfig->value, $tenant);

        $item = CatalogItem::query()
            ->forTenant($tenant->getKey())
            ->findOrFail($catalogItem->getKey());

        $item->fill($request->validated());
        $item->save();

        $this->audit->record(
            tenantId: $tenant->getKey(),
            eventType: 'catalog_item_updated',
            actorUserId: $request->user()?->getKey(),
            target: $item,
            payload: [
                'catalog_item_id' => $item->getKey(),
            ],
        );

        return response()->json([
            'data' => $this->builder->serializeCatalogItem($item->fresh()),
        ]);
    }

    public function destroy(Request $request, CatalogItem $catalogItem): JsonResponse
    {
        $tenant = $this->tenantFromRequest($request);
        Gate::forUser($request->user())->authorize(Permission::ManageAgentConfig->value, $tenant);

        $item = CatalogItem::query()
            ->forTenant($tenant->getKey())
            ->findOrFail($catalogItem->getKey());

        $this->audit->record(
            tenantId: $tenant->getKey(),
            eventType: 'catalog_item_deleted',
            actorUserId: $request->user()?->getKey(),
            payload: [
                'catalog_item_id' => $item->getKey(),
                'name' => $item->name,
            ],
        );

        $item->delete();

        return response()->json([], Response::HTTP_NO_CONTENT);
    }
}
