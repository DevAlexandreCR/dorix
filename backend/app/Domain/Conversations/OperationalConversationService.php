<?php

namespace App\Domain\Conversations;

use App\Domain\Conversations\Contracts\ConversationStatusTransitioner;
use App\Domain\Conversations\Exceptions\ConversationOperationException;
use App\Domain\WhatsApp\Contracts\OutboundMessageSender;
use App\Domain\WhatsApp\DTO\OutboundMessageData;
use App\Enums\ConversationStatus;
use App\Models\Conversation;
use App\Models\ConversationMessage;
use App\Models\HandoffRequest;
use App\Models\User;
use App\Support\AgentEvents\AgentEventRecorder;
use App\Support\Audit\AuditEventRecorder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class OperationalConversationService
{
    public function __construct(
        protected ConversationStatusTransitioner $statusTransitioner,
        protected OutboundMessageSender $outboundMessageSender,
        protected AgentEventRecorder $events,
        protected AuditEventRecorder $auditEvents,
    ) {
    }

    public function handoff(
        Conversation $conversation,
        User $actor,
        ?User $assignee = null,
        ?string $reason = null,
    ): Conversation {
        $assignee ??= $actor;
        $reason = $this->normalizeReason($reason);

        return DB::transaction(function () use ($conversation, $actor, $assignee, $reason): Conversation {
            $conversation = $this->lockConversation($conversation);
            $previousStatus = $conversation->status;
            $previousAssigneeId = $conversation->assigned_to_user_id;

            $conversation = $this->statusTransitioner->transition(
                $conversation,
                ConversationStatus::HumanHandoff,
                $assignee->getKey(),
            );

            $handoffRequest = $this->upsertHandoffRequest(
                conversation: $conversation,
                requestedByUser: $actor,
                assignee: $assignee,
                reason: $reason,
            );

            if ($previousStatus !== ConversationStatus::HumanHandoff) {
                $this->events->record($conversation->tenant_id, 'handoff_triggered', [
                    'whatsapp_line_id' => $conversation->whatsapp_line_id,
                    'conversation_id' => $conversation->getKey(),
                    'payload' => [
                        'handoff_request_id' => $handoffRequest->getKey(),
                        'reason' => $reason,
                        'source' => 'operator_console',
                        'previous_status' => $previousStatus->value,
                    ],
                ]);
            }

            $this->events->record($conversation->tenant_id, 'handoff_accepted', [
                'whatsapp_line_id' => $conversation->whatsapp_line_id,
                'conversation_id' => $conversation->getKey(),
                'payload' => [
                    'handoff_request_id' => $handoffRequest->getKey(),
                    'assigned_to_user_id' => $assignee->getKey(),
                    'accepted_by_user_id' => $actor->getKey(),
                ],
            ]);

            if ($previousStatus !== ConversationStatus::HumanHandoff) {
                $this->auditEvents->record(
                    tenantId: $conversation->tenant_id,
                    eventType: 'handoff_triggered',
                    actorUserId: $actor->getKey(),
                    target: $conversation,
                    payload: [
                        'handoff_request_id' => $handoffRequest->getKey(),
                        'reason' => $reason,
                        'previous_status' => $previousStatus->value,
                        'previous_assigned_to_user_id' => $previousAssigneeId,
                        'assigned_to_user_id' => $assignee->getKey(),
                    ],
                );
            }

            $this->auditEvents->record(
                tenantId: $conversation->tenant_id,
                eventType: $previousAssigneeId && $previousAssigneeId !== $assignee->getKey()
                    ? 'handoff_reassigned'
                    : 'handoff_accepted',
                actorUserId: $actor->getKey(),
                target: $conversation,
                payload: [
                    'handoff_request_id' => $handoffRequest->getKey(),
                    'previous_status' => $previousStatus->value,
                    'previous_assigned_to_user_id' => $previousAssigneeId,
                    'assigned_to_user_id' => $assignee->getKey(),
                ],
            );

            return $conversation->fresh(['assignedToUser']);
        });
    }

    public function assign(
        Conversation $conversation,
        User $actor,
        User $assignee,
    ): Conversation {
        return DB::transaction(function () use ($conversation, $actor, $assignee): Conversation {
            $conversation = $this->lockConversation($conversation);
            $previousStatus = $conversation->status;
            $previousAssigneeId = $conversation->assigned_to_user_id;

            $conversation = $this->statusTransitioner->transition(
                $conversation,
                ConversationStatus::HumanHandoff,
                $assignee->getKey(),
            );

            $handoffRequest = $this->upsertHandoffRequest(
                conversation: $conversation,
                requestedByUser: $actor,
                assignee: $assignee,
                reason: null,
            );

            $eventType = $previousAssigneeId && $previousAssigneeId !== $assignee->getKey()
                ? 'handoff_reassigned'
                : 'handoff_accepted';

            $this->events->record($conversation->tenant_id, 'handoff_accepted', [
                'whatsapp_line_id' => $conversation->whatsapp_line_id,
                'conversation_id' => $conversation->getKey(),
                'payload' => [
                    'handoff_request_id' => $handoffRequest->getKey(),
                    'assigned_to_user_id' => $assignee->getKey(),
                    'accepted_by_user_id' => $actor->getKey(),
                    'previous_status' => $previousStatus->value,
                ],
            ]);

            if ($previousStatus !== ConversationStatus::HumanHandoff) {
                $this->auditEvents->record(
                    tenantId: $conversation->tenant_id,
                    eventType: 'handoff_triggered',
                    actorUserId: $actor->getKey(),
                    target: $conversation,
                    payload: [
                        'handoff_request_id' => $handoffRequest->getKey(),
                        'previous_status' => $previousStatus->value,
                        'previous_assigned_to_user_id' => $previousAssigneeId,
                        'assigned_to_user_id' => $assignee->getKey(),
                    ],
                );
            }

            $this->auditEvents->record(
                tenantId: $conversation->tenant_id,
                eventType: $eventType,
                actorUserId: $actor->getKey(),
                target: $conversation,
                payload: [
                    'handoff_request_id' => $handoffRequest->getKey(),
                    'previous_status' => $previousStatus->value,
                    'previous_assigned_to_user_id' => $previousAssigneeId,
                    'assigned_to_user_id' => $assignee->getKey(),
                ],
            );

            return $conversation->fresh(['assignedToUser']);
        });
    }

    public function manualReply(
        Conversation $conversation,
        User $actor,
        string $body,
    ): ConversationMessage {
        $body = trim($body);

        if ($body === '') {
            throw new ConversationOperationException('The manual reply body is required.');
        }

        $conversation = DB::transaction(function () use ($conversation, $actor): Conversation {
            $conversation = $this->lockConversation($conversation);

            if ($conversation->status !== ConversationStatus::HumanHandoff) {
                throw new ConversationOperationException(
                    'Conversation must be in HUMAN_HANDOFF before sending a manual reply.',
                    409,
                );
            }

            if ($conversation->assigned_to_user_id !== null && $conversation->assigned_to_user_id !== $actor->getKey()) {
                throw new ConversationOperationException(
                    'Conversation is currently assigned to another operator.',
                    409,
                );
            }

            if ($conversation->assigned_to_user_id === null) {
                $conversation = $this->statusTransitioner->transition(
                    $conversation,
                    ConversationStatus::HumanHandoff,
                    $actor->getKey(),
                );

                $this->upsertHandoffRequest(
                    conversation: $conversation,
                    requestedByUser: $actor,
                    assignee: $actor,
                    reason: null,
                );
            }

            return $conversation->fresh();
        });

        $message = $this->outboundMessageSender->send(new OutboundMessageData(
            tenantId: $conversation->tenant_id,
            conversationId: $conversation->getKey(),
            whatsAppLineId: $conversation->whatsapp_line_id,
            recipientPhone: $conversation->contact_phone,
            body: $body,
            idempotencyKey: sprintf(
                'manual-reply:%d:%d:%s',
                $conversation->getKey(),
                $actor->getKey(),
                (string) Str::uuid(),
            ),
            payload: [
                'source' => 'manual_console',
                'operator_user_id' => $actor->getKey(),
            ],
        ));

        $this->auditEvents->record(
            tenantId: $conversation->tenant_id,
            eventType: 'manual_reply_sent',
            actorUserId: $actor->getKey(),
            target: $message,
            payload: [
                'conversation_id' => $conversation->getKey(),
                'assigned_to_user_id' => $conversation->fresh()->assigned_to_user_id,
            ],
        );

        return $message->fresh();
    }

    public function resume(
        Conversation $conversation,
        User $actor,
        ConversationStatus $targetStatus,
    ): Conversation {
        if (! in_array($targetStatus, [ConversationStatus::BotActive, ConversationStatus::WaitingCustomer], true)) {
            throw new ConversationOperationException('The target status must be BOT_ACTIVE or WAITING_CUSTOMER.');
        }

        return DB::transaction(function () use ($conversation, $actor, $targetStatus): Conversation {
            $conversation = $this->lockConversation($conversation);

            if ($conversation->status !== ConversationStatus::HumanHandoff) {
                throw new ConversationOperationException(
                    'Conversation must be in HUMAN_HANDOFF before bot resume.',
                    409,
                );
            }

            $previousAssigneeId = $conversation->assigned_to_user_id;
            $conversation = $this->statusTransitioner->transition($conversation, $targetStatus);

            $handoffRequest = $this->latestOpenHandoffRequest($conversation);

            if ($handoffRequest) {
                $handoffRequest->forceFill([
                    'status' => 'resolved',
                    'resolved_at' => now(),
                ])->save();
            }

            $this->events->record($conversation->tenant_id, 'bot_resumed', [
                'whatsapp_line_id' => $conversation->whatsapp_line_id,
                'conversation_id' => $conversation->getKey(),
                'payload' => [
                    'handoff_request_id' => $handoffRequest?->getKey(),
                    'resumed_by_user_id' => $actor->getKey(),
                    'target_status' => $targetStatus->value,
                ],
            ]);

            $this->auditEvents->record(
                tenantId: $conversation->tenant_id,
                eventType: 'bot_resumed',
                actorUserId: $actor->getKey(),
                target: $conversation,
                payload: [
                    'handoff_request_id' => $handoffRequest?->getKey(),
                    'target_status' => $targetStatus->value,
                    'previous_assigned_to_user_id' => $previousAssigneeId,
                ],
            );

            return $conversation->fresh(['assignedToUser']);
        });
    }

    protected function lockConversation(Conversation $conversation): Conversation
    {
        return Conversation::query()
            ->whereKey($conversation->getKey())
            ->lockForUpdate()
            ->firstOrFail();
    }

    protected function latestOpenHandoffRequest(Conversation $conversation): ?HandoffRequest
    {
        return HandoffRequest::query()
            ->forTenant($conversation->tenant_id)
            ->where('conversation_id', $conversation->getKey())
            ->whereIn('status', ['requested', 'accepted'])
            ->latest('id')
            ->first();
    }

    protected function upsertHandoffRequest(
        Conversation $conversation,
        User $requestedByUser,
        ?User $assignee,
        ?string $reason,
    ): HandoffRequest {
        $handoffRequest = $this->latestOpenHandoffRequest($conversation);

        if (! $handoffRequest) {
            return HandoffRequest::query()->create([
                'tenant_id' => $conversation->tenant_id,
                'conversation_id' => $conversation->getKey(),
                'requested_by_user_id' => $requestedByUser->getKey(),
                'assigned_to_user_id' => $assignee?->getKey(),
                'requested_by_type' => 'operator',
                'status' => $assignee ? 'accepted' : 'requested',
                'reason' => $reason,
                'accepted_at' => $assignee ? now() : null,
            ]);
        }

        $handoffRequest->forceFill([
            'requested_by_user_id' => $handoffRequest->requested_by_user_id ?? $requestedByUser->getKey(),
            'assigned_to_user_id' => $assignee?->getKey(),
            'requested_by_type' => $handoffRequest->requested_by_type ?: 'operator',
            'status' => $assignee ? 'accepted' : 'requested',
            'reason' => $reason ?? $handoffRequest->reason,
            'accepted_at' => $assignee ? ($handoffRequest->accepted_at ?? now()) : $handoffRequest->accepted_at,
        ])->save();

        return $handoffRequest->fresh();
    }

    protected function normalizeReason(?string $reason): ?string
    {
        $reason = is_string($reason) ? trim($reason) : null;

        return $reason === '' ? null : $reason;
    }
}
