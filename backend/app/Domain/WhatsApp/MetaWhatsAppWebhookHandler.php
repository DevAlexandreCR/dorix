<?php

namespace App\Domain\WhatsApp;

use App\Domain\Conversations\Contracts\ConversationResolver;
use App\Domain\WhatsApp\Contracts\WhatsAppLineResolver;
use App\Domain\WhatsApp\Contracts\WhatsAppWebhookHandler;
use App\Domain\WhatsApp\DTO\InboundMessageData;
use App\Domain\WhatsApp\DTO\ResolvedWhatsAppLine;
use App\Domain\WhatsApp\DTO\StatusUpdateData;
use App\Domain\WhatsApp\DTO\WebhookHandlingResult;
use App\Enums\MessageDirection;
use App\Enums\TenantStatus;
use App\Jobs\ProcessIncomingMessageJob;
use App\Models\Conversation;
use App\Models\ConversationMessage;
use App\Support\AgentEvents\AgentEventRecorder;
use App\Support\Tenancy\TenantContext;
use App\Support\Tenancy\TenantContextManager;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

class MetaWhatsAppWebhookHandler implements WhatsAppWebhookHandler
{
    public function __construct(
        protected MetaWebhookPayloadNormalizer $normalizer,
        protected WhatsAppLineResolver $lineResolver,
        protected ConversationResolver $conversationResolver,
        protected TenantContextManager $tenantContextManager,
        protected AgentEventRecorder $events,
    ) {
    }

    public function handle(array $payload): WebhookHandlingResult
    {
        $normalized = $this->normalizer->normalize($payload);

        $receivedMessages = 0;
        $deduplicatedMessages = 0;
        $statusUpdates = 0;
        $jobsDispatched = 0;

        foreach ($normalized->inboundMessages as $inboundMessage) {
            $resolvedLine = $this->lineResolver->resolve($inboundMessage->phoneNumberId);

            if ($resolvedLine->tenant->status === TenantStatus::Paused) {
                $this->withinTenantContext($resolvedLine, function () use ($resolvedLine, $inboundMessage): void {
                    $this->recordSkippedTenantPausedMessage($resolvedLine, $inboundMessage);
                });

                continue;
            }

            $wasSaved = $this->withinTenantContext($resolvedLine, function () use ($resolvedLine, $inboundMessage, &$jobsDispatched): bool {
                $conversation = $this->conversationResolver->resolveForInbound($resolvedLine, $inboundMessage);

                return $this->persistInboundMessage($resolvedLine, $conversation, $inboundMessage, $jobsDispatched);
            });

            if ($wasSaved) {
                $receivedMessages++;
            } else {
                $deduplicatedMessages++;
            }
        }

        foreach ($normalized->statusUpdates as $statusUpdate) {
            $resolvedLine = $this->lineResolver->resolve($statusUpdate->phoneNumberId);

            $this->withinTenantContext($resolvedLine, function () use ($resolvedLine, $statusUpdate, &$statusUpdates): void {
                $this->persistStatusUpdate($resolvedLine, $statusUpdate);
                $statusUpdates++;
            });
        }

        return new WebhookHandlingResult(
            receivedMessages: $receivedMessages,
            deduplicatedMessages: $deduplicatedMessages,
            statusUpdates: $statusUpdates,
            jobsDispatched: $jobsDispatched,
        );
    }

