<?php

namespace App\Domain\Tools\Tools;

use App\Domain\Catalog\CatalogItemPolicy;
use App\Domain\Catalog\CatalogItemPricePresenter;
use App\Domain\Tools\Contracts\ToolInterface;
use App\Domain\Tools\DTO\ToolDefinition;
use App\Domain\Tools\DTO\ToolInvocation;
use App\Domain\Tools\DTO\ToolResult;
use App\Domain\Tools\Exceptions\InvalidToolArgumentsException;
use App\Domain\Tools\ToolNextAction;
use App\Models\CatalogItem;
use App\Support\AgentEvents\AgentEventRecorder;

class GetServiceDetailsTool implements ToolInterface
{
    public function __construct(
        protected CatalogItemPolicy $policy,
        protected CatalogItemPricePresenter $pricePresenter,
        protected AgentEventRecorder $events,
    ) {}

    public function definition(): ToolDefinition
    {
        return new ToolDefinition(
            name: 'get_service_details',
            description: 'Resolve a catalog item by id and return its full conversational detail: description, human-readable price, duration, and assessment policy.',
            inputSchema: [
                'type' => 'object',
                'additionalProperties' => false,
                'required' => ['item_id'],
                'properties' => [
                    'item_id' => [
                        'type' => 'integer',
                        'description' => 'Catalog item id, as listed in the catalog index of the prompt.',
                    ],
                ],
            ],
            outputSchema: [
                'type' => 'object',
                'additionalProperties' => false,
                'properties' => [
                    'item_id' => ['type' => 'integer'],
                    'found' => ['type' => 'boolean'],
                    'reason' => ['type' => 'string'],
                    'kind' => ['type' => 'string'],
                    'name' => ['type' => 'string'],
                    'description' => ['type' => 'string'],
                    'price' => ['type' => 'string'],
                    'duration_minutes' => ['type' => 'integer'],
                    'bookable' => ['type' => 'boolean'],
                    'requires_assessment' => ['type' => 'boolean'],
                    'assessment_item' => ['type' => 'object'],
                ],
            ],
            defaultTimeoutSeconds: 10,
            isImplemented: true,
            implementationPhase: 7,
        );
    }

    public function execute(ToolInvocation $invocation): ToolResult
    {
        $itemId = $this->requirePositiveInteger($invocation->arguments['item_id'] ?? null, 'item_id');

        $item = CatalogItem::query()
            ->forTenant($invocation->context->tenant)
            ->where('id', $itemId)
            ->where('active', true)
            ->first();

        if ($item === null) {
            $this->recordNotFound($invocation, $itemId);

            return $this->notFoundResult($itemId);
        }

        $detail = $this->presentDetail($item);

        $this->recordFound($invocation, $item, $detail);

        return new ToolResult(
            nextAction: ToolNextAction::ContinueWithRetrievedContext,
            outputSummary: $detail,
            metadata: [
                'tool_category' => 'catalog',
                'retrieved_context' => [$detail],
            ],
        );
    }

    /**
     * @return array<string, mixed>
     */
    protected function presentDetail(CatalogItem $item): array
    {
        $assessmentItem = $item->assessmentItem;

        return [
            'item_id' => $item->getKey(),
            'found' => true,
            'kind' => $item->kind,
            'name' => $item->name,
            'description' => $item->description,
            'price' => $this->pricePresenter->present($item),
            'duration_minutes' => $item->duration_minutes,
            'bookable' => $this->policy->isBookable($item),
            'requires_assessment' => $assessmentItem !== null,
            'assessment_item' => $assessmentItem !== null ? [
                'id' => $assessmentItem->getKey(),
                'name' => $assessmentItem->name,
                'price' => $this->pricePresenter->present($assessmentItem),
                'duration_minutes' => $assessmentItem->duration_minutes,
            ] : null,
        ];
    }

    protected function notFoundResult(int $itemId): ToolResult
    {
        $detail = [
            'item_id' => $itemId,
            'found' => false,
            'reason' => (string) __('api.catalog.item_not_found'),
        ];

        return new ToolResult(
            nextAction: ToolNextAction::ContinueWithRetrievedContext,
            outputSummary: $detail,
            metadata: [
                'tool_category' => 'catalog',
                'retrieved_context' => [$detail],
            ],
        );
    }

    protected function requirePositiveInteger(mixed $value, string $field): int
    {
        if (! is_numeric($value) || (int) $value != $value || (int) $value <= 0) {
            throw new InvalidToolArgumentsException(__('api.tools.field_positive_integer', [
                'field' => $field,
            ]));
        }

        return (int) $value;
    }

    /**
     * @param  array<string, mixed>  $detail
     */
    protected function recordFound(ToolInvocation $invocation, CatalogItem $item, array $detail): void
    {
        $this->events->record($invocation->context->tenant->getKey(), 'catalog_item_lookup_completed', [
            'whatsapp_line_id' => $invocation->context->line->getKey(),
            'conversation_id' => $invocation->context->conversation->getKey(),
            'conversation_message_id' => $invocation->context->triggeringMessage->getKey(),
            'payload' => [
                'tool_name' => 'get_service_details',
                'item_id' => $item->getKey(),
                'kind' => $item->kind,
                'requires_assessment' => $detail['requires_assessment'],
            ],
        ]);
    }

    protected function recordNotFound(ToolInvocation $invocation, int $itemId): void
    {
        $this->events->record($invocation->context->tenant->getKey(), 'catalog_item_lookup_not_found', [
            'whatsapp_line_id' => $invocation->context->line->getKey(),
            'conversation_id' => $invocation->context->conversation->getKey(),
            'conversation_message_id' => $invocation->context->triggeringMessage->getKey(),
            'payload' => [
                'tool_name' => 'get_service_details',
                'item_id' => $itemId,
            ],
        ]);
    }
}
