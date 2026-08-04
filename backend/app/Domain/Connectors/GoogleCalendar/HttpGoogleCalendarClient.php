<?php

namespace App\Domain\Connectors\GoogleCalendar;

use App\Domain\Connectors\GoogleCalendar\DTO\CalendarAccessToken;
use App\Domain\Connectors\GoogleCalendar\DTO\CalendarBusyInterval;
use App\Domain\Connectors\GoogleCalendar\DTO\CalendarEvent;
use App\Domain\Connectors\GoogleCalendar\DTO\CalendarEventDraft;
use App\Domain\Connectors\GoogleCalendar\Exceptions\CalendarConnectionUnavailableException;
use App\Domain\Connectors\GoogleCalendar\Exceptions\CalendarRequestFailedException;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\Response;

/**
 * Real HTTP-backed implementation of GoogleCalendarClient, talking to the
 * three plain Google REST endpoints the scheduling tools need
 * (freebusy.query, events.insert, oauth2/token) — no Google SDK, no
 * Socialite (design D6). Bound as the default GoogleCalendarClient
 * implementation in AppServiceProvider.
 */
class HttpGoogleCalendarClient implements GoogleCalendarClient
{
    public function __construct(
        protected HttpFactory $http,
    ) {
    }

    public function freeBusy(
        string $accessToken,
        string $calendarId,
        CarbonImmutable $rangeStart,
        CarbonImmutable $rangeEnd,
        string $timezone,
    ): array {
        $response = $this->http
            ->baseUrl($this->calendarBaseUrl())
            ->withToken($accessToken)
            ->acceptJson()
            ->post('/freeBusy', [
                'timeMin' => $rangeStart->toRfc3339String(),
                'timeMax' => $rangeEnd->toRfc3339String(),
                'timeZone' => $timezone,
                'items' => [
                    ['id' => $calendarId],
                ],
            ]);

        $this->assertAccessTokenAccepted($response);

        $busy = data_get($response->json(), "calendars.{$calendarId}.busy");

        if (! is_array($busy)) {
            return [];
        }

        return array_values(array_map(
            static fn (array $interval): CalendarBusyInterval => new CalendarBusyInterval(
                start: CarbonImmutable::parse((string) $interval['start']),
                end: CarbonImmutable::parse((string) $interval['end']),
            ),
            array_filter(
                $busy,
                static fn ($interval): bool => is_array($interval) && isset($interval['start'], $interval['end']),
            ),
        ));
    }

    public function createEvent(
        string $accessToken,
        string $calendarId,
        CalendarEventDraft $event,
    ): CalendarEvent {
        $response = $this->http
            ->baseUrl($this->calendarBaseUrl())
            ->withToken($accessToken)
            ->acceptJson()
            ->post(sprintf('/calendars/%s/events', rawurlencode($calendarId)), [
                'summary' => $event->summary,
                'description' => $event->description,
                'start' => [
                    'dateTime' => $event->start->toRfc3339String(),
                    'timeZone' => $event->timezone,
                ],
                'end' => [
                    'dateTime' => $event->end->toRfc3339String(),
                    'timeZone' => $event->timezone,
                ],
            ]);

        $this->assertAccessTokenAccepted($response);

        $data = $response->json();
        $eventId = is_array($data) ? ($data['id'] ?? null) : null;

        if (! is_string($eventId) || $eventId === '') {
            throw new CalendarRequestFailedException(__('api.tools.calendar_request_failed'));
        }

        return new CalendarEvent(
            providerEventId: $eventId,
            summary: is_string($data['summary'] ?? null) ? $data['summary'] : $event->summary,
            start: $event->start,
            end: $event->end,
        );
    }

    public function refreshAccessToken(string $refreshToken): CalendarAccessToken
    {
        $response = $this->http
            ->asForm()
            ->acceptJson()
            ->post($this->oauthTokenUrl(), [
                'client_id' => (string) config('services.google.client_id'),
                'client_secret' => (string) config('services.google.client_secret'),
                'refresh_token' => $refreshToken,
                'grant_type' => 'refresh_token',
            ]);

        if (! $response->successful()) {
            if ($this->isInvalidGrant($response)) {
                throw new CalendarConnectionUnavailableException(__('api.tools.calendar_connection_unavailable'));
            }

            throw new CalendarRequestFailedException(__('api.tools.calendar_request_failed'));
        }

        $data = $response->json();
        $accessToken = is_array($data) ? ($data['access_token'] ?? null) : null;
        $expiresIn = is_array($data) ? ($data['expires_in'] ?? null) : null;

        if (! is_string($accessToken) || $accessToken === '') {
            throw new CalendarRequestFailedException(__('api.tools.calendar_request_failed'));
        }

        return new CalendarAccessToken(
            token: $accessToken,
            expiresAt: CarbonImmutable::now()->addSeconds(is_numeric($expiresIn) ? (int) $expiresIn : 3600),
        );
    }

    protected function assertAccessTokenAccepted(Response $response): void
    {
        if (in_array($response->status(), [401, 403], true)) {
            throw new CalendarConnectionUnavailableException(__('api.tools.calendar_connection_unavailable'));
        }

        if (! $response->successful()) {
            throw new CalendarRequestFailedException(__('api.tools.calendar_request_failed'));
        }
    }

    protected function isInvalidGrant(Response $response): bool
    {
        $data = $response->json();

        return is_array($data) && ($data['error'] ?? null) === 'invalid_grant';
    }

    protected function calendarBaseUrl(): string
    {
        return rtrim((string) config('services.google.calendar_base_url', 'https://www.googleapis.com/calendar/v3'), '/');
    }

    protected function oauthTokenUrl(): string
    {
        return (string) config('services.google.oauth_token_url', 'https://oauth2.googleapis.com/token');
    }
}
