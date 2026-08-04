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
use App\Domain\Tools\ToolNextAction;
use App\Domain\Tools\ToolRegistry;
use App\Enums\ConversationStatus;
use App\Enums\MessageDirection;
use App\Jobs\ProcessIncomingMessageJob;
use App\Models\AgentConfig;
use App\Models\ApiCredential;
use App\Models\Conversation;
use App\Models\ConversationMessage;
use App\Models\DataSource;
use App\Models\DataSourceChunk;
use App\Models\DataSourceImport;
use App\Models\Tenant;
use App\Models\TenantToolConfig;
use App\Models\ToolExecution;
use App\Models\UploadedFile;
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
            'search_knowledge',
            'get_service_details',
            'check_availability',
            'create_appointment',
        ], $definitions->keys()->all());
        $this->assertTrue($definitions['create_lead']->isImplemented);
        $this->assertTrue($definitions['save_customer_data']->isImplemented);
        $this->assertTrue($definitions['handoff_to_human']->isImplemented);
        $this->assertTrue($definitions['search_inventory']->isImplemented);
        $this->assertTrue($definitions['search_knowledge']->isImplemented);
        $this->assertTrue($definitions['get_service_details']->isImplemented);
        $this->assertTrue($definitions['check_availability']->isImplemented);
        $this->assertTrue($definitions['create_appointment']->isImplemented);
        $this->assertSame(6, $definitions['search_inventory']->implementationPhase);
        $this->assertSame(6, $definitions['search_knowledge']->implementationPhase);
        $this->assertSame(7, $definitions['get_service_details']->implementationPhase);
        $this->assertSame(7, $definitions['check_availability']->implementationPhase);
        $this->assertSame(7, $definitions['create_appointment']->implementationPhase);
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
                    'current_intent' => 'customer_data_capture',
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
        $this->assertSame('customer_data_capture', $state->current_intent);
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

    public function test_processing_job_blocks_create_lead_without_required_fields(): void
    {
        [$tenant, $line, $conversation, $message] = $this->conversationFixtures();
        $this->createWhatsappCredential($tenant);
        $this->enableTool($tenant, $line, 'create_lead');

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
                    'current_intent' => 'lead_qualification',
                    'internal_notes' => 'Create the lead now.',
                ], JSON_THROW_ON_ERROR),
            ], 200),
            'https://graph.facebook.com/*' => Http::response([
                'messages' => [
                    ['id' => 'wamid.outbound.lead-missing.300'],
                ],
            ], 200),
        ]);

        config()->set('services.openai.api_key', 'test-openai-key');

        $this->runProcessingJob($tenant, $conversation, $message);

        $conversation->refresh();
        $state = $conversation->state()->firstOrFail();
        $outboundMessage = ConversationMessage::query()
            ->where('conversation_id', $conversation->id)
            ->where('direction', MessageDirection::Outbound)
            ->latest('id')
            ->firstOrFail();

        $this->assertSame(ConversationStatus::WaitingCustomer, $conversation->status);
        $this->assertSame('request_missing_information', $state->last_agent_action);
        $this->assertSame('lead_qualification', $state->current_intent);
        $this->assertSame('Antes de continuar, compárteme lo siguiente: interest_summary.', $outboundMessage->body);
        $this->assertDatabaseCount('tool_executions', 0);
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
                            'interest_summary' => 'Mesa auxiliar',
                        ],
                    ], JSON_THROW_ON_ERROR),
                    'missing_information_fields' => [],
                    'current_intent' => 'lead_qualification',
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
        $this->assertSame(
            __('api.tools.not_enabled', ['tool_name' => 'create_lead']),
            $execution->error_message,
        );
        $this->assertDatabaseHas('handoff_requests', [
            'tenant_id' => $tenant->id,
            'conversation_id' => $conversation->id,
            'reason' => __('api.tools.not_enabled', ['tool_name' => 'create_lead']),
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
            new SlowTimeoutTool,
        ]));

        $this->enableTool($tenant, $line, 'save_customer_data', timeoutSeconds: 1);

        Http::fake([
            'https://api.openai.com/v1/responses' => Http::response([
                'output_text' => json_encode([
                    'outcome' => 'call_tool',
                    'reply_text' => '',
                    'handoff_reason' => '',
                    'tool_name' => 'save_customer_data',
                    'tool_arguments_json' => '{}',
                    'missing_information_fields' => [],
                    'current_intent' => 'customer_data_capture',
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
        $this->assertSame(
            __('api.tools.timeout', ['tool_name' => 'save_customer_data', 'timeout' => 1]),
            $execution->error_message,
        );
        $this->assertDatabaseHas('agent_events', [
            'tenant_id' => $tenant->id,
            'event_type' => 'tool_failed',
            'conversation_message_id' => $message->id,
        ]);
    }

    public function test_processing_job_executes_search_inventory_using_the_bound_excel_data_source(): void
    {
        [$tenant, $line, $conversation, $message] = $this->conversationFixtures();

        $this->createWhatsappCredential($tenant);
        [$dataSource, $import] = $this->createReadyExcelDataSource($tenant);

        DataSourceChunk::query()->create([
            'tenant_id' => $tenant->id,
            'data_source_id' => $dataSource->id,
            'data_source_import_id' => $import->id,
            'chunk_type' => 'table_row',
            'sheet_name' => 'Inventario',
            'row_start' => 2,
            'row_end' => 2,
            'section_key' => 'inventario-row-2',
            'content_text' => "Sku: SKU-100\nNombre: Mesa auxiliar\nCategoria: mobiliario\nPrecio: 159900\nMoneda: COP\nCantidad: 8\nDisponibilidad: Disponible",
            'structured_payload' => [
                'sku' => 'SKU-100',
                'nombre' => 'Mesa auxiliar',
                'categoria' => 'mobiliario',
                'precio' => '159900',
                'moneda' => 'COP',
                'cantidad' => '8',
                'disponibilidad' => 'Disponible',
            ],
            'metadata' => [
                'datasets' => ['inventory', 'knowledge'],
            ],
        ]);

        $this->enableTool($tenant, $line, 'search_inventory', bindings: [
            'data_source_id' => $dataSource->id,
        ]);

        Http::fake([
            'https://api.openai.com/v1/responses' => Http::sequence()
                ->push([
                    'output_text' => json_encode([
                        'outcome' => 'call_tool',
                        'reply_text' => '',
                        'handoff_reason' => '',
                        'tool_name' => 'search_inventory',
                        'tool_arguments_json' => json_encode([
                            'query' => 'mesa auxiliar',
                        ], JSON_THROW_ON_ERROR),
                        'missing_information_fields' => [],
                        'current_intent' => 'inventory_lookup',
                        'internal_notes' => 'Retrieve inventory context first.',
                    ], JSON_THROW_ON_ERROR),
                ], 200)
                ->push([
                    'output_text' => json_encode([
                        'outcome' => 'send_message',
                        'reply_text' => 'Sí, encontré la Mesa auxiliar. El archivo indica SKU-100, precio 159900 COP y disponibilidad Disponible.',
                        'handoff_reason' => '',
                        'tool_name' => '',
                        'tool_arguments_json' => '{}',
                        'missing_information_fields' => [],
                        'current_intent' => 'inventory_lookup',
                        'internal_notes' => 'Answer only from retrieved rows.',
                    ], JSON_THROW_ON_ERROR),
                ], 200),
            'https://graph.facebook.com/*' => Http::response([
                'messages' => [
                    ['id' => 'wamid.outbound.inventory.300'],
                ],
            ], 200),
        ]);

        config()->set('services.openai.api_key', 'test-openai-key');

        $this->runProcessingJob($tenant, $conversation, $message);

        $execution = ToolExecution::query()->firstOrFail();
        $outboundMessage = ConversationMessage::query()
            ->where('conversation_id', $conversation->id)
            ->where('direction', MessageDirection::Outbound)
            ->latest('id')
            ->firstOrFail();

        $this->assertSame('succeeded', $execution->status);
        $this->assertSame('search_inventory', $execution->tool_name);
        $this->assertSame(1, $execution->output_summary['match_count']);
        $this->assertStringContainsString('Mesa auxiliar', $execution->output_summary['matches'][0]['preview']);
        $this->assertArrayNotHasKey('structured_payload', $execution->output_summary['matches'][0]);
        $this->assertStringContainsString('Mesa auxiliar', $outboundMessage->body ?? '');
        $this->assertStringContainsString('SKU-100', $outboundMessage->body ?? '');
        $this->assertDatabaseHas('agent_events', [
            'tenant_id' => $tenant->id,
            'event_type' => 'tool_data_source_resolved',
            'conversation_message_id' => $message->id,
        ]);
        $this->assertDatabaseHas('agent_events', [
            'tenant_id' => $tenant->id,
            'event_type' => 'data_source_search_completed',
            'conversation_message_id' => $message->id,
        ]);
    }

    public function test_processing_job_executes_search_knowledge_using_the_bound_excel_data_source(): void
    {
        [$tenant, $line, $conversation, $message] = $this->conversationFixtures();

        $this->createWhatsappCredential($tenant);
        [$dataSource, $import] = $this->createReadyExcelDataSource($tenant);

        DataSourceChunk::query()->create([
            'tenant_id' => $tenant->id,
            'data_source_id' => $dataSource->id,
            'data_source_import_id' => $import->id,
            'chunk_type' => 'text_block',
            'sheet_name' => 'FAQ',
            'row_start' => 1,
            'row_end' => 3,
            'section_key' => 'faq-text',
            'content_text' => "Envios\n¿Hacen envíos nacionales?\nSí, hacemos envíos a todo Colombia.",
            'structured_payload' => [
                'topic' => 'envios',
                'question' => '¿Hacen envíos nacionales?',
                'answer' => 'Sí, hacemos envíos a todo Colombia.',
            ],
            'metadata' => [
                'datasets' => ['knowledge'],
            ],
        ]);

        $this->enableTool($tenant, $line, 'search_knowledge', bindings: [
            'data_source_id' => $dataSource->id,
        ]);

        Http::fake([
            'https://api.openai.com/v1/responses' => Http::sequence()
                ->push([
                    'output_text' => json_encode([
                        'outcome' => 'call_tool',
                        'reply_text' => '',
                        'handoff_reason' => '',
                        'tool_name' => 'search_knowledge',
                        'tool_arguments_json' => json_encode([
                            'question' => '¿Hacen envíos nacionales?',
                        ], JSON_THROW_ON_ERROR),
                        'missing_information_fields' => [],
                        'current_intent' => 'knowledge_lookup',
                        'internal_notes' => 'Retrieve documentary context first.',
                    ], JSON_THROW_ON_ERROR),
                ], 200)
                ->push([
                    'output_text' => json_encode([
                        'outcome' => 'send_message',
                        'reply_text' => 'Sí. Según la información cargada, hacen envíos a todo Colombia.',
                        'handoff_reason' => '',
                        'tool_name' => '',
                        'tool_arguments_json' => '{}',
                        'missing_information_fields' => [],
                        'current_intent' => 'knowledge_lookup',
                        'internal_notes' => 'Answer from retrieved context only.',
                    ], JSON_THROW_ON_ERROR),
                ], 200),
            'https://graph.facebook.com/*' => Http::response([
                'messages' => [
                    ['id' => 'wamid.outbound.faq.300'],
                ],
            ], 200),
        ]);

        config()->set('services.openai.api_key', 'test-openai-key');

        $this->runProcessingJob($tenant, $conversation, $message);

        $execution = ToolExecution::query()->firstOrFail();
        $outboundMessage = ConversationMessage::query()
            ->where('conversation_id', $conversation->id)
            ->where('direction', MessageDirection::Outbound)
            ->latest('id')
            ->firstOrFail();

        $this->assertSame('succeeded', $execution->status);
        $this->assertSame('search_knowledge', $execution->tool_name);
        $this->assertSame(1, $execution->output_summary['match_count']);
        $this->assertStringContainsString('envíos a todo Colombia', $execution->output_summary['matches'][0]['preview']);
        $this->assertArrayNotHasKey('structured_payload', $execution->output_summary['matches'][0]);
        $this->assertStringContainsString('envíos a todo Colombia', $outboundMessage->body ?? '');
        $this->assertDatabaseHas('agent_events', [
            'tenant_id' => $tenant->id,
            'event_type' => 'tool_data_source_resolved',
            'conversation_message_id' => $message->id,
        ]);
        $this->assertDatabaseHas('agent_events', [
            'tenant_id' => $tenant->id,
            'event_type' => 'data_source_search_completed',
            'conversation_message_id' => $message->id,
        ]);
    }

    public function test_processing_job_uses_the_handoff_tool_public_reply_before_handoff(): void
    {
        [$tenant, $line, $conversation, $message] = $this->conversationFixtures();
        $this->createWhatsappCredential($tenant);
        $this->enableTool($tenant, $line, 'handoff_to_human');

        Http::fake([
            'https://api.openai.com/v1/responses' => Http::response([
                'output_text' => json_encode([
                    'outcome' => 'call_tool',
                    'reply_text' => '',
                    'handoff_reason' => '',
                    'tool_name' => 'handoff_to_human',
                    'tool_arguments_json' => json_encode([
                        'reason' => 'Customer asked for confirmation from an agent.',
                        'reply_text' => 'Te voy a pasar con una persona del equipo.',
                    ], JSON_THROW_ON_ERROR),
                    'missing_information_fields' => [],
                    'current_intent' => 'handoff_requested',
                    'internal_notes' => 'Escalate to a human.',
                ], JSON_THROW_ON_ERROR),
            ], 200),
            'https://graph.facebook.com/*' => Http::response([
                'messages' => [
                    ['id' => 'wamid.outbound.handoff-tool.300'],
                ],
            ], 200),
        ]);

        config()->set('services.openai.api_key', 'test-openai-key');

        $this->runProcessingJob($tenant, $conversation, $message);

        $conversation->refresh();
        $execution = ToolExecution::query()->firstOrFail();
        $outboundMessage = ConversationMessage::query()
            ->where('conversation_id', $conversation->id)
            ->where('direction', MessageDirection::Outbound)
            ->latest('id')
            ->firstOrFail();

        $this->assertSame(ConversationStatus::HumanHandoff, $conversation->status);
        $this->assertSame('handoff_to_human', $execution->tool_name);
        $this->assertSame('succeeded', $execution->status);
        $this->assertSame('Te voy a pasar con una persona del equipo.', $outboundMessage->body);
        $this->assertDatabaseHas('handoff_requests', [
            'tenant_id' => $tenant->id,
            'conversation_id' => $conversation->id,
            'reason' => 'Customer asked for confirmation from an agent.',
        ]);
    }

    public function test_processing_job_falls_back_to_public_handoff_when_retrieval_returns_no_matches(): void
    {
        [$tenant, $line, $conversation, $message] = $this->conversationFixtures();
        $this->createWhatsappCredential($tenant);
        [$dataSource] = $this->createReadyExcelDataSource($tenant);

        $this->enableTool($tenant, $line, 'search_inventory', bindings: [
            'data_source_id' => $dataSource->id,
        ]);

        Http::fake([
            'https://api.openai.com/v1/responses' => Http::response([
                'output_text' => json_encode([
                    'outcome' => 'call_tool',
                    'reply_text' => '',
                    'handoff_reason' => '',
                    'tool_name' => 'search_inventory',
                    'tool_arguments_json' => json_encode([
                        'query' => 'producto inexistente',
                    ], JSON_THROW_ON_ERROR),
                    'missing_information_fields' => [],
                    'current_intent' => 'inventory_lookup',
                    'internal_notes' => 'Search inventory first.',
                ], JSON_THROW_ON_ERROR),
            ], 200),
            'https://graph.facebook.com/*' => Http::response([
                'messages' => [
                    ['id' => 'wamid.outbound.inventory-empty.300'],
                ],
            ], 200),
        ]);

        config()->set('services.openai.api_key', 'test-openai-key');

        $this->runProcessingJob($tenant, $conversation, $message);

        $conversation->refresh();
        $execution = ToolExecution::query()->firstOrFail();
        $outboundMessage = ConversationMessage::query()
            ->where('conversation_id', $conversation->id)
            ->where('direction', MessageDirection::Outbound)
            ->latest('id')
            ->firstOrFail();

        $this->assertSame(ConversationStatus::HumanHandoff, $conversation->status);
        $this->assertSame('search_inventory', $execution->tool_name);
        $this->assertSame(0, $execution->output_summary['match_count']);
        $this->assertSame(
            __('api.agent.default_handoff_customer_message'),
            $outboundMessage->body,
        );
        $this->assertDatabaseHas('handoff_requests', [
            'tenant_id' => $tenant->id,
            'conversation_id' => $conversation->id,
            'status' => 'requested',
        ]);
    }

    public function test_processing_job_executes_search_knowledge_using_a_bound_text_data_source(): void
    {
        [$tenant, $line, $conversation, $message] = $this->conversationFixtures();

        $this->createWhatsappCredential($tenant);
        [$dataSource, $import] = $this->createReadyExcelDataSource($tenant, 'txt');

        DataSourceChunk::query()->create([
            'tenant_id' => $tenant->id,
            'data_source_id' => $dataSource->id,
            'data_source_import_id' => $import->id,
            'chunk_type' => 'text_block',
            'sheet_name' => 'Politicas',
            'section_key' => 'politicas-txt-1',
            'content_text' => 'Garantia extendida por doce meses para equipos comprados en tienda.',
            'structured_payload' => [
                'source_name' => 'Politicas',
                'chunk_index' => 1,
            ],
            'metadata' => [
                'datasets' => ['knowledge'],
                'source_type' => 'txt',
            ],
        ]);

        $this->enableTool($tenant, $line, 'search_knowledge', bindings: [
            'data_source_id' => $dataSource->id,
        ]);

        Http::fake([
            'https://api.openai.com/v1/responses' => Http::sequence()
                ->push([
                    'output_text' => json_encode([
                        'outcome' => 'call_tool',
                        'reply_text' => '',
                        'handoff_reason' => '',
                        'tool_name' => 'search_knowledge',
                        'tool_arguments_json' => json_encode([
                            'question' => 'garantia extendida',
                        ], JSON_THROW_ON_ERROR),
                        'missing_information_fields' => [],
                        'current_intent' => 'knowledge_lookup',
                        'internal_notes' => 'Retrieve documentary context first.',
                    ], JSON_THROW_ON_ERROR),
                ], 200)
                ->push([
                    'output_text' => json_encode([
                        'outcome' => 'send_message',
                        'reply_text' => 'La garantía extendida es de doce meses para equipos comprados en tienda.',
                        'handoff_reason' => '',
                        'tool_name' => '',
                        'tool_arguments_json' => '{}',
                        'missing_information_fields' => [],
                        'current_intent' => 'knowledge_lookup',
                        'internal_notes' => 'Answer from retrieved context only.',
                    ], JSON_THROW_ON_ERROR),
                ], 200),
            'https://graph.facebook.com/*' => Http::response([
                'messages' => [
                    ['id' => 'wamid.outbound.txt.300'],
                ],
            ], 200),
        ]);

        config()->set('services.openai.api_key', 'test-openai-key');

        $this->runProcessingJob($tenant, $conversation, $message);

        $execution = ToolExecution::query()->firstOrFail();
        $outboundMessage = ConversationMessage::query()
            ->where('conversation_id', $conversation->id)
            ->where('direction', MessageDirection::Outbound)
            ->latest('id')
            ->firstOrFail();

        $this->assertSame('succeeded', $execution->status);
        $this->assertSame('search_knowledge', $execution->tool_name);
        $this->assertSame(1, $execution->output_summary['match_count']);
        $this->assertStringContainsString('Garantia extendida', $execution->output_summary['matches'][0]['preview']);
        $this->assertStringContainsString('garantía extendida', $outboundMessage->body ?? '');
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

    protected function enableTool(
        Tenant $tenant,
        WhatsAppLine $line,
        string $toolName,
        ?int $timeoutSeconds = null,
        array $bindings = [],
    ): void {
        TenantToolConfig::query()->create([
            'tenant_id' => $tenant->id,
            'whatsapp_line_id' => $line->id,
            'scope_type' => 'whatsapp_line',
            'scope_key' => TenantScopeKey::forWhatsAppLine($line),
            'tool_name' => $toolName,
            'enabled' => true,
            'timeout_seconds' => $timeoutSeconds,
            'bindings' => $bindings,
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
            'model_key' => 'balanced',
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

    /**
     * @return array{0: DataSource, 1: DataSourceImport}
     */
    protected function createReadyExcelDataSource(Tenant $tenant, string $type = 'excel'): array
    {
        $dataSource = DataSource::query()->create([
            'tenant_id' => $tenant->id,
            'name' => 'Catalogo principal',
            'type' => $type,
            'status' => 'ready',
            'last_synced_at' => now(),
            'metadata' => [
                'datasets' => ['inventory', 'knowledge'],
            ],
        ]);

        $uploadedFile = UploadedFile::query()->create([
            'tenant_id' => $tenant->id,
            'data_source_id' => $dataSource->id,
            'disk' => 'local',
            'path' => 'data-sources/test.xlsx',
            'original_name' => 'test.xlsx',
            'mime_type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'size_bytes' => 1024,
        ]);

        $import = DataSourceImport::query()->create([
            'tenant_id' => $tenant->id,
            'data_source_id' => $dataSource->id,
            'uploaded_file_id' => $uploadedFile->id,
            'status' => 'succeeded',
            'attempts_count' => 1,
            'processed_sheet_count' => 2,
            'generated_chunk_count' => 2,
            'started_at' => now(),
            'finished_at' => now(),
        ]);

        return [$dataSource, $import];
    }
}

class SlowTimeoutTool implements ToolInterface
{
    public function definition(): ToolDefinition
    {
        return new ToolDefinition(
            name: 'save_customer_data',
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
