<?php

namespace App\Domain\Scheduling\DTO;

/**
 * A single open window within a business day, e.g. 09:00-13:00. Times are
 * "H:i" strings (24h, zero-padded) expressed in the scheduling
 * configuration's timezone — no date attached, since a window is a
 * recurring weekly rule, not a concrete instant.
 */
final readonly class BusinessHoursWindow
{
    private function __construct(
        public string $start,
        public string $end,
    ) {
    }

    /**
     * Builds a window from a raw override entry (`['start' => 'H:i', 'end' => 'H:i']`).
     * Malformed entries (missing/invalid times, or end <= start) are dropped
     * rather than throwing — a single bad window in the admin-configured
     * overrides should not take the whole day offline.
     */
    public static function tryFromArray(mixed $value): ?self
    {
        if (! is_array($value)) {
            return null;
        }

        $start = $value['start'] ?? null;
        $end = $value['end'] ?? null;

        if (! self::isValidTime($start) || ! self::isValidTime($end)) {
            return null;
        }

        if (self::toMinutes($start) >= self::toMinutes($end)) {
            return null;
        }

        return new self($start, $end);
    }

    public function startMinutes(): int
    {
        return self::toMinutes($this->start);
    }

    public function endMinutes(): int
    {
        return self::toMinutes($this->end);
    }

    private static function isValidTime(mixed $value): bool
    {
        return is_string($value) && preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $value) === 1;
    }

    private static function toMinutes(string $time): int
    {
        [$hours, $minutes] = explode(':', $time);

        return ((int) $hours * 60) + (int) $minutes;
    }
}
