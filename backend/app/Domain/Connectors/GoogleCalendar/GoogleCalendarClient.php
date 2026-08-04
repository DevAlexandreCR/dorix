<?php

namespace App\Domain\Connectors\GoogleCalendar;

use App\Domain\Connectors\GoogleCalendar\DTO\CalendarAccessToken;
use App\Domain\Connectors\GoogleCalendar\DTO\CalendarBusyInterval;
use App\Domain\Connectors\GoogleCalendar\DTO\CalendarEvent;
use App\Domain\Connectors\GoogleCalendar\DTO\CalendarEventDraft;
use App\Domain\Connectors\GoogleCalendar\Exceptions\CalendarConnectionUnavailableException;
use Carbon\CarbonImmutable;

/**
 * Thin, stateless wrapper around the three Google Calendar REST calls the
 * scheduling tools need. Access token acquisition/caching is the caller's
 * responsibility (short-lived cache, never persisted) — this contract only
 * knows how to exchange a refresh token for an access token and how to use
 * an access token against the freebusy/events endpoints.
 */
interface GoogleCalendarClient
{
    /**
     * Busy blocks on the given calendar within [rangeStart, rangeEnd), used
     * to intersect with business hours and item duration when computing
     * available slots.
     *
     * @return array<int, CalendarBusyInterval>
     *
     * @throws CalendarConnectionUnavailableException when the access token is rejected (e.g. revoked access).
     */
    public function freeBusy(
        string $accessToken,
        string $calendarId,
        CarbonImmutable $rangeStart,
        CarbonImmutable $rangeEnd,
        string $timezone,
    ): array;

    /**
     * Inserts an event on the given calendar. Duration/summary/description
     * always come from the caller (catalog + customer data), never from the
     * LLM's own arithmetic.
     *
     * @throws CalendarConnectionUnavailableException when the access token is rejected (e.g. revoked access).
     */
    public function createEvent(
        string $accessToken,
        string $calendarId,
        CalendarEventDraft $event,
    ): CalendarEvent;

    /**
     * Exchanges a persisted refresh token for a short-lived access token.
     * The access token is never persisted by callers of this interface.
     *
     * @throws CalendarConnectionUnavailableException when the refresh token was revoked (Google's `invalid_grant`).
     */
    public function refreshAccessToken(string $refreshToken): CalendarAccessToken;
}
