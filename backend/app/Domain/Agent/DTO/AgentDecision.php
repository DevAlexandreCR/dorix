<?php

namespace App\Domain\Agent\DTO;

use App\Domain\Agent\AgentDecisionOutcome;
use App\Domain\Agent\Exceptions\InvalidAgentDecisionException;

final readonly class AgentDecision
{
    /**
     * @param  array<int, string>  $missingInformationFields
     * @param  array<string, mixed>  $toolArguments
     */
    public function __construct(
        public AgentDecisionOutcome $outcome,
        public string $replyText = '',
        public string $handoffReason = '',
        public string $toolName = '',
        public array $toolArguments = [],
        public array $missingInformationFields = [],
        public string $currentIntent = '',
        public string $internalNotes = '',
        public bool $policyAllowed = true,
        public string $policyNormalizedIntent = '',
        public string $policyBlockedReason = '',
        public ?AgentDecisionOutcome $policyReplacementOutcome = null,
    ) {
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public static function fromArray(array $payload): self
    {
        $outcomeValue = $payload['outcome'] ?? null;

        if (! is_string($outcomeValue) || ($outcome = AgentDecisionOutcome::tryFrom($outcomeValue)) === null) {
            throw new InvalidAgentDecisionException(__('api.agent.decision_invalid_outcome'));
        }

        $replyText = self::normalizeString($payload['reply_text'] ?? '');
        $handoffReason = self::normalizeString($payload['handoff_reason'] ?? '');
        $toolName = self::normalizeString($payload['tool_name'] ?? '');
        $toolArguments = self::decodeToolArguments($payload['tool_arguments_json'] ?? '{}');
        $missingInformationFields = self::normalizeStringArray($payload['missing_information_fields'] ?? []);
        $currentIntent = self::normalizeString($payload['current_intent'] ?? '');
        $internalNotes = self::normalizeString($payload['internal_notes'] ?? '');

        if (in_array($outcome, [
            AgentDecisionOutcome::SendMessage,
            AgentDecisionOutcome::RequestMissingInformation,
        ], true) && $replyText === '') {
            throw new InvalidAgentDecisionException(__('api.agent.decision_reply_required'));
        }

        if ($outcome === AgentDecisionOutcome::CallTool && $toolName === '') {
            throw new InvalidAgentDecisionException(__('api.agent.decision_tool_required'));
        }

        return new self(
            outcome: $outcome,
            replyText: $replyText,
            handoffReason: $handoffReason,
            toolName: $toolName,
            toolArguments: $toolArguments,
            missingInformationFields: $missingInformationFields,
            currentIntent: $currentIntent,
            internalNotes: $internalNotes,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'outcome' => $this->outcome->value,
            'reply_text' => $this->replyText,
            'handoff_reason' => $this->handoffReason,
            'tool_name' => $this->toolName,
            'tool_arguments' => $this->toolArguments,
            'missing_information_fields' => $this->missingInformationFields,
            'current_intent' => $this->currentIntent,
            'internal_notes' => $this->internalNotes,
            'policy' => [
                'allowed' => $this->policyAllowed,
                'normalized_intent' => $this->policyNormalizedIntent !== ''
                    ? $this->policyNormalizedIntent
                    : $this->currentIntent,
                'blocked_reason' => $this->policyBlockedReason,
                'replacement_outcome' => $this->policyReplacementOutcome?->value,
            ],
        ];
    }

    protected static function normalizeString(mixed $value): string
    {
        return is_string($value) ? trim($value) : '';
    }

    /**
     * @param  mixed  $value
     * @return array<int, string>
     */
    protected static function normalizeStringArray(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        return array_values(array_filter(array_map(
            static fn (mixed $item): string => is_string($item) ? trim($item) : '',
            $value,
        ), static fn (string $item): bool => $item !== ''));
    }

    /**
     * @return array<string, mixed>
     */
    protected static function decodeToolArguments(mixed $value): array
    {
        if (! is_string($value) || trim($value) === '') {
            return [];
        }

        $decoded = json_decode($value, true);

        if (! is_array($decoded)) {
            throw new InvalidAgentDecisionException(__('api.agent.decision_arguments_invalid'));
        }

        return $decoded;
    }
}
