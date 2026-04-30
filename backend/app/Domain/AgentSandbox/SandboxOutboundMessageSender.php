<?php

namespace App\Domain\AgentSandbox;

use App\Domain\Conversations\Contracts\ConversationStateRepository;
use App\Domain\WhatsApp\Contracts\OutboundMessageSender;
use App\Domain\WhatsApp\DTO\OutboundMessageData;
use App\Domain\WhatsApp\Exceptions\WhatsAppSendFailedException;
use App\Enums\ConversationSource;
use App\Enums\ConversationStatus;
use App\Enums\MessageDirection;
use App\Models\Conversation;
use App\Models\ConversationMessage;
use App\Models\WhatsAppLine;
use App\Support\AgentEvents\AgentEventRecorder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Str;

class SandboxOutboundMessageSender implements OutboundMessageSender
{
    public function __construct(
        protected ConversationStateRepository $stateRepository,
        protected AgentEventRecorder $events,
    ) {
    }

    public function send(OutboundMessageData $outboundMessage): ConversationMessage
    {
        if ($outboundMessage->messageType !== 'text') {
            throw new WhatsAppSendFailedException('Only outbound text messages are supported in the sandbox.');
        }

        $existingMessage = ConversationMessage::query()
            ->where('idempotency_key', $outboundMessage->idempotencyKey)
            ->first();

        if ($existingMessage) {
            return $existingMessage;
        }

        $line = WhatsAppLine::query()->findOrFail($outboundMessage->whatsAppLineId);
        $conversation = Conversation::query()
            ->forTenant($outboundMessage->tenantId)
            ->findOrFail($outboundMessage->conversationId);

        if (
            (int) $line->tenant_id !== $outboundMessage->tenantId
            || (int) $conversation->whatsapp_line_id !== $line->getKey()
            || $conversation->source !== ConversationSource::AgentSandbox
        ) {
            throw new WhatsAppSendFailedException('Sandbox outbound message context is inconsistent.');
        }

        if (
            $conversation->status === ConversationStatus::HumanHandoff
            && $this->isAutomatedRuntimePayload($outboundMessage->payload)
        ) {
            $this->events->record($outboundMessage->tenantId, 'sandbox_message_rejected', [
                'whatsapp_line_id' => $line->getKey(),
                'conversation_id' => $conversation->getKey(),
                'payload' => [
                    'idempotency_key' => $outboundMessage->idempotencyKey,
                    'reason' => 'blocked_during_human_handoff',
                ],
            ]);

            throw new WhatsAppSendFailedException(
                'Automated sandbox outbound messages are blocked while the conversation is in HUMAN_HANDOFF.',
            );
        }

        $this->stateRepository->getOrCreate($conversation);

        try {
            $message = ConversationMessage::query()->create([
                'tenant_id' => $outboundMessage->tenantId,
                'conversation_id' => $conversation->getKey(),
                'direction' => MessageDirection::Outbound,
                'message_type' => $outboundMessage->messageType,
                'body' => $outboundMessage->body,
                'payload' => [
                    'source' => 'sandbox_outbound',
                    'request' => [
                        'to' => $outboundMessage->recipientPhone,
                        'type' => $outboundMessage->messageType,
                        'body' => $outboundMessage->body,
                    ],
                    'context' => $outboundMessage->payload,
                ],
                'provider_message_id' => 'sandbox-outbound:'.Str::uuid(),
                'idempotency_key' => $outboundMessage->idempotencyKey,
                'status' => 'sandbox_sent',
                'sent_at' => now(),
            ]);
        } catch (QueryException $exception) {
            if (! $this->isDuplicateIdempotencyException($exception)) {
                throw $exception;
            }

            return ConversationMessage::query()
                ->where('idempotency_key', $outboundMessage->idempotencyKey)
                ->firstOrFail();
        }

        $conversation->forceFill([
            'last_message_at' => now(),
        ])->save();

        $this->events->record($outboundMessage->tenantId, 'sandbox_message_persisted', [
            'whatsapp_line_id' => $line->getKey(),
            'conversation_id' => $conversation->getKey(),
            'conversation_message_id' => $message->getKey(),
            'payload' => [
                'idempotency_key' => $outboundMessage->idempotencyKey,
                'provider_message_id' => $message->provider_message_id,
            ],
        ]);

        return $message->fresh();
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    protected function isAutomatedRuntimePayload(array $payload): bool
    {
        $source = (string) ($payload['source'] ?? '');

        return str_starts_with($source, 'agent_runtime');
    }

    protected function isDuplicateIdempotencyException(QueryException $exception): bool
    {
        $sqlState = $exception->errorInfo[0] ?? null;

        return in_array($sqlState, ['23000', '23505'], true)
            && str_contains($exception->getMessage(), 'idempotency_key');
    }
}
