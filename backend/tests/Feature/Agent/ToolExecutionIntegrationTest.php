<?php

namespace Tests\Feature\Agent;

use App\Domain\Agent\AgentContextLoader;
use App\Domain\Agent\AgentDecisionApplier;
use App\Domain\Agent\Contracts\AgentRuntimeInterface;
use App\Domain\Conversations\Contracts\ConversationLockManager;
use App\Domain\Conversations\Contracts\ConversationStateRepository;
use App\Domain\Tools\Contracts\ToolInterface;
use App\Domain\Tools\DTO\ToolDefinition;
use App\Domain\Tools\DTO\ToolInvocation;
use App\Domain\Tools\DTO\ToolResult;
use App\Domain\Tools\ToolRegistry;
use App\Domain\Tools\ToolNextAction;
use App\Enums\ConversationStatus;
use App\Enums\MessageDirection;
use App\Jobs\ProcessIncomingMessageJob;
use App\Models\AgentConfig;
use App\Models\ApiCredential;
use App\Models\Conversation;
use App\Models\ConversationMessage;
use App\Models\Tenant;
use App\Models\TenantToolConfig;
use App\Models\ToolExecution;
use App\Models\WhatsAppLine;
use App\Support\AgentEvents\AgentEventRecorder;
use App\Support\Tenancy\TenantContextManager;
use App\Support\Tenancy\TenantScopeKey;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ToolExecutionIntegrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_tool_registry_exposes_the_required_mvp_tool_set(): void
    {
        $definitions = collect(app(ToolRegistry::class)->all())
            ->map(fn (ToolInterface $tool): ToolDefinition => $tool->definition())
            ->keyBy(fn (ToolDefinition $definition): string => $definition->name);

        $this->assertSame([
            'create_lead',
            'save_customer_data',
            'handoff_to_human',
            'search_inventory',
            'search_faq',
        ], $definitions->keys()->all());
        $this->assertTrue($definitions['create_lead']->isImplemented);
        $this->assertTrue($definitions['save_customer_data']->isImplemented);
        $this->assertTrue($definitions['handoff_to_human']->isImplemented);
        $this->assertFalse($definitions['search_inventory']->isImplemented);
        $this->assertFalse($definitions['search_faq']->isImplemented);
        $this->assertSame(6, $definitions['search_inventory']->implementationPhase);
        $this->assertSame(6, $definitions['search_faq']->implementationPhase);
    }

    public function test_processing_job_executes_save_customer_data_and_logs_the_tool_execution(): void
    {
        [$tenant, $line, $conversation, $message] = $this->conversationFixtures();

        $this->createWhatsappCredential($tenant);
        $this->enableTool($tenant, $line, 'save_customer_data');

        Http::fake([
            'https://api.openai.com/v1/responses' => Http::response([
                'output_text' => json_encode([
                    'outcome' => 'call_tool',
                    'reply_text' => '',
                    'handoff_reason' => '',
                    'tool_name' => 'save_customer_data',
                    'tool_arguments_json' => json_encode([
                        'customer_data' => [
                            'customer_name' => 'Ana Cliente',
                            'city' => 'Bogota',
                            'budget' => '500000',
                        ],
                        'reply_text' => 'Perfecto, ya registré tus datos.',
                    ], JSON_THROW_ON_ERROR),
                    'missing_information_fields' => [],
                    'current_intent' => 'lead_capture',
                    'internal_notes' => 'Persist the customer data before continuing.',
                ], JSON_THROW_ON_ERROR),
            ], 200),
            'https://graph.facebook.com/*' => Http::response([
                'messages' => [
                    ['id' => 'wamid.outbound.300'],
                ],
            ], 200),
        ]);

        config()->set('services.openai.api_key', 'test-openai-key');

        $this->runProcessingJob($tenant, $conversation, $message);

        $conversation->refresh();
        $state = $conversation->state()->firstOrFail();
        $execution = ToolExecution::query()->firstOrFail();
        $outboundMessage = ConversationMessage::query()
            ->where('conversation_id', $conversation->id)
            ->where('direction', MessageDirection::Outbound)
            ->latest('id')
            ->firstOrFail();

        $this->assertSame(ConversationStatus::WaitingCustomer, $conversation->status);
        $this->assertSame('call_tool', $state->last_agent_action);
        $this->assertSame('lead_capture', $state->current_intent);
        $this->assertSame('Bogota', $state->collected_data['city']);
        $this->assertSame('500000', $state->collected_data['budget']);
        $this->assertSame('Perfecto, ya registré tus datos.', $outboundMessage->body);
        $this->assertSame('succeeded', $execution->status);
        $this->assertSame('save_customer_data', $execution->tool_name);
        $this->assertSame(['customer_name', 'city', 'budget'], $execution->output_summary['saved_fields']);
        $this->assertDatabaseHas('agent_events', [
            'tenant_id' => $tenant->id,
            'event_type' => 'tool_called',
            'conversation_message_id' => $message->id,
        ]);
        $this->assertDatabaseHas('agent_events', [
            'tenant_id' => $tenant->id,
            'event_type' => 'tool_succeeded',
            'conversation_message_id' => $message->id,
        ]);
    }

    public function test_processing_job_falls_back_when_the_runtime_calls_a_tool_that_is_not_enabled(): void
    {
        [$tenant, $line, $conversation, $message] = $this->conversationFixtures();

        Http::fake([
            'https://api.openai.com/v1/responses' => Http::response([
                'output_text' => json_encode([
                    'outcome' => 'call_tool',
                    'reply_text' => '',
                    'handoff_reason' => '',
                    'tool_name' => 'create_lead',
                    'tool_arguments_json' => json_encode([
                        'lead_data' => [
                            'customer_name' => 'Ana Cliente',
                        ],
                    ], JSON_THROW_ON_ERROR),
                    'missing_information_fields' => [],
                    'current_intent' => 'lead_capture',
                    'internal_notes' => 'Try to create the lead.',
                ], JSON_THROW_ON_ERROR),
            ], 200),
        ]);

        config()->set('services.openai.api_key', 'test-openai-key');

        $this->runProcessingJob($tenant, $conversation, $message);

        $conversation->refresh();
        $execution = ToolExecution::query()->firstOrFail();

        $this->assertSame(ConversationStatus::HumanHandoff, $conversation->status);
        $this->assertSame('not_enabled', $execution->status);
        $this->assertSame('The tool "create_lead" is not enabled for the current tenant or WhatsApp line.', $execution->error_message);
        $this->assertDatabaseHas('handoff_requests', [
            'tenant_id' => $tenant->id,
            'conversation_id' => $conversation->id,
            'reason' => 'The tool "create_lead" is not enabled for the current tenant or WhatsApp line.',
        ]);
        $this->assertDatabaseHas('agent_events', [
            'tenant_id' => $tenant->id,
            'event_type' => 'tool_failed',
            'conversation_message_id' => $message->id,
        ]);
    }

    public function test_processing_job_times_out_long_running_tools_and_logs_the_failure(): void
    {
        [$tenant, $line, $conversation, $message] = $this->conversationFixtures();

        app()->instance(ToolRegistry::class, new ToolRegistry([
            new SlowTimeoutTool(),
        ]));

        $this->enableTool($tenant, $line, 'slow_timeout_tool', timeoutSeconds: 1);

        Http::fake([
            'https://api.openai.com/v1/responses' => Http::response([
                'output_text' => json_encode([
                    'outcome' => 'call_tool',
                    'reply_text' => '',
                    'handoff_reason' => '',
                    'tool_name' => 'slow_timeout_tool',
                    'tool_arguments_json' => '{}',
                    'missing_information_fields' => [],
                    'current_intent' => 'timeout_probe',
                    'internal_notes' => 'Execute the slow tool.',
                ], JSON_THROW_ON_ERROR),
            ], 200),
        ]);

        config()->set('services.openai.api_key', 'test-openai-key');

        $this->runProcessingJob($tenant, $conversation, $message);

        $conversation->refresh();
        $execution = ToolExecution::query()->firstOrFail();

        $this->assertSame(ConversationStatus::HumanHandoff, $conversation->status);
        $this->assertSame('timed_out', $execution->status);
        $this->assertStringContainsString('exceeded the configured timeout of 1 seconds', $execution->error_message ?? '');
        $this->assertDatabaseHas('agent_events', [
            'tenant_id' => $tenant->id,
            'event_type' => 'tool_failed',
            'conversation_message_id' => $message->id,
        ]);
    }

    protected function runProcessingJob(Tenant $tenant, Conversation $conversation, ConversationMessage $message): void
    {
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
    }

    protected function enableTool(Tenant $tenant, WhatsAppLine $line, string $toolName, ?int $timeoutSeconds = null): void
    {
        TenantToolConfig::query()->create([
            'tenant_id' => $tenant->id,
            'whatsapp_line_id' => $line->id,
            'scope_type' => 'whatsapp_line',
            'scope_key' => TenantScopeKey::forWhatsAppLine($line),
            'tool_name' => $toolName,
            'enabled' => true,
            'timeout_seconds' => $timeoutSeconds,
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
            'provider_message_id' => 'wamid.inbound.400',
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

class SlowTimeoutTool implements ToolInterface
{
    public function definition(): ToolDefinition
    {
        return new ToolDefinition(
            name: 'slow_timeout_tool',
            description: 'A slow test tool used to validate timeout handling.',
            inputSchema: [
                'type' => 'object',
                'additionalProperties' => false,
                'properties' => [],
            ],
            outputSchema: [
                'type' => 'object',
                'additionalProperties' => false,
                'properties' => [
                    'ok' => [
                        'type' => 'boolean',
                    ],
                ],
            ],
            defaultTimeoutSeconds: 1,
            isImplemented: true,
            implementationPhase: 5,
        );
    }

    public function execute(ToolInvocation $invocation): ToolResult
    {
        sleep(2);

        return new ToolResult(
            nextAction: ToolNextAction::WaitForCustomer,
            outputSummary: [
                'ok' => true,
            ],
        );
    }
}
