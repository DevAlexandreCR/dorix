<?php

namespace App\Domain\Connectors\GoogleCalendar\DTO;

use Carbon\CarbonImmutable;

final readonly class CalendarEvent
{
    public function __construct(
        public string $providerEventId,
        public string $summary,
        public CarbonImmutable $start,
        public CarbonImmutable $end,
    ) {
    }
}
