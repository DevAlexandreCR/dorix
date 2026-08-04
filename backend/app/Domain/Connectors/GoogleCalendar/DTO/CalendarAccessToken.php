<?php

namespace App\Domain\Connectors\GoogleCalendar\DTO;

use Carbon\CarbonImmutable;

final readonly class CalendarAccessToken
{
    public function __construct(
        public string $token,
        public CarbonImmutable $expiresAt,
    ) {
    }
}
