<?php

namespace App\Http\Controllers\Api;

use App\Domain\Tools\ToolRegistry;
use App\Enums\Permission;
use App\Http\Controllers\Api\Concerns\ResolvesTenantFromRequest;
use App\Http\Requests\Api\UpsertToolConfigRequest;
use App\Models\DataSource;
use App\Models\TenantToolConfig;
use App\Models\WhatsAppLine;
use App\Support\Admin\AdminPanelDataBuilder;
use App\Support\Audit\AuditEventRecorder;
use App\Support\Tenancy\TenantScopeKey;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class AdminToolConfigController
{
    use ResolvesTenantFromRequest;

    public function __construct(
        protected ToolRegistry $toolRegistry,
        protected AdminPanelDataBuilder $builder,
        protected AuditEventRecorder $audit,
    ) {
    }

    public function updateTenant(UpsertToolConfigRequest $request, string $toolName): JsonResponse
    {
        $tenant = $this->tenantFromRequest($request);
        Gate::forUser($request->user())->authorize(Permission::ManageAgentConfig->value, $tenant);
        $this->ensureToolExists($toolName);

        $config = TenantToolConfig::query()->firstOrNew([
            'tenant_id' => $tenant->getKey(),
            'scope_key' => TenantScopeKey::forTenant($tenant),
            'tool_name' => $toolName,
        ]);

        $config = $this->persistConfig($tenant->getKey(), $config, $request->validated(), [
            'whatsapp_line_id' => null,
            'scope_type' => 'tenant',
            'scope_key' => TenantScopeKey::forTenant($tenant),
            'tool_name' => $toolName,
        ]);

        $this->audit->record(
            tenantId: $tenant->getKey(),
            eventType: 'tool_config_updated',
            actorUserId: $request->user()?->getKey(),
            target: $config,
            payload: [
                'scope_key' => $config->scope_key,
                'tool_name' => $toolName,
            ],
        );

        return response()->json([
            'data' => $this->builder->serializeToolConfig($config->fresh()),
        ]);
    }

    public function updateLine(UpsertToolConfigRequest $request, WhatsAppLine $whatsappLine, string $toolName): JsonResponse
    {
        $tenant = $this->tenantFromRequest($request);
        Gate::forUser($request->user())->authorize(Permission::ManageAgentConfig->value, $tenant);
        $this->ensureToolExists($toolName);

        $line = WhatsAppLine::query()
            ->forTenant($tenant->getKey())
            ->findOrFail($whatsappLine->getKey());

        $config = TenantToolConfig::query()->firstOrNew([
            'tenant_id' => $tenant->getKey(),
            'scope_key' => TenantScopeKey::forWhatsAppLine($line),
            'tool_name' => $toolName,
        ]);

        $config = $this->persistConfig($tenant->getKey(), $config, $request->validated(), [
            'whatsapp_line_id' => $line->getKey(),
            'scope_type' => 'whatsapp_line',
            'scope_key' => TenantScopeKey::forWhatsAppLine($line),
            'tool_name' => $toolName,
        ]);

        $this->audit->record(
            tenantId: $tenant->getKey(),
            eventType: 'tool_config_updated',
            actorUserId: $request->user()?->getKey(),
            target: $config,
            payload: [
                'scope_key' => $config->scope_key,
                'tool_name' => $toolName,
                'whatsapp_line_id' => $line->getKey(),
            ],
        );

        return response()->json([
            'data' => $this->builder->serializeToolConfig($config->fresh()->load('whatsappLine:id,name,display_phone_number')),
        ]);
    }

    protected function ensureToolExists(string $toolName): void
    {
        if ($this->toolRegistry->has($toolName)) {
            return;
        }

        throw ValidationException::withMessages([
            'tool_name' => [__('api.admin.tool_not_registered')],
        ]);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $scope
     */
    protected function persistConfig(int $tenantId, TenantToolConfig $config, array $payload, array $scope): TenantToolConfig
    {
        $dataSourceId = $payload['data_source_id'] ?? null;

        if ($dataSourceId !== null) {
            $exists = DataSource::query()
                ->forTenant($tenantId)
                ->whereKey((int) $dataSourceId)
                ->exists();

            if (! $exists) {
                throw ValidationException::withMessages([
                    'data_source_id' => [__('api.admin.data_source_wrong_tenant')],
                ]);
            }
        }

        $config->forceFill([
            'tenant_id' => $tenantId,
            ...$scope,
            'enabled' => (bool) $payload['enabled'],
            'timeout_seconds' => $payload['timeout_seconds'] ?? null,
            'bindings' => $dataSourceId !== null ? ['data_source_id' => (int) $dataSourceId] : [],
        ])->save();

        return $config;
    }
}
