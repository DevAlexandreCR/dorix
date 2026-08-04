<?php

namespace App\Domain\Scheduling;

use App\Domain\Connectors\GoogleCalendar\DTO\CalendarBusyInterval;
use App\Domain\Scheduling\DTO\AvailabilitySlot;
use Carbon\CarbonImmutable;

/**
 * Pure slot arithmetic: freebusy ∩ business hours ∩ item duration, all in
 * the scheduling configuration's timezone (design D9, spec "Tool
 * check_availability con slots computados"). No I/O, no LLM — the caller
 * (check_availability, task 4.3) supplies busy intervals already fetched
 * from Google Calendar, and this class only decides which candidate start
 * times are actually bookable.
 *
 * Slot granularity: candidates are generated at a fixed 30-minute step
 * from each business-hours window's start, regardless of the requested
 * duration (a 45-minute service still gets offered at :00/:30, not just at
 * its own cadence). This matches common booking-UX conventions (e.g.
 * Calendly) and keeps the candidate list small and predictable for the
 * LLM to present, at the cost of occasionally missing a free gap that
 * exists but isn't aligned to the 30-minute grid — an accepted trade-off
 * for v1. {@see self::isSlotAvailable()} has no such limitation: it
 * validates one exact, caller-chosen instant and is what task 4.4 uses to
 * re-validate immediately before inserting the event.
 */
class SlotComputationService
{
    public const SLOT_STEP_MINUTES = 30;

    /**
     * Candidate slots for a single day, sorted chronologically. Each slot
     * fits entirely inside one business-hours window and does not overlap
     * any busy interval.
     *
     * @param  CarbonImmutable  $date  any instant on the day being queried; only the calendar date matters
     * @param  array<int, CalendarBusyInterval>  $busyIntervals  need not be sorted or merged
     * @return array<int, AvailabilitySlot>
     */
    public function availableSlotsFor(
        SchedulingConfiguration $config,
        CarbonImmutable $date,
        int $durationMinutes,
        array $busyIntervals,
    ): array {
        if ($durationMinutes <= 0) {
            return [];
        }

        $localDate = $date->setTimezone($config->timezone);
        $windows = $config->windowsForWeekday(strtolower($localDate->format('l')));

        if ($windows === []) {
            return [];
        }

        $dayStart = $localDate->startOfDay();
        $busy = $this->mergeIntervals($busyIntervals);
        $slots = [];

        foreach ($windows as $window) {
            $windowStart = $dayStart->addMinutes($window->startMinutes());
            $windowEnd = $dayStart->addMinutes($window->endMinutes());

            $cursor = $windowStart;

            while ($cursor->lessThan($windowEnd)) {
                $slotEnd = $cursor->addMinutes($durationMinutes);

                if ($slotEnd->greaterThan($windowEnd)) {
                    // Duration no longer fits before the window closes; later
                    // cursors in this window would only be later, so stop.
                    break;
                }

                if (! $this->overlapsAny($cursor, $slotEnd, $busy)) {
                    $slots[] = new AvailabilitySlot($cursor, $slotEnd);
                }

                $cursor = $cursor->addMinutes(self::SLOT_STEP_MINUTES);
            }
        }

        // Windows are processed in the order they appear in the override
        // config, which need not be chronological (admins can list them in
        // any order); sort the merged result so callers always get slots in
        // ascending start-time order regardless of that input order.
        usort($slots, static fn (AvailabilitySlot $a, AvailabilitySlot $b): int => $a->start->getTimestamp() <=> $b->start->getTimestamp());

        return $slots;
    }

    /**
     * Re-validates one exact, already-chosen slot: still inside a business
     * hours window for its weekday, and still free against a fresh
     * freebusy read. Used by create_appointment (task 4.4) immediately
     * before `events.insert` to close the check_availability -> insert
     * race (design "Riesgo: doble reserva").
     *
     * @param  array<int, CalendarBusyInterval>  $busyIntervals  need not be sorted or merged
     */
    public function isSlotAvailable(
        SchedulingConfiguration $config,
        CarbonImmutable $slotStart,
        CarbonImmutable $slotEnd,
        array $busyIntervals,
    ): bool {
        if (! $slotEnd->greaterThan($slotStart)) {
            return false;
        }

        $localStart = $slotStart->setTimezone($config->timezone);
        $localEnd = $slotEnd->setTimezone($config->timezone);
        $windows = $config->windowsForWeekday(strtolower($localStart->format('l')));
        $dayStart = $localStart->startOfDay();

        $fitsAWindow = false;

        foreach ($windows as $window) {
            $windowStart = $dayStart->addMinutes($window->startMinutes());
            $windowEnd = $dayStart->addMinutes($window->endMinutes());

            if ($localStart->greaterThanOrEqualTo($windowStart) && $localEnd->lessThanOrEqualTo($windowEnd)) {
                $fitsAWindow = true;
                break;
            }
        }

        if (! $fitsAWindow) {
            return false;
        }

        return ! $this->overlapsAny($slotStart, $slotEnd, $this->mergeIntervals($busyIntervals));
    }

    /**
     * @param  array<int, CalendarBusyInterval>  $busy  assumed already sorted/merged (see {@see self::mergeIntervals()})
     */
    private function overlapsAny(CarbonImmutable $start, CarbonImmutable $end, array $busy): bool
    {
        foreach ($busy as $interval) {
            if ($start->lessThan($interval->end) && $end->greaterThan($interval->start)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Sorts and merges busy intervals so overlap checks don't need to
     * assume normalized input — the calendar connector's fake deliberately
     * does not sort or merge what it returns, and neither should the real
     * Google Calendar response be trusted to.
     *
     * @param  array<int, CalendarBusyInterval>  $intervals
     * @return array<int, CalendarBusyInterval>
     */
    private function mergeIntervals(array $intervals): array
    {
        if ($intervals === []) {
            return [];
        }

        $sorted = $intervals;
        usort(
            $sorted,
            static fn (CalendarBusyInterval $a, CalendarBusyInterval $b): int => $a->start->getTimestamp() <=> $b->start->getTimestamp(),
        );

        $merged = [array_shift($sorted)];

        foreach ($sorted as $interval) {
            $last = $merged[array_key_last($merged)];

            if ($interval->start->greaterThan($last->end)) {
                $merged[] = $interval;

                continue;
            }

            if ($interval->end->greaterThan($last->end)) {
                $merged[array_key_last($merged)] = new CalendarBusyInterval($last->start, $interval->end);
            }
        }

        return $merged;
    }
}
