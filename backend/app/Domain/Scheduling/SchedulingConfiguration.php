<?php

namespace App\Domain\Scheduling;

use App\Domain\Scheduling\DTO\BusinessHoursWindow;

/**
 * Resolved scheduling configuration for a line: target Google Calendar id,
 * business hours per weekday, and the timezone all slot arithmetic happens
 * in (design D5/D9, spec "Configuración de agendamiento por scope").
 *
 * Built from the raw `tenant_tool_configs.overrides` JSON via
 * {@see self::fromOverrides()} — see {@see SchedulingConfigResolver} for how
 * that array is resolved with line->tenant fallback. Expected shape:
 *
 * ```json
 * {
 *   "calendar_id": "primary",
 *   "timezone": "America/Bogota",
 *   "business_hours": {
 *     "monday":    [{"start": "09:00", "end": "13:00"}, {"start": "14:00", "end": "18:00"}],
 *     "tuesday":   [{"start": "09:00", "end": "18:00"}],
 *     "wednesday": [{"start": "09:00", "end": "18:00"}],
 *     "thursday":  [{"start": "09:00", "end": "18:00"}],
 *     "friday":    [{"start": "09:00", "end": "18:00"}],
 *     "saturday":  [{"start": "09:00", "end": "13:00"}],
 *     "sunday":    []
 *   }
 * }
 * ```
 *
 * Weekday keys are lowercase English day names. A missing key or an empty
 * array means the line is closed that day. `calendar_id` defaults to
 * `"primary"` (Google's alias for the account's main calendar) and
 * `timezone` defaults to `America/Bogota`, matching design D5.
 */
final readonly class SchedulingConfiguration
{
    public const DEFAULT_CALENDAR_ID = 'primary';

    public const DEFAULT_TIMEZONE = 'America/Bogota';

    private const WEEKDAYS = [
        'monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday',
    ];

    /**
     * @param  array<string, array<int, BusinessHoursWindow>>  $businessHours  keyed by lowercase English weekday name
     */
    private function __construct(
        public string $calendarId,
        public string $timezone,
        public array $businessHours,
    ) {
    }

    /**
     * @param  array<string, mixed>  $overrides  raw, already line->tenant-resolved `tenant_tool_configs.overrides`
     */
    public static function fromOverrides(array $overrides): self
    {
        $calendarId = $overrides['calendar_id'] ?? null;
        $timezone = $overrides['timezone'] ?? null;

        return new self(
            calendarId: is_string($calendarId) && $calendarId !== '' ? $calendarId : self::DEFAULT_CALENDAR_ID,
            timezone: is_string($timezone) && $timezone !== '' ? $timezone : self::DEFAULT_TIMEZONE,
            businessHours: self::parseBusinessHours($overrides['business_hours'] ?? null),
        );
    }

    /**
     * @return array<int, BusinessHoursWindow> windows for the given weekday, empty when closed
     */
    public function windowsForWeekday(string $weekday): array
    {
        return $this->businessHours[strtolower($weekday)] ?? [];
    }

    /**
     * @param  mixed  $raw
     * @return array<string, array<int, BusinessHoursWindow>>
     */
    private static function parseBusinessHours(mixed $raw): array
    {
        if (! is_array($raw)) {
            return [];
        }

        $parsed = [];

        foreach (self::WEEKDAYS as $weekday) {
            $windows = $raw[$weekday] ?? [];

            if (! is_array($windows)) {
                continue;
            }

            $parsed[$weekday] = array_values(array_filter(array_map(
                static fn (mixed $window): ?BusinessHoursWindow => BusinessHoursWindow::tryFromArray($window),
                $windows,
            )));
        }

        return $parsed;
    }
}
