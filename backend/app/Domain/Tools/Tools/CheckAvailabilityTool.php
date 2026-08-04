<?php

namespace App\Domain\Tools\Tools;

use App\Domain\Catalog\CatalogItemPolicy;
use App\Domain\Connectors\GoogleCalendar\Exceptions\CalendarConnectionUnavailableException;
use App\Domain\Connectors\GoogleCalendar\GoogleCalendarAccessTokenProvider;
use App\Domain\Connectors\GoogleCalendar\GoogleCalendarClientResolver;
use App\Domain\Scheduling\DTO\AvailabilitySlot;
use App\Domain\Scheduling\SchedulingConfigResolver;
use App\Domain\Scheduling\SchedulingConfiguration;
use App\Domain\Scheduling\SlotComputationService;
use App\Domain\Tools\Contracts\ToolInterface;
use App\Domain\Tools\DTO\ToolDefinition;
use App\Domain\Tools\DTO\ToolInvocation;
use App\Domain\Tools\DTO\ToolResult;
use App\Domain\Tools\Exceptions\InvalidToolArgumentsException;
use App\Domain\Tools\ToolNextAction;
use App\Models\CatalogItem;
use App\Support\AgentEvents\AgentEventRecorder;
use Carbon\CarbonImmutable;

/**
 * Computes concrete, server-side available appointment slots for a
 * bookable catalog item on a given date (design D9, spec "Tool
 * check_availability con slots computados"): freebusy from Google Calendar
 * ∩ business hours ∩ item duration, all in the line's scheduling timezone.
 * The LLM only ever chooses among the slots this tool returns — it never
 * computes schedules itself.
 */
class CheckAvailabilityTool implements ToolInterface
{
    public function __construct(
        protected CatalogItemPolicy $policy,
        protected SchedulingConfigResolver $configResolver,
        protected SlotComputationService $slots,
        protected GoogleCalendarAccessTokenProvider $tokenProvider,
        protected GoogleCalendarClientResolver $calendarResolver,
        protected AgentEventRecorder $events,
    ) {}

    public function definition(): ToolDefinition
    {
        return new ToolDefinition(
            name: 'check_availability',
            description: 'Compute concrete available appointment slots for a bookable catalog item on a given date, using the line\'s connected Google Calendar and configured business hours. Choose only among the returned slots — never calculate schedules yourself. Items that require a prior assessment automatically compute availability for that assessment instead.',
            inputSchema: [
                'type' => 'object',
                'additionalProperties' => false,
                'required' => ['item_id', 'date'],
                'properties' => [
                    'item_id' => [
                        'type' => 'integer',
                        'description' => 'Catalog item id, as listed in the catalog index of the prompt.',
                    ],
                    'date' => [
                        'type' => 'string',
                        'description' => 'Requested date in YYYY-MM-DD format (ISO 8601 calendar date only — no time, no timezone). All slot arithmetic uses the WhatsApp line\'s configured scheduling timezone.',
                    ],
                ],
            ],
            outputSchema: [
                'type' => 'object',
                'additionalProperties' => false,
                'properties' => [
                    'item_id' => ['type' => 'integer'],
                    'date' => ['type' => 'string'],
                    'found' => ['type' => 'boolean'],
                    'bookable' => ['type' => 'boolean'],
                    'reason' => ['type' => 'string'],
                    'kind' => ['type' => 'string'],
                    'requires_assessment' => ['type' => 'boolean'],
                    'booking_item_id' => ['type' => 'integer'],
                    'booking_item_name' => ['type' => 'string'],
                    'duration_minutes' => ['type' => 'integer'],
                    'timezone' => ['type' => 'string'],
                    'calendar_id' => ['type' => 'string'],
                    'slot_count' => ['type' => 'integer'],
                    'slots' => [
                        'type' => 'array',
                        'items' => ['type' => 'object'],
                    ],
                ],
            ],
            defaultTimeoutSeconds: 15,
            isImplemented: true,
            implementationPhase: 7,
        );
    }

