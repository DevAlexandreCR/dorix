<?php

namespace Tests\Feature\Agent;

use App\Domain\Connectors\GoogleCalendar\FakeGoogleCalendarClient;
use App\Domain\Connectors\GoogleCalendar\GoogleCalendarAccessTokenProvider;
use App\Domain\Scheduling\SchedulingConfigResolver;
use App\Enums\ConversationSource;
use App\Enums\ConversationStatus;
use App\Enums\TenantRole;
use App\Models\AgentConfig;
use App\Models\ApiCredential;
use App\Models\CatalogItem;
use App\Models\Conversation;
use App\Models\Tenant;
use App\Models\TenantToolConfig;
use App\Models\TenantUser;
use App\Models\User;
use App\Models\WhatsAppLine;
use App\Support\Tenancy\TenantScopeKey;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class AgentSandboxTest extends TestCase
{
    use RefreshDatabase;

    public function test_tenant_admin_can_create_chat_and_close_a_sandbox_session_without_meta_calls(): void
    {
        [$tenant, $line] = $this->sandboxFixtures();
        $tenantAdmin = $this->tenantUser($tenant, TenantRole::TenantAdmin);

        Http::fake([
            'https://api.openai.com/v1/responses' => Http::response([
                'output_text' => json_encode([
                    'outcome' => 'request_missing_information',
                    'reply_text' => 'Respuesta sandbox.',
                    'handoff_reason' => '',
                    'tool_name' => '',
                    'tool_arguments_json' => '{}',
                    'missing_information_fields' => ['interest_summary'],
                    'current_intent' => 'customer_data_capture',
                    'internal_notes' => 'Reply locally.',
                ], JSON_THROW_ON_ERROR),
            ], 200),
        ]);

        $sessionResponse = $this->actingAs($tenantAdmin)
            ->withCsrf()
            ->withHeader('X-Tenant-Id', (string) $tenant->id)
            ->postJson('/api/v1/agent-sandbox/sessions', [
                'whatsapp_line_id' => $line->id,
                'label' => 'Prueba runtime',
            ])
            ->assertCreated()
            ->assertJsonPath('data.conversation.source', ConversationSource::AgentSandbox->value)
            ->assertJsonPath('data.conversation.label', 'Prueba runtime');

        $conversationId = $sessionResponse->json('data.conversation.id');

        $this->actingAs($tenantAdmin)
            ->withHeader('X-Tenant-Id', (string) $tenant->id)
            ->getJson('/api/v1/agent-sandbox/sessions')
            ->assertOk()
            ->assertJsonPath('data.0.id', $conversationId)
            ->assertJsonPath('meta.available_lines.0.id', $line->id);

        $messageResponse = $this->actingAs($tenantAdmin)
            ->withCsrf()
            ->withHeader('X-Tenant-Id', (string) $tenant->id)
            ->postJson("/api/v1/agent-sandbox/sessions/{$conversationId}/messages", [
                'body' => 'Hola sandbox',
            ])
            ->assertCreated()
            ->assertJsonPath('data.conversation.status', ConversationStatus::WaitingCustomer->value)
            ->assertJsonPath('data.last_turn.runtime_outcome', 'request_missing_information')
            ->assertJsonPath('data.last_turn.agent_pack_key', 'sales_support_v1')
            ->assertJsonPath('data.last_turn.policy.allowed', true)
            ->assertJsonPath('data.last_turn.policy.normalized_intent', 'customer_data_capture')
            ->assertJsonPath('data.new_messages.0.direction', 'inbound')
            ->assertJsonPath('data.new_messages.1.direction', 'outbound')
            ->assertJsonPath('data.new_messages.1.source', 'agent_runtime');

        $this->actingAs($tenantAdmin)
            ->withCsrf()
            ->withHeader('X-Tenant-Id', (string) $tenant->id)
            ->postJson("/api/v1/agent-sandbox/sessions/{$conversationId}/close")
            ->assertOk()
            ->assertJsonPath('data.conversation.status', ConversationStatus::Closed->value);

        $this->actingAs($tenantAdmin)
            ->withCsrf()
            ->withHeader('Accept-Language', 'es-CO')
            ->withHeader('X-Tenant-Id', (string) $tenant->id)
            ->postJson("/api/v1/agent-sandbox/sessions/{$conversationId}/messages", [
                'body' => 'No debe entrar',
            ])
            ->assertConflict()
            ->assertJsonPath('code', 'sandbox_closed')
            ->assertJsonPath('message', 'Las sesiones sandbox cerradas no aceptan más mensajes.');

        $this->assertDatabaseHas('conversation_messages', [
            'conversation_id' => $conversationId,
            'status' => 'sandbox_sent',
            'body' => 'Respuesta sandbox.',
        ]);
        $this->assertDatabaseHas('agent_events', [
            'tenant_id' => $tenant->id,
            'conversation_id' => $conversationId,
            'event_type' => 'sandbox_turn_completed',
        ]);
        $this->assertDatabaseHas('agent_events', [
            'tenant_id' => $tenant->id,
            'conversation_id' => $conversationId,
            'event_type' => 'sandbox_session_closed',
        ]);

        Http::assertSentCount(1);
        Http::assertSent(fn ($request) => $request->url() === 'https://api.openai.com/v1/responses');
    }

    public function test_sandbox_sessions_are_excluded_from_the_operational_inbox(): void
    {
        [$tenant, $line] = $this->sandboxFixtures();
        $tenantAdmin = $this->tenantUser($tenant, TenantRole::TenantAdmin);

        Conversation::query()->create([
            'tenant_id' => $tenant->id,
            'whatsapp_line_id' => $line->id,
            'contact_phone' => 'sandbox:test',
            'contact_name' => 'Sandbox Hidden',
            'status' => ConversationStatus::BotActive,
            'source' => ConversationSource::AgentSandbox,
        ]);

        Conversation::query()->create([
            'tenant_id' => $tenant->id,
            'whatsapp_line_id' => $line->id,
            'contact_phone' => '573001234567',
            'contact_name' => 'WhatsApp Visible',
            'status' => ConversationStatus::BotActive,
            'source' => ConversationSource::WhatsApp,
        ]);

        $this->actingAs($tenantAdmin)
            ->withHeader('X-Tenant-Id', (string) $tenant->id)
            ->getJson('/api/v1/conversations')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.contact_name', 'WhatsApp Visible');
    }

    public function test_sandbox_turns_record_tool_executions_and_turn_metadata(): void
    {
        [$tenant, $line] = $this->sandboxFixtures();
        $tenantAdmin = $this->tenantUser($tenant, TenantRole::TenantAdmin);

        TenantToolConfig::query()->create([
            'tenant_id' => $tenant->id,
            'whatsapp_line_id' => null,
            'scope_type' => 'tenant',
            'scope_key' => TenantScopeKey::forTenant($tenant),
            'tool_name' => 'create_lead',
            'enabled' => true,
        ]);

        Http::fake([
            'https://api.openai.com/v1/responses' => Http::response([
                'output_text' => json_encode([
                    'outcome' => 'call_tool',
                    'reply_text' => '',
                    'handoff_reason' => '',
                    'tool_name' => 'create_lead',
                    'tool_arguments_json' => json_encode([
                        'lead_data' => [
                            'customer_name' => 'Ana Sandbox',
                            'interest_summary' => 'Producto premium',
                            'email' => 'ana@example.com',
                        ],
                        'reply_text' => 'Lead creado.',
                    ], JSON_THROW_ON_ERROR),
                    'missing_information_fields' => [],
                    'current_intent' => 'lead_qualification',
                    'internal_notes' => 'Persist lead.',
                ], JSON_THROW_ON_ERROR),
            ], 200),
        ]);

        $sessionResponse = $this->actingAs($tenantAdmin)
            ->withCsrf()
            ->withHeader('X-Tenant-Id', (string) $tenant->id)
            ->postJson('/api/v1/agent-sandbox/sessions', [
                'whatsapp_line_id' => $line->id,
                'label' => 'Tool trace',
            ])
            ->assertCreated();

        $conversationId = $sessionResponse->json('data.conversation.id');

        $this->actingAs($tenantAdmin)
            ->withCsrf()
            ->withHeader('X-Tenant-Id', (string) $tenant->id)
            ->postJson("/api/v1/agent-sandbox/sessions/{$conversationId}/messages", [
                'body' => 'Guarda mi lead',
            ])
            ->assertCreated()
            ->assertJsonPath('data.last_turn.runtime_outcome', 'call_tool')
            ->assertJsonPath('data.last_turn.tool_executions.0.tool_name', 'create_lead')
            ->assertJsonPath('data.last_turn.tool_executions.0.status', 'succeeded')
            ->assertJsonPath('data.new_messages.1.body', 'Lead creado.');

        $this->assertDatabaseHas('tool_executions', [
            'tenant_id' => $tenant->id,
            'conversation_id' => $conversationId,
            'tool_name' => 'create_lead',
            'status' => 'succeeded',
        ]);
        $this->assertDatabaseHas('agent_events', [
            'tenant_id' => $tenant->id,
            'conversation_id' => $conversationId,
            'event_type' => 'tool_called',
        ]);
        $this->assertDatabaseHas('agent_events', [
            'tenant_id' => $tenant->id,
            'conversation_id' => $conversationId,
            'event_type' => 'tool_succeeded',
        ]);
        $this->assertDatabaseHas('conversations', [
            'id' => $conversationId,
            'contact_name' => 'Ana Sandbox',
        ]);
    }

    public function test_sandbox_conversation_books_an_appointment_across_two_turns_against_the_fake_calendar(): void
    {
        // End-to-end proof of design D7/D8: a sandbox conversation drives the
        // real HTTP controller/service/runtime pipeline through two
        // successive turns (check_availability, then create_appointment —
        // one tool call per turn, per the accepted D8 limitation) and the
        // appointment is recorded against FakeGoogleCalendarClient with no
        // real outbound HTTP call to Google.
        [$tenant, $line] = $this->sandboxFixtures();
        $tenantAdmin = $this->tenantUser($tenant, TenantRole::TenantAdmin);

        $this->enableSchedulingTools($tenant, $line);
        $this->makeCalendarCredential($line, 'refresh-token-sandbox');

        Cache::flush();
        app(FakeGoogleCalendarClient::class)->setBusyIntervals([]);

        $item = CatalogItem::create([
            'tenant_id' => $tenant->id,
            'kind' => 'service',
            'name' => 'Limpieza facial',
            'price_type' => 'fixed',
            'price_amount' => 100000,
            'duration_minutes' => 60,
        ]);

        $sessionResponse = $this->actingAs($tenantAdmin)
            ->withCsrf()
            ->withHeader('X-Tenant-Id', (string) $tenant->id)
            ->postJson('/api/v1/agent-sandbox/sessions', [
                'whatsapp_line_id' => $line->id,
                'label' => 'Agendamiento E2E',
            ])
            ->assertCreated();

        $conversationId = $sessionResponse->json('data.conversation.id');

        // Both turns' LLM responses are queued in ONE sequence up front:
        // registering a second Http::fake() before turn 2 would add a
        // second stub for the same URL without clearing the first
        // (already-exhausted) sequence, and the earlier one keeps matching
        // first — so turn 2's first request would hit the drained turn-1
        // sequence and blow up with "the response sequence is empty".
        //
        // Turn 1: the agent checks availability, then replies with the slot
        // it found. Turn 2: the agent books the appointment. Each turn is
        // two real LLM calls in one request — the tool's
        // ContinueWithRetrievedContext result triggers an immediate
        // follow-up AgentRuntime::run() to obtain the reply (see
        // AgentDecisionApplier::continueWithRetrievedContext).
        Http::fake([
            'https://api.openai.com/v1/responses' => Http::sequence()
                ->push([
                    'output_text' => json_encode([
                        'outcome' => 'call_tool',
                        'reply_text' => '',
                        'handoff_reason' => '',
                        'tool_name' => 'check_availability',
                        'tool_arguments_json' => json_encode([
                            'item_id' => $item->id,
                            'date' => '2026-08-10',
                        ], JSON_THROW_ON_ERROR),
                        'missing_information_fields' => [],
                        'current_intent' => 'service_scheduling',
                        'internal_notes' => 'Check availability first.',
                    ], JSON_THROW_ON_ERROR),
                ], 200)
                ->push([
                    'output_text' => json_encode([
                        'outcome' => 'send_message',
                        'reply_text' => 'Tengo un espacio a las 9:00 a. m. el 10 de agosto, ¿te sirve?',
                        'handoff_reason' => '',
                        'tool_name' => '',
                        'tool_arguments_json' => '{}',
                        'missing_information_fields' => [],
                        'current_intent' => 'service_scheduling',
                        'internal_notes' => 'Offer the retrieved slot.',
                    ], JSON_THROW_ON_ERROR),
                ], 200)
                ->push([
                    'output_text' => json_encode([
                        'outcome' => 'call_tool',
                        'reply_text' => '',
                        'handoff_reason' => '',
                        'tool_name' => 'create_appointment',
                        'tool_arguments_json' => json_encode([
                            'item_id' => $item->id,
                            'slot_start' => '2026-08-10T09:00:00-05:00',
                            'customer_name' => 'Ana Cliente',
                        ], JSON_THROW_ON_ERROR),
                        'missing_information_fields' => [],
                        'current_intent' => 'service_scheduling',
                        'internal_notes' => 'Book the confirmed slot.',
                    ], JSON_THROW_ON_ERROR),
                ], 200)
                ->push([
                    'output_text' => json_encode([
                        'outcome' => 'send_message',
                        'reply_text' => 'Listo, tu cita quedó agendada para el 10 de agosto a las 9:00 a. m.',
                        'handoff_reason' => '',
                        'tool_name' => '',
                        'tool_arguments_json' => '{}',
                        'missing_information_fields' => [],
                        'current_intent' => 'service_scheduling',
                        'internal_notes' => 'Confirm the booking.',
                    ], JSON_THROW_ON_ERROR),
                ], 200),
        ]);

        $this->actingAs($tenantAdmin)
            ->withCsrf()
            ->withHeader('X-Tenant-Id', (string) $tenant->id)
            ->postJson("/api/v1/agent-sandbox/sessions/{$conversationId}/messages", [
                'body' => 'Quiero agendar una limpieza facial.',
            ])
            ->assertCreated()
            ->assertJsonPath('data.last_turn.runtime_outcome', 'call_tool')
            ->assertJsonPath('data.last_turn.agent_pack_key', 'sales_support_v1')
            ->assertJsonPath('data.last_turn.policy.normalized_intent', 'service_scheduling')
            ->assertJsonPath('data.last_turn.tool_executions.0.tool_name', 'check_availability')
            ->assertJsonPath('data.last_turn.tool_executions.0.status', 'succeeded')
            ->assertJsonPath(
                'data.new_messages.1.body',
                'Tengo un espacio a las 9:00 a. m. el 10 de agosto, ¿te sirve?'
            );

        Http::assertSentCount(2);

        $this->assertDatabaseHas('tool_executions', [
            'tenant_id' => $tenant->id,
            'conversation_id' => $conversationId,
            'tool_name' => 'check_availability',
            'status' => 'succeeded',
        ]);

        // Turn 2: the agent books the appointment against the fake calendar
        // (reuses the same Http::fake sequence set up before turn 1).
        $this->actingAs($tenantAdmin)
            ->withCsrf()
            ->withHeader('X-Tenant-Id', (string) $tenant->id)
            ->postJson("/api/v1/agent-sandbox/sessions/{$conversationId}/messages", [
                'body' => 'Sí, esa hora me sirve. Confírmame la cita.',
            ])
            ->assertCreated()
            ->assertJsonPath('data.last_turn.runtime_outcome', 'call_tool')
            ->assertJsonPath('data.last_turn.tool_executions.0.tool_name', 'create_appointment')
            ->assertJsonPath('data.last_turn.tool_executions.0.status', 'succeeded')
            ->assertJsonPath(
                'data.new_messages.1.body',
                'Listo, tu cita quedó agendada para el 10 de agosto a las 9:00 a. m.'
            );

        Http::assertSentCount(4);

        $this->assertDatabaseHas('tool_executions', [
            'tenant_id' => $tenant->id,
            'conversation_id' => $conversationId,
            'tool_name' => 'create_appointment',
            'status' => 'succeeded',
        ]);

        $this->assertDatabaseHas('agent_events', [
            'tenant_id' => $tenant->id,
            'conversation_id' => $conversationId,
            'event_type' => 'appointment_created',
        ]);

        $createdEvents = app(FakeGoogleCalendarClient::class)->createdEvents();
        $this->assertCount(1, $createdEvents);
        $this->assertSame('Limpieza facial — Ana Cliente', $createdEvents[0]->summary);
    }

    public function test_sandbox_sessions_without_a_label_use_a_human_default_name_in_the_request_locale(): void
    {
        [$tenant, $line] = $this->sandboxFixtures();
        $tenantAdmin = $this->tenantUser($tenant, TenantRole::TenantAdmin);

        Carbon::setTestNow(Carbon::create(2026, 4, 30, 16, 42, 0));

        try {
            $this->actingAs($tenantAdmin)
                ->withCsrf()
                ->withHeader('Accept-Language', 'es-CO')
                ->withHeader('X-Tenant-Id', (string) $tenant->id)
                ->postJson('/api/v1/agent-sandbox/sessions', [
                    'whatsapp_line_id' => $line->id,
                ])
                ->assertCreated()
                ->assertJsonPath('data.conversation.label', 'Prueba 30/04/2026 4:42 p. m.');

            $this->actingAs($tenantAdmin)
                ->withCsrf()
                ->withHeader('Accept-Language', 'en')
                ->withHeader('X-Tenant-Id', (string) $tenant->id)
                ->postJson('/api/v1/agent-sandbox/sessions', [
                    'whatsapp_line_id' => $line->id,
                ])
                ->assertCreated()
                ->assertJsonPath('data.conversation.label', 'Test 30/04/2026 4:42 PM');
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_viewer_cannot_access_sandbox_endpoints(): void
    {
        [$tenant, $line] = $this->sandboxFixtures();
        $viewer = $this->tenantUser($tenant, TenantRole::Viewer);

        $this->actingAs($viewer)
            ->withHeader('X-Tenant-Id', (string) $tenant->id)
            ->getJson('/api/v1/agent-sandbox/sessions')
            ->assertForbidden();

        $this->actingAs($viewer)
            ->withCsrf()
            ->withHeader('X-Tenant-Id', (string) $tenant->id)
            ->postJson('/api/v1/agent-sandbox/sessions', [
                'whatsapp_line_id' => $line->id,
            ])
            ->assertForbidden();
    }

    /**
     * @return array{Tenant, WhatsAppLine}
     */
    protected function sandboxFixtures(): array
    {
        $tenant = Tenant::query()->create([
            'name' => 'Sandbox Tenant',
            'slug' => 'sandbox-tenant',
        ]);

        $line = WhatsAppLine::query()->create([
            'tenant_id' => $tenant->id,
            'name' => 'Sandbox Line',
            'phone_number_id' => 'sandbox-line-id',
            'display_phone_number' => '+57 300 111 2233',
            'status' => 'active',
            'is_enabled' => true,
        ]);

        AgentConfig::query()->create([
            'tenant_id' => $tenant->id,
            'whatsapp_line_id' => null,
            'scope_type' => 'tenant',
            'scope_key' => TenantScopeKey::forTenant($tenant),
            'name' => 'Sandbox Agent',
            'model_key' => 'balanced',
            'prompt_version' => 'v1',
            'is_active' => true,
            'settings' => [
                'automation_enabled' => true,
            ],
        ]);

        config()->set('services.openai.api_key', 'test-openai-key');

        return [$tenant, $line];
    }

    protected function tenantUser(Tenant $tenant, TenantRole $role): User
    {
        $user = User::factory()->create();

        TenantUser::query()->create([
            'tenant_id' => $tenant->id,
            'user_id' => $user->id,
            'role' => $role,
        ]);

        return $user;
    }

    /**
     * Enables both `check_availability` (which doubles as the scheduling
     * config row read by SchedulingConfigResolver) and `create_appointment`
     * for the given line, following the same fixture shape used in
     * CheckAvailabilityToolTest/CreateAppointmentToolTest.
     */
    protected function enableSchedulingTools(Tenant $tenant, WhatsAppLine $line): void
    {
        TenantToolConfig::query()->create([
            'tenant_id' => $tenant->id,
            'whatsapp_line_id' => $line->id,
            'scope_type' => 'whatsapp_line',
            'scope_key' => TenantScopeKey::forWhatsAppLine($line),
            'tool_name' => SchedulingConfigResolver::CONFIG_TOOL_NAME,
            'enabled' => true,
            'overrides' => [
                'calendar_id' => 'primary',
                'timezone' => 'America/Bogota',
                'business_hours' => [
                    'monday' => [
                        ['start' => '09:00', 'end' => '13:00'],
                        ['start' => '14:00', 'end' => '18:00'],
                    ],
                ],
            ],
        ]);

        TenantToolConfig::query()->create([
            'tenant_id' => $tenant->id,
            'whatsapp_line_id' => $line->id,
            'scope_type' => 'whatsapp_line',
            'scope_key' => TenantScopeKey::forWhatsAppLine($line),
            'tool_name' => 'create_appointment',
            'enabled' => true,
        ]);
    }

    protected function makeCalendarCredential(WhatsAppLine $line, string $refreshToken): void
    {
        ApiCredential::query()->create([
            'tenant_id' => $line->tenant_id,
            'whatsapp_line_id' => $line->id,
            'scope_type' => 'whatsapp_line',
            'scope_key' => TenantScopeKey::forWhatsAppLine($line),
            'provider' => GoogleCalendarAccessTokenProvider::PROVIDER,
            'credential_key' => GoogleCalendarAccessTokenProvider::CREDENTIAL_KEY,
            'secret' => $refreshToken,
        ]);
    }
}
