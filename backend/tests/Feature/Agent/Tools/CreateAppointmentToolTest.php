<?php

namespace Tests\Feature\Agent\Tools;

use App\Domain\Agent\AgentDecisionOutcome;
use App\Domain\Agent\DTO\AgentContext;
use App\Domain\Agent\DTO\AgentDecision;
use App\Domain\Connectors\GoogleCalendar\DTO\CalendarBusyInterval;
use App\Domain\Connectors\GoogleCalendar\FakeGoogleCalendarClient;
use App\Domain\Connectors\GoogleCalendar\GoogleCalendarAccessTokenProvider;
use App\Domain\Scheduling\SchedulingConfigResolver;
use App\Domain\Tools\DTO\EnabledTool;
use App\Domain\Tools\DTO\ToolInvocation;
use App\Domain\Tools\ToolExecutionRunner;
use App\Domain\Tools\ToolNextAction;
use App\Domain\Tools\Tools\CreateAppointmentTool;
use App\Enums\ConversationSource;
use App\Enums\MessageDirection;
use App\Models\AgentConfig;
use App\Models\ApiCredential;
use App\Models\CatalogItem;
use App\Models\Conversation;
use App\Models\ConversationMessage;
use App\Models\ConversationState;
use App\Models\Tenant;
use App\Models\TenantToolConfig;
use App\Models\WhatsAppLine;
use App\Support\Tenancy\TenantScopeKey;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class CreateAppointmentToolTest extends TestCase
{
    use RefreshDatabase;

    private CreateAppointmentTool $tool;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tool = app(CreateAppointmentTool::class);

        // Auto-increment ids are reused across tests under RefreshDatabase; a
        // stale cached access token from an earlier test would otherwise leak
        // into this one under the same cache key (see
        // GoogleCalendarAccessTokenProviderTest / CheckAvailabilityToolTest).
        Cache::flush();
    }

    public function test_it_creates_the_appointment_with_the_duration_from_the_database(): void
    {
        $tenant = $this->makeTenant();
        $line = $this->makeLine($tenant);
        $this->makeSchedulingConfig($tenant, $line);
        $this->makeCredential($line, 'refresh-token-abc');
        app(FakeGoogleCalendarClient::class)->setBusyIntervals([]);

        $item = $this->makeItem($tenant, ['name' => 'Limpieza facial', 'duration_minutes' => 45]);

        $slotStart = '2026-08-10T09:00:00-05:00';

        $result = $this->tool->execute($this->invocation($tenant, $line, [
            'item_id' => $item->id,
            'slot_start' => $slotStart,
            'customer_name' => 'Ana Cliente',
        ]));

        $this->assertSame(ToolNextAction::ContinueWithRetrievedContext, $result->nextAction);
        $this->assertTrue($result->outputSummary['created']);
        $this->assertSame(45, $result->outputSummary['duration_minutes']);
        $this->assertSame('Limpieza facial — Ana Cliente', $result->outputSummary['summary']);

        $start = CarbonImmutable::parse($result->outputSummary['start']);
        $end = CarbonImmutable::parse($result->outputSummary['end']);
        $this->assertSame(45.0, $start->diffInMinutes($end));

        $createdEvents = app(FakeGoogleCalendarClient::class)->createdEvents();
        $this->assertCount(1, $createdEvents);
        $this->assertSame('Limpieza facial — Ana Cliente', $createdEvents[0]->summary);
        $this->assertStringContainsString('573001234567', $createdEvents[0]->description);
        $this->assertStringContainsString('Dorix', $createdEvents[0]->description);
        $this->assertSame(45.0, $createdEvents[0]->start->diffInMinutes($createdEvents[0]->end));

        $this->assertSame([$result->outputSummary], $result->metadata['retrieved_context']);

        $this->assertDatabaseHas('agent_events', [
            'tenant_id' => $tenant->id,
            'event_type' => 'appointment_created',
        ]);
    }

    public function test_it_does_not_create_the_event_when_the_slot_was_taken_between_check_and_create(): void
    {
        $tenant = $this->makeTenant();
        $line = $this->makeLine($tenant);
        $this->makeSchedulingConfig($tenant, $line);
        $this->makeCredential($line, 'refresh-token-abc');

        // Busy block exactly covers the slot the "customer" chose, simulating
        // another conversation booking it after check_availability ran.
        app(FakeGoogleCalendarClient::class)->setBusyIntervals([
            new CalendarBusyInterval(
                start: CarbonImmutable::parse('2026-08-10T09:00:00-05:00'),
                end: CarbonImmutable::parse('2026-08-10T10:00:00-05:00'),
            ),
        ]);

        $item = $this->makeItem($tenant, ['duration_minutes' => 60]);

        $result = $this->tool->execute($this->invocation($tenant, $line, [
            'item_id' => $item->id,
            'slot_start' => '2026-08-10T09:00:00-05:00',
            'customer_name' => 'Ana Cliente',
        ]));

        $this->assertSame(ToolNextAction::ContinueWithRetrievedContext, $result->nextAction);
        $this->assertFalse($result->outputSummary['created']);
        $this->assertSame('slot_taken', $result->outputSummary['rejected_reason']);
        $this->assertArrayHasKey('slots', $result->outputSummary);

        $this->assertCount(0, app(FakeGoogleCalendarClient::class)->createdEvents());

        $this->assertDatabaseHas('agent_events', [
            'tenant_id' => $tenant->id,
            'event_type' => 'appointment_slot_no_longer_available',
        ]);
    }

    public function test_it_rejects_an_item_that_requires_a_prior_assessment_without_touching_the_calendar(): void
    {
        $tenant = $this->makeTenant();
        $line = $this->makeLine($tenant);
        $this->makeSchedulingConfig($tenant, $line);
        // Deliberately no credential — proves the calendar is never contacted
        // once the item is rejected for requiring an assessment.

        $assessment = $this->makeItem($tenant, [
            'name' => 'Valoracion dermatologica',
            'price_amount' => 0,
            'duration_minutes' => 30,
        ]);
        $item = $this->makeItem($tenant, [
            'name' => 'Acido hialuronico',
            'price_amount' => null,
            'duration_minutes' => null,
            'assessment_item_id' => $assessment->id,
        ]);

        $result = $this->tool->execute($this->invocation($tenant, $line, [
            'item_id' => $item->id,
            'slot_start' => '2026-08-10T09:00:00-05:00',
            'customer_name' => 'Ana Cliente',
        ]));

        $this->assertSame(ToolNextAction::ContinueWithRetrievedContext, $result->nextAction);
        $this->assertFalse($result->outputSummary['created']);
        $this->assertSame('requires_assessment', $result->outputSummary['rejected_reason']);
        $this->assertSame($assessment->id, $result->outputSummary['assessment_item_id']);
        $this->assertSame('Valoracion dermatologica', $result->outputSummary['assessment_item_name']);
        $this->assertSame(
            __('api.catalog.booking_requires_assessment', ['assessment_name' => 'Valoracion dermatologica']),
            $result->outputSummary['reason'],
        );

        $this->assertDatabaseHas('agent_events', [
            'tenant_id' => $tenant->id,
            'event_type' => 'appointment_creation_rejected',
        ]);
    }

    public function test_it_rejects_a_product_without_touching_the_calendar(): void
    {
        $tenant = $this->makeTenant();
        $line = $this->makeLine($tenant);
        $this->makeSchedulingConfig($tenant, $line);
        // Deliberately no credential — proves the calendar is never contacted.

        $item = $this->makeItem($tenant, ['kind' => 'product', 'duration_minutes' => null]);

        $result = $this->tool->execute($this->invocation($tenant, $line, [
            'item_id' => $item->id,
            'slot_start' => '2026-08-10T09:00:00-05:00',
            'customer_name' => 'Ana Cliente',
        ]));

        $this->assertFalse($result->outputSummary['created']);
        $this->assertSame('not_bookable', $result->outputSummary['rejected_reason']);
        $this->assertSame(__('api.catalog.not_bookable_product'), $result->outputSummary['reason']);
    }

    public function test_it_hands_off_when_the_line_has_no_calendar_credential(): void
    {
        $tenant = $this->makeTenant();
        $line = $this->makeLine($tenant);
        $this->makeSchedulingConfig($tenant, $line);
        // deliberately no credential created for this line

        $item = $this->makeItem($tenant, ['duration_minutes' => 60]);

        $result = $this->tool->execute($this->invocation($tenant, $line, [
            'item_id' => $item->id,
            'slot_start' => '2026-08-10T09:00:00-05:00',
            'customer_name' => 'Ana Cliente',
        ]));

        $this->assertSame(ToolNextAction::RequestHandoff, $result->nextAction);
        $this->assertNotSame('', $result->handoffReason);
        $this->assertFalse($result->outputSummary['calendar_connection_available']);

        $this->assertDatabaseHas('agent_events', [
            'tenant_id' => $tenant->id,
            'event_type' => 'appointment_creation_unavailable',
        ]);
    }

    public function test_it_hands_off_when_the_credential_is_revoked(): void
    {
        $tenant = $this->makeTenant();
        $line = $this->makeLine($tenant);
        $this->makeSchedulingConfig($tenant, $line);
        $this->makeCredential($line, 'revoked-refresh-token');

        app(FakeGoogleCalendarClient::class)->markConnectionBroken();

        $item = $this->makeItem($tenant, ['duration_minutes' => 60]);

        $result = $this->tool->execute($this->invocation($tenant, $line, [
            'item_id' => $item->id,
            'slot_start' => '2026-08-10T09:00:00-05:00',
            'customer_name' => 'Ana Cliente',
        ]));

        $this->assertSame(ToolNextAction::RequestHandoff, $result->nextAction);
        $this->assertFalse($result->outputSummary['calendar_connection_available']);
    }

    public function test_it_reports_not_found_for_an_unknown_item_id(): void
    {
        $tenant = $this->makeTenant();
        $line = $this->makeLine($tenant);

        $result = $this->tool->execute($this->invocation($tenant, $line, [
            'item_id' => 999999,
            'slot_start' => '2026-08-10T09:00:00-05:00',
            'customer_name' => 'Ana Cliente',
        ]));

        $this->assertSame(ToolNextAction::ContinueWithRetrievedContext, $result->nextAction);
        $this->assertFalse($result->outputSummary['found']);
        $this->assertFalse($result->outputSummary['created']);
        $this->assertSame(__('api.catalog.item_not_found'), $result->outputSummary['reason']);
    }

    public function test_it_does_not_leak_an_item_belonging_to_another_tenant(): void
    {
        $tenantA = $this->makeTenant('acme');
        $lineA = $this->makeLine($tenantA);

        $tenantB = $this->makeTenant('globex');
        $otherTenantItem = $this->makeItem($tenantB, ['name' => 'Servicio de Globex']);

        $result = $this->tool->execute($this->invocation($tenantA, $lineA, [
            'item_id' => $otherTenantItem->id,
            'slot_start' => '2026-08-10T09:00:00-05:00',
            'customer_name' => 'Ana Cliente',
        ]));

        $this->assertFalse($result->outputSummary['found']);
        $this->assertStringNotContainsString('Globex', json_encode($result->outputSummary));
    }

    public function test_it_rejects_a_malformed_slot_start(): void
    {
        $tenant = $this->makeTenant();
        $line = $this->makeLine($tenant);
        $item = $this->makeItem($tenant, ['duration_minutes' => 60]);

        $this->expectException(\App\Domain\Tools\Exceptions\InvalidToolArgumentsException::class);

        $this->tool->execute($this->invocation($tenant, $line, [
            'item_id' => $item->id,
            'slot_start' => '2026-08-10',
            'customer_name' => 'Ana Cliente',
        ]));
    }

    public function test_whatsapp_conversations_resolve_the_real_http_calendar_client(): void
    {
        Config::set('services.google.oauth_token_url', 'https://oauth2.googleapis.com/token');
        Config::set('services.google.calendar_base_url', 'https://www.googleapis.com/calendar/v3');

        Http::fake([
            'https://oauth2.googleapis.com/token' => Http::response([
                'access_token' => 'real-access-token',
                'expires_in' => 3600,
            ], 200),
            'https://www.googleapis.com/calendar/v3/freeBusy' => Http::response([
                'calendars' => ['primary' => ['busy' => []]],
            ], 200),
            'https://www.googleapis.com/calendar/v3/calendars/primary/events' => Http::response([
                'id' => 'real-event-1',
                'summary' => 'Limpieza facial — Ana Cliente',
            ], 200),
        ]);

        $tenant = $this->makeTenant('acme-real');
        $line = $this->makeLine($tenant, 'acme-real-phone-id');
        $this->makeSchedulingConfig($tenant, $line);
        $this->makeCredential($line, 'refresh-token-real');
        $item = $this->makeItem($tenant, ['name' => 'Limpieza facial', 'duration_minutes' => 60]);

        $result = $this->tool->execute($this->invocation($tenant, $line, [
            'item_id' => $item->id,
            'slot_start' => '2026-08-10T09:00:00-05:00',
            'customer_name' => 'Ana Cliente',
        ], ConversationSource::WhatsApp));

        $this->assertTrue($result->outputSummary['created']);
        Http::assertSent(fn ($request): bool => str_contains($request->url(), 'oauth2.googleapis.com/token'));
        Http::assertSent(fn ($request): bool => str_contains($request->url(), 'freeBusy'));
        Http::assertSent(fn ($request): bool => str_contains($request->url(), 'calendars/primary/events'));
    }

    public function test_sandbox_conversations_resolve_the_fake_calendar_client_without_any_http_call(): void
    {
        Http::fake();

        $tenant = $this->makeTenant('acme-sandbox');
        $line = $this->makeLine($tenant, 'acme-sandbox-phone-id');
        $this->makeSchedulingConfig($tenant, $line);
        $this->makeCredential($line, 'refresh-token-fake');
        app(FakeGoogleCalendarClient::class)->setBusyIntervals([]);

        $item = $this->makeItem($tenant, ['duration_minutes' => 60]);

        $result = $this->tool->execute($this->invocation($tenant, $line, [
            'item_id' => $item->id,
            'slot_start' => '2026-08-10T09:00:00-05:00',
            'customer_name' => 'Ana Cliente',
        ], ConversationSource::AgentSandbox));

        $this->assertTrue($result->outputSummary['created']);
        Http::assertNothingSent();
    }

    public function test_execution_via_the_tool_execution_runner_is_recorded_in_tool_executions(): void
    {
        // Unlike the other tests in this file (which call
        // CreateAppointmentTool::execute() directly for speed and
        // determinism), this test goes through the real
        // ToolExecutionRunner::run() path — the same one
        // ProcessIncomingMessageJob uses — to prove the spec's
        // "registro en tool_executions" requirement is actually satisfied.
        // ToolExecutionRunner creates the ToolExecution row generically for
        // every tool; this test does not add any tool-specific persistence
        // code, it only confirms the generic behavior covers this tool too.
        $tenant = $this->makeTenant();
        $line = $this->makeLine($tenant);
        $this->makeSchedulingConfig($tenant, $line);
        $this->makeCredential($line, 'refresh-token-abc');
        app(FakeGoogleCalendarClient::class)->setBusyIntervals([]);

        $item = $this->makeItem($tenant, ['duration_minutes' => 60]);

        $conversation = Conversation::query()->create([
            'tenant_id' => $tenant->id,
            'whatsapp_line_id' => $line->id,
            'contact_phone' => '573001234567',
            'contact_name' => 'Ana Cliente',
            'status' => 'BOT_ACTIVE',
            'source' => ConversationSource::AgentSandbox->value,
            'last_message_at' => now(),
            'last_customer_message_at' => now(),
        ]);

        $message = ConversationMessage::query()->create([
            'tenant_id' => $tenant->id,
            'conversation_id' => $conversation->id,
            'direction' => MessageDirection::Inbound,
            'message_type' => 'text',
            'body' => 'Quiero agendar una cita.',
            'provider_message_id' => 'wamid.inbound.'.$conversation->id,
            'status' => 'received',
            'received_at' => now(),
        ]);

        $enabledTool = new EnabledTool($this->tool->definition(), new TenantToolConfig(['enabled' => true]));

        $context = new AgentContext(
            tenant: $tenant,
            line: $line,
            conversation: $conversation,
            state: new ConversationState(['collected_data' => []]),
            agentConfig: new AgentConfig(['settings' => []]),
            triggeringMessage: $message,
            recentMessages: [],
            enabledTools: [$enabledTool],
        );

        $decision = new AgentDecision(
            outcome: AgentDecisionOutcome::CallTool,
            toolName: 'create_appointment',
            toolArguments: [
                'item_id' => $item->id,
                'slot_start' => '2026-08-10T09:00:00-05:00',
                'customer_name' => 'Ana Cliente',
            ],
        );

        $result = app(ToolExecutionRunner::class)->run($context, $decision);

        $this->assertTrue($result->outputSummary['created']);

        $this->assertDatabaseHas('tool_executions', [
            'tenant_id' => $tenant->id,
            'conversation_id' => $conversation->id,
            'tool_name' => 'create_appointment',
            'status' => 'succeeded',
        ]);
    }

    private function makeTenant(string $slug = 'acme'): Tenant
    {
        return Tenant::query()->create([
            'name' => 'Acme',
            'slug' => $slug.'-'.uniqid(),
        ]);
    }

    private function makeLine(Tenant $tenant, ?string $phoneNumberId = null): WhatsAppLine
    {
        return WhatsAppLine::query()->create([
            'tenant_id' => $tenant->id,
            'name' => 'Primary Line',
            'phone_number_id' => $phoneNumberId ?? 'phone-number-id-'.uniqid(),
            'display_phone_number' => '+573001112233',
            'status' => 'active',
            'is_enabled' => true,
        ]);
    }

    private function makeSchedulingConfig(Tenant $tenant, WhatsAppLine $line): void
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
    }

    private function makeCredential(WhatsAppLine $line, string $refreshToken): ApiCredential
    {
        return ApiCredential::query()->create([
            'tenant_id' => $line->tenant_id,
            'whatsapp_line_id' => $line->id,
            'scope_type' => 'whatsapp_line',
            'scope_key' => TenantScopeKey::forWhatsAppLine($line),
            'provider' => GoogleCalendarAccessTokenProvider::PROVIDER,
            'credential_key' => GoogleCalendarAccessTokenProvider::CREDENTIAL_KEY,
            'secret' => $refreshToken,
        ]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function makeItem(Tenant $tenant, array $overrides = []): CatalogItem
    {
        return CatalogItem::create(array_merge([
            'tenant_id' => $tenant->id,
            'kind' => 'service',
            'name' => 'Limpieza facial',
            'price_type' => 'fixed',
            'price_amount' => 100000,
            'duration_minutes' => 60,
        ], $overrides));
    }

    /**
     * @param  array<string, mixed>  $arguments
     */
    private function invocation(
        Tenant $tenant,
        WhatsAppLine $line,
        array $arguments,
        ConversationSource $source = ConversationSource::AgentSandbox,
    ): ToolInvocation {
        $conversation = Conversation::query()->create([
            'tenant_id' => $tenant->id,
            'whatsapp_line_id' => $line->id,
            'contact_phone' => '573001234567',
            'contact_name' => 'Ana Cliente',
            'status' => 'BOT_ACTIVE',
            'source' => $source->value,
            'last_message_at' => now(),
            'last_customer_message_at' => now(),
        ]);

        $message = ConversationMessage::query()->create([
            'tenant_id' => $tenant->id,
            'conversation_id' => $conversation->id,
            'direction' => MessageDirection::Inbound,
            'message_type' => 'text',
            'body' => 'Quiero agendar una cita.',
            'provider_message_id' => 'wamid.inbound.'.$conversation->id,
            'status' => 'received',
            'received_at' => now(),
        ]);

        $context = new AgentContext(
            tenant: $tenant,
            line: $line,
            conversation: $conversation,
            state: new ConversationState(['collected_data' => []]),
            agentConfig: new AgentConfig(['settings' => []]),
            triggeringMessage: $message,
            recentMessages: [],
            enabledTools: [],
        );

        return new ToolInvocation(
            context: $context,
            tool: new EnabledTool($this->tool->definition(), new TenantToolConfig()),
            arguments: $arguments,
        );
    }
}
