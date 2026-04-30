<?php

namespace App\Domain\Tools\Tools;

use App\Domain\Tools\Contracts\ToolInterface;
use App\Domain\Tools\DTO\ToolDefinition;
use App\Domain\Tools\DTO\ToolInvocation;
use App\Domain\Tools\DTO\ToolResult;
use App\Domain\Tools\Exceptions\InvalidToolArgumentsException;
use App\Domain\Tools\ToolNextAction;

class CreateLeadTool implements ToolInterface
{
    public function definition(): ToolDefinition
    {
        return new ToolDefinition(
            name: 'create_lead',
            description: 'Create or refresh a lead snapshot for the current conversation using structured customer data already collected.',
            inputSchema: [
                'type' => 'object',
                'additionalProperties' => false,
                'required' => ['lead_data'],
                'properties' => [
                    'lead_data' => [
                        'type' => 'object',
                        'description' => 'Structured lead payload to persist for the conversation.',
                        'additionalProperties' => true,
                    ],
                    'reply_text' => [
                        'type' => 'string',
                        'description' => 'Optional customer-facing confirmation message to send after creating the lead.',
                    ],
                ],
            ],
            outputSchema: [
                'type' => 'object',
                'additionalProperties' => false,
                'properties' => [
                    'saved_fields' => [
                        'type' => 'array',
                        'items' => ['type' => 'string'],
                    ],
                    'lead_status' => [
                        'type' => 'string',
                    ],
                ],
            ],
            defaultTimeoutSeconds: 10,
            isImplemented: true,
            implementationPhase: 5,
        );
    }

    public function execute(ToolInvocation $invocation): ToolResult
    {
        $leadData = $this->requireObject($invocation->arguments['lead_data'] ?? null, 'lead_data');
        $replyText = $this->optionalString($invocation->arguments['reply_text'] ?? '');

        $existingCollectedData = $invocation->context->state->collected_data ?? [];
        $mergedCollectedData = array_merge($existingCollectedData, $leadData);

        $existingMetadata = $invocation->context->conversation->metadata ?? [];
        $existingLead = is_array($existingMetadata['lead'] ?? null) ? $existingMetadata['lead'] : [];

        $leadSnapshot = array_merge($existingLead, [
            'status' => 'created',
            'created_via' => 'tool:create_lead',
            'created_at' => now()->toIso8601String(),
            'data' => $leadData,
        ]);

        $conversationUpdates = [
            'metadata' => array_merge($existingMetadata, [
                'lead' => $leadSnapshot,
            ]),
        ];

        $contactName = $this->extractContactName($leadData);

        if ($contactName !== null) {
            $conversationUpdates['contact_name'] = $contactName;
        }

        return new ToolResult(
            nextAction: $replyText !== '' ? ToolNextAction::SendMessageAndWait : ToolNextAction::WaitForCustomer,
            replyText: $replyText,
            outputSummary: [
                'saved_fields' => array_keys($leadData),
                'lead_status' => 'created',
            ],
            stateUpdates: [
                'collected_data' => $mergedCollectedData,
            ],
            conversationUpdates: $conversationUpdates,
            metadata: [
                'tool_category' => 'lead_management',
            ],
        );
    }

    /**
     * @return array<string, mixed>
     */
    protected function requireObject(mixed $value, string $field): array
    {
        if (! is_array($value) || $value === []) {
            throw new InvalidToolArgumentsException(__('api.tools.field_non_empty_object', [
                'field' => $field,
            ]));
        }

        return $value;
    }

    protected function optionalString(mixed $value): string
    {
        return is_string($value) ? trim($value) : '';
    }

    protected function extractContactName(array $leadData): ?string
    {
        foreach (['name', 'customer_name', 'full_name'] as $field) {
            $value = $leadData[$field] ?? null;

            if (is_string($value) && trim($value) !== '') {
                return trim($value);
            }
        }

        return null;
    }
}
