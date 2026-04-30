<?php

namespace App\Domain\Tools;

use App\Domain\Agent\DTO\AgentContext;
use App\Domain\Agent\DTO\AgentDecision;
use App\Domain\Tools\Contracts\ToolInterface;
use App\Domain\Tools\DTO\EnabledTool;
use App\Domain\Tools\DTO\ToolResult;
use App\Domain\Tools\DTO\ToolInvocation;
use App\Domain\Tools\Exceptions\ToolNotEnabledException;
use App\Domain\Tools\Exceptions\ToolNotFoundException;
use App\Domain\Tools\Exceptions\ToolNotImplementedException;
use App\Domain\Tools\Exceptions\ToolTimeoutException;
use App\Models\ToolExecution;
use App\Support\AgentEvents\AgentEventRecorder;
use App\Support\Observability\ObservabilityPayloadSanitizer;
use Throwable;

class ToolExecutionRunner
{
    public function __construct(
        protected ToolRegistry $registry,
        protected AgentEventRecorder $events,
        protected ObservabilityPayloadSanitizer $sanitizer,
    ) {
    }

    public function run(AgentContext $context, AgentDecision $decision): ToolResult
    {
        $enabledTool = $context->enabledTool($decision->toolName);
        $definition = $this->registry->has($decision->toolName)
            ? $this->registry->get($decision->toolName)->definition()
            : null;

        $execution = ToolExecution::query()->create([
            'tenant_id' => $context->tenant->getKey(),
            'conversation_id' => $context->conversation->getKey(),
            'conversation_message_id' => $context->triggeringMessage->getKey(),
            'tool_name' => $decision->toolName,
            'status' => 'started',
            'input_summary' => $this->safeArray([
                'arguments' => $decision->toolArguments,
            ]),
            'metadata' => [
                'decision' => $this->safeArray($decision->toArray()),
                'tool_definition' => $definition?->toArray(),
                'enabled' => $enabledTool !== null,
                'bindings' => $this->safeArray($enabledTool?->config->bindings ?? []),
            ],
            'executed_at' => now(),
        ]);

        $this->events->record($context->tenant->getKey(), 'tool_called', [
            'whatsapp_line_id' => $context->line->getKey(),
            'conversation_id' => $context->conversation->getKey(),
            'conversation_message_id' => $context->triggeringMessage->getKey(),
            'payload' => [
                'tool_execution_id' => $execution->getKey(),
                'tool_name' => $decision->toolName,
                'timeout_seconds' => $enabledTool?->timeoutSeconds(),
            ],
        ]);

        $startedAt = microtime(true);

        try {
            if ($enabledTool === null) {
                throw new ToolNotEnabledException(sprintf('The tool "%s" is not enabled for the current tenant or WhatsApp line.', $decision->toolName));
            }

            $tool = $this->registry->get($decision->toolName);
            $result = $this->executeWithTimeout($tool, new ToolInvocation(
                context: $context,
                tool: $enabledTool,
                arguments: $decision->toolArguments,
            ), $enabledTool->timeoutSeconds());

            $durationMs = $this->durationMs($startedAt);

            $execution->forceFill([
                'status' => 'succeeded',
                'output_summary' => $this->safeArray(array_merge($result->outputSummary, [
                    'next_action' => $result->nextAction->value,
                ])),
                'duration_ms' => $durationMs,
                'metadata' => array_merge($execution->metadata ?? [], [
                    'tool_result' => $this->safeArray($result->toArray()),
                ]),
            ])->save();

            $this->events->record($context->tenant->getKey(), 'tool_succeeded', [
                'whatsapp_line_id' => $context->line->getKey(),
                'conversation_id' => $context->conversation->getKey(),
                'conversation_message_id' => $context->triggeringMessage->getKey(),
                'payload' => [
                    'tool_execution_id' => $execution->getKey(),
                    'tool_name' => $decision->toolName,
                    'duration_ms' => $durationMs,
                    'next_action' => $result->nextAction->value,
                ],
            ]);

            return $result;
        } catch (Throwable $exception) {
            $durationMs = $this->durationMs($startedAt);
            $status = $this->statusForException($exception);

            $execution->forceFill([
                'status' => $status,
                'duration_ms' => $durationMs,
                'error_message' => $exception->getMessage(),
                'metadata' => array_merge($execution->metadata ?? [], [
                    'error' => $this->safeArray([
                        'message' => $exception->getMessage(),
                        'exception' => $exception::class,
                    ]),
                ]),
            ])->save();

            $this->events->record($context->tenant->getKey(), 'tool_failed', [
                'whatsapp_line_id' => $context->line->getKey(),
                'conversation_id' => $context->conversation->getKey(),
                'conversation_message_id' => $context->triggeringMessage->getKey(),
                'payload' => [
                    'tool_execution_id' => $execution->getKey(),
                    'tool_name' => $decision->toolName,
                    'duration_ms' => $durationMs,
                    'status' => $status,
                    'error' => $exception->getMessage(),
                    'exception' => $exception::class,
                ],
            ]);

            throw $exception;
        }
    }

    protected function executeWithTimeout(ToolInterface $tool, ToolInvocation $invocation, int $timeoutSeconds): ToolResult
    {
        if ($timeoutSeconds <= 0 || ! function_exists('pcntl_alarm') || ! function_exists('pcntl_signal')) {
            return $tool->execute($invocation);
        }

        $previousAsyncSignals = function_exists('pcntl_async_signals')
            ? pcntl_async_signals(true)
            : null;

        $previousHandler = function_exists('pcntl_signal_get_handler')
            ? pcntl_signal_get_handler(SIGALRM)
            : SIG_DFL;

        pcntl_signal(SIGALRM, function () use ($invocation, $timeoutSeconds): void {
            throw new ToolTimeoutException(sprintf(
                'The tool "%s" exceeded the configured timeout of %d seconds.',
                $invocation->tool->name(),
                $timeoutSeconds,
            ));
        });

        pcntl_alarm($timeoutSeconds);

        try {
            return $tool->execute($invocation);
        } finally {
            pcntl_alarm(0);
            pcntl_signal(SIGALRM, $previousHandler);

            if ($previousAsyncSignals !== null) {
                pcntl_async_signals($previousAsyncSignals);
            }
        }
    }

    protected function statusForException(Throwable $exception): string
    {
        return match (true) {
            $exception instanceof ToolTimeoutException => 'timed_out',
            $exception instanceof ToolNotEnabledException => 'not_enabled',
            $exception instanceof ToolNotFoundException => 'unknown_tool',
            $exception instanceof ToolNotImplementedException => 'not_implemented',
            default => 'failed',
        };
    }

    protected function durationMs(float $startedAt): int
    {
        return (int) round((microtime(true) - $startedAt) * 1000);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    protected function safeArray(array $payload): array
    {
        $sanitized = $this->sanitizer->sanitizeForStorage($payload);

        return is_array($sanitized) ? $sanitized : ['value' => $sanitized];
    }
}
