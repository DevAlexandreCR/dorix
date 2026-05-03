<?php

namespace App\Http\Controllers\Api;

use App\Domain\AgentSandbox\SandboxAgentTurnService;
use App\Enums\ConversationSource;
use App\Enums\Permission;
use App\Http\Requests\Api\StoreAgentSandboxMessageRequest;
use App\Http\Requests\Api\StoreAgentSandboxSessionRequest;
use App\Models\AgentEvent;
use App\Models\Conversation;
use App\Models\ConversationMessage;
use App\Models\Tenant;
use App\Models\ToolExecution;
use App\Models\WhatsAppLine;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\Response;

class AgentSandboxController
{
    public function __construct(
        protected SandboxAgentTurnService $service,
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        $tenant = $this->tenantFromRequest($request);
        $this->authorizeForTenant($request, Permission::ManageAgentConfig, $tenant);

        $sessions = Conversation::query()
            ->forTenant($tenant->getKey())
            ->where('source', ConversationSource::AgentSandbox->value)
            ->with('whatsappLine:id,name,display_phone_number')
            ->select('conversations.*')
            ->selectSub(
                ConversationMessage::query()
                    ->select('body')
                    ->whereColumn('conversation_id', 'conversations.id')
                    ->orderByDesc('id')
                    ->limit(1),
                'last_message_preview',
            )
            ->orderByDesc('last_message_at')
            ->orderByDesc('id')
            ->get();

        return response()->json([
            'data' => $sessions->map(
                fn (Conversation $conversation): array => $this->serializeConversationSummary($conversation)
            )->all(),
            'meta' => [
                'available_lines' => $this->availableLines($tenant)->all(),
            ],
        ]);
    }

    public function store(StoreAgentSandboxSessionRequest $request): JsonResponse
    {
        $tenant = $this->tenantFromRequest($request);
        $this->authorizeForTenant($request, Permission::ManageAgentConfig, $tenant);

        $line = $this->findLine($tenant, (int) $request->validated('whatsapp_line_id'));
        $conversation = $this->service->createSession(
            $line,
            $request->user(),
            $request->validated('label'),
        );

        return response()->json([
            'data' => $this->serializeSessionPayload($conversation),
        ], Response::HTTP_CREATED);
    }

    public function show(Request $request, int $conversation): JsonResponse
    {
        $tenant = $this->tenantFromRequest($request);
        $this->authorizeForTenant($request, Permission::ManageAgentConfig, $tenant);

        return response()->json([
            'data' => $this->serializeSessionPayload($this->findConversation($tenant, $conversation)),
        ]);
    }

    public function storeMessage(StoreAgentSandboxMessageRequest $request, int $conversation): JsonResponse
    {
        $tenant = $this->tenantFromRequest($request);
        $this->authorizeForTenant($request, Permission::ManageAgentConfig, $tenant);

        $conversationModel = $this->findConversation($tenant, $conversation);

        $triggeringMessage = $this->service->runTurn(
            $conversationModel,
            (string) $request->validated('body'),
        );

        $conversationModel = $this->findConversation($tenant, $conversationModel->getKey());
        $newMessages = $this->messagesCreatedByTurn($conversationModel, $triggeringMessage);

        return response()->json([
            'data' => array_merge($this->serializeSessionPayload($conversationModel, $triggeringMessage), [
                'new_messages' => $newMessages->map(
                    fn (ConversationMessage $message): array => $this->serializeMessage($message)
                )->all(),
            ]),
        ], Response::HTTP_CREATED);
    }

    public function close(Request $request, int $conversation): JsonResponse
    {
        $tenant = $this->tenantFromRequest($request);
        $this->authorizeForTenant($request, Permission::ManageAgentConfig, $tenant);

        $conversationModel = $this->service->closeSession(
            $this->findConversation($tenant, $conversation),
            $request->user(),
        );

        return response()->json([
            'data' => $this->serializeSessionPayload($conversationModel),
        ]);
    }

    protected function tenantFromRequest(Request $request): Tenant
    {
        /** @var TenantContext $context */
        $context = $request->attributes->get(TenantContext::class);

        return $context->tenant;
    }

    protected function authorizeForTenant(Request $request, Permission $permission, Tenant $tenant): void
    {
        Gate::forUser($request->user())->authorize($permission->value, $tenant);
    }

    protected function findConversation(Tenant $tenant, int $conversationId): Conversation
    {
        return Conversation::query()
            ->forTenant($tenant->getKey())
            ->where('source', ConversationSource::AgentSandbox->value)
            ->with(['whatsappLine:id,name,display_phone_number', 'state', 'handoffRequests'])
            ->findOrFail($conversationId);
    }

