<?php

namespace App\Domain\Connectors\GoogleCalendar;

use App\Models\ApiCredential;

/**
 * Presentation of a WhatsApp line's Google Calendar connection, derived
 * from `api_credentials` exactly as it exists today — no new migration
 * (design D12, spec "Detección y señalización de conexión rota").
 *
 * - `none`: no `google_calendar` / `refresh_token` credential row for the line.
 * - `connected`: the credential exists and is not flagged broken.
 * - `broken`: the credential exists and `metadata.calendar_connection_status`
 *   is `'broken'`. Set by GoogleCalendarAccessTokenProvider when Google
 *   rejects the refresh token with `invalid_grant`.
 *
 * Heads-up for task 3.4: call `forCredential($credential)->value` per line
 * in AdminPanelDataBuilder to expose `connected|broken|none`.
 * Heads-up for task 3.3: call `markConnected($credential)` right after a
 * successful (re)connection upsert, so the admin panel reflects success
 * immediately instead of waiting for the next real booking attempt to
 * self-heal the flag (GoogleCalendarAccessTokenProvider also calls this
 * after every successful non-cached refresh, as a defense in depth).
 */
enum CalendarConnectionStatus: string
{
    case None = 'none';
    case Connected = 'connected';
    case Broken = 'broken';

    public const METADATA_KEY = 'calendar_connection_status';

    public static function forCredential(?ApiCredential $credential): self
    {
        if ($credential === null) {
            return self::None;
        }

        $status = ($credential->metadata ?? [])[self::METADATA_KEY] ?? null;

        return $status === self::Broken->value ? self::Broken : self::Connected;
    }

    public static function markBroken(ApiCredential $credential): void
    {
        $metadata = $credential->metadata ?? [];

        if (($metadata[self::METADATA_KEY] ?? null) === self::Broken->value) {
            return;
        }

        $metadata[self::METADATA_KEY] = self::Broken->value;

        $credential->forceFill(['metadata' => $metadata])->save();
    }

    public static function markConnected(ApiCredential $credential): void
    {
        $metadata = $credential->metadata ?? [];

        if (! array_key_exists(self::METADATA_KEY, $metadata)) {
            return;
        }

        unset($metadata[self::METADATA_KEY]);

        $credential->forceFill(['metadata' => $metadata])->save();
    }
}
