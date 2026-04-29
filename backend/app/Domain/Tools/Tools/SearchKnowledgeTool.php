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

class SearchKnowledgeTool implements ToolInterface
{
    public function __construct(
        protected DataSourceReader $reader,
        protected ToolBoundDataSourceResolver $dataSourceResolver,
    ) {
    }

    public function definition(): ToolDefinition
    {
        return new ToolDefinition(
            name: 'search_knowledge',
            description: 'Retrieve relevant documentary context for the current tenant so the conversational model can answer from source material.',
            inputSchema: [
                'type' => 'object',
                'additionalProperties' => false,
                'required' => ['question'],
                'properties' => [
                    'question' => [
                        'type' => 'string',
                        'description' => 'Customer question to resolve against the available knowledge source.',
                    ],
                    'topic' => [
                        'type' => 'string',
                        'description' => 'Optional topic hint to guide retrieval.',
                    ],
                    'limit' => [
                        'type' => 'integer',
                        'description' => 'Optional maximum number of context matches to retrieve.',
                    ],
                ],
            ],
            outputSchema: [
                'type' => 'object',
                'additionalProperties' => false,
                'properties' => [
                    'data_source_id' => ['type' => 'integer'],
                    'match_count' => ['type' => 'integer'],
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
        $question = $this->requireString($invocation->arguments['question'] ?? null, 'question');
        $topic = $this->optionalString($invocation->arguments['topic'] ?? null);
        $limit = $this->limit($invocation->arguments['limit'] ?? null, 3);

        try {
            $dataSource = $this->dataSourceResolver->resolveForTool($invocation);
        } catch (DataSourceUnavailableException $exception) {
            return new ToolResult(
                nextAction: ToolNextAction::RequestHandoff,
                handoffReason: $exception->getMessage(),
                outputSummary: [
                    'data_source_available' => false,
                    'question' => $question,
                ],
                metadata: [
                    'tool_category' => 'document_retrieval',
                    'retrieval_mode' => 'knowledge',
                ],
            );
        }

        $matches = $this->reader->search($dataSource, [
            'mode' => 'knowledge',
            'question' => $question,
            'topic' => $topic,
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
                'retrieval_mode' => 'knowledge',
                'retrieval_query' => $question,
                'retrieval_topic' => $topic,
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

    protected function optionalString(mixed $value): string
    {
        return is_string($value) ? trim($value) : '';
    }

    protected function limit(mixed $value, int $default): int
    {
        if (! is_numeric($value)) {
            return $default;
        }

        return max(1, min(5, (int) $value));
    }
}
