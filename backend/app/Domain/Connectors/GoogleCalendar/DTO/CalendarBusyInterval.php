<?php

namespace App\Domain\Connectors\GoogleCalendar\DTO;

use Carbon\CarbonImmutable;

final readonly class CalendarBusyInterval
{
    public function __construct(
        public CarbonImmutable $start,
        public CarbonImmutable $end,
    ) {
    }
}