    protected function persistInboundMessage(
        ResolvedWhatsAppLine $resolvedLine,
        Conversation $conversation,
        InboundMessageData $message,
        int &$jobsDispatched,
    ): bool {
        $this->events->record($resolvedLine->tenantId(), 'webhook_received', [
            'whatsapp_line_id' => $resolvedLine->line->getKey(),
            'conversation_id' => $conversation->getKey(),
            'payload' => [
                'kind' => 'inbound_message',
                'provider_message_id' => $message->providerMessageId,
                'provider_message_type' => $message->providerMessageType,
            ],
            'occurred_at' => $message->receivedAt,
        ]);

        $existingMessage = ConversationMessage::query()
            ->forTenant($resolvedLine->tenantId())
            ->where('provider_message_id', $message->providerMessageId)
            ->first();

        if ($existingMessage) {
            $this->recordDeduplicatedInboundMessage($resolvedLine, $conversation, $message, $existingMessage);

            return false;
        }

        try {
            $conversationMessage = DB::transaction(function () use ($resolvedLine, $conversation, $message): ConversationMessage {
                return ConversationMessage::query()->create([
                    'tenant_id' => $resolvedLine->tenantId(),
                    'conversation_id' => $conversation->getKey(),
                    'direction' => MessageDirection::Inbound,
                    'message_type' => $message->messageType,
                    'body' => $message->body,
                    'payload' => [
                        'source' => 'meta_webhook',
                        'phone_number_id' => $message->phoneNumberId,
                        'provider_message_type' => $message->providerMessageType,
                        'raw' => $message->payload,
                    ],
                    'provider_message_id' => $message->providerMessageId,
                    'status' => 'received',
                    'received_at' => $message->receivedAt,
                ]);
            });
        } catch (QueryException $exception) {
            if (! $this->isDuplicateMessageException($exception)) {
                throw $exception;
            }

            $existingMessage = ConversationMessage::query()
                ->forTenant($resolvedLine->tenantId())
                ->where('provider_message_id', $message->providerMessageId)
                ->first();

            $this->recordDeduplicatedInboundMessage($resolvedLine, $conversation, $message, $existingMessage);

            return false;
        }

        $conversation->forceFill([
            'contact_name' => $message->contactName ?? $conversation->contact_name,
            'last_message_at' => $message->receivedAt,
            'last_customer_message_at' => $message->receivedAt,
        ])->save();

        $this->events->record($resolvedLine->tenantId(), 'message_saved', [
            'whatsapp_line_id' => $resolvedLine->line->getKey(),
            'conversation_id' => $conversation->getKey(),
            'conversation_message_id' => $conversationMessage->getKey(),
            'payload' => [
                'provider_message_id' => $message->providerMessageId,
                'message_type' => $message->messageType,
            ],
            'occurred_at' => $message->receivedAt,
        ]);

        ProcessIncomingMessageJob::dispatch(
            $resolvedLine->tenantId(),
            $conversation->getKey(),
            $conversationMessage->getKey(),
        );

        $jobsDispatched++;

        $this->events->record($resolvedLine->tenantId(), 'processing_job_dispatched', [
            'whatsapp_line_id' => $resolvedLine->line->getKey(),
            'conversation_id' => $conversation->getKey(),
            'conversation_message_id' => $conversationMessage->getKey(),
            'payload' => [
                'job' => ProcessIncomingMessageJob::class,
                'queue' => ProcessIncomingMessageJob::QUEUE,
                'tries' => 3,
            ],
            'occurred_at' => $message->receivedAt,
        ]);

        return true;
    }

    protected function recordSkippedTenantPausedMessage(
        ResolvedWhatsAppLine $resolvedLine,
        InboundMessageData $message,
    ): void {
        $this->events->record($resolvedLine->tenantId(), 'webhook_skipped_tenant_paused', [
            'whatsapp_line_id' => $resolvedLine->line->getKey(),
            'payload' => [
                'kind' => 'inbound_message',
                'provider_message_id' => $message->providerMessageId,
                'provider_message_type' => $message->providerMessageType,
            ],
            'occurred_at' => $message->receivedAt,
        ]);
    }