    public function execute(ToolInvocation $invocation): ToolResult
    {
        $itemId = $this->requirePositiveInteger($invocation->arguments['item_id'] ?? null, 'item_id');
        $date = $this->requireIsoDate($invocation->arguments['date'] ?? null, 'date');

        $item = CatalogItem::query()
            ->forTenant($invocation->context->tenant)
            ->where('id', $itemId)
            ->where('active', true)
            ->first();

        if ($item === null) {
            $this->recordNotFound($invocation, $itemId, $date);

            return $this->notFoundResult($itemId, $date);
        }

        [$bookingItem, $requiresAssessment] = $this->resolveBookingTarget($item);

        if ($bookingItem === null || ! $this->policy->isBookable($bookingItem)) {
            $reason = $this->notBookableReason($item, $bookingItem);
            $this->recordNotBookable($invocation, $item, $date, $reason);

            return $this->notBookableResult($item, $date, $requiresAssessment, $reason);
        }

        $config = $this->configResolver->forLine($invocation->context->line);
        $client = $this->calendarResolver->forConversationSource($invocation->context->conversation->source);
        $requestedDate = CarbonImmutable::parse($date, $config->timezone)->startOfDay();

        try {
            $accessToken = $this->tokenProvider->getAccessToken($invocation->context->line, $client);

            $busyIntervals = $client->freeBusy(
                $accessToken,
                $config->calendarId,
                $requestedDate,
                $requestedDate->addDay(),
                $config->timezone,
            );
        } catch (CalendarConnectionUnavailableException $exception) {
            $this->recordUnavailable($invocation, $item, $date, $exception->getMessage());

            return new ToolResult(
                nextAction: ToolNextAction::RequestHandoff,
                handoffReason: $exception->getMessage(),
                outputSummary: [
                    'item_id' => $itemId,
                    'date' => $date,
                    'calendar_connection_available' => false,
                ],
                metadata: [
                    'tool_category' => 'scheduling',
                ],
            );
        }

        $slots = $this->slots->availableSlotsFor($config, $requestedDate, (int) $bookingItem->duration_minutes, $busyIntervals);

        $result = $this->availabilityResult($item, $bookingItem, $date, $requiresAssessment, $config, $slots);

        $this->recordCompleted($invocation, $item, $bookingItem, $date, count($slots));

        return new ToolResult(
            nextAction: ToolNextAction::ContinueWithRetrievedContext,
            outputSummary: $result,
            metadata: [
                'tool_category' => 'scheduling',
                'retrieved_context' => [$result],
            ],
        );
    }

    /**
     * @return array{0: ?CatalogItem, 1: bool} the item whose duration actually governs
     *                                          the booking, and whether that item is a
     *                                          linked assessment rather than $item itself.
     */
    protected function resolveBookingTarget(CatalogItem $item): array
    {
        if ($item->assessment_item_id !== null) {
            return [$item->assessmentItem, true];
        }

        return [$item, false];
    }

    protected function notBookableReason(CatalogItem $item, ?CatalogItem $bookingItem): string
    {
        $target = $bookingItem ?? $item;

        if ($target->kind === 'product') {
            return (string) __('api.catalog.not_bookable_product');
        }

        return (string) __('api.catalog.not_bookable_no_duration');
    }

    /**
     * @return array<string, mixed>
     */
    protected function availabilityResult(
        CatalogItem $item,
        CatalogItem $bookingItem,
        string $date,
        bool $requiresAssessment,
        SchedulingConfiguration $config,
        array $slots,
    ): array {
        $result = [
            'item_id' => $item->getKey(),
            'date' => $date,
            'found' => true,
            'bookable' => true,
            'requires_assessment' => $requiresAssessment,
            'booking_item_id' => $bookingItem->getKey(),
            'booking_item_name' => $bookingItem->name,
            'duration_minutes' => $bookingItem->duration_minutes,
            'timezone' => $config->timezone,
            'calendar_id' => $config->calendarId,
            'slot_count' => count($slots),
            'slots' => array_map(
                static fn (AvailabilitySlot $slot): array => [
                    'start' => $slot->start->toIso8601String(),
                    'end' => $slot->end->toIso8601String(),
                ],
                $slots,
            ),
        ];

        if ($requiresAssessment) {
            $result['reason'] = (string) __('api.catalog.availability_requires_assessment', [
                'assessment_name' => $bookingItem->name,
            ]);
        }

        return $result;
    }

