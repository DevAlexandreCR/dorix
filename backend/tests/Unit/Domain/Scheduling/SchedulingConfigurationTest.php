<?php

namespace Tests\Unit\Domain\Scheduling;

use App\Domain\Scheduling\SchedulingConfiguration;
use Tests\TestCase;

class SchedulingConfigurationTest extends TestCase
{
    public function test_defaults_apply_when_overrides_are_empty(): void
    {
        $config = SchedulingConfiguration::fromOverrides([]);

        $this->assertSame(SchedulingConfiguration::DEFAULT_CALENDAR_ID, $config->calendarId);
        $this->assertSame(SchedulingConfiguration::DEFAULT_TIMEZONE, $config->timezone);
        $this->assertSame([], $config->windowsForWeekday('monday'));
    }

    public function test_calendar_id_and_timezone_are_read_from_overrides(): void
    {
        $config = SchedulingConfiguration::fromOverrides([
            'calendar_id' => 'staff@example.com',
            'timezone' => 'America/Mexico_City',
        ]);

        $this->assertSame('staff@example.com', $config->calendarId);
        $this->assertSame('America/Mexico_City', $config->timezone);
    }

    public function test_business_hours_windows_are_parsed_per_weekday(): void
    {
        $config = SchedulingConfiguration::fromOverrides([
            'business_hours' => [
                'monday' => [
                    ['start' => '09:00', 'end' => '13:00'],
                    ['start' => '14:00', 'end' => '18:00'],
                ],
                'sunday' => [],
            ],
        ]);

        $mondayWindows = $config->windowsForWeekday('monday');
        $this->assertCount(2, $mondayWindows);
        $this->assertSame('09:00', $mondayWindows[0]->start);
        $this->assertSame('18:00', $mondayWindows[1]->end);

        // Weekday key present but empty, and weekday key absent entirely,
        // both mean "closed" — no distinction the caller needs to make.
        $this->assertSame([], $config->windowsForWeekday('sunday'));
        $this->assertSame([], $config->windowsForWeekday('tuesday'));
    }

    public function test_weekday_lookup_is_case_insensitive(): void
    {
        $config = SchedulingConfiguration::fromOverrides([
            'business_hours' => [
                'monday' => [['start' => '09:00', 'end' => '13:00']],
            ],
        ]);

        $this->assertCount(1, $config->windowsForWeekday('Monday'));
        $this->assertCount(1, $config->windowsForWeekday('MONDAY'));
    }

    public function test_malformed_windows_are_dropped_without_throwing(): void
    {
        $config = SchedulingConfiguration::fromOverrides([
            'business_hours' => [
                'monday' => [
                    ['start' => '09:00', 'end' => '13:00'],
                    ['start' => 'not-a-time', 'end' => '18:00'],
                    ['start' => '18:00', 'end' => '18:00'], // zero-length
                    ['start' => '20:00', 'end' => '19:00'], // reversed
                    'not-an-array',
                    ['start' => '19:00'], // missing end
                ],
            ],
        ]);

        $windows = $config->windowsForWeekday('monday');
        $this->assertCount(1, $windows);
        $this->assertSame('09:00', $windows[0]->start);
        $this->assertSame('13:00', $windows[0]->end);
    }

    public function test_non_string_calendar_id_and_timezone_fall_back_to_defaults(): void
    {
        $config = SchedulingConfiguration::fromOverrides([
            'calendar_id' => 42,
            'timezone' => '',
            'business_hours' => 'not-an-array',
        ]);

        $this->assertSame(SchedulingConfiguration::DEFAULT_CALENDAR_ID, $config->calendarId);
        $this->assertSame(SchedulingConfiguration::DEFAULT_TIMEZONE, $config->timezone);
        $this->assertSame([], $config->windowsForWeekday('monday'));
    }
}
