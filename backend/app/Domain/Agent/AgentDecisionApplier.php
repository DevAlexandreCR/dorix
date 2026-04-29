<?php

namespace App\Domain\Agent;

use App\Domain\Agent\DTO\AgentContext;
use App\Domain\Agent\DTO\AgentDecision;
use App\Domain\Conversations\Contracts\ConversationStateRepository;
use App\Domain\Conversations\Contracts\ConversationStatusTransitioner;
use App\Domain\WhatsApp\Contracts\OutboundMessageSender;
use App\Domain\WhatsApp\DTO\OutboundMessageData;
use App\Enums\ConversationStatus;
use App\Models\Conversation;
use App\Models\HandoffRequest;
use App\Support\AgentEvents\AgentEventRecorder;
use Illuminate\Support\Str;

class AgentDecisionApplier
{
    public function __construct(
        protected ConversationStatusTransitioner $statusTransitioner,
        protected ConversationStateRepository $stateRepository,
        protected OutboundMessageSender $outboundMessageSender,
        protected AgentEventRecorder $events,
    ) {
    }

    public function apply(AgentContext $context, AgentDecision $decision): void
    {
        $conversation = $context->conversation->fresh();

        $this->updateState($conversation, $decision);

        match ($decision->outcome) {
            AgentDecisionOutcome::SendMessage,
            AgentDecisionOutcome::RequestMissingInformation => $this->sendReplyAndWait($context, $decision, $conversation),
            AgentDecisionOutcome::WaitForCustomer,
            AgentDecisionOutcome::NoReply => $this->waitForCustomer($conversation),
            AgentDecisionOutcome::RequestHandoff => $this->fallbackToHumanHandoff(
                $conversation,
                $context->line->getKey(),
                $context->triggeringMessage->getKey(),
                $decision->handoffReason !== '' ? $decision->handoffReason : 'The runtime requested a human handoff.',
                [
                    'decision' => $decision->toArray(),
                ],
            ),
            AgentDecisionOutcome::CallTool => $this->fallbackToHumanHandoff(
                $conversation,
                $context->line->getKey(),
                $context->triggeringMessage->getKey(),
                sprintf('The runtime requested tool "%s", but tool execution is not implemented in phase 4.', $decision->toolName),
                [
                    'decision' => $decision->toArray(),
                ],
            ),
            AgentDecisionOutcome::Error => $this->fallbackToHumanHandoff(
                $conversation,
                $context->line->getKey(),
                $context->triggeringMessage->getKey(),
                $decision->handoffReason !== '' ? $decision->handoffReason : 'The runtime returned an error outcome.',
                [
                    'decision' => $decision->toArray(),
                ],
            ),
        };
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function fallbackToHumanHandoff(
        Conversation $conversation,
        ?int $whatsAppLineId,
        ?int $messageId,
        string $reason,
        array $payload = [],
    ): void {
        $conversation = $this->statusTransitioner->transition($conversation->fresh(), ConversationStatus::HumanHandoff);

        $this->stateRepository->update($conversation, [
            'last_agent_action' => AgentDecisionOutcome::RequestHandoff->value,
        ]);

        $handoffRequest = HandoffRequest::query()
            ->forTenant($conversation->tenant_id)
            ->where('conversation_id', $conversation->getKey())
            ->where('status', 'requested')
            ->latest('id')
            ->first();

        if (! $handoffRequest) {
            $handoffRequest = HandoffRequest::query()->create([
                'tenant_id' => $conversation->tenant_id,
                'conversation_id' => $conversation->getKey(),
                'requested_by_type' => 'runtime',
                'status' => 'requested',
                'reason' => $reason,
                'payload' => $payload,
            ]);
        }

        $this->events->record($conversation->tenant_id, 'handoff_triggered', [
            'whatsapp_line_id' => $whatsAppLineId,
            'conversation_id' => $conversation->getKey(),
            'conversation_message_id' => $messageId,
            'payload' => [
                'handoff_request_id' => $handoffRequest->getKey(),
                'reason' => $reason,
                'source' => 'agent_runtime',
            ],
        ]);
    }

    protected function sendReplyAndWait(AgentContext $context, AgentDecision $decision, Conversation $conversation): void
    {
        $this->outboundMessageSender->send(new OutboundMessageData(
            tenantId: $context->tenant->getKey(),
            conversationId: $conversation->getKey(),
            whatsAppLineId: $context->line->getKey(),
            recipientPhone: $conversation->contact_phone,
            body: $decision->replyText,
            idempotencyKey: sprintf(
                'agent-runtime:%d:%d:%s',
                $conversation->getKey(),
                $context->triggeringMessage->getKey(),
                Str::slug($decision->outcome->value, '_'),
            ),
            payload: [
                'source' => 'agent_runtime',
                'decision' => $decision->toArray(),
            ],
        ));

        $this->waitForCustomer($conversation);
    }

    protected function waitForCustomer(Conversation $conversation): void
    {
        $this->statusTransitioner->transition($conversation->fresh(), ConversationStatus::WaitingCustomer);
    }

    protected function updateState(Conversation $conversation, AgentDecision $decision): void
    {
        $attributes = [
            'last_agent_action' => $decision->outcome->value,
        ];

        if ($decision->currentIntent !== '') {
            $attributes['current_intent'] = $decision->currentIntent;
        }

        $this->stateRepository->update($conversation, $attributes);
    }
}
