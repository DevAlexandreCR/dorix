<?php

namespace App\Domain\Agent;

final readonly class IntentDefinition
{
    /**
     * @param  array<int, AgentDecisionOutcome>  $allowedOutcomes
     * @param  array<int, string>  $allowedTools
     * @param  array<int, string>  $requiredFields
     */
    public function __construct(
        public string $key,
        public array $allowedOutcomes = [],
        public array $allowedTools = [],
        public array $requiredFields = [],
        public bool $requiresRetrievedContextForSendMessage = false,
    ) {
    }

    public function allowsOutcome(AgentDecisionOutcome $outcome): bool
    {
        return in_array($outcome, $this->allowedOutcomes, true);
    }

    public function allowsTool(string $toolName): bool
    {
        return in_array($toolName, $this->allowedTools, true);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'allowed_outcomes' => array_map(
                static fn (AgentDecisionOutcome $outcome): string => $outcome->value,
                $this->allowedOutcomes,
            ),
            'allowed_tools' => $this->allowedTools,
            'required_fields' => $this->requiredFields,
            'requires_retrieved_context_for_send_message' => $this->requiresRetrievedContextForSendMessage,
        ];
    }
}
