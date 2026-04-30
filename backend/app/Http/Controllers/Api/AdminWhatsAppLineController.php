<?php

namespace App\Http\Controllers\Api;

use App\Enums\Permission;
use App\Http\Controllers\Api\Concerns\ResolvesTenantFromRequest;
use App\Http\Requests\Api\UpsertWhatsAppLineRequest;
use App\Models\WhatsAppLine;
use App\Support\Admin\AdminPanelDataBuilder;
use App\Support\Audit\AuditEventRecorder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\Response;

class AdminWhatsAppLineController
{
    use ResolvesTenantFromRequest;

    public function __construct(
        protected AdminPanelDataBuilder $builder,
        protected AuditEventRecorder $audit,
    ) {
    }

    public function store(UpsertWhatsAppLineRequest $request): JsonResponse
    {
        $tenant = $this->tenantFromRequest($request);
        Gate::forUser($request->user())->authorize(Permission::ManageTenant->value, $tenant);

        $line = WhatsAppLine::query()->create([
            ...$request->validated(),
            'tenant_id' => $tenant->getKey(),
        ]);

        $this->audit->record(
            tenantId: $tenant->getKey(),
            eventType: 'whatsapp_line_created',
            actorUserId: $request->user()?->getKey(),
            target: $line,
            payload: [
                'whatsapp_line_id' => $line->getKey(),
                'phone_number_id' => $line->phone_number_id,
            ],
        );

        return response()->json([
            'data' => $this->builder->serializeWhatsAppLine($line),
        ], Response::HTTP_CREATED);
    }

    public function update(UpsertWhatsAppLineRequest $request, WhatsAppLine $whatsappLine): JsonResponse
    {
        $tenant = $this->tenantFromRequest($request);
        Gate::forUser($request->user())->authorize(Permission::ManageTenant->value, $tenant);

        $line = WhatsAppLine::query()
            ->forTenant($tenant->getKey())
            ->findOrFail($whatsappLine->getKey());

        $line->fill($request->validated());
        $line->save();

        $this->audit->record(
            tenantId: $tenant->getKey(),
            eventType: 'whatsapp_line_updated',
            actorUserId: $request->user()?->getKey(),
            target: $line,
            payload: [
                'whatsapp_line_id' => $line->getKey(),
            ],
        );

        return response()->json([
            'data' => $this->builder->serializeWhatsAppLine($line->fresh()),
        ]);
    }

    public function destroy(Request $request, WhatsAppLine $whatsappLine): JsonResponse
    {
        $tenant = $this->tenantFromRequest($request);
        Gate::forUser($request->user())->authorize(Permission::ManageTenant->value, $tenant);

        $line = WhatsAppLine::query()
            ->forTenant($tenant->getKey())
            ->findOrFail($whatsappLine->getKey());

        $this->audit->record(
            tenantId: $tenant->getKey(),
            eventType: 'whatsapp_line_deleted',
            actorUserId: $request->user()?->getKey(),
            payload: [
                'whatsapp_line_id' => $line->getKey(),
                'phone_number_id' => $line->phone_number_id,
            ],
        );

        $line->delete();

        return response()->json([], Response::HTTP_NO_CONTENT);
    }
}
