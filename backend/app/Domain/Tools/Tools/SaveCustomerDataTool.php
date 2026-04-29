<?php

namespace App\Domain\Tools\Tools;

use App\Domain\Tools\Contracts\ToolInterface;
use App\Domain\Tools\DTO\ToolDefinition;
use App\Domain\Tools\DTO\ToolInvocation;
use App\Domain\Tools\DTO\ToolResult;
use App\Domain\Tools\Exceptions\InvalidToolArgumentsException;
use App\Domain\Tools\ToolNextAction;

class SaveCustomerDataTool implements ToolInterface
{
    public function definition(): ToolDefinition
    {
        return new ToolDefinition(
            name: 'save_customer_data',
            description: 'Persist structured customer data in the conversation state so it can be reused in later runtime turns.',
            inputSchema: [
                'type' => 'object',
                'additionalProperties' => false,
                'required' => ['customer_data'],
                'properties' => [
                    'customer_data' => [
                        'type' => 'object',
                        'description' => 'Structured customer data extracted from the conversation.',
                        'additionalProperties' => true,
                    ],
                    'reply_text' => [
                        'type' => 'string',
                        'description' => 'Optional customer-facing confirmation message to send after saving the data.',
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
                    'total_collected_fields' => [
                        'type' => 'integer',
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
        $customerData = $this->requireObject($invocation->arguments['customer_data'] ?? null, 'customer_data');
        $replyText = $this->optionalString($invocation->arguments['reply_text'] ?? '');

        $existingCollectedData = $invocation->context->state->collected_data ?? [];
        $mergedCollectedData = array_merge($existingCollectedData, $customerData);

        $conversationUpdates = [];
        $contactName = $this->extractContactName($customerData);

        if ($contactName !== null) {
            $conversationUpdates['contact_name'] = $contactName;
        }

        return new ToolResult(
            nextAction: $replyText !== '' ? ToolNextAction::SendMessageAndWait : ToolNextAction::WaitForCustomer,
            replyText: $replyText,
            outputSummary: [
                'saved_fields' => array_keys($customerData),
                'total_collected_fields' => count($mergedCollectedData),
            ],
            stateUpdates: [
                'collected_data' => $mergedCollectedData,
            ],
            conversationUpdates: $conversationUpdates,
            metadata: [
                'tool_category' => 'customer_data',
            ],
        );
    }

    /**
     * @return array<string, mixed>
     */
    protected function requireObject(mixed $value, string $field): array
    {
        if (! is_array($value) || $value === []) {
            throw new InvalidToolArgumentsException(sprintf('The tool argument "%s" must be a non-empty object.', $field));
        }

        return $value;
    }

    protected function optionalString(mixed $value): string
    {
        return is_string($value) ? trim($value) : '';
    }

    protected function extractContactName(array $customerData): ?string
    {
        foreach (['name', 'customer_name', 'full_name'] as $field) {
            $value = $customerData[$field] ?? null;

            if (is_string($value) && trim($value) !== '') {
                return trim($value);
            }
        }

        return null;
    }
}
