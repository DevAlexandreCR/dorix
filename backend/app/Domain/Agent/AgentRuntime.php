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
        protected AgentDecisionPolicy $policy,
        protected LlmProviderInterface $provider,
        protected AgentEventRecorder $events,
    ) {
    }

    public function run(AgentContext $context): AgentDecision
    {
        $resolvedModel = $context->resolvedModel;

        $this->events->record($context->tenant->getKey(), 'agent_started', [
            'whatsapp_line_id' => $context->line->getKey(),
            'conversation_id' => $context->conversation->getKey(),
            'conversation_message_id' => $context->triggeringMessage->getKey(),
                'payload' => [
                    'model_key' => $resolvedModel['key'] ?? null,
                    'resolved_model_id' => $resolvedModel['model_id'] ?? null,
                    'model_source' => $resolvedModel['source'] ?? null,
                    'prompt_version' => $context->agentConfig->prompt_version,
                    'recent_message_count' => count($context->recentMessages),
                    'retrieved_context_count' => count($context->retrievedContext),
                    'enabled_tools' => array_map(
                        static fn ($tool): string => $tool->name(),
                        $context->enabledTools,
                ),
                    'agent_pack_key' => $this->policy->activePack($context)->key,
            ],
        ]);

        try {
            $decision = $this->policy->apply(
                $context,
                $this->provider->generateDecision($context, $this->promptBuilder->build($context)),
            );

            $this->events->record($context->tenant->getKey(), 'agent_response_generated', [
                'whatsapp_line_id' => $context->line->getKey(),
                'conversation_id' => $context->conversation->getKey(),
                'conversation_message_id' => $context->triggeringMessage->getKey(),
                'payload' => [
                    'model_key' => $resolvedModel['key'] ?? null,
                    'resolved_model_id' => $resolvedModel['model_id'] ?? null,
                    'model_source' => $resolvedModel['source'] ?? null,
                    'prompt_version' => $context->agentConfig->prompt_version,
                    'retrieved_context_count' => count($context->retrievedContext),
                    'agent_pack_key' => $this->policy->activePack($context)->key,
                    'policy' => $decision->toArray()['policy'],
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
                    'model_key' => $resolvedModel['key'] ?? null,
                    'resolved_model_id' => $resolvedModel['model_id'] ?? null,
                    'model_source' => $resolvedModel['source'] ?? null,
                    'prompt_version' => $context->agentConfig->prompt_version,
                    'retrieved_context_count' => count($context->retrievedContext),
                    'error' => $exception->getMessage(),
                    'exception' => $exception::class,
                ],
            ]);

            throw $exception;
        }
    }
}
