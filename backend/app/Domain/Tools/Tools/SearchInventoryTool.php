<?php

namespace App\Domain\Tools\Tools;

use App\Domain\Tools\Contracts\ToolInterface;
use App\Domain\Tools\DTO\ToolDefinition;
use App\Domain\Tools\DTO\ToolInvocation;
use App\Domain\Tools\DTO\ToolResult;
use App\Domain\Tools\Exceptions\ToolNotImplementedException;

class SearchInventoryTool implements ToolInterface
{
    public function definition(): ToolDefinition
    {
        return new ToolDefinition(
            name: 'search_inventory',
            description: 'Search inventory records for the current tenant. The Excel-backed implementation is delivered in phase 6.',
            inputSchema: [
                'type' => 'object',
                'additionalProperties' => false,
                'required' => ['query'],
                'properties' => [
                    'query' => [
                        'type' => 'string',
                        'description' => 'Inventory search query or filter expression.',
                    ],
                    'filters' => [
                        'type' => 'object',
                        'description' => 'Optional structured filters to apply to the inventory search.',
                        'additionalProperties' => true,
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
        throw new ToolNotImplementedException('The "search_inventory" tool is registered, but its Excel-backed implementation is deferred to phase 6.');
    }
}
