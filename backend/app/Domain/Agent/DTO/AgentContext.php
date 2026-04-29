<?php

namespace App\Domain\Agent\DTO;

use App\Models\AgentConfig;
use App\Models\Conversation;
use App\Models\ConversationMessage;
use App\Models\ConversationState;
use App\Models\Tenant;
use App\Models\TenantToolConfig;
use App\Models\WhatsAppLine;

final readonly class AgentContext
{
    /**
     * @param  array<int, ConversationMessage>  $recentMessages
     * @param  array<int, TenantToolConfig>  $enabledTools
     */
    public function __construct(
        public Tenant $tenant,
        public WhatsAppLine $line,
        public Conversation $conversation,
        public ConversationState $state,
        public AgentConfig $agentConfig,
        public ConversationMessage $triggeringMessage,
        public array $recentMessages,
        public array $enabledTools,
    ) {
    }
}
