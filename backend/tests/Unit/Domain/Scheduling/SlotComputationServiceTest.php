<?php

namespace Tests\Unit\Domain\Scheduling;

use App\Domain\Connectors\GoogleCalendar\DTO\CalendarBusyInterval;
use App\Domain\Scheduling\SchedulingConfiguration;
use App\Domain\Scheduling\SlotComputationService;
use Carbon\CarbonImmutable;
use Tests\TestCase;

class SlotComputationServiceTest extends TestCase
{
    private SlotComputationService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = new SlotComputationService();
    }

    private function bogotaConfig(array $businessHours): SchedulingConfiguration
    {
        return SchedulingConfiguration::fromOverrides([
            'timezone' => 'America/Bogota',
            'business_hours' => $businessHours,
        ]);
    }

    /** A Monday, chosen arbitrarily and fixed across tests. */
    private function monday(string $time = '00:00', string $timezone = 'America/Bogota'): CarbonImmutable
    {
        return CarbonImmutable::parse("2026-08-03 {$time}", $timezone);
    }

    public function test_fully_free_day_yields_slots_at_every_step_that_fit_duration(): void
    {
        $config = $this->bogotaConfig([
            'monday' => [['start' => '09:00', 'end' => '10:30']],
        ]);

        $slots = $this->service->availableSlotsFor($config, $this->monday(), 30, []);

        // 09:00, 09:30, 10:00 all fit a 30-minute service before 10:30 closes.
        $this->assertCount(3, $slots);
        $this->assertSame('09:00', $slots[0]->start->format('H:i'));
        $this->assertSame('09:30', $slots[0]->end->format('H:i'));
        $this->assertSame('10:00', $slots[2]->start->format('H:i'));
        $this->assertSame('10:30', $slots[2]->end->format('H:i'));
    }

    public function test_closed_day_yields_no_slots(): void
    {
        $config = $this->bogotaConfig([
            'monday' => [], // explicit empty
        ]);

        $slots = $this->service->availableSlotsFor($config, $this->monday(), 30, []);

        $this->assertSame([], $slots);
    }

    public function test_weekday_absent_from_business_hours_yields_no_slots(): void
    {
        $config = $this->bogotaConfig([
            'tuesday' => [['start' => '09:00', 'end' => '18:00']],
        ]);

        // Monday has no key at all in business_hours -> closed.
        $slots = $this->service->availableSlotsFor($config, $this->monday(), 30, []);

        $this->assertSame([], $slots);
    }

    public function test_duration_longer_than_any_window_yields_no_slots(): void
    {
        $config = $this->bogotaConfig([
            'monday' => [['start' => '09:00', 'end' => '09:30']],
        ]);

        $slots = $this->service->availableSlotsFor($config, $this->monday(), 60, []);

        $this->assertSame([], $slots);
    }

    public function test_partial_busy_block_excludes_only_overlapping_slots(): void
    {
        $config = $this->bogotaConfig([
            'monday' => [['start' => '09:00', 'end' => '11:00']],
        ]);

        $busy = [
            new CalendarBusyInterval(
                start: $this->monday('09:30'),
                end: $this->monday('10:00'),
            ),
        ];

        $slots = $this->service->availableSlotsFor($config, $this->monday(), 30, $busy);
        $starts = array_map(static fn ($slot) => $slot->start->format('H:i'), $slots);

        // 09:00 fits before busy; 09:30 and (30-min-duration ending at 10:00,
        // overlapping [09:30,10:00) at the boundary check) are excluded;
        // 10:00 and 10:30 are free again.
        $this->assertSame(['09:00', '10:00', '10:30'], $starts);
    }

    public function test_busy_block_straddling_start_of_business_hours(): void
    {
        $config = $this->bogotaConfig([
            'monday' => [['start' => '09:00', 'end' => '10:00']],
        ]);

        $busy = [
            new CalendarBusyInterval(
                start: $this->monday('08:30'),
                end: $this->monday('09:15'),
            ),
        ];

        $slots = $this->service->availableSlotsFor($config, $this->monday(), 30, $busy);
        $starts = array_map(static fn ($slot) => $slot->start->format('H:i'), $slots);

        // 09:00-09:30 overlaps the tail of the busy block; 09:30-10:00 is free.
        $this->assertSame(['09:30'], $starts);
    }

    public function test_busy_block_straddling_end_of_business_hours(): void
    {
        $config = $this->bogotaConfig([
            'monday' => [['start' => '09:00', 'end' => '13:00']],
        ]);

        $busy = [
            new CalendarBusyInterval(
                start: $this->monday('12:45'),
                end: $this->monday('13:30'),
            ),
        ];

        $slots = $this->service->availableSlotsFor($config, $this->monday(), 30, $busy);
        $starts = array_map(static fn ($slot) => $slot->start->format('H:i'), $slots);

        // 12:00-12:30 is free, 12:30-13:00 overlaps the busy block's start.
        $this->assertContains('12:00', $starts);
        $this->assertNotContains('12:30', $starts);
    }

    public function test_back_to_back_busy_blocks_leave_no_gap_between_them(): void
    {
        $config = $this->bogotaConfig([
            'monday' => [['start' => '09:00', 'end' => '12:00']],
        ]);

        $busy = [
            new CalendarBusyInterval(start: $this->monday('10:00'), end: $this->monday('10:30')),
            new CalendarBusyInterval(start: $this->monday('10:30'), end: $this->monday('11:00')),
        ];

        $slots = $this->service->availableSlotsFor($config, $this->monday(), 30, $busy);
        $starts = array_map(static fn ($slot) => $slot->start->format('H:i'), $slots);

        $this->assertNotContains('10:00', $starts);
        $this->assertNotContains('10:30', $starts);
        // Immediately after the combined busy span is free.
        $this->assertContains('11:00', $starts);
    }

    public function test_unsorted_and_overlapping_busy_intervals_are_handled_correctly(): void
    {
        $config = $this->bogotaConfig([
            'monday' => [['start' => '09:00', 'end' => '12:00']],
        ]);

        // Deliberately unsorted and overlapping, mirroring FakeGoogleCalendarClient's
        // contract of NOT normalizing what it returns.
        $busy = [
            new CalendarBusyInterval(start: $this->monday('10:30'), end: $this->monday('11:15')),
            new CalendarBusyInterval(start: $this->monday('10:00'), end: $this->monday('10:45')),
        ];

        $slots = $this->service->availableSlotsFor($config, $this->monday(), 30, $busy);
        $starts = array_map(static fn ($slot) => $slot->start->format('H:i'), $slots);

        // Merged busy span is 10:00-11:15; nothing in that window should appear.
        $this->assertNotContains('10:00', $starts);
        $this->assertNotContains('10:30', $starts);
        $this->assertNotContains('11:00', $starts);
        $this->assertContains('09:00', $starts);
        $this->assertContains('09:30', $starts);
        // Candidates are on the fixed 30-minute grid from the window start
        // (09:00), so the first grid-aligned candidate fully past the merged
        // busy span (10:00-11:15) is 11:30, not 11:15 itself.
        $this->assertContains('11:30', $starts);
    }

    public function test_multiple_windows_in_a_day_each_produce_slots(): void
    {
        $config = $this->bogotaConfig([
            'monday' => [
                ['start' => '09:00', 'end' => '10:00'],
                ['start' => '14:00', 'end' => '15:00'],
            ],
        ]);

        $slots = $this->service->availableSlotsFor($config, $this->monday(), 30, []);
        $starts = array_map(static fn ($slot) => $slot->start->format('H:i'), $slots);

        $this->assertSame(['09:00', '09:30', '14:00', '14:30'], $starts);
    }

    public function test_slots_are_computed_in_the_configured_non_default_timezone(): void
    {
        $config = $this->bogotaConfig([
            'monday' => [['start' => '09:00', 'end' => '10:00']],
        ]);
        $mexicoConfig = SchedulingConfiguration::fromOverrides([
            'timezone' => 'America/Mexico_City',
            'business_hours' => [
                'monday' => [['start' => '09:00', 'end' => '10:00']],
            ],
        ]);

        // Same wall-clock local business hours in two different timezones
        // (Bogota UTC-5, Mexico City UTC-6) must produce different absolute
        // instants one hour apart.
        $bogotaSlots = $this->service->availableSlotsFor($config, $this->monday('12:00', 'UTC'), 30, []);
        $mexicoSlots = $this->service->availableSlotsFor($mexicoConfig, $this->monday('12:00', 'UTC'), 30, []);

        $this->assertNotEmpty($bogotaSlots);
        $this->assertNotEmpty($mexicoSlots);

        // 09:00 America/Bogota (UTC-5) = 14:00 UTC; 09:00 America/Mexico_City
        // (UTC-6) = 15:00 UTC — same local wall-clock hour, one hour apart in
        // absolute time.
        $this->assertSame('14:00', $bogotaSlots[0]->start->setTimezone('UTC')->format('H:i'));
        $this->assertSame('15:00', $mexicoSlots[0]->start->setTimezone('UTC')->format('H:i'));
        $this->assertSame(
            3600,
            $mexicoSlots[0]->start->getTimestamp() - $bogotaSlots[0]->start->getTimestamp(),
        );
    }

    public function test_slots_are_sorted_chronologically_across_windows(): void
    {
        $config = $this->bogotaConfig([
            'monday' => [
                ['start' => '14:00', 'end' => '15:00'],
                ['start' => '09:00', 'end' => '10:00'],
            ],
        ]);

        // Windows are defined out of order in the override; the computed
        // slots for the earlier window must still come first.
        $slots = $this->service->availableSlotsFor($config, $this->monday(), 30, []);
        $starts = array_map(static fn ($slot) => $slot->start->format('H:i'), $slots);

        $this->assertSame(['09:00', '09:30', '14:00', '14:30'], $starts);
    }

    // --- isSlotAvailable (single-slot revalidation, used by create_appointment / task 4.4) ---

    public function test_is_slot_available_true_for_a_free_slot_inside_business_hours(): void
    {
        $config = $this->bogotaConfig([
            'monday' => [['start' => '09:00', 'end' => '17:00']],
        ]);

        $available = $this->service->isSlotAvailable(
            $config,
            $this->monday('10:00'),
            $this->monday('10:30'),
            [],
        );

        $this->assertTrue($available);
    }

    public function test_is_slot_available_false_when_slot_overlaps_busy_interval(): void
    {
        $config = $this->bogotaConfig([
            'monday' => [['start' => '09:00', 'end' => '17:00']],
        ]);

        $busy = [new CalendarBusyInterval(start: $this->monday('10:15'), end: $this->monday('10:45'))];

        $available = $this->service->isSlotAvailable(
            $config,
            $this->monday('10:00'),
            $this->monday('10:30'),
            $busy,
        );

        $this->assertFalse($available);
    }

    public function test_is_slot_available_false_when_outside_business_hours(): void
    {
        $config = $this->bogotaConfig([
            'monday' => [['start' => '09:00', 'end' => '17:00']],
        ]);

        $available = $this->service->isSlotAvailable(
            $config,
            $this->monday('17:30'),
            $this->monday('18:00'),
            [],
        );

        $this->assertFalse($available);
    }

    public function test_is_slot_available_false_on_a_closed_day(): void
    {
        $config = $this->bogotaConfig([
            'monday' => [],
        ]);

        $available = $this->service->isSlotAvailable(
            $config,
            $this->monday('10:00'),
            $this->monday('10:30'),
            [],
        );

        $this->assertFalse($available);
    }

    public function test_is_slot_available_false_when_end_is_not_after_start(): void
    {
        $config = $this->bogotaConfig([
            'monday' => [['start' => '09:00', 'end' => '17:00']],
        ]);

        $available = $this->service->isSlotAvailable(
            $config,
            $this->monday('10:00'),
            $this->monday('10:00'),
            [],
        );

        $this->assertFalse($available);
    }

    public function test_is_slot_available_handles_unsorted_overlapping_busy_intervals(): void
    {
        $config = $this->bogotaConfig([
            'monday' => [['start' => '09:00', 'end' => '17:00']],
        ]);

        $busy = [
            new CalendarBusyInterval(start: $this->monday('11:00'), end: $this->monday('11:45')),
            new CalendarBusyInterval(start: $this->monday('10:30'), end: $this->monday('11:15')),
        ];

        $available = $this->service->isSlotAvailable(
            $config,
            $this->monday('11:20'),
            $this->monday('11:50'),
            $busy,
        );

        $this->assertFalse($available);
    }
}
