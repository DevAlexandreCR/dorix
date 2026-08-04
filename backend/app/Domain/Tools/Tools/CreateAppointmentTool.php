<?php

namespace App\Domain\Tools\Tools;

use App\Domain\Catalog\CatalogItemPolicy;
use App\Domain\Connectors\GoogleCalendar\DTO\CalendarEventDraft;
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
use App\Models\Conversation;
use App\Support\AgentEvents\AgentEventRecorder;
use Carbon\CarbonImmutable;

/**
 * Creates a Google Calendar appointment for a bookable catalog item (design
 * D9, spec "Tool create_appointment con verdad desde la BD"). Duration
 * always comes from `catalog_items.duration_minutes` — the LLM only ever
 * chooses the item and a slot already offered by check_availability, never
 * a duration. Items with `assessment_item_id` are rejected outright: the
 * agent must offer the linked assessment item instead of booking the
 * procedure directly. Immediately before `events.insert`, availability is
 * re-validated with a fresh freebusy read to close the
 * check_availability -> create_appointment race window (design "Riesgo:
 * doble reserva") — the residual millisecond-scale window is an accepted
 * v1 trade-off, not a bug.
 */
class CreateAppointmentTool implements ToolInterface
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
            name: 'create_appointment',
            description: 'Create a calendar appointment for a bookable catalog item at a specific slot already offered by check_availability. Duration always comes from the catalog item, never from tool arguments — do not attempt to specify an end time or duration. Items that require a prior assessment are rejected; offer the linked assessment item instead.',
            inputSchema: [
                'type' => 'object',
                'additionalProperties' => false,
                'required' => ['item_id', 'slot_start', 'customer_name'],
                'properties' => [
                    'item_id' => [
                        'type' => 'integer',
                        'description' => 'Catalog item id, as listed in the catalog index of the prompt.',
                    ],
                    'slot_start' => [
                        'type' => 'string',
                        'description' => 'Exact slot start, as ISO 8601 datetime with UTC offset (one of the `start` values returned by check_availability for this item).',
                    ],
                    'customer_name' => [
                        'type' => 'string',
                        'description' => "Customer's full name to attach to the calendar event.",
                    ],
                ],
            ],
            outputSchema: [
                'type' => 'object',
                'additionalProperties' => false,
                'properties' => [
                    'item_id' => ['type' => 'integer'],
                    'found' => ['type' => 'boolean'],
                    'created' => ['type' => 'boolean'],
                    'rejected_reason' => ['type' => 'string'],
                    'reason' => ['type' => 'string'],
                    'kind' => ['type' => 'string'],
                    'assessment_item_id' => ['type' => 'integer'],
                    'assessment_item_name' => ['type' => 'string'],
                    'event_id' => ['type' => 'string'],
                    'summary' => ['type' => 'string'],
                    'start' => ['type' => 'string'],
                    'end' => ['type' => 'string'],
                    'timezone' => ['type' => 'string'],
                    'calendar_id' => ['type' => 'string'],
                    'duration_minutes' => ['type' => 'integer'],
                    'slot_count' => ['type' => 'integer'],
                    'slots' => [
                        'type' => 'array',
                        'items' => ['type' => 'object'],
                    ],
                    'calendar_connection_available' => ['type' => 'boolean'],
                ],
            ],
            defaultTimeoutSeconds: 20,
            isImplemented: true,
            implementationPhase: 7,
        );
    }

    public function execute(ToolInvocation $invocation): ToolResult
    {
        $itemId = $this->requirePositiveInteger($invocation->arguments['item_id'] ?? null, 'item_id');
        $slotStartRaw = $this->requireIsoDateTime($invocation->arguments['slot_start'] ?? null, 'slot_start');
        $customerName = $this->requireNonEmptyString($invocation->arguments['customer_name'] ?? null, 'customer_name');

        $item = CatalogItem::query()
            ->forTenant($invocation->context->tenant)
            ->where('id', $itemId)
            ->where('active', true)
            ->first();

        if ($item === null) {
            $this->recordNotFound($invocation, $itemId);

            return $this->notFoundResult($itemId);
        }

        if ($item->assessment_item_id !== null) {
            $assessment = $item->assessmentItem;
            $reason = $assessment !== null
                ? (string) __('api.catalog.booking_requires_assessment', ['assessment_name' => $assessment->name])
                : (string) __('api.catalog.not_bookable_no_duration');

            $this->recordRejected($invocation, $item, 'requires_assessment', $reason);

            return $this->rejectedResult($item, 'requires_assessment', $reason, array_filter([
                'assessment_item_id' => $assessment?->getKey(),
                'assessment_item_name' => $assessment?->name,
            ], static fn (mixed $value): bool => $value !== null));
        }

        if (! $this->policy->isBookable($item)) {
            $reason = $item->kind === 'product'
                ? (string) __('api.catalog.not_bookable_product')
                : (string) __('api.catalog.not_bookable_no_duration');

            $this->recordRejected($invocation, $item, 'not_bookable', $reason);

            return $this->rejectedResult($item, 'not_bookable', $reason, [
                'kind' => $item->kind,
            ]);
        }

        $config = $this->configResolver->forLine($invocation->context->line);
        $client = $this->calendarResolver->forConversationSource($invocation->context->conversation->source);

        $durationMinutes = (int) $item->duration_minutes;
        $slotStart = CarbonImmutable::parse($slotStartRaw)->setTimezone($config->timezone);
        $slotEnd = $slotStart->addMinutes($durationMinutes);
        $dayStart = $slotStart->startOfDay();

        try {
            $accessToken = $this->tokenProvider->getAccessToken($invocation->context->line, $client);

            $busyIntervals = $client->freeBusy(
                $accessToken,
                $config->calendarId,
                $dayStart,
                $dayStart->addDay(),
                $config->timezone,
            );

            if (! $this->slots->isSlotAvailable($config, $slotStart, $slotEnd, $busyIntervals)) {
                $refreshedSlots = $this->slots->availableSlotsFor($config, $dayStart, $durationMinutes, $busyIntervals);

                $this->recordSlotTaken($invocation, $item, $slotStart, $slotEnd);

                return $this->slotTakenResult($item, $slotStart, $slotEnd, $durationMinutes, $config, $refreshedSlots);
            }

            $event = $client->createEvent($accessToken, $config->calendarId, new CalendarEventDraft(
                summary: $this->eventSummary($item, $customerName),
                description: $this->eventDescription($invocation->context->conversation),
                start: $slotStart,
                end: $slotEnd,
                timezone: $config->timezone,
            ));
        } catch (CalendarConnectionUnavailableException $exception) {
            $this->recordUnavailable($invocation, $item, $exception->getMessage());

            return new ToolResult(
                nextAction: ToolNextAction::RequestHandoff,
                handoffReason: $exception->getMessage(),
                outputSummary: [
                    'item_id' => $itemId,
                    'calendar_connection_available' => false,
                ],
                metadata: [
                    'tool_category' => 'scheduling',
                ],
            );
        }

        $this->recordCreated($invocation, $item, $event->providerEventId, $slotStart, $slotEnd);

        $result = [
            'item_id' => $item->getKey(),
            'found' => true,
            'created' => true,
            'kind' => $item->kind,
            'event_id' => $event->providerEventId,
            'summary' => $event->summary,
            'start' => $event->start->toIso8601String(),
            'end' => $event->end->toIso8601String(),
            'timezone' => $config->timezone,
            'calendar_id' => $config->calendarId,
            'duration_minutes' => $durationMinutes,
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

    protected function eventSummary(CatalogItem $item, string $customerName): string
    {
        return sprintf('%s — %s', $item->name, $customerName);
    }

    protected function eventDescription(Conversation $conversation): string
    {
        return (string) __('api.catalog.appointment_event_description', [
            'phone' => $conversation->contact_phone,
        ]);
    }

    protected function notFoundResult(int $itemId): ToolResult
    {
        $result = [
            'item_id' => $itemId,
            'found' => false,
            'created' => false,
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

    /**
     * @param  array<string, mixed>  $extra
     */
    protected function rejectedResult(CatalogItem $item, string $rejectedReason, string $reason, array $extra = []): ToolResult
    {
        $result = array_merge([
            'item_id' => $item->getKey(),
            'found' => true,
            'created' => false,
            'rejected_reason' => $rejectedReason,
            'reason' => $reason,
        ], $extra);

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
     * @param  array<int, AvailabilitySlot>  $slots
     */
    protected function slotTakenResult(
        CatalogItem $item,
        CarbonImmutable $slotStart,
        CarbonImmutable $slotEnd,
        int $durationMinutes,
        SchedulingConfiguration $config,
        array $slots,
    ): ToolResult {
        $result = [
            'item_id' => $item->getKey(),
            'found' => true,
            'created' => false,
            'rejected_reason' => 'slot_taken',
            'reason' => (string) __('api.catalog.slot_no_longer_available'),
            'requested_start' => $slotStart->toIso8601String(),
            'requested_end' => $slotEnd->toIso8601String(),
            'timezone' => $config->timezone,
            'calendar_id' => $config->calendarId,
            'duration_minutes' => $durationMinutes,
            'slot_count' => count($slots),
            'slots' => array_map(
                static fn (AvailabilitySlot $slot): array => [
                    'start' => $slot->start->toIso8601String(),
                    'end' => $slot->end->toIso8601String(),
                ],
                $slots,
            ),
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

    protected function requireNonEmptyString(mixed $value, string $field): string
    {
        if (! is_string($value) || trim($value) === '') {
            throw new InvalidToolArgumentsException(__('api.tools.field_non_empty_string', [
                'field' => $field,
            ]));
        }

        return trim($value);
    }

    protected function requireIsoDateTime(mixed $value, string $field): string
    {
        if (! is_string($value) || ! preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(\.\d{1,6})?(Z|[+-]\d{2}:\d{2})$/', $value)) {
            throw new InvalidToolArgumentsException(__('api.tools.field_iso_datetime', [
                'field' => $field,
            ]));
        }

        try {
            CarbonImmutable::parse($value);
        } catch (\Exception) {
            throw new InvalidToolArgumentsException(__('api.tools.field_iso_datetime', [
                'field' => $field,
            ]));
        }

        return $value;
    }

    protected function recordCreated(ToolInvocation $invocation, CatalogItem $item, string $eventId, CarbonImmutable $start, CarbonImmutable $end): void
    {
        $this->events->record($invocation->context->tenant->getKey(), 'appointment_created', [
            'whatsapp_line_id' => $invocation->context->line->getKey(),
            'conversation_id' => $invocation->context->conversation->getKey(),
            'conversation_message_id' => $invocation->context->triggeringMessage->getKey(),
            'payload' => [
                'tool_name' => 'create_appointment',
                'item_id' => $item->getKey(),
                'provider_event_id' => $eventId,
                'start' => $start->toIso8601String(),
                'end' => $end->toIso8601String(),
            ],
        ]);
    }

    protected function recordNotFound(ToolInvocation $invocation, int $itemId): void
    {
        $this->events->record($invocation->context->tenant->getKey(), 'appointment_creation_item_not_found', [
            'whatsapp_line_id' => $invocation->context->line->getKey(),
            'conversation_id' => $invocation->context->conversation->getKey(),
            'conversation_message_id' => $invocation->context->triggeringMessage->getKey(),
            'payload' => [
                'tool_name' => 'create_appointment',
                'item_id' => $itemId,
            ],
        ]);
    }

    protected function recordRejected(ToolInvocation $invocation, CatalogItem $item, string $rejectedReason, string $reason): void
    {
        $this->events->record($invocation->context->tenant->getKey(), 'appointment_creation_rejected', [
            'whatsapp_line_id' => $invocation->context->line->getKey(),
            'conversation_id' => $invocation->context->conversation->getKey(),
            'conversation_message_id' => $invocation->context->triggeringMessage->getKey(),
            'payload' => [
                'tool_name' => 'create_appointment',
                'item_id' => $item->getKey(),
                'rejected_reason' => $rejectedReason,
                'reason' => $reason,
            ],
        ]);
    }

    protected function recordSlotTaken(ToolInvocation $invocation, CatalogItem $item, CarbonImmutable $start, CarbonImmutable $end): void
    {
        $this->events->record($invocation->context->tenant->getKey(), 'appointment_slot_no_longer_available', [
            'whatsapp_line_id' => $invocation->context->line->getKey(),
            'conversation_id' => $invocation->context->conversation->getKey(),
            'conversation_message_id' => $invocation->context->triggeringMessage->getKey(),
            'payload' => [
                'tool_name' => 'create_appointment',
                'item_id' => $item->getKey(),
                'requested_start' => $start->toIso8601String(),
                'requested_end' => $end->toIso8601String(),
            ],
        ]);
    }

    protected function recordUnavailable(ToolInvocation $invocation, CatalogItem $item, string $reason): void
    {
        $this->events->record($invocation->context->tenant->getKey(), 'appointment_creation_unavailable', [
            'whatsapp_line_id' => $invocation->context->line->getKey(),
            'conversation_id' => $invocation->context->conversation->getKey(),
            'conversation_message_id' => $invocation->context->triggeringMessage->getKey(),
            'payload' => [
                'tool_name' => 'create_appointment',
                'item_id' => $item->getKey(),
                'reason' => $reason,
            ],
        ]);
    }
}
