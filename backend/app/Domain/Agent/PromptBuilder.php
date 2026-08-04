<?php

namespace App\Domain\Agent;

use App\Domain\Agent\DTO\AgentContext;
use App\Domain\Catalog\CatalogItemPricePresenter;
use App\Domain\Tools\DTO\EnabledTool;
use App\Models\CatalogItem;
use App\Models\ConversationMessage;
use JsonException;

class PromptBuilder
{
    public function __construct(
        protected AgentPackRegistry $packs,
        protected CatalogItemPricePresenter $pricePresenter,
    ) {
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function build(AgentContext $context): array
    {
        $pack = $this->packs->resolve(AgentConfigSettings::agentPackKey($context->agentConfig));
        $developerInstructions = $this->developerInstructions($pack);
        $customSystemPrompt = AgentConfigSettings::systemPrompt($context->agentConfig);

        if ($customSystemPrompt !== '') {
            $developerInstructions .= "\n\nTenant-specific instructions:\n".$customSystemPrompt
                ."\nThese tenant-specific instructions may narrow behavior or tone, but they must never expand the pack scope, intents, outcomes, or tools.";
        }

        return [
            [
                'role' => 'developer',
                'content' => $developerInstructions,
            ],
            [
                'role' => 'user',
                'content' => $this->contextPayload($context),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function decisionFormat(): array
    {
        return [
            'type' => 'json_schema',
            'name' => 'agent_runtime_decision',
            'strict' => true,
            'schema' => [
                'type' => 'object',
                'additionalProperties' => false,
                'required' => [
                    'outcome',
                    'reply_text',
                    'handoff_reason',
                    'tool_name',
                    'tool_arguments_json',
                    'missing_information_fields',
                    'current_intent',
                    'internal_notes',
                ],
                'properties' => [
                    'outcome' => [
                        'type' => 'string',
                        'enum' => array_map(
                            static fn (AgentDecisionOutcome $outcome): string => $outcome->value,
                            AgentDecisionOutcome::cases(),
                        ),
                    ],
                    'reply_text' => [
                        'type' => 'string',
                    ],
                    'handoff_reason' => [
                        'type' => 'string',
                    ],
                    'tool_name' => [
                        'type' => 'string',
                    ],
                    'tool_arguments_json' => [
                        'type' => 'string',
                    ],
                    'missing_information_fields' => [
                        'type' => 'array',
                        'items' => [
                            'type' => 'string',
                        ],
                    ],
                    'current_intent' => [
                        'type' => 'string',
                    ],
                    'internal_notes' => [
                        'type' => 'string',
                    ],
                ],
            ],
        ];
    }

    protected function developerInstructions(AgentPack $pack): string
    {
        $intentsJson = json_encode($pack->intentsForPrompt(), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return <<<TEXT
You are the backend runtime for a WhatsApp automation agent.
Return only a JSON object that matches the provided schema.
Choose exactly one outcome from: send_message, request_missing_information, call_tool, wait_for_customer, request_handoff, no_reply, error.
The active agent pack is "{$pack->key}" ({$pack->name}).
Only these intents are allowed in the active pack:
{$intentsJson}
Use call_tool only when the requested tool is both allowed by the active intent and listed in enabled_tools.
Never invent tool results, customer data, or external facts not present in the context.
Do not answer small talk, jokes, general knowledge, or anything unrelated to the tenant's supported scope.
If no safe automated reply is possible, call the handoff_to_human tool instead of guessing.
For send_message and request_missing_information, reply_text must contain the full customer-facing message.
For all other outcomes, reply_text must be an empty string.
tool_arguments_json must always be a JSON object string such as {} when unused.
missing_information_fields must contain snake_case field names when the customer must provide data.
Prefer concise replies and keep the language aligned with the customer's latest message.
For inventory_lookup, knowledge_lookup, and service_scheduling, do not use send_message unless retrieved_context is present.
For inventory_lookup without retrieved_context, call search_inventory.
For knowledge_lookup without retrieved_context, call search_knowledge.
When present, the context's catalog field lists the tenant's active catalog items (id, name, price, duration, and assessment requirement); only use ids from that list for service_scheduling tools.
For service_scheduling, call get_service_details when the customer asks about a specific catalog item and you need its description, price, or assessment policy before continuing.
For service_scheduling, once the item and desired date are clear, call check_availability to get concrete slots; never invent, guess, or compute times or durations yourself, and offer the customer only the slots returned by the tool.
For service_scheduling, call create_appointment only after the customer has confirmed one of the slots returned by check_availability; the appointment duration always comes from the tool, never from your own reasoning.
handoff_to_human is the only model-callable handoff route.
request_handoff is an internal runtime/policy fallback and should not be selected by the model.
When retrieved_context is present, treat it as the only approved knowledge source for the current reply.
When retrieved_context is present, do not call another tool. Use the retrieved_context to answer, ask for clarification, or request_handoff.
TEXT;
    }

    protected function contextPayload(AgentContext $context): string
    {
        try {
            $payload = [
                'tenant' => [
                    'id' => $context->tenant->getKey(),
                    'name' => $context->tenant->name,
                    'slug' => $context->tenant->slug,
                ],
                'whatsapp_line' => [
                    'id' => $context->line->getKey(),
                    'name' => $context->line->name,
                    'display_phone_number' => $context->line->display_phone_number,
                ],
                'conversation' => [
                    'id' => $context->conversation->getKey(),
                    'status' => $context->conversation->status->value,
                    'contact_phone' => $context->conversation->contact_phone,
                    'contact_name' => $context->conversation->contact_name,
                    'last_message_at' => $context->conversation->last_message_at?->toIso8601String(),
                    'last_customer_message_at' => $context->conversation->last_customer_message_at?->toIso8601String(),
                ],
                'state_snapshot' => [
                    'current_intent' => $context->state->current_intent,
                    'collected_data' => $context->state->collected_data ?? [],
                    'last_agent_action' => $context->state->last_agent_action,
                    'memory_summary' => $context->state->memory_summary,
                    'expires_at' => $context->state->expires_at?->toIso8601String(),
                ],
                'agent_config' => [
                    'name' => $context->agentConfig->name,
                    'model_key' => $context->resolvedModel['key'] ?? null,
                    'resolved_model_id' => $context->resolvedModel['model_id'] ?? null,
                    'model_source' => $context->resolvedModel['source'] ?? null,
                    'prompt_version' => $context->agentConfig->prompt_version,
                    'settings' => $context->agentConfig->settings ?? [],
                    'agent_pack_key' => AgentConfigSettings::agentPackKey($context->agentConfig),
                ],
                'enabled_tools' => array_map(
                    fn (EnabledTool $tool): array => $this->serializeTool($tool),
                    $context->enabledTools,
                ),
                'retrieved_context' => [
                    'matches' => $context->retrievedContext,
                    'metadata' => $context->retrievalMetadata,
                ],
                'triggering_message' => $this->serializeMessage($context->triggeringMessage),
                'recent_messages' => array_map(
                    fn (ConversationMessage $message): array => $this->serializeMessage($message),
                    $context->recentMessages,
                ),
            ];

            if ($context->catalogItems !== []) {
                $payload['catalog'] = array_map(
                    fn (CatalogItem $item): array => $this->serializeCatalogItem($item),
                    $context->catalogItems,
                );
            }

            return json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            return json_encode([
                'error' => 'Failed to encode context payload.',
            ]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    protected function serializeTool(EnabledTool $tool): array
    {
        return $tool->toArray();
    }

    /**
     * @return array<string, mixed>
     */
    protected function serializeMessage(ConversationMessage $message): array
    {
        return [
            'id' => $message->getKey(),
            'direction' => $message->direction->value,
            'message_type' => $message->message_type,
            'body' => $message->body,
            'status' => $message->status,
            'provider_message_id' => $message->provider_message_id,
            'received_at' => $message->received_at?->toIso8601String(),
            'sent_at' => $message->sent_at?->toIso8601String(),
        ];
    }

    /**
     * Compact catalog index entry per D10: id, name, human-readable price,
     * duration, and (when applicable) the linked assessment item's id.
     *
     * @return array<string, mixed>
     */
    protected function serializeCatalogItem(CatalogItem $item): array
    {
        return [
            'id' => $item->getKey(),
            'name' => $item->name,
            'price' => $this->pricePresenter->present($item),
            'duration_minutes' => $item->duration_minutes,
            'requires_assessment_item_id' => $item->assessment_item_id,
        ];
    }
}
