<?php

namespace App\Domain\Agent;

final class AgentPackRegistry
{
    /**
     * @var array<string, AgentPack>
     */
    protected array $packs;

    public function __construct()
    {
        $salesSupport = new AgentPack(
            key: AgentConfigSettings::DEFAULT_AGENT_PACK_KEY,
            name: 'Sales Support v1',
            intents: [
                'knowledge_lookup' => new IntentDefinition(
                    key: 'knowledge_lookup',
                    allowedOutcomes: [
                        AgentDecisionOutcome::CallTool,
                        AgentDecisionOutcome::SendMessage,
                    ],
                    allowedTools: [
                        'search_knowledge',
                    ],
                    requiresRetrievedContextForSendMessage: true,
                ),
                'inventory_lookup' => new IntentDefinition(
                    key: 'inventory_lookup',
                    allowedOutcomes: [
                        AgentDecisionOutcome::CallTool,
                        AgentDecisionOutcome::SendMessage,
                    ],
                    allowedTools: [
                        'search_inventory',
                    ],
                    requiresRetrievedContextForSendMessage: true,
                ),
                'service_scheduling' => new IntentDefinition(
                    key: 'service_scheduling',
                    allowedOutcomes: [
                        AgentDecisionOutcome::CallTool,
                        AgentDecisionOutcome::SendMessage,
                    ],
                    allowedTools: [
                        'get_service_details',
                        'check_availability',
                        'create_appointment',
                    ],
                    requiresRetrievedContextForSendMessage: true,
                ),
                'customer_data_capture' => new IntentDefinition(
                    key: 'customer_data_capture',
                    allowedOutcomes: [
                        AgentDecisionOutcome::RequestMissingInformation,
                        AgentDecisionOutcome::CallTool,
                    ],
                    allowedTools: [
                        'save_customer_data',
                    ],
                ),
                'lead_qualification' => new IntentDefinition(
                    key: 'lead_qualification',
                    allowedOutcomes: [
                        AgentDecisionOutcome::RequestMissingInformation,
                        AgentDecisionOutcome::CallTool,
                    ],
                    allowedTools: [
                        'save_customer_data',
                        'create_lead',
                    ],
                    requiredFields: [
                        'customer_name',
                        'interest_summary',
                    ],
                ),
                'handoff_requested' => new IntentDefinition(
                    key: 'handoff_requested',
                    allowedOutcomes: [
                        AgentDecisionOutcome::CallTool,
                    ],
                    allowedTools: [
                        'handoff_to_human',
                    ],
                ),
                'unsupported' => new IntentDefinition(
                    key: 'unsupported',
                    allowedOutcomes: [
                        AgentDecisionOutcome::CallTool,
                    ],
                    allowedTools: [
                        'handoff_to_human',
                    ],
                ),
            ],
        );

        $this->packs = [
            $salesSupport->key => $salesSupport,
        ];
    }

    public function default(): AgentPack
    {
        return $this->packs[AgentConfigSettings::DEFAULT_AGENT_PACK_KEY];
    }

    public function resolve(?string $key): AgentPack
    {
        $normalized = is_string($key) ? trim($key) : '';

        return $this->packs[$normalized] ?? $this->default();
    }

    public function has(string $key): bool
    {
        return array_key_exists(trim($key), $this->packs);
    }

    /**
     * @return array<int, string>
     */
    public function keys(): array
    {
        return array_keys($this->packs);
    }

    /**
     * @return array<int, array<string, string>>
     */
    public function available(): array
    {
        return array_map(
            static fn (AgentPack $pack): array => [
                'key' => $pack->key,
                'name' => $pack->name,
            ],
            array_values($this->packs),
        );
    }
}
