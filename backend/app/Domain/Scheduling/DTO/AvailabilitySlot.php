<?php

namespace App\Domain\Scheduling\DTO;

use Carbon\CarbonImmutable;

/**
 * A concrete candidate appointment slot, already intersected with business
 * hours and busy intervals. `start`/`end` are tz-aware CarbonImmutable
 * instants (in the scheduling configuration's timezone) — safe to compare
 * or convert at the Google Calendar API boundary without further lookups.
 */
final readonly class AvailabilitySlot
{
    public function __construct(
        public CarbonImmutable $start,
        public CarbonImmutable $end,
    ) {
    }
}
