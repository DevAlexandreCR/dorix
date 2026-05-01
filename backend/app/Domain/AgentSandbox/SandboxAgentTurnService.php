<?php

namespace App\Domain\AgentSandbox;

use App\Domain\Agent\AgentContextLoader;
use App\Domain\Agent\AgentDecisionApplier;
use App\Domain\Agent\Contracts\AgentRuntimeInterface;
use App\Domain\Conversations\Contracts\ConversationLockManager;
use App\Domain\Conversations\Contracts\ConversationStateRepository;
use App\Domain\Conversations\Contracts\ConversationStatusTransitioner;
use App\Domain\Conversations\Exceptions\ConversationOperationException;
use App\Domain\Tools\ToolExecutionRunner;
use App\Enums\ConversationSource;
use App\Enums\ConversationStatus;
use App\Enums\MessageDirection;
use App\Models\Conversation;
use App\Models\ConversationMessage;
use App\Models\User;
use App\Models\WhatsAppLine;
use App\Support\AgentEvents\AgentEventRecorder;
use App\Support\Observability\ObservabilityPayloadSanitizer;
use Illuminate\Support\Str;
use Throwable;

class SandboxAgentTurnService
{
    public function __construct(
        protected ConversationLockManager $lockManager,
        protected ConversationStateRepository $stateRepository,
        protected ConversationStatusTransitioner $statusTransitioner,
        protected AgentContextLoader $contextLoader,
        protected AgentRuntimeInterface $agentRuntime,
        protected AgentEventRecorder $events,
        protected ToolExecutionRunner $toolExecutionRunner,
        protected ObservabilityPayloadSanitizer $sanitizer,
        protected SandboxOutboundMessageSender $outboundMessageSender,
    ) {
    }

    public function createSession(WhatsAppLine $line, User $actor, ?string $label = null): Conversation
    {
        $conversation = Conversation::query()->create([
            'tenant_id' => $line->tenant_id,
            'whatsapp_line_id' => $line->getKey(),
            'contact_phone' => 'sandbox:'.Str::uuid(),
            'contact_name' => $this->normalizeLabel($label, $actor),
            'status' => ConversationStatus::BotActive,
            'source' => ConversationSource::AgentSandbox,
            'metadata' => [
                'sandbox' => [
                    'created_by_user_id' => $actor->getKey(),
                    'label' => $this->normalizeLabel($label, $actor),
                ],
            ],
        ]);

        $this->stateRepository->getOrCreate($conversation);

        $this->events->record($line->tenant_id, 'sandbox_session_created', [
            'whatsapp_line_id' => $line->getKey(),
            'conversation_id' => $conversation->getKey(),
            'payload' => [
                'created_by_user_id' => $actor->getKey(),
                'label' => $conversation->contact_name,
            ],
        ]);

        return $conversation->fresh(['whatsappLine', 'state']);
    }

