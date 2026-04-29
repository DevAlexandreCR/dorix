<?php

namespace App\Domain\Tools\Tools;

use App\Domain\Tools\Contracts\ToolInterface;
use App\Domain\Tools\DTO\ToolDefinition;
use App\Domain\Tools\DTO\ToolInvocation;
use App\Domain\Tools\DTO\ToolResult;
use App\Domain\Tools\Exceptions\ToolNotImplementedException;

class SearchFaqTool implements ToolInterface
{
    public function definition(): ToolDefinition
    {
        return new ToolDefinition(
            name: 'search_faq',
            description: 'Search FAQ knowledge for the current tenant. The Excel-backed implementation is delivered in phase 6.',
            inputSchema: [
                'type' => 'object',
                'additionalProperties' => false,
                'required' => ['question'],
                'properties' => [
                    'question' => [
                        'type' => 'string',
                        'description' => 'Customer question to resolve against the FAQ knowledge base.',
                    ],
                    'topic' => [
                        'type' => 'string',
                        'description' => 'Optional FAQ topic hint.',
                    ],
                ],
            ],
            outputSchema: [
                'type' => 'object',
                'additionalProperties' => false,
                'properties' => [
                    'matches' => [
                        'type' => 'array',
                        'items' => ['type' => 'object'],
                    ],
                ],
            ],
            defaultTimeoutSeconds: 10,
            isImplemented: false,
            implementationPhase: 6,
        );
    }

    public function execute(ToolInvocation $invocation): ToolResult
    {
        throw new ToolNotImplementedException('The "search_faq" tool is registered, but its Excel-backed implementation is deferred to phase 6.');
    }
}