    protected function findLine(Tenant $tenant, int $lineId): WhatsAppLine
    {
        return WhatsAppLine::query()
            ->forTenant($tenant->getKey())
            ->findOrFail($lineId);
    }

    /**
     * @return array<string, mixed>
     */
    protected function serializeSessionPayload(
        Conversation $conversation,
        ?ConversationMessage $triggeringMessage = null,
    ): array {
        $messages = ConversationMessage::query()
            ->forTenant($conversation->tenant_id)
            ->where('conversation_id', $conversation->getKey())
            ->orderBy('id')
            ->get();

        return [
            'conversation' => $this->serializeConversationDetail($conversation),
            'messages' => $messages->map(fn (ConversationMessage $message): array => $this->serializeMessage($message))->all(),
            'last_turn' => $this->serializeLastTurn($conversation, $triggeringMessage),
        ];
    }

    /**
     * @return \Illuminate\Support\Collection<int, array<string, mixed>>
     */
    protected function availableLines(Tenant $tenant)
    {
        return WhatsAppLine::query()
            ->forTenant($tenant->getKey())
            ->orderBy('name')
            ->orderBy('id')
            ->get(['id', 'name', 'display_phone_number', 'is_enabled'])
            ->map(fn (WhatsAppLine $line): array => [
                'id' => $line->getKey(),
                'name' => $line->name,
                'display_phone_number' => $line->display_phone_number,
                'is_enabled' => $line->is_enabled,
            ]);
    }

