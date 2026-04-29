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

        return new AgentContext(
            tenant: $line->tenant,
            line: $line,
            conversation: $conversation->fresh(),
            state: $state->fresh(),
            agentConfig: $this->resolveAgentConfig($conversation, $line),
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
        );
    }

    protected function resolveAgentConfig(Conversation $conversation, WhatsAppLine $line): AgentConfig
    {
        $agentConfig = AgentConfig::query()
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
            ->first();

        if (! $agentConfig) {
            throw new MissingAgentConfigurationException('No active agent configuration was found for the conversation tenant or WhatsApp line.');
        }

        return $agentConfig;
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
