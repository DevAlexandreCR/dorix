<?php

namespace App\Domain\Connectors\GoogleCalendar\DTO;

use Carbon\CarbonImmutable;

final readonly class CalendarEventDraft
{
    public function __construct(
        public string $summary,
        public string $description,
        public CarbonImmutable $start,
        public CarbonImmutable $end,
        public string $timezone,
    ) {
    }
}