    public function runTurn(Conversation $conversation, string $body): ConversationMessage
    {
        $body = trim($body);

        if ($body === '') {
            throw new ConversationOperationException(
                'sandbox_body_required',
                'api.conversations.sandbox_body_required',
            );
        }

        if ($conversation->source !== ConversationSource::AgentSandbox) {
            throw new ConversationOperationException(
                'sandbox_not_found',
                'api.conversations.sandbox_not_found',
                status: 404,
            );
        }

        if ($conversation->status === ConversationStatus::Closed) {
            throw new ConversationOperationException(
                'sandbox_closed',
                'api.conversations.sandbox_closed',
                status: 409,
            );
        }

        $message = null;

        $processed = $this->lockManager->runExclusive(
            $conversation->tenant_id,
            $conversation->getKey(),
            function () use ($conversation, $body, &$message): void {
                $conversation = Conversation::query()
                    ->forTenant($conversation->tenant_id)
                    ->findOrFail($conversation->getKey());

                if ($conversation->status === ConversationStatus::WaitingCustomer) {
                    $conversation = $this->statusTransitioner->transition($conversation, ConversationStatus::BotActive);
                }

                $state = $this->stateRepository->expireOperationalMemory(
                    $this->stateRepository->getOrCreate($conversation)
                );

                $message = ConversationMessage::query()->create([
                    'tenant_id' => $conversation->tenant_id,
                    'conversation_id' => $conversation->getKey(),
                    'direction' => MessageDirection::Inbound,
                    'message_type' => 'text',
                    'body' => $body,
                    'provider_message_id' => 'sandbox:'.Str::uuid(),
                    'payload' => [
                        'source' => 'agent_sandbox_user',
                    ],
                    'status' => 'received',
                    'received_at' => now(),
                ]);

                $conversation->forceFill([
                    'last_message_at' => now(),
                    'last_customer_message_at' => now(),
                ])->save();

                $this->events->record($conversation->tenant_id, 'sandbox_turn_started', [
                    'whatsapp_line_id' => $conversation->whatsapp_line_id,
                    'conversation_id' => $conversation->getKey(),
                    'conversation_message_id' => $message->getKey(),
                    'payload' => [
                        'conversation_status' => $conversation->status->value,
                    ],
                ]);

                $runtimeOutcome = 'skipped';

                if ($conversation->status !== ConversationStatus::BotActive) {
                    $runtimeOutcome = 'skipped_due_to_status';

                    $this->events->record($conversation->tenant_id, 'agent_runtime_skipped_due_to_status', [
                        'whatsapp_line_id' => $conversation->whatsapp_line_id,
                        'conversation_id' => $conversation->getKey(),
                        'conversation_message_id' => $message->getKey(),
                        'payload' => [
                            'conversation_status' => $conversation->status->value,
                            'source' => ConversationSource::AgentSandbox->value,
                        ],
                    ]);
                } else {
                    try {
                        $context = $this->contextLoader->load($conversation, $message->getKey(), $state);
                        $automationEnabled = (bool) (($context->agentConfig->settings ?? [])['automation_enabled'] ?? true);

                        if (! $context->line->is_enabled || ! $automationEnabled) {
                            $runtimeOutcome = 'skipped_due_to_configuration';

                            $this->events->record($conversation->tenant_id, 'agent_runtime_skipped_due_to_configuration', [
                                'whatsapp_line_id' => $context->line->getKey(),
                                'conversation_id' => $conversation->getKey(),
                                'conversation_message_id' => $message->getKey(),
                                'payload' => [
                                    'line_enabled' => $context->line->is_enabled,
                                    'automation_enabled' => $automationEnabled,
                                    'scope_key' => $context->agentConfig->scope_key,
                                    'source' => ConversationSource::AgentSandbox->value,
                                ],
                            ]);
                        } else {
                            $decision = $this->agentRuntime->run($context);
                            $this->sandboxDecisionApplier()->apply($context, $decision);
                            $runtimeOutcome = $decision->outcome->value;
                        }
                    } catch (Throwable $exception) {
                        $this->sandboxDecisionApplier()->fallbackToHumanHandoff(
                            $conversation,
                            $conversation->whatsapp_line_id,
                            $message->getKey(),
                            $exception->getMessage(),
                            [
                                'source' => ConversationSource::AgentSandbox->value,
                                'exception' => $exception::class,
                            ],
                        );

                        $runtimeOutcome = 'fallback_human_handoff';
                    }
                }

                $this->events->record($conversation->tenant_id, 'sandbox_turn_completed', [
                    'whatsapp_line_id' => $conversation->whatsapp_line_id,
                    'conversation_id' => $conversation->getKey(),
                    'conversation_message_id' => $message->getKey(),
                    'payload' => [
                        'runtime_outcome' => $runtimeOutcome,
                    ],
                ]);
            }
        );

        if (! $processed || ! $message instanceof ConversationMessage) {
            throw new ConversationOperationException(
                'sandbox_busy_turn',
                'api.conversations.sandbox_busy_turn',
                status: 409,
            );
        }

        return $message->fresh();
    }

    public function closeSession(Conversation $conversation, User $actor): Conversation
    {
        if ($conversation->source !== ConversationSource::AgentSandbox) {
            throw new ConversationOperationException(
                'sandbox_not_found',
                'api.conversations.sandbox_not_found',
                status: 404,
            );
        }

        $processed = $this->lockManager->runExclusive(
            $conversation->tenant_id,
            $conversation->getKey(),
            function () use ($conversation, $actor): void {
                $conversation = Conversation::query()
                    ->forTenant($conversation->tenant_id)
                    ->findOrFail($conversation->getKey());

                $conversation = $this->statusTransitioner->transition($conversation, ConversationStatus::Closed);

                $metadata = $conversation->metadata ?? [];
                $sandboxMetadata = is_array($metadata['sandbox'] ?? null) ? $metadata['sandbox'] : [];

                $conversation->forceFill([
                    'metadata' => array_merge($metadata, [
                        'sandbox' => array_merge($sandboxMetadata, [
                            'closed_by_user_id' => $actor->getKey(),
                            'closed_at' => now()->toIso8601String(),
                        ]),
                    ]),
                ])->save();

                $this->events->record($conversation->tenant_id, 'sandbox_session_closed', [
                    'whatsapp_line_id' => $conversation->whatsapp_line_id,
                    'conversation_id' => $conversation->getKey(),
                    'payload' => [
                        'closed_by_user_id' => $actor->getKey(),
                    ],
                ]);
            }
        );

        if (! $processed) {
            throw new ConversationOperationException(
                'sandbox_busy_action',
                'api.conversations.sandbox_busy_action',
                status: 409,
            );
        }

        return $conversation->fresh(['whatsappLine', 'state']);
    }

    protected function sandboxDecisionApplier(): AgentDecisionApplier
    {
        return new AgentDecisionApplier(
            $this->statusTransitioner,
            $this->stateRepository,
            $this->outboundMessageSender,
            $this->events,
            $this->toolExecutionRunner,
            $this->agentRuntime,
            $this->sanitizer,
        );
    }

    protected function normalizeLabel(?string $label, User $actor): string
    {
        $label = trim((string) $label);

        if ($label !== '') {
            return $label;
        }

        $timestamp = now();

        if (app()->getLocale() === 'en') {
            return sprintf('Test %s', $timestamp->format('d/m/Y g:i A'));
        }

        return sprintf('Prueba %s', $timestamp->format('d/m/Y g:i')).' '.($timestamp->hour < 12 ? 'a. m.' : 'p. m.');
    }
}
