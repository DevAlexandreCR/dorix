<?php

namespace App\Domain\WhatsApp;

use App\Domain\Conversations\Contracts\ConversationStateRepository;
use App\Domain\WhatsApp\Contracts\OutboundMessageSender;
use App\Domain\WhatsApp\DTO\OutboundMessageData;
use App\Domain\WhatsApp\Exceptions\MissingWhatsAppCredentialException;
use App\Domain\WhatsApp\Exceptions\WhatsAppSendFailedException;
use App\Enums\ConversationStatus;
use App\Enums\MessageDirection;
use App\Models\ApiCredential;
use App\Models\Conversation;
use App\Models\ConversationMessage;
use App\Models\WhatsAppLine;
use App\Support\AgentEvents\AgentEventRecorder;
use App\Support\Tenancy\TenantContext;
use App\Support\Tenancy\TenantContextManager;
use App\Support\Tenancy\TenantScopeKey;
use Illuminate\Database\QueryException;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\RequestException;

class MetaGraphOutboundMessageSender implements OutboundMessageSender
{
    protected const PROVIDER = 'whatsapp_meta';

    protected const ACCESS_TOKEN_KEY = 'access_token';

    public function __construct(
        protected HttpFactory $http,
        protected TenantContextManager $tenantContextManager,
        protected ConversationStateRepository $stateRepository,
        protected AgentEventRecorder $events,
    ) {
    }

