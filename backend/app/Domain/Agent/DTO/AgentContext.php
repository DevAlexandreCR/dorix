<?php

namespace App\Domain\Agent\DTO;

use App\Domain\Tools\DTO\EnabledTool;
use App\Models\AgentConfig;
use App\Models\Conversation;
use App\Models\ConversationMessage;
use App\Models\ConversationState;
use App\Models\Tenant;
use App\Models\WhatsAppLine;

final readonly class AgentContext
{
    /**
     * @param  array<int, ConversationMessage>  $recentMessages
     * @param  array<int, EnabledTool>  $enabledTools
     * @param  array<int, array<string, mixed>>  $retrievedContext
     * @param  array<string, mixed>  $retrievalMetadata
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
        public array $retrievedContext = [],
        public array $retrievalMetadata = [],
        public ?AgentConfig $lineAgentConfig = null,
        public ?AgentConfig $tenantAgentConfig = null,
        public array $resolvedModel = [],
    ) {
    }

    public function enabledTool(string $name): ?EnabledTool
    {
        foreach ($this->enabledTools as $tool) {
            if ($tool->name() === $name) {
                return $tool;
            }
        }

        return null;
    }

    /**
     * @param  array<int, array<string, mixed>>  $retrievedContext
     * @param  array<string, mixed>  $retrievalMetadata
     */
    public function withRetrievedContext(array $retrievedContext, array $retrievalMetadata = []): self
    {
        return new self(
            tenant: $this->tenant,
            line: $this->line,
            conversation: $this->conversation,
            state: $this->state,
            agentConfig: $this->agentConfig,
            triggeringMessage: $this->triggeringMessage,
            recentMessages: $this->recentMessages,
            enabledTools: $this->enabledTools,
            retrievedContext: $retrievedContext,
            retrievalMetadata: $retrievalMetadata,
            lineAgentConfig: $this->lineAgentConfig,
            tenantAgentConfig: $this->tenantAgentConfig,
            resolvedModel: $this->resolvedModel,
        );
    }
}
