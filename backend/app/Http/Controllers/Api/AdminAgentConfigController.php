<?php

namespace App\Http\Controllers\Api;

use App\Domain\Agent\AgentConfigSettings;
use App\Domain\Agent\AgentPackRegistry;
use App\Enums\Permission;
use App\Http\Controllers\Api\Concerns\ResolvesTenantFromRequest;
use App\Http\Requests\Api\UpsertAgentConfigRequest;
use App\Models\AgentConfig;
use App\Models\WhatsAppLine;
use App\Support\Admin\AdminPanelDataBuilder;
use App\Support\Audit\AuditEventRecorder;
use App\Support\Tenancy\TenantScopeKey;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Illuminate\Support\Facades\Gate;

class AdminAgentConfigController
{
    use ResolvesTenantFromRequest;

    public function __construct(
        protected AgentPackRegistry $packs,
        protected AdminPanelDataBuilder $builder,
        protected AuditEventRecorder $audit,
    ) {
    }

    public function updateTenant(UpsertAgentConfigRequest $request): JsonResponse
    {
        $tenant = $this->tenantFromRequest($request);
        Gate::forUser($request->user())->authorize(Permission::ManageAgentConfig->value, $tenant);

        $config = AgentConfig::query()->firstOrNew([
            'tenant_id' => $tenant->getKey(),
            'scope_key' => TenantScopeKey::forTenant($tenant),
        ]);

        $config = $this->persistConfig($config, $request->validated(), [
            'tenant_id' => $tenant->getKey(),
            'whatsapp_line_id' => null,
            'scope_type' => 'tenant',
            'scope_key' => TenantScopeKey::forTenant($tenant),
        ]);

        $this->audit->record(
            tenantId: $tenant->getKey(),
            eventType: 'agent_config_updated',
            actorUserId: $request->user()?->getKey(),
            target: $config,
            payload: [
                'scope_key' => $config->scope_key,
            ],
        );

        return response()->json([
            'data' => $this->builder->serializeAgentConfig($config->fresh()),
            'meta' => [
                'available_agent_packs' => $this->packs->available(),
            ],
        ]);
    }

    public function updateLine(UpsertAgentConfigRequest $request, WhatsAppLine $whatsappLine): JsonResponse
    {
        $tenant = $this->tenantFromRequest($request);
        Gate::forUser($request->user())->authorize(Permission::ManageAgentConfig->value, $tenant);

        $line = WhatsAppLine::query()
            ->forTenant($tenant->getKey())
            ->findOrFail($whatsappLine->getKey());

        $config = AgentConfig::query()->firstOrNew([
            'tenant_id' => $tenant->getKey(),
            'scope_key' => TenantScopeKey::forWhatsAppLine($line),
        ]);

        $config = $this->persistConfig($config, $request->validated(), [
            'tenant_id' => $tenant->getKey(),
            'whatsapp_line_id' => $line->getKey(),
            'scope_type' => 'whatsapp_line',
            'scope_key' => TenantScopeKey::forWhatsAppLine($line),
        ]);

        $this->audit->record(
            tenantId: $tenant->getKey(),
            eventType: 'agent_config_updated',
            actorUserId: $request->user()?->getKey(),
            target: $config,
            payload: [
                'scope_key' => $config->scope_key,
                'whatsapp_line_id' => $line->getKey(),
            ],
        );

        return response()->json([
            'data' => $this->builder->serializeAgentConfig($config->fresh()->load('whatsappLine:id,name,display_phone_number')),
            'meta' => [
                'available_agent_packs' => $this->packs->available(),
            ],
        ]);
    }

    public function destroyLine(Request $request, WhatsAppLine $whatsappLine): Response
    {
        $tenant = $this->tenantFromRequest($request);
        Gate::forUser($request->user())->authorize(Permission::ManageAgentConfig->value, $tenant);

        $line = WhatsAppLine::query()
            ->forTenant($tenant->getKey())
            ->findOrFail($whatsappLine->getKey());

        $config = AgentConfig::query()
            ->forTenant($tenant->getKey())
            ->where('scope_key', TenantScopeKey::forWhatsAppLine($line))
            ->first();

        if ($config) {
            $this->audit->record(
                tenantId: $tenant->getKey(),
                eventType: 'agent_config_deleted',
                actorUserId: $request->user()?->getKey(),
                target: $config,
                payload: [
                    'scope_key' => $config->scope_key,
                    'whatsapp_line_id' => $line->getKey(),
                ],
            );

            $config->delete();
        }

        return response()->noContent();
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $scope
     */
    protected function persistConfig(AgentConfig $config, array $payload, array $scope): AgentConfig
    {
        $settings = is_array($config->settings) ? $config->settings : [];
        $settings['automation_enabled'] = (bool) $payload['automation_enabled'];
        $settings['system_prompt'] = trim((string) ($payload['system_prompt'] ?? ''));
        $settings['agent_pack_key'] = trim((string) ($payload['agent_pack_key'] ?? AgentConfigSettings::DEFAULT_AGENT_PACK_KEY))
            ?: AgentConfigSettings::DEFAULT_AGENT_PACK_KEY;
        $settings['handoff_customer_message'] = trim((string) ($payload['handoff_customer_message'] ?? ''));

        $config->forceFill([
            ...$scope,
            'name' => $payload['name'],
            'model' => $payload['model'] ?: null,
            'prompt_version' => $payload['prompt_version'] ?: null,
            'is_active' => (bool) $payload['is_active'],
            'settings' => $settings,
        ])->save();

        return $config;
    }
}