    public function send(OutboundMessageData $outboundMessage): ConversationMessage
    {
        if ($outboundMessage->messageType !== 'text') {
            throw new WhatsAppSendFailedException(__('api.whatsapp.outbound_text_only_phase_2'));
        }

        $existingMessage = ConversationMessage::query()
            ->where('idempotency_key', $outboundMessage->idempotencyKey)
            ->first();

        if ($existingMessage) {
            return $existingMessage;
        }

        $line = WhatsAppLine::query()->with('tenant')->findOrFail($outboundMessage->whatsAppLineId);
        $conversation = Conversation::query()
            ->forTenant($outboundMessage->tenantId)
            ->findOrFail($outboundMessage->conversationId);

        if ((int) $line->tenant_id !== $outboundMessage->tenantId || (int) $conversation->whatsapp_line_id !== $line->getKey()) {
            throw new WhatsAppSendFailedException(__('api.whatsapp.outbound_context_invalid'));
        }

        return $this->withinTenantContext($line, function () use ($outboundMessage, $line, $conversation): ConversationMessage {
            if (
                $conversation->status === ConversationStatus::HumanHandoff
                && $this->isAutomatedRuntimePayload($outboundMessage->payload)
            ) {
                $this->events->record($outboundMessage->tenantId, 'whatsapp_message_rejected', [
                    'whatsapp_line_id' => $line->getKey(),
                    'conversation_id' => $conversation->getKey(),
                    'payload' => [
                        'idempotency_key' => $outboundMessage->idempotencyKey,
                        'reason' => 'blocked_during_human_handoff',
                    ],
                ]);

                throw new WhatsAppSendFailedException(
                    __('api.whatsapp.outbound_blocked_handoff'),
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
                        'source' => 'outbound_sender',
                        'request' => [
                            'to' => $outboundMessage->recipientPhone,
                            'type' => $outboundMessage->messageType,
                            'body' => $outboundMessage->body,
                        ],
                        'context' => $outboundMessage->payload,
                    ],
                    'idempotency_key' => $outboundMessage->idempotencyKey,
                    'status' => 'pending',
                ]);
            } catch (QueryException $exception) {
                if (! $this->isDuplicateIdempotencyException($exception)) {
                    throw $exception;
                }

                return ConversationMessage::query()
                    ->where('idempotency_key', $outboundMessage->idempotencyKey)
                    ->firstOrFail();
            }

            $this->events->record($outboundMessage->tenantId, 'whatsapp_message_send_requested', [
                'whatsapp_line_id' => $line->getKey(),
                'conversation_id' => $conversation->getKey(),
                'conversation_message_id' => $message->getKey(),
                'payload' => [
                    'idempotency_key' => $outboundMessage->idempotencyKey,
                    'to' => $outboundMessage->recipientPhone,
                ],
            ]);

            try {
                $credential = $this->resolveAccessTokenCredential($line);

                $requestPayload = [
                    'messaging_product' => 'whatsapp',
                    'recipient_type' => 'individual',
                    'to' => $outboundMessage->recipientPhone,
                    'type' => 'text',
                    'text' => [
                        'body' => $outboundMessage->body,
                    ],
                ];

                $response = $this->http
                    ->baseUrl(rtrim((string) config('services.whatsapp.meta.base_url'), '/'))
                    ->withToken($credential->secret)
                    ->acceptJson()
                    ->post(sprintf('/%s/%s/messages', config('services.whatsapp.meta.api_version'), $line->phone_number_id), $requestPayload);

                $responseData = $response->json();

                if (! $response->successful()) {
                    throw new RequestException($response);
                }

                $providerMessageId = data_get($responseData, 'messages.0.id');

                if (! is_string($providerMessageId) || $providerMessageId === '') {
                    throw new WhatsAppSendFailedException(__('api.whatsapp.meta_missing_message_id'));
                }

                $message->forceFill([
                    'provider_message_id' => $providerMessageId,
                    'status' => 'accepted',
                    'sent_at' => now(),
                    'payload' => array_merge($message->payload ?? [], [
                        'response' => $responseData,
                    ]),
                ])->save();

                $credential->forceFill([
                    'last_used_at' => now(),
                ])->save();

                $conversation->forceFill([
                    'last_message_at' => now(),
                ])->save();

                $this->events->record($outboundMessage->tenantId, 'whatsapp_message_sent', [
                    'whatsapp_line_id' => $line->getKey(),
                    'conversation_id' => $conversation->getKey(),
                    'conversation_message_id' => $message->getKey(),
                    'payload' => [
                        'provider_message_id' => $providerMessageId,
                    ],
                ]);

                return $message->fresh();
            } catch (\Throwable $exception) {
                $this->markSendFailure($message, $exception);

                $this->events->record($outboundMessage->tenantId, 'whatsapp_message_rejected', [
                    'whatsapp_line_id' => $line->getKey(),
                    'conversation_id' => $conversation->getKey(),
                    'conversation_message_id' => $message->getKey(),
                    'payload' => [
                        'idempotency_key' => $outboundMessage->idempotencyKey,
                        'error' => $exception->getMessage(),
                    ],
                ]);

                if ($exception instanceof WhatsAppSendFailedException) {
                    throw $exception;
                }

                throw new WhatsAppSendFailedException(__('api.whatsapp.send_failed'), previous: $exception);
            }
        });
    }

    protected function resolveAccessTokenCredential(WhatsAppLine $line): ApiCredential
    {
        $credential = ApiCredential::query()
            ->forTenant($line->tenant_id)
            ->where('provider', self::PROVIDER)
            ->where('credential_key', self::ACCESS_TOKEN_KEY)
            ->whereIn('scope_key', [
                TenantScopeKey::forWhatsAppLine($line),
                TenantScopeKey::forTenant($line->tenant_id),
            ])
            ->orderByRaw(
                'case when scope_key = ? then 0 else 1 end',
                [TenantScopeKey::forWhatsAppLine($line)]
            )
            ->first();

        if (! $credential) {
            throw new MissingWhatsAppCredentialException(__('api.whatsapp.missing_credential'));
        }

        return $credential;
    }

    protected function markSendFailure(ConversationMessage $message, \Throwable $exception): void
    {
        $errorPayload = [
            'exception' => $exception->getMessage(),
        ];

        if ($exception instanceof RequestException) {
            $errorPayload['response'] = $exception->response?->json();
        }

        $message->forceFill([
            'status' => $exception instanceof RequestException ? 'rejected' : 'failed',
            'failed_at' => now(),
            'error_code' => $exception instanceof RequestException
                ? (string) ($exception->response?->status() ?? 'http_error')
                : 'send_failed',
            'error_message' => $exception->getMessage(),
            'payload' => array_merge($message->payload ?? [], [
                'failure' => $errorPayload,
            ]),
        ])->save();
    }

    protected function isDuplicateIdempotencyException(QueryException $exception): bool
    {
        $sqlState = $exception->errorInfo[0] ?? null;

        return in_array($sqlState, ['23000', '23505'], true)
            && str_contains($exception->getMessage(), 'idempotency_key');
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    protected function isAutomatedRuntimePayload(array $payload): bool
    {
        $source = (string) ($payload['source'] ?? '');

        return str_starts_with($source, 'agent_runtime');
    }

    /**
     * @template TReturn
     *
     * @param  callable(): TReturn  $callback
     * @return TReturn
     */
    protected function withinTenantContext(WhatsAppLine $line, callable $callback): mixed
    {
        $this->tenantContextManager->set(new TenantContext($line->tenant, 'whatsapp:outbound'));

        try {
            return $callback();
        } finally {
            $this->tenantContextManager->clear();
        }
    }
}
