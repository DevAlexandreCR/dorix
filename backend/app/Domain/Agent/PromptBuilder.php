<?php

namespace App\Domain\Agent;

use App\Domain\Agent\DTO\AgentContext;
use App\Domain\Tools\DTO\EnabledTool;
use App\Models\ConversationMessage;
use JsonException;

class PromptBuilder
{
    /**
     * @return array<int, array<string, mixed>>
     */
    public function build(AgentContext $context): array
    {
        return [
            [
                'role' => 'developer',
                'content' => $this->developerInstructions(),
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

    protected function developerInstructions(): string
    {
        return <<<'TEXT'
You are the backend runtime for a WhatsApp automation agent.
Return only a JSON object that matches the provided schema.
Choose exactly one outcome from: send_message, request_missing_information, call_tool, wait_for_customer, request_handoff, no_reply, error.
Use call_tool only when the requested tool is listed in enabled_tools.
Never invent tool results, customer data, or external facts not present in the context.
If no safe automated reply is possible, request_handoff is preferred over guessing.
For send_message and request_missing_information, reply_text must contain the full customer-facing message.
For all other outcomes, reply_text must be an empty string.
tool_arguments_json must always be a JSON object string such as {} when unused.
missing_information_fields must contain snake_case field names when the customer must provide data.
Prefer concise replies and keep the language aligned with the customer's latest message.
When retrieved_context is present, treat it as the only approved knowledge source for the current reply.
When retrieved_context is present, do not call another tool. Use the retrieved_context to answer, ask for clarification, or request_handoff.
TEXT;
    }

    protected function contextPayload(AgentContext $context): string
    {
        try {
            return json_encode([
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
                    'model' => $context->agentConfig->model,
                    'prompt_version' => $context->agentConfig->prompt_version,
                    'settings' => $context->agentConfig->settings ?? [],
                ],
                'enabled_tools' => array_map(
                    fn (EnabledTool $tool): array => $this->serializeTool($tool),
                    $context->enabledTools,
                ),
                'retrieval_context' => [
                    'matches' => $context->retrievedContext,
                    'metadata' => $context->retrievalMetadata,
                ],
                'triggering_message' => $this->serializeMessage($context->triggeringMessage),
                'recent_messages' => array_map(
                    fn (ConversationMessage $message): array => $this->serializeMessage($message),
                    $context->recentMessages,
                ),
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
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
}
