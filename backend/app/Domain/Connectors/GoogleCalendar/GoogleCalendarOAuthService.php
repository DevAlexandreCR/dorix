<?php

namespace App\Domain\Connectors\GoogleCalendar;

use App\Domain\Connectors\GoogleCalendar\Exceptions\CalendarRequestFailedException;
use App\Domain\Connectors\GoogleCalendar\Exceptions\InvalidConsentStateException;
use App\Models\ApiCredential;
use App\Models\Tenant;
use App\Models\WhatsAppLine;
use App\Support\Audit\AuditEventRecorder;
use App\Support\Tenancy\TenantScopeKey;
use Illuminate\Http\Client\Factory as HttpFactory;

/**
 * OAuth 2.0 authorization-code flow that connects a WhatsApp line's
 * Google Calendar (design D6): builds the consent URL with a signed
 * `state`, and on callback exchanges the `code` for a refresh token and
 * upserts it as the line's `google_calendar` credential. Plain REST via
 * Laravel's `Http::` client — no Google SDK, no Socialite.
 *
 * Refreshing access tokens for an already-connected line is
 * GoogleCalendarAccessTokenProvider's job, not this class's — this is
 * only the one-time consent + code exchange that produces the refresh
 * token in the first place.
 */
class GoogleCalendarOAuthService
{
    private const SCOPES = [
        'https://www.googleapis.com/auth/calendar.events',
        'https://www.googleapis.com/auth/calendar.freebusy',
    ];

    public function __construct(
        protected HttpFactory $http,
        protected AuditEventRecorder $audit,
    ) {
    }

    public function buildConsentUrl(Tenant $tenant, WhatsAppLine $line): string
    {
        $query = [
            'client_id' => (string) config('services.google.client_id'),
            'redirect_uri' => (string) config('services.google.redirect_uri'),
            'response_type' => 'code',
            'scope' => implode(' ', self::SCOPES),
            'access_type' => 'offline',
            'prompt' => 'consent',
            'state' => GoogleCalendarConsentState::issue($tenant, $line),
        ];

        return $this->authorizeUrl().'?'.http_build_query($query, '', '&', PHP_QUERY_RFC3986);
    }

    /**
     * @throws InvalidConsentStateException
     * @throws CalendarRequestFailedException
     */
    public function completeConnection(string $state, string $code): ApiCredential
    {
        $parsedState = GoogleCalendarConsentState::fromToken($state);

        $line = WhatsAppLine::query()->find($parsedState->whatsAppLineId);

        if (! $line instanceof WhatsAppLine || $line->tenant_id !== $parsedState->tenantId) {
            throw new InvalidConsentStateException(__('api.tools.calendar_oauth_state_invalid'));
        }

        $refreshToken = $this->exchangeCodeForRefreshToken($code);
        $credential = $this->upsertCredential($line, $refreshToken);

        // Self-heal any pre-existing `broken` flag immediately on a fresh
        // connection, rather than waiting for the next real booking
        // attempt to self-heal via GoogleCalendarAccessTokenProvider.
        CalendarConnectionStatus::markConnected($credential);

        $this->audit->record(
            tenantId: $line->tenant_id,
            eventType: 'google_calendar_connected',
            target: $credential,
            payload: ['whatsapp_line_id' => $line->getKey()],
        );

        return $credential->fresh();
    }

    protected function exchangeCodeForRefreshToken(string $code): string
    {
        $response = $this->http
            ->asForm()
            ->acceptJson()
            ->post($this->tokenUrl(), [
                'client_id' => (string) config('services.google.client_id'),
                'client_secret' => (string) config('services.google.client_secret'),
                'code' => $code,
                'redirect_uri' => (string) config('services.google.redirect_uri'),
                'grant_type' => 'authorization_code',
            ]);

        if (! $response->successful()) {
            throw new CalendarRequestFailedException(__('api.tools.calendar_request_failed'));
        }

        $data = $response->json();
        $refreshToken = is_array($data) ? ($data['refresh_token'] ?? null) : null;

        if (! is_string($refreshToken) || $refreshToken === '') {
            throw new CalendarRequestFailedException(__('api.tools.calendar_request_failed'));
        }

        return $refreshToken;
    }

    protected function upsertCredential(WhatsAppLine $line, string $refreshToken): ApiCredential
    {
        $credential = ApiCredential::query()->firstOrNew([
            'tenant_id' => $line->tenant_id,
            'scope_key' => TenantScopeKey::forWhatsAppLine($line),
            'provider' => GoogleCalendarAccessTokenProvider::PROVIDER,
            'credential_key' => GoogleCalendarAccessTokenProvider::CREDENTIAL_KEY,
        ]);

        $credential->forceFill([
            'tenant_id' => $line->tenant_id,
            'whatsapp_line_id' => $line->getKey(),
            'scope_type' => 'whatsapp_line',
            'scope_key' => TenantScopeKey::forWhatsAppLine($line),
            'provider' => GoogleCalendarAccessTokenProvider::PROVIDER,
            'credential_key' => GoogleCalendarAccessTokenProvider::CREDENTIAL_KEY,
            'secret' => $refreshToken,
            'metadata' => $credential->metadata ?? [],
        ])->save();

        return $credential;
    }

    protected function authorizeUrl(): string
    {
        return rtrim((string) config('services.google.oauth_authorize_url', 'https://accounts.google.com/o/oauth2/v2/auth'), '/');
    }

    protected function tokenUrl(): string
    {
        return (string) config('services.google.oauth_token_url', 'https://oauth2.googleapis.com/token');
    }
}
