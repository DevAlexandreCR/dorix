<?php

namespace Tests\Unit\Domain\Connectors\GoogleCalendar;

use App\Domain\Connectors\GoogleCalendar\DTO\CalendarBusyInterval;
use App\Domain\Connectors\GoogleCalendar\DTO\CalendarEventDraft;
use App\Domain\Connectors\GoogleCalendar\Exceptions\CalendarConnectionUnavailableException;
use App\Domain\Connectors\GoogleCalendar\FakeGoogleCalendarClient;
use App\Domain\Connectors\GoogleCalendar\GoogleCalendarClient;
use App\Domain\Connectors\GoogleCalendar\GoogleCalendarClientResolver;
use App\Domain\Connectors\GoogleCalendar\HttpGoogleCalendarClient;
use App\Enums\ConversationSource;
use Carbon\CarbonImmutable;
use Tests\TestCase;

class FakeGoogleCalendarClientTest extends TestCase
{
    public function test_container_resolves_the_real_http_client_by_default(): void
    {
        $client = $this->app->make(GoogleCalendarClient::class);

        $this->assertInstanceOf(HttpGoogleCalendarClient::class, $client);
    }

    public function test_the_fake_google_calendar_test_helper_swaps_the_binding(): void
    {
        $fake = $this->fakeGoogleCalendar();

        $this->assertSame($fake, $this->app->make(GoogleCalendarClient::class));
    }

    public function test_the_resolver_returns_the_fake_for_sandbox_conversations(): void
    {
        $resolver = $this->app->make(GoogleCalendarClientResolver::class);

        $this->assertInstanceOf(
            FakeGoogleCalendarClient::class,
            $resolver->forConversationSource(ConversationSource::AgentSandbox),
        );
    }

    public function test_the_resolver_returns_the_default_binding_for_whatsapp_conversations(): void
    {
        $resolver = $this->app->make(GoogleCalendarClientResolver::class);

        $this->assertInstanceOf(
            HttpGoogleCalendarClient::class,
            $resolver->forConversationSource(ConversationSource::WhatsApp),
        );
    }

    public function test_free_busy_returns_only_intervals_overlapping_the_requested_range(): void
    {
        $client = new FakeGoogleCalendarClient();

        $withinRange = new CalendarBusyInterval(
            start: CarbonImmutable::parse('2026-08-10 10:00:00', 'America/Bogota'),
            end: CarbonImmutable::parse('2026-08-10 11:00:00', 'America/Bogota'),
        );
        $outsideRange = new CalendarBusyInterval(
            start: CarbonImmutable::parse('2026-08-12 10:00:00', 'America/Bogota'),
            end: CarbonImmutable::parse('2026-08-12 11:00:00', 'America/Bogota'),
        );

        $client->setBusyIntervals([$withinRange, $outsideRange]);

        $result = $client->freeBusy(
            accessToken: 'fake-token',
            calendarId: 'primary',
            rangeStart: CarbonImmutable::parse('2026-08-10 00:00:00', 'America/Bogota'),
            rangeEnd: CarbonImmutable::parse('2026-08-11 00:00:00', 'America/Bogota'),
            timezone: 'America/Bogota',
        );

        $this->assertCount(1, $result);
        $this->assertTrue($result[0]->start->equalTo($withinRange->start));
    }

    public function test_create_event_records_the_draft_and_returns_a_deterministic_event(): void
    {
        $client = new FakeGoogleCalendarClient();

        $draft = new CalendarEventDraft(
            summary: 'Limpieza facial — Maria Perez',
            description: 'Tel: +573001234567. Origen: Dorix.',
            start: CarbonImmutable::parse('2026-08-10 10:00:00', 'America/Bogota'),
            end: CarbonImmutable::parse('2026-08-10 11:00:00', 'America/Bogota'),
            timezone: 'America/Bogota',
        );

        $event = $client->createEvent('fake-token', 'primary', $draft);

        $this->assertSame('fake-event-1', $event->providerEventId);
        $this->assertSame($draft->summary, $event->summary);
        $this->assertCount(1, $client->createdEvents());
        $this->assertSame($draft, $client->createdEvents()[0]);
    }

    public function test_refresh_access_token_records_the_refresh_token_used(): void
    {
        $client = new FakeGoogleCalendarClient();

        $token = $client->refreshAccessToken('refresh-token-abc');

        $this->assertSame('fake-access-token-refresh-token-abc', $token->token);
        $this->assertTrue($token->expiresAt->isFuture());
        $this->assertSame(['refresh-token-abc'], $client->refreshedTokens());
    }

    public function test_broken_connection_raises_the_calendar_unavailable_exception_on_every_call(): void
    {
        $client = new FakeGoogleCalendarClient();
        $client->markConnectionBroken();

        $this->expectException(CalendarConnectionUnavailableException::class);

        $client->refreshAccessToken('refresh-token-abc');
    }
}
