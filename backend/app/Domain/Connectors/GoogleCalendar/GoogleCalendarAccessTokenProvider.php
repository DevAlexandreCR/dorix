<?php

namespace App\Domain\Connectors\GoogleCalendar;

use App\Domain\Connectors\GoogleCalendar\Exceptions\CalendarConnectionUnavailableException;
use App\Models\ApiCredential;
use App\Models\WhatsAppLine;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Cache\Factory as CacheFactory;

/**
 * Resolves a valid Google Calendar access token for a WhatsApp line's
 * `google_calendar` credential: refreshes on demand via the persisted
 * refresh token, caches the access token briefly, and NEVER persists the
 * access token itself (design D5/D12). Also the single place that
 * updates `last_used_at` and marks the connection broken on
 * `invalid_grant`.
 *
 * Future scheduling tools (task 4.x) should depend on this class rather
 * than resolving GoogleCalendarClient directly for token acquisition.
 *
 * The constructor-injected {@see self::$client} is whatever
 * `GoogleCalendarClient::class` resolves to in the container at
 * construction time (the real HTTP client by default) and is used when no
 * `$client` override is passed to {@see self::getAccessToken()}. Callers
 * that must be sandbox-aware (e.g. scheduling tools resolving the client
 * per-conversation via `GoogleCalendarClientResolver`) should pass that
 * resolved client explicitly so a sandbox conversation's refresh call
 * genuinely goes through `FakeGoogleCalendarClient`, not the fixed
 * constructor dependency.
 */
class GoogleCalendarAccessTokenProvider
{
    public const PROVIDER = 'google_calendar';

    public const CREDENTIAL_KEY = 'refresh_token';

    protected const CACHE_KEY_PREFIX = 'google_calendar_access_token:';

    /** Refresh a bit before Google's own expiry to avoid using a token that expires mid-request. */
    protected const CACHE_TTL_BUFFER_SECONDS = 60;

    /** Cache is deliberately short-lived even if Google grants a longer-lived token. */
    protected const CACHE_TTL_CAP_SECONDS = 45 * 60;

    public function __construct(
        protected GoogleCalendarClient $client,
        protected CacheFactory $cache,
    ) {
    }

    /**
     * @param  GoogleCalendarClient|null  $client  overrides the constructor-injected client for this call
     *                                              (e.g. the sandbox-resolved `FakeGoogleCalendarClient`). Defaults to
     *                                              whatever `GoogleCalendarClient::class` resolved to at construction.
     *
     * @throws CalendarConnectionUnavailableException when there is no credential for the line, or Google reports `invalid_grant`.
     */
    public function getAccessToken(WhatsAppLine $line, ?GoogleCalendarClient $client = null): string
    {
        $client ??= $this->client;
        $credential = $this->resolveCredential($line);
        $store = $this->cache->store(config('cache.default'));
        $cacheKey = $this->cacheKey($credential);

        $cachedToken = $store->get($cacheKey);

        if (is_string($cachedToken) && $cachedToken !== '') {
            $this->touchLastUsed($credential);

            return $cachedToken;
        }

        try {
            $accessToken = $client->refreshAccessToken($credential->secret);
        } catch (CalendarConnectionUnavailableException $exception) {
            CalendarConnectionStatus::markBroken($credential);

            throw $exception;
        }

        $store->put($cacheKey, $accessToken->token, $this->cacheTtlSeconds($accessToken->expiresAt));

        // A real refresh call succeeding is proof the connection is healthy again —
        // self-heal a stale broken flag even if task 3.3's callback didn't clear it.
        CalendarConnectionStatus::markConnected($credential);
        $this->touchLastUsed($credential);

        return $accessToken->token;
    }

    protected function resolveCredential(WhatsAppLine $line): ApiCredential
    {
        $credential = ApiCredential::query()
            ->forTenant($line->tenant_id)
            ->where('whatsapp_line_id', $line->getKey())
            ->where('provider', self::PROVIDER)
            ->where('credential_key', self::CREDENTIAL_KEY)
            ->first();

        if (! $credential) {
            throw new CalendarConnectionUnavailableException(__('api.tools.calendar_connection_unavailable'));
        }

        return $credential;
    }

    protected function cacheKey(ApiCredential $credential): string
    {
        return self::CACHE_KEY_PREFIX.$credential->getKey();
    }

    protected function cacheTtlSeconds(CarbonImmutable $expiresAt): int
    {
        $secondsUntilExpiry = $expiresAt->getTimestamp() - CarbonImmutable::now()->getTimestamp();
        $ttl = max(1, $secondsUntilExpiry - self::CACHE_TTL_BUFFER_SECONDS);

        return min($ttl, self::CACHE_TTL_CAP_SECONDS);
    }

    protected function touchLastUsed(ApiCredential $credential): void
    {
        $credential->forceFill(['last_used_at' => now()])->save();
    }
}
