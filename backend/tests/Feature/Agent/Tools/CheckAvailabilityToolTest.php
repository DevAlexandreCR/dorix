<?php

namespace Tests\Feature\Agent\Tools;

use App\Domain\Agent\DTO\AgentContext;
use App\Domain\Connectors\GoogleCalendar\DTO\CalendarBusyInterval;
use App\Domain\Connectors\GoogleCalendar\FakeGoogleCalendarClient;
use App\Domain\Connectors\GoogleCalendar\GoogleCalendarAccessTokenProvider;
use App\Domain\Scheduling\SchedulingConfigResolver;
use App\Domain\Tools\DTO\EnabledTool;
use App\Domain\Tools\DTO\ToolInvocation;
use App\Domain\Tools\ToolNextAction;
use App\Domain\Tools\Tools\CheckAvailabilityTool;
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

class CheckAvailabilityToolTest extends TestCase
{
    use RefreshDatabase;

    private CheckAvailabilityTool $tool;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tool = app(CheckAvailabilityTool::class);

        // Auto-increment ids are reused across tests under RefreshDatabase; a
        // stale cached access token from an earlier test would otherwise leak
        // into this one under the same cache key (see
        // GoogleCalendarAccessTokenProviderTest for the same gotcha).
        Cache::flush();
    }

    public function test_it_returns_only_slots_that_do_not_overlap_a_partial_busy_block(): void
    {
        $tenant = $this->makeTenant();
        $line = $this->makeLine($tenant);
        $this->makeSchedulingConfig($tenant, $line);
        $this->makeCredential($line, 'refresh-token-abc');

        app(FakeGoogleCalendarClient::class)->setBusyIntervals([
            new CalendarBusyInterval(
                start: CarbonImmutable::parse('2026-08-10 09:00:00', 'America/Bogota'),
                end: CarbonImmutable::parse('2026-08-10 12:00:00', 'America/Bogota'),
            ),
        ]);

        $item = $this->makeItem($tenant, ['duration_minutes' => 60]);

        $result = $this->tool->execute($this->invocation($tenant, $line, [
            'item_id' => $item->id,
            'date' => '2026-08-10',
        ]));

        $this->assertSame(ToolNextAction::ContinueWithRetrievedContext, $result->nextAction);
        $this->assertTrue($result->outputSummary['bookable']);
        $this->assertGreaterThan(0, $result->outputSummary['slot_count']);

        $busyStart = CarbonImmutable::parse('2026-08-10 09:00:00', 'America/Bogota');
        $busyEnd = CarbonImmutable::parse('2026-08-10 12:00:00', 'America/Bogota');

        foreach ($result->outputSummary['slots'] as $slot) {
            $start = CarbonImmutable::parse($slot['start']);
            $end = CarbonImmutable::parse($slot['end']);
            $this->assertFalse($start->lessThan($busyEnd) && $end->greaterThan($busyStart));
        }

        $this->assertSame([$result->outputSummary], $result->metadata['retrieved_context']);

        $this->assertDatabaseHas('agent_events', [
            'tenant_id' => $tenant->id,
            'event_type' => 'availability_check_completed',
        ]);
    }

    public function test_it_uses_the_linked_assessment_items_duration_and_signals_it(): void
    {
        $tenant = $this->makeTenant();
        $line = $this->makeLine($tenant);
        $this->makeSchedulingConfig($tenant, $line);
        $this->makeCredential($line, 'refresh-token-abc');
        app(FakeGoogleCalendarClient::class)->setBusyIntervals([]);

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
            'date' => '2026-08-10',
        ]));

        $this->assertTrue($result->outputSummary['bookable']);
        $this->assertTrue($result->outputSummary['requires_assessment']);
        $this->assertSame($assessment->id, $result->outputSummary['booking_item_id']);
        $this->assertSame('Valoracion dermatologica', $result->outputSummary['booking_item_name']);
        $this->assertSame(30, $result->outputSummary['duration_minutes']);
        $this->assertSame(
            __('api.catalog.availability_requires_assessment', ['assessment_name' => 'Valoracion dermatologica']),
            $result->outputSummary['reason'],
        );
        $this->assertGreaterThan(0, $result->outputSummary['slot_count']);
    }

    public function test_a_closed_day_returns_zero_slots_without_triggering_a_handoff(): void
    {
        $tenant = $this->makeTenant();
        $line = $this->makeLine($tenant);
        $this->makeSchedulingConfig($tenant, $line); // only "monday" has hours configured
        $this->makeCredential($line, 'refresh-token-abc');
        app(FakeGoogleCalendarClient::class)->setBusyIntervals([]);

        $item = $this->makeItem($tenant, ['duration_minutes' => 60]);

        $result = $this->tool->execute($this->invocation($tenant, $line, [
            'item_id' => $item->id,
            'date' => '2026-08-09', // Sunday, no configured hours -> closed
        ]));

        $this->assertSame(ToolNextAction::ContinueWithRetrievedContext, $result->nextAction);
        $this->assertTrue($result->outputSummary['bookable']);
        $this->assertSame(0, $result->outputSummary['slot_count']);
        $this->assertSame([], $result->outputSummary['slots']);
        $this->assertNotEmpty($result->metadata['retrieved_context']);
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
            'date' => '2026-08-10',
        ]));

        $this->assertSame(ToolNextAction::RequestHandoff, $result->nextAction);
        $this->assertNotSame('', $result->handoffReason);
        $this->assertFalse($result->outputSummary['calendar_connection_available']);

        $this->assertDatabaseHas('agent_events', [
            'tenant_id' => $tenant->id,
            'event_type' => 'availability_check_unavailable',
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
            'date' => '2026-08-10',
        ]));

        $this->assertSame(ToolNextAction::RequestHandoff, $result->nextAction);
        $this->assertFalse($result->outputSummary['calendar_connection_available']);
    }

    public function test_a_product_is_reported_as_not_bookable_without_touching_the_calendar(): void
    {
        $tenant = $this->makeTenant();
        $line = $this->makeLine($tenant);
        $this->makeSchedulingConfig($tenant, $line);
        // deliberately no credential — proves the calendar is never contacted for a non-bookable item

        $item = $this->makeItem($tenant, ['kind' => 'product', 'duration_minutes' => null]);

        $result = $this->tool->execute($this->invocation($tenant, $line, [
            'item_id' => $item->id,
            'date' => '2026-08-10',
        ]));

        $this->assertSame(ToolNextAction::ContinueWithRetrievedContext, $result->nextAction);
        $this->assertTrue($result->outputSummary['found']);
        $this->assertFalse($result->outputSummary['bookable']);
        $this->assertSame(__('api.catalog.not_bookable_product'), $result->outputSummary['reason']);

        $this->assertDatabaseHas('agent_events', [
            'tenant_id' => $tenant->id,
            'event_type' => 'availability_check_not_bookable',
        ]);
    }

    public function test_a_service_without_a_valid_duration_is_reported_as_not_bookable(): void
    {
        $tenant = $this->makeTenant();
        $line = $this->makeLine($tenant);
        $this->makeSchedulingConfig($tenant, $line);

        $item = $this->makeItem($tenant, ['duration_minutes' => null]);

        $result = $this->tool->execute($this->invocation($tenant, $line, [
            'item_id' => $item->id,
            'date' => '2026-08-10',
        ]));

        $this->assertFalse($result->outputSummary['bookable']);
        $this->assertSame(__('api.catalog.not_bookable_no_duration'), $result->outputSummary['reason']);
    }

    public function test_it_reports_not_found_for_an_unknown_item_id(): void
    {
        $tenant = $this->makeTenant();
        $line = $this->makeLine($tenant);

        $result = $this->tool->execute($this->invocation($tenant, $line, [
            'item_id' => 999999,
            'date' => '2026-08-10',
        ]));

        $this->assertSame(ToolNextAction::ContinueWithRetrievedContext, $result->nextAction);
        $this->assertFalse($result->outputSummary['found']);
        $this->assertSame(999999, $result->outputSummary['item_id']);
        $this->assertSame(__('api.catalog.item_not_found'), $result->outputSummary['reason']);
        $this->assertSame([$result->outputSummary], $result->metadata['retrieved_context']);

        $this->assertDatabaseHas('agent_events', [
            'tenant_id' => $tenant->id,
            'event_type' => 'availability_check_item_not_found',
        ]);
    }

    public function test_it_does_not_leak_an_item_belonging_to_another_tenant(): void
    {
        $tenantA = $this->makeTenant('acme');
        $lineA = $this->makeLine($tenantA);

        $tenantB = $this->makeTenant('globex');
        $otherTenantItem = $this->makeItem($tenantB, ['name' => 'Servicio de Globex']);

        $result = $this->tool->execute($this->invocation($tenantA, $lineA, [
            'item_id' => $otherTenantItem->id,
            'date' => '2026-08-10',
        ]));

        $this->assertFalse($result->outputSummary['found']);
        $this->assertStringNotContainsString('Globex', json_encode($result->outputSummary));

        $this->assertDatabaseHas('agent_events', [
            'tenant_id' => $tenantA->id,
            'event_type' => 'availability_check_item_not_found',
        ]);
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
        ]);

        $tenant = $this->makeTenant('acme-real');
        $line = $this->makeLine($tenant, 'acme-real-phone-id');
        $this->makeSchedulingConfig($tenant, $line);
        $this->makeCredential($line, 'refresh-token-real');
        $item = $this->makeItem($tenant, ['duration_minutes' => 60]);

        $result = $this->tool->execute($this->invocation($tenant, $line, [
            'item_id' => $item->id,
            'date' => '2026-08-10',
        ], ConversationSource::WhatsApp));

        $this->assertTrue($result->outputSummary['bookable']);
        Http::assertSent(fn ($request): bool => str_contains($request->url(), 'oauth2.googleapis.com/token'));
        Http::assertSent(fn ($request): bool => str_contains($request->url(), 'freeBusy'));
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
            'date' => '2026-08-10',
        ], ConversationSource::AgentSandbox));

        $this->assertTrue($result->outputSummary['bookable']);
        Http::assertNothingSent();
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
