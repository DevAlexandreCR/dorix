<?php

namespace App\Domain\Agent;

use App\Domain\Agent\DTO\AgentContext;
use App\Domain\Agent\Exceptions\MissingAgentConfigurationException;
use App\Domain\Tools\DTO\EnabledTool;
use App\Domain\Tools\ToolRegistry;
use App\Models\AgentConfig;
use App\Models\Conversation;
use App\Models\ConversationMessage;
use App\Models\ConversationState;
use App\Models\TenantToolConfig;
use App\Models\WhatsAppLine;
use App\Support\Tenancy\TenantScopeKey;

class AgentContextLoader
{
    public function __construct(
        protected ToolRegistry $toolRegistry,
        protected AgentModelCatalog $models,
    ) {
    }

    public function load(Conversation $conversation, int $messageId, ConversationState $state): AgentContext
    {
        $line = WhatsAppLine::query()
            ->with('tenant')
            ->forTenant($conversation->tenant_id)
            ->findOrFail($conversation->whatsapp_line_id);

        $triggeringMessage = ConversationMessage::query()
            ->forTenant($conversation->tenant_id)
            ->where('conversation_id', $conversation->getKey())
            ->findOrFail($messageId);

        [$agentConfig, $lineAgentConfig, $tenantAgentConfig] = $this->resolveAgentConfigs($conversation, $line);

        return new AgentContext(
            tenant: $line->tenant,
            line: $line,
            conversation: $conversation->fresh(),
            state: $state->fresh(),
            agentConfig: $agentConfig,
            triggeringMessage: $triggeringMessage,
            recentMessages: ConversationMessage::query()
                ->forTenant($conversation->tenant_id)
                ->where('conversation_id', $conversation->getKey())
                ->orderByDesc('created_at')
                ->orderByDesc('id')
                ->limit(12)
                ->get()
                ->sortBy('id')
                ->values()
                ->all(),
            enabledTools: $this->resolveEnabledTools($conversation, $line),
            lineAgentConfig: $lineAgentConfig,
            tenantAgentConfig: $tenantAgentConfig,
            resolvedModel: $this->models->effectiveForConfigs($lineAgentConfig, $tenantAgentConfig),
        );
    }

    /**
     * @return array{0: AgentConfig, 1: AgentConfig|null, 2: AgentConfig|null}
     */
    protected function resolveAgentConfigs(Conversation $conversation, WhatsAppLine $line): array
    {
        $configs = AgentConfig::query()
            ->forTenant($conversation->tenant_id)
            ->where('is_active', true)
            ->whereIn('scope_key', [
                TenantScopeKey::forWhatsAppLine($line),
                TenantScopeKey::forTenant($conversation->tenant_id),
            ])
            ->orderByRaw(
                'case when scope_key = ? then 0 else 1 end',
                [TenantScopeKey::forWhatsAppLine($line)]
            )
            ->get()
            ->keyBy('scope_key');

        /** @var AgentConfig|null $lineConfig */
        $lineConfig = $configs->get(TenantScopeKey::forWhatsAppLine($line));
        /** @var AgentConfig|null $tenantConfig */
        $tenantConfig = $configs->get(TenantScopeKey::forTenant($conversation->tenant_id));
        $agentConfig = $lineConfig ?? $tenantConfig;

        if (! $agentConfig) {
            throw new MissingAgentConfigurationException(__('api.agent.missing_configuration'));
        }

        return [$agentConfig, $lineConfig, $tenantConfig];
    }

    /**
     * @return array<int, EnabledTool>
     */
    protected function resolveEnabledTools(Conversation $conversation, WhatsAppLine $line): array
    {
        $configs = TenantToolConfig::query()
            ->forTenant($conversation->tenant_id)
            ->whereIn('scope_key', [
                TenantScopeKey::forTenant($conversation->tenant_id),
                TenantScopeKey::forWhatsAppLine($line),
            ])
            ->orderByRaw(
                'case when scope_key = ? then 0 else 1 end',
                [TenantScopeKey::forTenant($conversation->tenant_id)]
            )
            ->orderBy('id')
            ->get()
            ->keyBy('tool_name')
            ->filter(static fn (TenantToolConfig $tool): bool => $tool->enabled)
            ->values()
            ->all();

        return $this->toolRegistry->resolveEnabledTools($configs);
    }
}
