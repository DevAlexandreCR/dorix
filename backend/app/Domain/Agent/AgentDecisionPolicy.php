<?php

namespace App\Domain\Agent;

use App\Domain\Agent\DTO\AgentContext;
use App\Domain\Agent\DTO\AgentDecision;

class AgentDecisionPolicy
{
    public function __construct(
        protected AgentPackRegistry $packs,
    ) {
    }

    public function apply(AgentContext $context, AgentDecision $decision): AgentDecision
    {
        $pack = $this->activePack($context);
        $normalizedIntent = $pack->normalizeIntent($decision->currentIntent);
        $intent = $pack->intent($normalizedIntent);
        $unknownIntent = trim($decision->currentIntent) === '' || $normalizedIntent !== trim(strtolower(str_replace('-', '_', $decision->currentIntent)));

        if ($decision->outcome === AgentDecisionOutcome::RequestHandoff) {
            return $this->fallbackHandoff(
                $decision,
                $normalizedIntent,
                'The model selected the internal request_handoff outcome.',
            );
        }

        if ($decision->outcome === AgentDecisionOutcome::Error) {
            return $this->fallbackHandoff(
                $decision,
                $normalizedIntent,
                'The model selected the error outcome.',
            );
        }

        if ($unknownIntent && ! $intent->allowsOutcome($decision->outcome)) {
            return $this->fallbackHandoff(
                $decision,
                $normalizedIntent,
                sprintf('The intent "%s" is not allowed in pack "%s".', $decision->currentIntent, $pack->key),
            );
        }

        if (! $intent->allowsOutcome($decision->outcome)) {
            return $this->fallbackHandoff(
                $decision,
                $normalizedIntent,
                sprintf('The intent "%s" does not allow the outcome "%s".', $normalizedIntent, $decision->outcome->value),
            );
        }

        if ($decision->outcome === AgentDecisionOutcome::SendMessage
            && $intent->requiresRetrievedContextForSendMessage
            && $context->retrievedContext === []) {
            return $this->fallbackHandoff(
                $decision,
                $normalizedIntent,
                sprintf('The intent "%s" requires retrieved context before send_message.', $normalizedIntent),
            );
        }

        if ($decision->outcome === AgentDecisionOutcome::CallTool && $context->retrievedContext !== []) {
            return $this->fallbackHandoff(
                $decision,
                $normalizedIntent,
                'Follow-up turns with retrieved context cannot call another tool.',
            );
        }

        if ($decision->outcome === AgentDecisionOutcome::CallTool) {
            if (! $intent->allowsTool($decision->toolName)) {
                return $this->fallbackHandoff(
                    $decision,
                    $normalizedIntent,
                    sprintf('The intent "%s" does not allow the tool "%s".', $normalizedIntent, $decision->toolName),
                );
            }

            if ($normalizedIntent === 'handoff_requested' && $decision->toolName !== 'handoff_to_human') {
                return $this->fallbackHandoff(
                    $decision,
                    $normalizedIntent,
                    'The handoff_requested intent must call the handoff_to_human tool.',
                );
            }

            if ($normalizedIntent === 'unsupported' && $decision->toolName !== 'handoff_to_human') {
                return $this->fallbackHandoff(
                    $decision,
                    $normalizedIntent,
                    'Unsupported requests can only call the handoff_to_human tool.',
                );
            }

            if ($decision->toolName === 'create_lead') {
                $missingFields = $this->missingLeadFields($context, $decision, $intent);

                if ($missingFields !== []) {
                    return new AgentDecision(
                        outcome: AgentDecisionOutcome::RequestMissingInformation,
                        replyText: $this->missingInformationReplyText($missingFields),
                        handoffReason: '',
                        toolName: '',
                        toolArguments: [],
                        missingInformationFields: $missingFields,
                        currentIntent: $normalizedIntent,
                        internalNotes: $decision->internalNotes,
                        policyAllowed: false,
                        policyNormalizedIntent: $normalizedIntent,
                        policyBlockedReason: sprintf('The create_lead tool requires the fields: %s.', implode(', ', $missingFields)),
                        policyReplacementOutcome: AgentDecisionOutcome::RequestMissingInformation,
                    );
                }
            }
        }

        return new AgentDecision(
            outcome: $decision->outcome,
            replyText: $decision->replyText,
            handoffReason: $decision->handoffReason,
            toolName: $decision->toolName,
            toolArguments: $decision->toolArguments,
            missingInformationFields: $decision->missingInformationFields,
            currentIntent: $normalizedIntent,
            internalNotes: $decision->internalNotes,
            policyAllowed: ! $unknownIntent,
            policyNormalizedIntent: $normalizedIntent,
            policyBlockedReason: $unknownIntent
                ? sprintf('The intent "%s" was normalized to "%s".', $decision->currentIntent, $normalizedIntent)
                : '',
            policyReplacementOutcome: null,
        );
    }

    public function activePack(AgentContext $context): AgentPack
    {
        return $this->packs->resolve(AgentConfigSettings::agentPackKey($context->agentConfig));
    }

    protected function fallbackHandoff(AgentDecision $decision, string $normalizedIntent, string $reason): AgentDecision
    {
        return new AgentDecision(
            outcome: AgentDecisionOutcome::RequestHandoff,
            replyText: $decision->replyText,
            handoffReason: $decision->handoffReason !== '' ? $decision->handoffReason : $reason,
            toolName: '',
            toolArguments: [],
            missingInformationFields: [],
            currentIntent: $normalizedIntent,
            internalNotes: $decision->internalNotes,
            policyAllowed: false,
            policyNormalizedIntent: $normalizedIntent,
            policyBlockedReason: $reason,
            policyReplacementOutcome: AgentDecisionOutcome::RequestHandoff,
        );
    }

    /**
     * @return array<int, string>
     */
    protected function missingLeadFields(AgentContext $context, AgentDecision $decision, IntentDefinition $intent): array
    {
        $stateData = is_array($context->state->collected_data) ? $context->state->collected_data : [];
        $leadData = $decision->toolArguments['lead_data'] ?? [];
        $leadData = is_array($leadData) ? $leadData : [];
        $merged = array_merge($stateData, $leadData);

        return array_values(array_filter(
            $intent->requiredFields,
            static fn (string $field): bool => ! array_key_exists($field, $merged)
                || ! is_string($merged[$field])
                || trim((string) $merged[$field]) === '',
        ));
    }

    /**
     * @param  array<int, string>  $missingFields
     */
    protected function missingInformationReplyText(array $missingFields): string
    {
        return __('api.agent.missing_required_fields_message', [
            'fields' => implode(', ', $missingFields),
        ]);
    }
}
