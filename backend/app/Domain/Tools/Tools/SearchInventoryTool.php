<?php

namespace App\Domain\Tools\Tools;

use App\Domain\DataSources\Contracts\DataSourceReader;
use App\Domain\DataSources\Exceptions\DataSourceUnavailableException;
use App\Domain\DataSources\ToolBoundDataSourceResolver;
use App\Domain\Tools\Contracts\ToolInterface;
use App\Domain\Tools\DTO\ToolDefinition;
use App\Domain\Tools\DTO\ToolInvocation;
use App\Domain\Tools\DTO\ToolResult;
use App\Domain\Tools\Exceptions\InvalidToolArgumentsException;
use App\Domain\Tools\ToolNextAction;

class SearchInventoryTool implements ToolInterface
{
    public function __construct(
        protected DataSourceReader $reader,
        protected ToolBoundDataSourceResolver $dataSourceResolver,
    ) {
    }

    public function definition(): ToolDefinition
    {
        return new ToolDefinition(
            name: 'search_inventory',
            description: 'Retrieve inventory-related rows or table fragments from the tenant Excel source so the conversational model can answer from source material.',
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
                    'limit' => [
                        'type' => 'integer',
                        'description' => 'Optional maximum number of matches to retrieve.',
                    ],
                ],
            ],
            outputSchema: [
                'type' => 'object',
                'additionalProperties' => false,
                'properties' => [
                    'data_source_id' => [
                        'type' => 'integer',
                    ],
                    'match_count' => [
                        'type' => 'integer',
                    ],
                    'matches' => [
                        'type' => 'array',
                        'items' => ['type' => 'object'],
                    ],
                ],
            ],
            defaultTimeoutSeconds: 10,
            isImplemented: true,
            implementationPhase: 6,
        );
    }

    public function execute(ToolInvocation $invocation): ToolResult
    {
        $query = $this->requireString($invocation->arguments['query'] ?? null, 'query');
        $filters = $invocation->arguments['filters'] ?? [];
        $limit = $this->limit($invocation->arguments['limit'] ?? null, 3);

        if (! is_array($filters)) {
            throw new InvalidToolArgumentsException('The tool argument "filters" must be an object when provided.');
        }

        try {
            $dataSource = $this->dataSourceResolver->resolveForTool($invocation);
        } catch (DataSourceUnavailableException $exception) {
            return new ToolResult(
                nextAction: ToolNextAction::RequestHandoff,
                handoffReason: $exception->getMessage(),
                outputSummary: [
                    'data_source_available' => false,
                    'query' => $query,
                ],
                metadata: [
                    'tool_category' => 'document_retrieval',
                    'retrieval_mode' => 'inventory',
                ],
            );
        }

        $matches = $this->reader->search($dataSource, [
            'mode' => 'inventory',
            'query' => $query,
            'filters' => $filters,
            'limit' => $limit,
        ]);

        return new ToolResult(
            nextAction: ToolNextAction::ContinueWithRetrievedContext,
            outputSummary: [
                'data_source_id' => $dataSource->getKey(),
                'match_count' => count($matches),
                'matches' => $matches,
            ],
            metadata: [
                'tool_category' => 'document_retrieval',
                'retrieval_mode' => 'inventory',
                'retrieval_query' => $query,
                'retrieved_context' => $matches,
            ],
        );
    }

    protected function requireString(mixed $value, string $field): string
    {
        if (! is_string($value) || trim($value) === '') {
            throw new InvalidToolArgumentsException(sprintf('The tool argument "%s" must be a non-empty string.', $field));
        }

        return trim($value);
    }

    protected function limit(mixed $value, int $default): int
    {
        if (! is_numeric($value)) {
            return $default;
        }

        return max(1, min(5, (int) $value));
    }
}