    protected function recordDeduplicatedInboundMessage(
        ResolvedWhatsAppLine $resolvedLine,
        Conversation $conversation,
        InboundMessageData $message,
        ?ConversationMessage $existingMessage,
    ): void {
        $this->events->record($resolvedLine->tenantId(), 'message_deduplicated', [
            'whatsapp_line_id' => $resolvedLine->line->getKey(),
            'conversation_id' => $existingMessage?->conversation_id ?? $conversation->getKey(),
            'conversation_message_id' => $existingMessage?->getKey(),
            'payload' => [
                'provider_message_id' => $message->providerMessageId,
            ],
            'occurred_at' => $message->receivedAt,
        ]);
    }

    protected function persistStatusUpdate(ResolvedWhatsAppLine $resolvedLine, StatusUpdateData $statusUpdate): void
    {
        $this->events->record($resolvedLine->tenantId(), 'webhook_received', [
            'whatsapp_line_id' => $resolvedLine->line->getKey(),
            'payload' => [
                'kind' => 'status_update',
                'provider_message_id' => $statusUpdate->providerMessageId,
                'status' => $statusUpdate->status,
            ],
            'occurred_at' => $statusUpdate->occurredAt,
        ]);

        $conversationMessage = ConversationMessage::query()
            ->forTenant($resolvedLine->tenantId())
            ->where('provider_message_id', $statusUpdate->providerMessageId)
            ->where('direction', MessageDirection::Outbound)
            ->first();

        if (! $conversationMessage) {
            $this->events->record($resolvedLine->tenantId(), 'webhook_rejected', [
                'whatsapp_line_id' => $resolvedLine->line->getKey(),
                'payload' => [
                    'reason' => 'outbound_message_not_found',
                    'provider_message_id' => $statusUpdate->providerMessageId,
                    'status' => $statusUpdate->status,
                ],
                'occurred_at' => $statusUpdate->occurredAt,
            ]);

            return;
        }

        $payload = $conversationMessage->payload ?? [];
        $statusHistory = $payload['status_history'] ?? [];
        $statusHistory[] = $statusUpdate->payload;

        $error = $statusUpdate->errors[0] ?? [];

        $conversationMessage->forceFill([
            'status' => $statusUpdate->status,
            'payload' => array_merge($payload, [
                'latest_status' => $statusUpdate->payload,
                'status_history' => $statusHistory,
            ]),
            'sent_at' => in_array($statusUpdate->status, ['sent', 'delivered', 'read'], true)
                ? ($conversationMessage->sent_at ?? $statusUpdate->occurredAt)
                : $conversationMessage->sent_at,
            'failed_at' => $statusUpdate->status === 'failed'
                ? $statusUpdate->occurredAt
                : $conversationMessage->failed_at,
            'error_code' => $error['code'] ?? $conversationMessage->error_code,
            'error_message' => $error['title'] ?? $error['message'] ?? $conversationMessage->error_message,
        ])->save();

        $this->events->record($resolvedLine->tenantId(), 'whatsapp_status_updated', [
            'whatsapp_line_id' => $resolvedLine->line->getKey(),
            'conversation_id' => $conversationMessage->conversation_id,
            'conversation_message_id' => $conversationMessage->getKey(),
            'payload' => [
                'provider_message_id' => $statusUpdate->providerMessageId,
                'status' => $statusUpdate->status,
                'errors' => $statusUpdate->errors,
            ],
            'occurred_at' => $statusUpdate->occurredAt,
        ]);
    }

    /**
     * @template TReturn
     *
     * @param  callable(): TReturn  $callback
     * @return TReturn
     */
    protected function withinTenantContext(ResolvedWhatsAppLine $resolvedLine, callable $callback): mixed
    {
        $this->tenantContextManager->set(new TenantContext($resolvedLine->tenant, 'whatsapp:webhook'));

        try {
            return $callback();
        } finally {
            $this->tenantContextManager->clear();
        }
    }

    protected function isDuplicateMessageException(QueryException $exception): bool
    {
        $sqlState = $exception->errorInfo[0] ?? null;
        $message = $exception->getMessage();

        return in_array($sqlState, ['23000', '23505'], true)
            && str_contains($message, 'provider_message_id');
    }
}