    /**
     * @return array<string, mixed>
     */
    protected function serializeConversationSummary(Conversation $conversation): array
    {
        return [
            'id' => $conversation->getKey(),
            'source' => $conversation->source->value,
            'label' => $this->sandboxLabel($conversation),
            'status' => $conversation->status->value,
            'whatsapp_line' => $conversation->whatsappLine ? [
                'id' => $conversation->whatsappLine->getKey(),
                'name' => $conversation->whatsappLine->name,
                'display_phone_number' => $conversation->whatsappLine->display_phone_number,
            ] : null,
            'last_message_at' => $conversation->last_message_at?->toIso8601String(),
            'last_message_preview' => $conversation->getAttribute('last_message_preview'),
            'created_at' => $conversation->created_at?->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function serializeConversationDetail(Conversation $conversation): array
    {
        $conversation->loadMissing(['whatsappLine:id,name,display_phone_number', 'state', 'handoffRequests']);
        $latestHandoff = $conversation->handoffRequests->sortByDesc('id')->first();

        return [
            'id' => $conversation->getKey(),
            'source' => $conversation->source->value,
            'label' => $this->sandboxLabel($conversation),
            'contact_phone' => $conversation->contact_phone,
            'status' => $conversation->status->value,
            'whatsapp_line' => $conversation->whatsappLine ? [
                'id' => $conversation->whatsappLine->getKey(),
                'name' => $conversation->whatsappLine->name,
                'display_phone_number' => $conversation->whatsappLine->display_phone_number,
            ] : null,
            'last_message_at' => $conversation->last_message_at?->toIso8601String(),
            'last_customer_message_at' => $conversation->last_customer_message_at?->toIso8601String(),
            'state' => $conversation->state ? [
                'current_intent' => $conversation->state->current_intent,
                'last_agent_action' => $conversation->state->last_agent_action,
                'collected_data' => $conversation->state->collected_data,
                'memory_summary' => $conversation->state->memory_summary,
                'expires_at' => $conversation->state->expires_at?->toIso8601String(),
            ] : null,
            'latest_handoff' => $latestHandoff ? [
                'id' => $latestHandoff->getKey(),
                'status' => $latestHandoff->status,
                'reason' => $latestHandoff->reason,
                'assigned_to_user_id' => $latestHandoff->assigned_to_user_id,
                'accepted_at' => $latestHandoff->accepted_at?->toIso8601String(),
                'resolved_at' => $latestHandoff->resolved_at?->toIso8601String(),
            ] : null,
            'created_at' => $conversation->created_at?->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function serializeMessage(ConversationMessage $message): array
    {
        $payload = $message->payload ?? [];

        return [
            'id' => $message->getKey(),
            'direction' => $message->direction->value,
            'message_type' => $message->message_type,
            'body' => $message->body,
            'status' => $message->status,
            'provider_message_id' => $message->provider_message_id,
            'error_message' => $message->error_message,
            'source' => data_get($payload, 'context.source') ?? ($payload['source'] ?? null),
            'sent_at' => $message->sent_at?->toIso8601String(),
            'received_at' => $message->received_at?->toIso8601String(),
            'failed_at' => $message->failed_at?->toIso8601String(),
            'created_at' => $message->created_at?->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    protected function serializeLastTurn(
        Conversation $conversation,
        ?ConversationMessage $triggeringMessage = null,
    ): ?array {
        $triggeringMessage ??= ConversationMessage::query()
            ->forTenant($conversation->tenant_id)
            ->where('conversation_id', $conversation->getKey())
            ->where('direction', 'inbound')
            ->latest('id')
            ->first();

        if (! $triggeringMessage) {
            return null;
        }

        $toolExecutions = ToolExecution::query()
            ->forTenant($conversation->tenant_id)
            ->where('conversation_id', $conversation->getKey())
            ->where('conversation_message_id', $triggeringMessage->getKey())
            ->orderBy('id')
            ->get();

        $events = AgentEvent::query()
            ->forTenant($conversation->tenant_id)
            ->where('conversation_id', $conversation->getKey())
            ->where('conversation_message_id', $triggeringMessage->getKey())
            ->orderBy('occurred_at')
            ->orderBy('id')
            ->get();

        $completionEvent = $events->firstWhere('event_type', 'sandbox_turn_completed');
        $runtimeEvent = $events->firstWhere('event_type', 'agent_response_generated');
        $runtimeOutcome = data_get($completionEvent?->payload ?? [], 'runtime_outcome')
            ?? data_get($runtimeEvent?->payload ?? [], 'decision.outcome');

        $errorEvent = $events->first(
            fn (AgentEvent $event): bool => in_array($event->event_type, ['agent_runtime_failed', 'tool_failed'], true)
        );

        return [
            'triggering_message_id' => $triggeringMessage->getKey(),
            'runtime_outcome' => is_string($runtimeOutcome) ? $runtimeOutcome : null,
            'agent_pack_key' => data_get($runtimeEvent?->payload ?? [], 'agent_pack_key'),
            'policy' => [
                'allowed' => data_get($runtimeEvent?->payload ?? [], 'policy.allowed'),
                'normalized_intent' => data_get($runtimeEvent?->payload ?? [], 'policy.normalized_intent'),
                'blocked_reason' => data_get($runtimeEvent?->payload ?? [], 'policy.blocked_reason'),
                'replacement_outcome' => data_get($runtimeEvent?->payload ?? [], 'policy.replacement_outcome'),
            ],
            'handoff_requested' => $events->contains(
                fn (AgentEvent $event): bool => $event->event_type === 'handoff_triggered'
            ),
            'error_message' => is_array($errorEvent?->payload ?? null)
                ? (data_get($errorEvent->payload, 'error') ?? data_get($errorEvent->payload, 'error_message'))
                : null,
            'tool_executions' => $toolExecutions->map(fn (ToolExecution $execution): array => [
                'id' => $execution->getKey(),
                'tool_name' => $execution->tool_name,
                'status' => $execution->status,
                'duration_ms' => $execution->duration_ms,
                'error_message' => $execution->error_message,
                'next_action' => data_get($execution->output_summary ?? [], 'next_action'),
                'executed_at' => $execution->executed_at?->toIso8601String(),
            ])->all(),
            'events' => $events->map(fn (AgentEvent $event): array => [
                'id' => $event->getKey(),
                'event_type' => $event->event_type,
                'occurred_at' => $event->occurred_at?->toIso8601String(),
            ])->all(),
        ];
    }

    protected function sandboxLabel(Conversation $conversation): string
    {
        $metadata = $conversation->metadata ?? [];
        $label = data_get($metadata, 'sandbox.label');

        if (is_string($label) && trim($label) !== '') {
            return trim($label);
        }

        if (is_string($conversation->contact_name) && trim($conversation->contact_name) !== '') {
            return trim($conversation->contact_name);
        }

        return 'Sandbox session';
    }

    /**
     * @return Collection<int, ConversationMessage>
     */
    protected function messagesCreatedByTurn(
        Conversation $conversation,
        ConversationMessage $triggeringMessage,
    ): Collection {
        $nextInboundMessageId = ConversationMessage::query()
            ->forTenant($conversation->tenant_id)
            ->where('conversation_id', $conversation->getKey())
            ->where('direction', 'inbound')
            ->where('id', '>', $triggeringMessage->getKey())
            ->orderBy('id')
            ->value('id');

        $query = ConversationMessage::query()
            ->forTenant($conversation->tenant_id)
            ->where('conversation_id', $conversation->getKey())
            ->where('id', '>=', $triggeringMessage->getKey())
            ->orderBy('id');

        if ($nextInboundMessageId) {
            $query->where('id', '<', $nextInboundMessageId);
        }

        return $query->get();
    }
}
