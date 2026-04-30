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
use App\Support\AgentEvents\AgentEventRecorder;

class SearchKnowledgeTool implements ToolInterface
{
    public function __construct(
        protected DataSourceReader $reader,
        protected ToolBoundDataSourceResolver $dataSourceResolver,
        protected AgentEventRecorder $events,
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
            $this->recordUnavailableSearch($invocation, $question, $topic, $exception->getMessage());

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

        $this->recordSearchCompleted($invocation, $dataSource, $question, $topic, $matches);

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

    protected function limit(mixed $value, int $default): int
    {
        if (! is_numeric($value)) {
            return $default;
        }

        return max(1, min(5, (int) $value));
    }

    /**
     * @param  array<int, array<string, mixed>>  $matches
     */
    protected function recordSearchCompleted(ToolInvocation $invocation, \App\Models\DataSource $dataSource, string $question, string $topic, array $matches): void
    {
        $this->events->record($invocation->context->tenant->getKey(), 'data_source_search_completed', [
            'whatsapp_line_id' => $invocation->context->line->getKey(),
            'conversation_id' => $invocation->context->conversation->getKey(),
            'conversation_message_id' => $invocation->context->triggeringMessage->getKey(),
            'payload' => [
                'tool_name' => 'search_knowledge',
                'data_source_id' => $dataSource->getKey(),
                'retrieval_mode' => 'knowledge',
                'question' => $question,
                'topic' => $topic,
                'match_count' => count($matches),
                'matched_chunk_ids' => array_values(array_map(
                    static fn (array $match): mixed => $match['id'] ?? null,
                    $matches,
                )),
                'sheet_names' => array_values(array_unique(array_filter(array_map(
                    static fn (array $match): mixed => data_get($match, 'source_ref.sheet_name'),
                    $matches,
                )))),
            ],
        ]);
    }

    protected function recordUnavailableSearch(ToolInvocation $invocation, string $question, string $topic, string $reason): void
    {
        $this->events->record($invocation->context->tenant->getKey(), 'data_source_search_unavailable', [
            'whatsapp_line_id' => $invocation->context->line->getKey(),
            'conversation_id' => $invocation->context->conversation->getKey(),
            'conversation_message_id' => $invocation->context->triggeringMessage->getKey(),
            'payload' => [
                'tool_name' => 'search_knowledge',
                'retrieval_mode' => 'knowledge',
                'question' => $question,
                'topic' => $topic,
                'reason' => $reason,
            ],
        ]);
    }
}
