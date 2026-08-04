<?php

namespace App\Domain\Connectors\GoogleCalendar;

use App\Domain\Connectors\GoogleCalendar\Exceptions\InvalidConsentStateException;
use App\Models\Tenant;
use App\Models\WhatsAppLine;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;
use JsonException;

/**
 * The signed, expiring `state` param carried through the Google OAuth
 * consent flow (design D6): identifies which tenant + WhatsApp line
 * requested the connection, so the public callback
 * (`GET /api/oauth/google/callback`) can complete it without any auth
 * context of its own.
 *
 * Uses Laravel's built-in encrypter (AES-256-CBC + MAC) rather than a
 * hand-rolled HMAC — `Crypt::decryptString()` already throws on any
 * tampering, so integrity comes for free; this class only adds the
 * expiry check and the payload shape on top.
 */
final readonly class GoogleCalendarConsentState
{
    private const DEFAULT_TTL_MINUTES = 10;

    public function __construct(
        public int $tenantId,
        public int $whatsAppLineId,
    ) {
    }

    public static function issue(Tenant $tenant, WhatsAppLine $line, int $ttlMinutes = self::DEFAULT_TTL_MINUTES): string
    {
        $payload = [
            'tenant_id' => $tenant->getKey(),
            'whatsapp_line_id' => $line->getKey(),
            'expires_at' => CarbonImmutable::now()->addMinutes($ttlMinutes)->getTimestamp(),
        ];

        return Crypt::encryptString(json_encode($payload, JSON_THROW_ON_ERROR));
    }

    /**
     * @throws InvalidConsentStateException when the token is tampered, malformed, or expired.
     */
    public static function fromToken(string $token): self
    {
        try {
            $decrypted = Crypt::decryptString($token);
            $payload = json_decode($decrypted, true, flags: JSON_THROW_ON_ERROR);
        } catch (DecryptException|JsonException) {
            throw new InvalidConsentStateException(__('api.tools.calendar_oauth_state_invalid'));
        }

        if (
            ! is_array($payload)
            || ! isset($payload['tenant_id'], $payload['whatsapp_line_id'], $payload['expires_at'])
            || ! is_numeric($payload['tenant_id'])
            || ! is_numeric($payload['whatsapp_line_id'])
            || ! is_numeric($payload['expires_at'])
        ) {
            throw new InvalidConsentStateException(__('api.tools.calendar_oauth_state_invalid'));
        }

        $expiresAt = CarbonImmutable::createFromTimestamp((int) $payload['expires_at']);

        if ($expiresAt->isPast()) {
            throw new InvalidConsentStateException(__('api.tools.calendar_oauth_state_invalid'));
        }

        return new self((int) $payload['tenant_id'], (int) $payload['whatsapp_line_id']);
    }
}
