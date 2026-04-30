<?php

namespace App\Domain\Agent;

use App\Domain\Agent\DTO\AgentContext;
use App\Domain\Agent\DTO\AgentDecision;
use App\Domain\Agent\Contracts\AgentRuntimeInterface;
use App\Domain\Conversations\Contracts\ConversationStateRepository;
use App\Domain\Conversations\Contracts\ConversationStatusTransitioner;
use App\Domain\Tools\DTO\ToolResult;
use App\Domain\Tools\ToolExecutionRunner;
use App\Domain\Tools\ToolNextAction;
use App\Domain\WhatsApp\Contracts\OutboundMessageSender;
use App\Domain\WhatsApp\DTO\OutboundMessageData;
use App\Enums\ConversationStatus;
use App\Models\Conversation;
use App\Models\HandoffRequest;
use App\Support\AgentEvents\AgentEventRecorder;
use App\Support\Observability\ObservabilityPayloadSanitizer;
use Illuminate\Support\Str;

class AgentDecisionApplier
{
    public function __construct(
        protected ConversationStatusTransitioner $statusTransitioner,
        protected ConversationStateRepository $stateRepository,
        protected OutboundMessageSender $outboundMessageSender,
        protected AgentEventRecorder $events,
        protected ToolExecutionRunner $toolExecutionRunner,
        protected AgentRuntimeInterface $agentRuntime,
        protected ObservabilityPayloadSanitizer $sanitizer,
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
            AgentDecisionOutcome::CallTool => $this->applyToolDecision($context, $decision, $conversation),
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
                'payload' => $this->safePayload($payload),
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

    protected function applyToolDecision(AgentContext $context, AgentDecision $decision, Conversation $conversation): void
    {
        $result = $this->toolExecutionRunner->run($context, $decision);

        $conversation = $this->applyConversationUpdates($conversation, $result);
        $this->applyToolStateUpdates($conversation, $result);

        match ($result->nextAction) {
            ToolNextAction::SendMessageAndWait => $this->sendToolReplyAndWait($context, $decision, $conversation, $result),
            ToolNextAction::ContinueWithRetrievedContext => $this->continueWithRetrievedContext($context, $decision, $conversation, $result),
            ToolNextAction::WaitForCustomer => $this->waitForCustomer($conversation),
            ToolNextAction::RequestHandoff => $this->fallbackToHumanHandoff(
                $conversation,
                $context->line->getKey(),
                $context->triggeringMessage->getKey(),
                $result->handoffReason !== '' ? $result->handoffReason : sprintf('The tool "%s" requested a human handoff.', $decision->toolName),
                [
                    'decision' => $decision->toArray(),
                    'tool_result' => $result->toArray(),
                ],
            ),
            ToolNextAction::NoReply => null,
        };
    }

    protected function continueWithRetrievedContext(
        AgentContext $context,
        AgentDecision $decision,
        Conversation $conversation,
        ToolResult $result,
    ): void {
        $retrievedContext = $this->normalizeRetrievedContext($result);

        if ($retrievedContext === []) {
            $this->waitForCustomer($conversation);

            return;
        }

        $followUpContext = $context->withRetrievedContext(
            $retrievedContext,
            [
                'tool_name' => $decision->toolName,
                'tool_arguments' => $decision->toolArguments,
                'tool_result' => $result->outputSummary,
            ],
        );

        $followUpDecision = $this->agentRuntime->run($followUpContext);

        if ($followUpDecision->outcome === AgentDecisionOutcome::CallTool) {
            $this->fallbackToHumanHandoff(
                $conversation,
                $context->line->getKey(),
                $context->triggeringMessage->getKey(),
                sprintf('The retrieval follow-up after tool "%s" attempted an additional tool call.', $decision->toolName),
                [
                    'decision' => $decision->toArray(),
                    'tool_result' => $result->toArray(),
                    'follow_up_decision' => $followUpDecision->toArray(),
                ],
            );

            return;
        }

        $this->apply($followUpContext, $followUpDecision);
    }

    protected function sendReplyAndWait(AgentContext $context, AgentDecision $decision, Conversation $conversation): void
    {
        $this->sendOutboundMessage(
            $context,
            $conversation,
            $decision->replyText,
            sprintf(
                'agent-runtime:%d:%d:%s',
                $conversation->getKey(),
                $context->triggeringMessage->getKey(),
                Str::slug($decision->outcome->value, '_'),
            ),
            [
                'source' => 'agent_runtime',
                'decision' => $decision->toArray(),
            ],
        );

        $this->waitForCustomer($conversation);
    }

    protected function sendToolReplyAndWait(
        AgentContext $context,
        AgentDecision $decision,
        Conversation $conversation,
        ToolResult $result,
    ): void {
        $this->sendOutboundMessage(
            $context,
            $conversation,
            $result->replyText,
            sprintf(
                'agent-runtime:%d:%d:%s:%s',
                $conversation->getKey(),
                $context->triggeringMessage->getKey(),
                Str::slug($decision->outcome->value, '_'),
                Str::slug($decision->toolName, '_'),
            ),
            [
                'source' => 'agent_runtime_tool',
                'decision' => $decision->toArray(),
                'tool_result' => $result->toArray(),
            ],
        );

        $this->waitForCustomer($conversation);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    protected function sendOutboundMessage(
        AgentContext $context,
        Conversation $conversation,
        string $body,
        string $idempotencyKey,
        array $payload,
    ): void {
        $this->outboundMessageSender->send(new OutboundMessageData(
            tenantId: $context->tenant->getKey(),
            conversationId: $conversation->getKey(),
            whatsAppLineId: $context->line->getKey(),
            recipientPhone: $conversation->contact_phone,
            body: $body,
            idempotencyKey: $idempotencyKey,
            payload: $payload,
        ));
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

    protected function applyToolStateUpdates(Conversation $conversation, ToolResult $result): void
    {
        if ($result->stateUpdates === []) {
            return;
        }

        $this->stateRepository->update($conversation, $result->stateUpdates);
    }

    protected function applyConversationUpdates(Conversation $conversation, ToolResult $result): Conversation
    {
        if ($result->conversationUpdates === []) {
            return $conversation->fresh();
        }

        $conversation->forceFill($result->conversationUpdates)->save();

        return $conversation->fresh();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    protected function normalizeRetrievedContext(ToolResult $result): array
    {
        $retrievedContext = $result->metadata['retrieved_context'] ?? $result->outputSummary['matches'] ?? [];

        return is_array($retrievedContext) ? array_values(array_filter(
            $retrievedContext,
            static fn (mixed $item): bool => is_array($item),
        )) : [];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    protected function safePayload(array $payload): array
    {
        $sanitized = $this->sanitizer->sanitizeForStorage($payload);

        return is_array($sanitized) ? $sanitized : ['value' => $sanitized];
    }
}
