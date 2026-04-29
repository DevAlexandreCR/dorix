<?php

namespace Tests\Feature\Agent;

use App\Domain\Agent\AgentContextLoader;
use App\Domain\Agent\AgentDecisionApplier;
use App\Domain\Agent\AgentDecisionOutcome;
use App\Domain\Agent\Contracts\AgentRuntimeInterface;
use App\Domain\Conversations\Contracts\ConversationLockManager;
use App\Domain\Conversations\Contracts\ConversationStateRepository;
use App\Enums\ConversationStatus;
use App\Enums\MessageDirection;
use App\Jobs\ProcessIncomingMessageJob;
use App\Models\AgentConfig;
use App\Models\ApiCredential;
use App\Models\Conversation;
use App\Models\ConversationMessage;
use App\Models\Tenant;
use App\Models\TenantToolConfig;
use App\Models\WhatsAppLine;
use App\Support\AgentEvents\AgentEventRecorder;
use App\Support\Tenancy\TenantContextManager;
use App\Support\Tenancy\TenantScopeKey;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AgentRuntimeIntegrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_runtime_maps_a_send_message_decision_from_openai(): void
    {
        [$tenant, $line, $conversation, $message] = $this->conversationFixtures();
        $state = app(ConversationStateRepository::class)->getOrCreate($conversation);

        Http::fake([
            'https://api.openai.com/v1/responses' => Http::response([
                'output_text' => json_encode([
                    'outcome' => 'send_message',
                    'reply_text' => 'Hola, te ayudo con eso.',
                    'handoff_reason' => '',
                    'tool_name' => '',
                    'tool_arguments_json' => '{}',
                    'missing_information_fields' => [],
                    'current_intent' => 'customer_support',
                    'internal_notes' => 'Respond directly.',
                ], JSON_THROW_ON_ERROR),
            ], 200),
        ]);

        config()->set('services.openai.api_key', 'test-openai-key');

        $decision = app(AgentRuntimeInterface::class)->run(
            app(AgentContextLoader::class)->load($conversation, $message->id, $state)
        );

        $this->assertSame(AgentDecisionOutcome::SendMessage, $decision->outcome);
        $this->assertSame('Hola, te ayudo con eso.', $decision->replyText);
        $this->assertSame('customer_support', $decision->currentIntent);
        $this->assertDatabaseHas('agent_events', [
            'tenant_id' => $tenant->id,
            'event_type' => 'agent_started',
            'conversation_message_id' => $message->id,
        ]);
        $this->assertDatabaseHas('agent_events', [
            'tenant_id' => $tenant->id,
            'event_type' => 'agent_response_generated',
            'conversation_message_id' => $message->id,
        ]);
    }

    public function test_runtime_maps_a_call_tool_decision_and_decodes_arguments(): void
    {
        [$tenant, $line, $conversation, $message] = $this->conversationFixtures();
        $state = app(ConversationStateRepository::class)->getOrCreate($conversation);

        TenantToolConfig::query()->create([
            'tenant_id' => $tenant->id,
            'whatsapp_line_id' => null,
            'scope_type' => 'tenant',
            'scope_key' => TenantScopeKey::forTenant($tenant),
            'tool_name' => 'search_faq',
            'enabled' => true,
        ]);

        Http::fake([
            'https://api.openai.com/v1/responses' => Http::response([
                'output_text' => json_encode([
                    'outcome' => 'call_tool',
                    'reply_text' => '',
                    'handoff_reason' => '',
                    'tool_name' => 'search_faq',
                    'tool_arguments_json' => '{"question":"horarios"}',
                    'missing_information_fields' => [],
                    'current_intent' => 'faq_lookup',
                    'internal_notes' => 'Need product FAQ lookup.',
                ], JSON_THROW_ON_ERROR),
            ], 200),
        ]);

        config()->set('services.openai.api_key', 'test-openai-key');

        $decision = app(AgentRuntimeInterface::class)->run(
            app(AgentContextLoader::class)->load($conversation, $message->id, $state)
        );

        $this->assertSame(AgentDecisionOutcome::CallTool, $decision->outcome);
        $this->assertSame('search_faq', $decision->toolName);
        $this->assertSame(['question' => 'horarios'], $decision->toolArguments);
    }

    public function test_processing_job_sends_the_runtime_reply_and_moves_to_waiting_customer(): void
    {
        [$tenant, $line, $conversation, $message] = $this->conversationFixtures();
        $this->createWhatsappCredential($tenant);

        Http::fake([
            'https://api.openai.com/v1/responses' => Http::response([
                'output_text' => json_encode([
                    'outcome' => 'send_message',
                    'reply_text' => 'Claro, cuéntame qué producto buscas.',
                    'handoff_reason' => '',
                    'tool_name' => '',
                    'tool_arguments_json' => '{}',
                    'missing_information_fields' => [],
                    'current_intent' => 'inventory_intake',
                    'internal_notes' => 'Reply and wait.',
                ], JSON_THROW_ON_ERROR),
            ], 200),
            'https://graph.facebook.com/*' => Http::response([
                'messages' => [
                    ['id' => 'wamid.outbound.200'],
                ],
            ], 200),
        ]);

        config()->set('services.openai.api_key', 'test-openai-key');

        $job = new ProcessIncomingMessageJob($tenant->id, $conversation->id, $message->id);
        $job->handle(
            app(TenantContextManager::class),
            app(AgentEventRecorder::class),
            app(ConversationLockManager::class),
            app(ConversationStateRepository::class),
            app(AgentContextLoader::class),
            app(AgentRuntimeInterface::class),
            app(AgentDecisionApplier::class),
        );

        $conversation->refresh();
        $outboundMessage = ConversationMessage::query()
            ->where('conversation_id', $conversation->id)
            ->where('direction', MessageDirection::Outbound)
            ->latest('id')
            ->firstOrFail();

        $state = $conversation->state()->firstOrFail();

        $this->assertSame(ConversationStatus::WaitingCustomer, $conversation->status);
        $this->assertSame('Claro, cuéntame qué producto buscas.', $outboundMessage->body);
        $this->assertSame('accepted', $outboundMessage->status);
        $this->assertSame('send_message', $state->last_agent_action);
        $this->assertSame('inventory_intake', $state->current_intent);
        $this->assertDatabaseHas('agent_events', [
            'tenant_id' => $tenant->id,
            'event_type' => 'whatsapp_message_sent',
            'conversation_message_id' => $outboundMessage->id,
        ]);
        $this->assertDatabaseHas('agent_events', [
            'tenant_id' => $tenant->id,
            'event_type' => 'processing_job_completed',
            'conversation_message_id' => $message->id,
        ]);
    }

    public function test_processing_job_creates_a_handoff_request_when_runtime_requests_handoff(): void
    {
        [$tenant, $line, $conversation, $message] = $this->conversationFixtures();

        Http::fake([
            'https://api.openai.com/v1/responses' => Http::response([
                'output_text' => json_encode([
                    'outcome' => 'request_handoff',
                    'reply_text' => '',
                    'handoff_reason' => 'Customer is asking for legal confirmation.',
                    'tool_name' => '',
                    'tool_arguments_json' => '{}',
                    'missing_information_fields' => [],
                    'current_intent' => 'legal_escalation',
                    'internal_notes' => 'Escalate to a human.',
                ], JSON_THROW_ON_ERROR),
            ], 200),
        ]);

        config()->set('services.openai.api_key', 'test-openai-key');

        $job = new ProcessIncomingMessageJob($tenant->id, $conversation->id, $message->id);
        $job->handle(
            app(TenantContextManager::class),
            app(AgentEventRecorder::class),
            app(ConversationLockManager::class),
            app(ConversationStateRepository::class),
            app(AgentContextLoader::class),
            app(AgentRuntimeInterface::class),
            app(AgentDecisionApplier::class),
        );

        $conversation->refresh();

        $this->assertSame(ConversationStatus::HumanHandoff, $conversation->status);
        $this->assertDatabaseHas('handoff_requests', [
            'tenant_id' => $tenant->id,
            'conversation_id' => $conversation->id,
            'requested_by_type' => 'runtime',
            'status' => 'requested',
            'reason' => 'Customer is asking for legal confirmation.',
        ]);
        $this->assertDatabaseHas('agent_events', [
            'tenant_id' => $tenant->id,
            'event_type' => 'handoff_triggered',
            'conversation_message_id' => $message->id,
        ]);
    }

    public function test_processing_job_falls_back_to_human_handoff_when_the_provider_fails(): void
    {
        [$tenant, $line, $conversation, $message] = $this->conversationFixtures();

        Http::fake([
            'https://api.openai.com/v1/responses' => Http::response([
                'error' => [
                    'message' => 'provider unavailable',
                ],
            ], 500),
        ]);

        config()->set('services.openai.api_key', 'test-openai-key');

        $job = new ProcessIncomingMessageJob($tenant->id, $conversation->id, $message->id);
        $job->handle(
            app(TenantContextManager::class),
            app(AgentEventRecorder::class),
            app(ConversationLockManager::class),
            app(ConversationStateRepository::class),
            app(AgentContextLoader::class),
            app(AgentRuntimeInterface::class),
            app(AgentDecisionApplier::class),
        );

        $conversation->refresh();

        $this->assertSame(ConversationStatus::HumanHandoff, $conversation->status);
        $this->assertDatabaseHas('handoff_requests', [
            'tenant_id' => $tenant->id,
            'conversation_id' => $conversation->id,
            'requested_by_type' => 'runtime',
            'status' => 'requested',
            'reason' => 'OpenAI runtime request failed with status 500.',
        ]);
        $this->assertDatabaseHas('agent_events', [
            'tenant_id' => $tenant->id,
            'event_type' => 'agent_runtime_failed',
            'conversation_message_id' => $message->id,
        ]);
        $this->assertDatabaseHas('agent_events', [
            'tenant_id' => $tenant->id,
            'event_type' => 'handoff_triggered',
            'conversation_message_id' => $message->id,
        ]);
    }

    /**
     * @return array{0: Tenant, 1: WhatsAppLine, 2: Conversation, 3: ConversationMessage}
     */
    protected function conversationFixtures(): array
    {
        $tenant = Tenant::query()->create([
            'name' => 'Acme',
            'slug' => 'acme',
        ]);

        $line = WhatsAppLine::query()->create([
            'tenant_id' => $tenant->id,
            'name' => 'Primary Line',
            'phone_number_id' => 'acme-phone-number-id',
            'display_phone_number' => '+573001112233',
            'status' => 'active',
            'is_enabled' => true,
        ]);

        AgentConfig::query()->create([
            'tenant_id' => $tenant->id,
            'whatsapp_line_id' => null,
            'scope_type' => 'tenant',
            'scope_key' => TenantScopeKey::forTenant($tenant),
            'name' => 'Default Agent',
            'model' => 'gpt-5.1',
            'prompt_version' => 'v1',
            'is_active' => true,
            'settings' => [
                'automation_enabled' => true,
            ],
        ]);

        $conversation = Conversation::query()->create([
            'tenant_id' => $tenant->id,
            'whatsapp_line_id' => $line->id,
            'contact_phone' => '573001234567',
            'contact_name' => 'Ana Cliente',
            'status' => ConversationStatus::BotActive,
            'last_message_at' => now(),
            'last_customer_message_at' => now(),
        ]);

        $message = ConversationMessage::query()->create([
            'tenant_id' => $tenant->id,
            'conversation_id' => $conversation->id,
            'direction' => MessageDirection::Inbound,
            'message_type' => 'text',
            'body' => 'Hola, necesito ayuda.',
            'provider_message_id' => 'wamid.inbound.100',
            'status' => 'received',
            'received_at' => now(),
        ]);

        return [$tenant, $line, $conversation, $message];
    }

    protected function createWhatsappCredential(Tenant $tenant): void
    {
        ApiCredential::query()->create([
            'tenant_id' => $tenant->id,
            'scope_type' => 'tenant',
            'scope_key' => TenantScopeKey::forTenant($tenant),
            'provider' => 'whatsapp_meta',
            'credential_key' => 'access_token',
            'secret' => 'test-whatsapp-token',
        ]);
    }
}
