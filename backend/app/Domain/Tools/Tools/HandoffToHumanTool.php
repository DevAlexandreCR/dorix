<?php

namespace App\Domain\Tools\Tools;

use App\Domain\Tools\Contracts\ToolInterface;
use App\Domain\Tools\DTO\ToolDefinition;
use App\Domain\Tools\DTO\ToolInvocation;
use App\Domain\Tools\DTO\ToolResult;
use App\Domain\Tools\Exceptions\InvalidToolArgumentsException;
use App\Domain\Tools\ToolNextAction;

class HandoffToHumanTool implements ToolInterface
{
    public function definition(): ToolDefinition
    {
        return new ToolDefinition(
            name: 'handoff_to_human',
            description: 'Escalate the conversation to a human operator when automation is no longer safe or sufficient.',
            inputSchema: [
                'type' => 'object',
                'additionalProperties' => false,
                'required' => ['reason'],
                'properties' => [
                    'reason' => [
                        'type' => 'string',
                        'description' => 'Operational reason for escalating to a human.',
                    ],
                    'reply_text' => [
                        'type' => 'string',
                        'description' => 'Optional public message to send before the handoff is created.',
                    ],
                    'payload' => [
                        'type' => 'object',
                        'description' => 'Optional structured context to include in the handoff request.',
                        'additionalProperties' => true,
                    ],
                ],
            ],
            outputSchema: [
                'type' => 'object',
                'additionalProperties' => false,
                'properties' => [
                    'handoff_requested' => [
                        'type' => 'boolean',
                    ],
                    'reason' => [
                        'type' => 'string',
                    ],
                ],
            ],
            defaultTimeoutSeconds: 5,
            isImplemented: true,
            implementationPhase: 5,
        );
    }

    public function execute(ToolInvocation $invocation): ToolResult
    {
        $reason = $this->requireString($invocation->arguments['reason'] ?? null, 'reason');
        $replyText = $this->optionalString($invocation->arguments['reply_text'] ?? '');
        $payload = $invocation->arguments['payload'] ?? [];

        if (! is_array($payload)) {
            throw new InvalidToolArgumentsException(__('api.tools.invalid_payload_object'));
        }

        return new ToolResult(
            nextAction: ToolNextAction::RequestHandoff,
            replyText: $replyText,
            handoffReason: $reason,
            outputSummary: [
                'handoff_requested' => true,
                'reason' => $reason,
            ],
            metadata: [
                'handoff_payload' => $payload,
                'tool_category' => 'handoff',
            ],
        );
    }

    protected function requireString(mixed $value, string $field): string
    {
        if (! is_string($value) || trim($value) === '') {
            throw new InvalidToolArgumentsException(__('api.tools.field_non_empty_string', [
                'field' => $field,
            ]));
        }

        return trim($value);
    }

    protected function optionalString(mixed $value): string
    {
        return is_string($value) ? trim($value) : '';
    }
}
