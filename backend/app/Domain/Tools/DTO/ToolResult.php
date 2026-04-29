<?php

namespace App\Domain\Tools\DTO;

use App\Domain\Tools\ToolNextAction;

final readonly class ToolResult
{
    /**
     * @param  array<string, mixed>  $outputSummary
     * @param  array<string, mixed>  $stateUpdates
     * @param  array<string, mixed>  $conversationUpdates
     * @param  array<string, mixed>  $metadata
     */
    public function __construct(
        public ToolNextAction $nextAction = ToolNextAction::WaitForCustomer,
        public string $replyText = '',
        public string $handoffReason = '',
        public array $outputSummary = [],
        public array $stateUpdates = [],
        public array $conversationUpdates = [],
        public array $metadata = [],
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'next_action' => $this->nextAction->value,
            'reply_text' => $this->replyText,
            'handoff_reason' => $this->handoffReason,
            'output_summary' => $this->outputSummary,
            'state_updates' => $this->stateUpdates,
            'conversation_updates' => $this->conversationUpdates,
            'metadata' => $this->metadata,
        ];
    }
}
