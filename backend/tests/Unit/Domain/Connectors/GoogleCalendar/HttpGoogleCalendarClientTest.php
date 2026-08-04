<?php

namespace Tests\Unit\Domain\Connectors\GoogleCalendar;

use App\Domain\Connectors\GoogleCalendar\DTO\CalendarEventDraft;
use App\Domain\Connectors\GoogleCalendar\Exceptions\CalendarConnectionUnavailableException;
use App\Domain\Connectors\GoogleCalendar\HttpGoogleCalendarClient;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class HttpGoogleCalendarClientTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Config::set('services.google.client_id', 'test-client-id');
        Config::set('services.google.client_secret', 'test-client-secret');
        Config::set('services.google.calendar_base_url', 'https://www.googleapis.com/calendar/v3');
        Config::set('services.google.oauth_token_url', 'https://oauth2.googleapis.com/token');
    }

    public function test_free_busy_maps_the_calendar_response_into_busy_intervals(): void
    {
        Http::fake([
            'https://www.googleapis.com/calendar/v3/freeBusy' => Http::response([
                'calendars' => [
                    'primary' => [
                        'busy' => [
                            ['start' => '2026-08-10T15:00:00Z', 'end' => '2026-08-10T16:00:00Z'],
                        ],
                    ],
                ],
            ], 200),
        ]);

        $client = new HttpGoogleCalendarClient(app(HttpFactory::class));

        $intervals = $client->freeBusy(
            accessToken: 'valid-access-token',
            calendarId: 'primary',
            rangeStart: CarbonImmutable::parse('2026-08-10 00:00:00', 'America/Bogota'),
            rangeEnd: CarbonImmutable::parse('2026-08-11 00:00:00', 'America/Bogota'),
            timezone: 'America/Bogota',
        );

        $this->assertCount(1, $intervals);
        $this->assertTrue($intervals[0]->start->equalTo(CarbonImmutable::parse('2026-08-10T15:00:00Z')));

        Http::assertSent(function ($request): bool {
            return $request->url() === 'https://www.googleapis.com/calendar/v3/freeBusy'
                && $request->hasHeader('Authorization', 'Bearer valid-access-token')
                && $request['timeZone'] === 'America/Bogota';
        });
    }

    public function test_create_event_returns_the_provider_event_id(): void
    {
        Http::fake([
            'https://www.googleapis.com/calendar/v3/calendars/primary/events' => Http::response([
                'id' => 'google-event-123',
                'summary' => 'Limpieza facial — Maria Perez',
            ], 200),
        ]);

        $client = new HttpGoogleCalendarClient(app(HttpFactory::class));

        $draft = new CalendarEventDraft(
            summary: 'Limpieza facial — Maria Perez',
            description: 'Tel: +573001234567. Origen: Dorix.',
            start: CarbonImmutable::parse('2026-08-10 10:00:00', 'America/Bogota'),
            end: CarbonImmutable::parse('2026-08-10 11:00:00', 'America/Bogota'),
            timezone: 'America/Bogota',
        );

        $event = $client->createEvent('valid-access-token', 'primary', $draft);

        $this->assertSame('google-event-123', $event->providerEventId);
        $this->assertSame('Limpieza facial — Maria Perez', $event->summary);

        Http::assertSentCount(1);
    }

    public function test_refresh_access_token_returns_a_short_lived_token(): void
    {
        Http::fake([
            'https://oauth2.googleapis.com/token' => Http::response([
                'access_token' => 'fresh-access-token',
                'expires_in' => 3600,
                'token_type' => 'Bearer',
            ], 200),
        ]);

        $client = new HttpGoogleCalendarClient(app(HttpFactory::class));

        $token = $client->refreshAccessToken('a-refresh-token');

        $this->assertSame('fresh-access-token', $token->token);
        $this->assertTrue($token->expiresAt->isFuture());

        Http::assertSent(function ($request): bool {
            return $request->url() === 'https://oauth2.googleapis.com/token'
                && $request['refresh_token'] === 'a-refresh-token'
                && $request['grant_type'] === 'refresh_token';
        });
    }

    public function test_refresh_access_token_maps_invalid_grant_to_connection_unavailable(): void
    {
        Http::fake([
            'https://oauth2.googleapis.com/token' => Http::response([
                'error' => 'invalid_grant',
                'error_description' => 'Token has been expired or revoked.',
            ], 400),
        ]);

        $client = new HttpGoogleCalendarClient(app(HttpFactory::class));

        $this->expectException(CalendarConnectionUnavailableException::class);

        $client->refreshAccessToken('a-revoked-refresh-token');
    }

    public function test_free_busy_maps_an_unauthorized_response_to_connection_unavailable(): void
    {
        Http::fake([
            'https://www.googleapis.com/calendar/v3/freeBusy' => Http::response([
                'error' => ['code' => 401, 'message' => 'Invalid Credentials'],
            ], 401),
        ]);

        $client = new HttpGoogleCalendarClient(app(HttpFactory::class));

        $this->expectException(CalendarConnectionUnavailableException::class);

        $client->freeBusy(
            accessToken: 'stale-access-token',
            calendarId: 'primary',
            rangeStart: CarbonImmutable::parse('2026-08-10 00:00:00', 'America/Bogota'),
            rangeEnd: CarbonImmutable::parse('2026-08-11 00:00:00', 'America/Bogota'),
            timezone: 'America/Bogota',
        );
    }
}
