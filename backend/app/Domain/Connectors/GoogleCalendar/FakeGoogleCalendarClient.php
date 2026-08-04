<?php

namespace App\Domain\Connectors\GoogleCalendar;

use App\Domain\Connectors\GoogleCalendar\DTO\CalendarAccessToken;
use App\Domain\Connectors\GoogleCalendar\DTO\CalendarBusyInterval;
use App\Domain\Connectors\GoogleCalendar\DTO\CalendarEvent;
use App\Domain\Connectors\GoogleCalendar\DTO\CalendarEventDraft;
use App\Domain\Connectors\GoogleCalendar\Exceptions\CalendarConnectionUnavailableException;
use Carbon\CarbonImmutable;

/**
 * Deterministic double for tests and the agent sandbox — no test or sandbox
 * conversation ever reaches the real Google API. Busy intervals are
 * settable up front; created events and refresh calls are recorded so
 * assertions can inspect what the caller asked for.
 */
class FakeGoogleCalendarClient implements GoogleCalendarClient
{
    /** @var array<int, CalendarBusyInterval> */
    private array $busyIntervals = [];

    /** @var array<int, CalendarEventDraft> */
    private array $createdEvents = [];

    /** @var array<int, string> */
    private array $refreshedTokens = [];

    private int $nextEventSequence = 1;

    private bool $connectionBroken = false;

    /**
     * @param  array<int, CalendarBusyInterval>  $intervals
     */
    public function setBusyIntervals(array $intervals): void
    {
        $this->busyIntervals = $intervals;
    }

    public function markConnectionBroken(bool $broken = true): void
    {
        $this->connectionBroken = $broken;
    }

    /**
     * @return array<int, CalendarEventDraft>
     */
    public function createdEvents(): array
    {
        return $this->createdEvents;
    }

    /**
     * @return array<int, string>
     */
    public function refreshedTokens(): array
    {
        return $this->refreshedTokens;
    }

    public function freeBusy(
        string $accessToken,
        string $calendarId,
        CarbonImmutable $rangeStart,
        CarbonImmutable $rangeEnd,
        string $timezone,
    ): array {
        $this->assertConnectionAvailable();

        return array_values(array_filter(
            $this->busyIntervals,
            static fn (CalendarBusyInterval $interval): bool => $interval->start->lessThan($rangeEnd)
                && $interval->end->greaterThan($rangeStart),
        ));
    }

    public function createEvent(
        string $accessToken,
        string $calendarId,
        CalendarEventDraft $event,
    ): CalendarEvent {
        $this->assertConnectionAvailable();

        $this->createdEvents[] = $event;

        return new CalendarEvent(
            providerEventId: sprintf('fake-event-%d', $this->nextEventSequence++),
            summary: $event->summary,
            start: $event->start,
            end: $event->end,
        );
    }

    public function refreshAccessToken(string $refreshToken): CalendarAccessToken
    {
        $this->assertConnectionAvailable();

        $this->refreshedTokens[] = $refreshToken;

        return new CalendarAccessToken(
            token: 'fake-access-token-'.$refreshToken,
            expiresAt: CarbonImmutable::now()->addHour(),
        );
    }

    private function assertConnectionAvailable(): void
    {
        if ($this->connectionBroken) {
            throw new CalendarConnectionUnavailableException(__('api.tools.calendar_connection_unavailable'));
        }
    }
}