    protected function notFoundResult(int $itemId, string $date): ToolResult
    {
        $result = [
            'item_id' => $itemId,
            'date' => $date,
            'found' => false,
            'reason' => (string) __('api.catalog.item_not_found'),
        ];

        return new ToolResult(
            nextAction: ToolNextAction::ContinueWithRetrievedContext,
            outputSummary: $result,
            metadata: [
                'tool_category' => 'scheduling',
                'retrieved_context' => [$result],
            ],
        );
    }

    protected function notBookableResult(CatalogItem $item, string $date, bool $requiresAssessment, string $reason): ToolResult
    {
        $result = [
            'item_id' => $item->getKey(),
            'date' => $date,
            'found' => true,
            'bookable' => false,
            'kind' => $item->kind,
            'requires_assessment' => $requiresAssessment,
            'reason' => $reason,
        ];

        return new ToolResult(
            nextAction: ToolNextAction::ContinueWithRetrievedContext,
            outputSummary: $result,
            metadata: [
                'tool_category' => 'scheduling',
                'retrieved_context' => [$result],
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

    protected function requireIsoDate(mixed $value, string $field): string
    {
        if (! is_string($value) || ! preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $matches)) {
            throw new InvalidToolArgumentsException(__('api.tools.field_iso_date', [
                'field' => $field,
            ]));
        }

        if (! checkdate((int) $matches[2], (int) $matches[3], (int) $matches[1])) {
            throw new InvalidToolArgumentsException(__('api.tools.field_iso_date', [
                'field' => $field,
            ]));
        }

        return $value;
    }

    protected function recordCompleted(ToolInvocation $invocation, CatalogItem $item, CatalogItem $bookingItem, string $date, int $slotCount): void
    {
        $this->events->record($invocation->context->tenant->getKey(), 'availability_check_completed', [
            'whatsapp_line_id' => $invocation->context->line->getKey(),
            'conversation_id' => $invocation->context->conversation->getKey(),
            'conversation_message_id' => $invocation->context->triggeringMessage->getKey(),
            'payload' => [
                'tool_name' => 'check_availability',
                'item_id' => $item->getKey(),
                'booking_item_id' => $bookingItem->getKey(),
                'date' => $date,
                'slot_count' => $slotCount,
            ],
        ]);
    }

    protected function recordNotFound(ToolInvocation $invocation, int $itemId, string $date): void
    {
        $this->events->record($invocation->context->tenant->getKey(), 'availability_check_item_not_found', [
            'whatsapp_line_id' => $invocation->context->line->getKey(),
            'conversation_id' => $invocation->context->conversation->getKey(),
            'conversation_message_id' => $invocation->context->triggeringMessage->getKey(),
            'payload' => [
                'tool_name' => 'check_availability',
                'item_id' => $itemId,
                'date' => $date,
            ],
        ]);
    }

    protected function recordNotBookable(ToolInvocation $invocation, CatalogItem $item, string $date, string $reason): void
    {
        $this->events->record($invocation->context->tenant->getKey(), 'availability_check_not_bookable', [
            'whatsapp_line_id' => $invocation->context->line->getKey(),
            'conversation_id' => $invocation->context->conversation->getKey(),
            'conversation_message_id' => $invocation->context->triggeringMessage->getKey(),
            'payload' => [
                'tool_name' => 'check_availability',
                'item_id' => $item->getKey(),
                'date' => $date,
                'reason' => $reason,
            ],
        ]);
    }

    protected function recordUnavailable(ToolInvocation $invocation, CatalogItem $item, string $date, string $reason): void
    {
        $this->events->record($invocation->context->tenant->getKey(), 'availability_check_unavailable', [
            'whatsapp_line_id' => $invocation->context->line->getKey(),
            'conversation_id' => $invocation->context->conversation->getKey(),
            'conversation_message_id' => $invocation->context->triggeringMessage->getKey(),
            'payload' => [
                'tool_name' => 'check_availability',
                'item_id' => $item->getKey(),
                'date' => $date,
                'reason' => $reason,
            ],
        ]);
    }
}
