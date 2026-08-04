<?php

namespace Tests\Unit\Domain\Agent;

use App\Domain\Agent\AgentDecisionOutcome;
use App\Domain\Agent\AgentDecisionPolicy;
use App\Domain\Agent\AgentPackRegistry;
use App\Domain\Agent\DTO\AgentContext;
use App\Domain\Agent\DTO\AgentDecision;
use App\Models\AgentConfig;
use App\Models\Conversation;
use App\Models\ConversationMessage;
use App\Models\ConversationState;
use App\Models\Tenant;
use App\Models\WhatsAppLine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AgentDecisionPolicyTest extends TestCase
{
    use RefreshDatabase;

    private AgentDecisionPolicy $policy;

    protected function setUp(): void
    {
        parent::setUp();

        $this->policy = new AgentDecisionPolicy(new AgentPackRegistry());
    }

    /**
     * design.md D8 / task 4.0: the service_scheduling intent authorizes the
     * three scheduling tools introduced by this change.
     */
    public function test_call_tool_is_permitted_for_each_scheduling_tool_under_the_service_scheduling_intent(): void
    {
        foreach (['get_service_details', 'check_availability', 'create_appointment'] as $toolName) {
            $decision = $this->policy->apply(
                $this->context(),
                new AgentDecision(
                    outcome: AgentDecisionOutcome::CallTool,
                    toolName: $toolName,
                    toolArguments: ['item_id' => 1],
                    currentIntent: 'service_scheduling',
                ),
            );

            $this->assertTrue($decision->policyAllowed, "Expected {$toolName} to be allowed under service_scheduling.");
            $this->assertSame(AgentDecisionOutcome::CallTool, $decision->outcome);
            $this->assertSame($toolName, $decision->toolName);
            $this->assertSame('service_scheduling', $decision->currentIntent);
        }
    }

    /**
     * design.md D8: this is an accepted limitation, not a bug. A single
     * processing cycle cannot chain get_service_details -> check_availability
     * once retrieved_context is already populated; the flow must continue on
     * the next inbound message.
     */
    public function test_a_second_call_tool_is_still_blocked_when_retrieved_context_is_already_present(): void
    {
        $decision = $this->policy->apply(
            $this->context(retrievedContext: [
                ['id' => 1, 'name' => 'Botox'],
            ]),
            new AgentDecision(
                outcome: AgentDecisionOutcome::CallTool,
                toolName: 'check_availability',
                toolArguments: ['item_id' => 1, 'date' => '2026-08-10'],
                currentIntent: 'service_scheduling',
            ),
        );

        $this->assertFalse($decision->policyAllowed);
        $this->assertSame(AgentDecisionOutcome::RequestHandoff, $decision->outcome);
        $this->assertSame('Follow-up turns with retrieved context cannot call another tool.', $decision->policyBlockedReason);
    }

    public function test_a_scheduling_tool_call_without_the_service_scheduling_intent_forces_handoff(): void
    {
        $decision = $this->policy->apply(
            $this->context(),
            new AgentDecision(
                outcome: AgentDecisionOutcome::CallTool,
                toolName: 'check_availability',
                toolArguments: ['item_id' => 1, 'date' => '2026-08-10'],
                currentIntent: 'knowledge_lookup',
            ),
        );

        $this->assertFalse($decision->policyAllowed);
        $this->assertSame(AgentDecisionOutcome::RequestHandoff, $decision->outcome);
        $this->assertSame(
            'The intent "knowledge_lookup" does not allow the tool "check_availability".',
            $decision->policyBlockedReason,
        );
    }

    /**
     * @param  array<int, array<string, mixed>>  $retrievedContext
     */
    private function context(array $retrievedContext = []): AgentContext
    {
        return new AgentContext(
            tenant: new Tenant(),
            line: new WhatsAppLine(),
            conversation: new Conversation(),
            state: new ConversationState(['collected_data' => []]),
            agentConfig: new AgentConfig(['settings' => []]),
            triggeringMessage: new ConversationMessage(),
            recentMessages: [],
            enabledTools: [],
            retrievedContext: $retrievedContext,
        );
    }
}
