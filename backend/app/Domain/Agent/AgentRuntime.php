<?php

namespace App\Domain\Agent;

use App\Domain\Agent\Contracts\AgentRuntimeInterface;
use App\Domain\Agent\Contracts\LlmProviderInterface;
use App\Domain\Agent\DTO\AgentContext;
use App\Domain\Agent\DTO\AgentDecision;
use App\Support\AgentEvents\AgentEventRecorder;
use Throwable;

class AgentRuntime implements AgentRuntimeInterface
{
    public function __construct(
        protected PromptBuilder $promptBuilder,
        protected LlmProviderInterface $provider,
        protected AgentEventRecorder $events,
    ) {
    }

    public function run(AgentContext $context): AgentDecision
    {
        $this->events->record($context->tenant->getKey(), 'agent_started', [
            'whatsapp_line_id' => $context->line->getKey(),
            'conversation_id' => $context->conversation->getKey(),
            'conversation_message_id' => $context->triggeringMessage->getKey(),
            'payload' => [
                'model' => $context->agentConfig->model,
                'prompt_version' => $context->agentConfig->prompt_version,
                'recent_message_count' => count($context->recentMessages),
                'enabled_tools' => array_map(
                    static fn ($tool): string => $tool->name(),
                    $context->enabledTools,
                ),
            ],
        ]);

        try {
            $decision = $this->provider->generateDecision($context, $this->promptBuilder->build($context));

            $this->events->record($context->tenant->getKey(), 'agent_response_generated', [
                'whatsapp_line_id' => $context->line->getKey(),
                'conversation_id' => $context->conversation->getKey(),
                'conversation_message_id' => $context->triggeringMessage->getKey(),
                'payload' => [
                    'model' => $context->agentConfig->model,
                    'prompt_version' => $context->agentConfig->prompt_version,
                    'decision' => $decision->toArray(),
                ],
            ]);

            return $decision;
        } catch (Throwable $exception) {
            $this->events->record($context->tenant->getKey(), 'agent_runtime_failed', [
                'whatsapp_line_id' => $context->line->getKey(),
                'conversation_id' => $context->conversation->getKey(),
                'conversation_message_id' => $context->triggeringMessage->getKey(),
                'payload' => [
                    'model' => $context->agentConfig->model,
                    'prompt_version' => $context->agentConfig->prompt_version,
                    'error' => $exception->getMessage(),
                    'exception' => $exception::class,
                ],
            ]);

            throw $exception;
        }
    }
}
