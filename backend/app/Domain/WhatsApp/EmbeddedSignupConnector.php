<?php

namespace App\Domain\WhatsApp;

use App\Domain\WhatsApp\Exceptions\EmbeddedSignupExchangeFailedException;
use App\Domain\WhatsApp\Exceptions\EmbeddedSignupProvisioningFailedException;
use App\Enums\WhatsAppConnectionMode;
use App\Models\ApiCredential;
use App\Models\Tenant;
use App\Models\WhatsAppLine;
use App\Support\Tenancy\TenantScopeKey;
use Illuminate\Database\QueryException;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Facades\DB;

/**
 * Orchestrates the Meta Embedded Signup flow for a tenant connecting a
 * WhatsApp line: exchanging the OAuth authorization code for a business
 * token, subscribing the WABA and registering the number for Cloud API,
 * resolving an initial line name, and persisting the resulting line and
 * credential (design.md D2). All Graph calls complete before any
 * persistence, so a failure at any step leaves nothing to roll back.
 */
class EmbeddedSignupConnector
{
    protected const PROVIDER = 'whatsapp_meta';

    protected const ACCESS_TOKEN_CREDENTIAL_KEY = 'access_token';

    protected const REGISTRATION_PIN_CREDENTIAL_KEY = 'registration_pin';

    public function __construct(
        protected HttpFactory $http,
    ) {
    }

    /**
     * Exchanges a one-time Embedded Signup authorization code for a
     * long-lived business token, calling Graph's
     * `GET /{api_version}/oauth/access_token`. The token is never logged.
     */
    public function exchangeCode(string $code): string
    {
        $response = $this->http
            ->baseUrl(rtrim((string) config('services.whatsapp.meta.base_url'), '/'))
            ->acceptJson()
            ->get(sprintf('/%s/oauth/access_token', config('services.whatsapp.meta.api_version')), [
                'client_id' => config('services.whatsapp.meta.app_id'),
                'client_secret' => config('services.whatsapp.meta.app_secret'),
                'code' => $code,
            ]);

        if (! $response->successful()) {
            throw new EmbeddedSignupExchangeFailedException(__('api.whatsapp.embedded_signup.exchange_failed'));
        }

        $data = $response->json();
        $accessToken = is_array($data) ? ($data['access_token'] ?? null) : null;

        if (! is_string($accessToken) || $accessToken === '') {
            throw new EmbeddedSignupExchangeFailedException(__('api.whatsapp.embedded_signup.exchange_failed'));
        }

        return $accessToken;
    }

    /**
     * Runs the post-exchange Graph provisioning for a phone number:
     * subscribes the platform app to the WABA (`POST
     * /{waba_id}/subscribed_apps`), then — only for
     * `connection_mode=cloud_api` — registers the number (`POST
     * /{phone_number_id}/register`) with a freshly generated 6-digit
     * PIN. Coexistence numbers are already registered by the WhatsApp
     * Business app, so `register` MUST NOT be called for that mode.
     *
     * Returns the generated PIN (never logged) when one was created,
     * so the caller can persist it as the line's `registration_pin`
     * credential; persistence itself happens elsewhere.
     */
    public function provisionPhoneNumber(
        string $wabaId,
        string $phoneNumberId,
        string $accessToken,
        WhatsAppConnectionMode $connectionMode,
    ): ?string {
        $this->subscribeWabaApp($wabaId, $accessToken);

        if ($connectionMode !== WhatsAppConnectionMode::CloudApi) {
            return null;
        }

        return $this->registerPhoneNumber($phoneNumberId, $accessToken);
    }

    /**
     * Resolves the line's initial display name when the frontend
     * doesn't supply one: queries `GET /{phone_number_id}` with
     * `fields=verified_name,display_phone_number` and prefers
     * `verified_name`, falling back to the visible number.
     */
    public function fetchInitialLineName(string $phoneNumberId, string $accessToken): string
    {
        $response = $this->http
            ->baseUrl(rtrim((string) config('services.whatsapp.meta.base_url'), '/'))
            ->withToken($accessToken)
            ->acceptJson()
            ->get(sprintf('/%s/%s', config('services.whatsapp.meta.api_version'), $phoneNumberId), [
                'fields' => 'verified_name,display_phone_number',
            ]);

        if (! $response->successful()) {
            throw new EmbeddedSignupProvisioningFailedException(__('api.whatsapp.embedded_signup.line_name_fetch_failed'));
        }

        $data = $response->json();
        $verifiedName = is_array($data) ? ($data['verified_name'] ?? null) : null;
        $displayPhoneNumber = is_array($data) ? ($data['display_phone_number'] ?? null) : null;

        if (is_string($verifiedName) && $verifiedName !== '') {
            return $verifiedName;
        }

        if (is_string($displayPhoneNumber) && $displayPhoneNumber !== '') {
            return $displayPhoneNumber;
        }

        throw new EmbeddedSignupProvisioningFailedException(__('api.whatsapp.embedded_signup.line_name_fetch_failed'));
    }

    /**
     * Persists a successful Embedded Signup connection in a single DB
     * transaction. MUST only be called after every Graph call above has
     * already succeeded (design.md D2 "Orden Graph-antes-de-DB": if this
     * fails, retrying the whole flow is safe because `subscribed_apps`
     * and `register` are idempotent, whereas persisting before Graph
     * would leave a line "connected" without a token).
     *
     * Creates or updates the tenant's `WhatsAppLine` for `$phoneNumberId`
     * (`connection_mode`, `status=active`, `is_enabled=true`, name) and
     * upserts its line-scoped `access_token` credential — and, when a
     * PIN was generated, its `registration_pin` credential — reusing the
     * existing row on reconnection rather than duplicating it. Two
     * concurrent connects racing on the same not-yet-seen
     * `phone_number_id` are resolved by catching the loser's unique
     * constraint violation and retrying as an update, so the race
     * settles into one consistent line instead of a duplicate or a
     * partial write.
     */
    public function persistConnection(
        Tenant $tenant,
        string $phoneNumberId,
        string $wabaId,
        WhatsAppConnectionMode $connectionMode,
        string $name,
        string $accessToken,
        ?string $registrationPin,
    ): WhatsAppLine {
        return DB::transaction(function () use (
            $tenant,
            $phoneNumberId,
            $wabaId,
            $connectionMode,
            $name,
            $accessToken,
            $registrationPin,
        ): WhatsAppLine {
            $line = $this->upsertWhatsAppLine($tenant, $phoneNumberId, $wabaId, $connectionMode, $name);

            $this->upsertLineCredential($tenant, $line, self::ACCESS_TOKEN_CREDENTIAL_KEY, $accessToken);

            if ($registrationPin !== null) {
                $this->upsertLineCredential($tenant, $line, self::REGISTRATION_PIN_CREDENTIAL_KEY, $registrationPin);
            }

            return $line->fresh();
        });
    }

    /**
     * Creates or updates the `WhatsAppLine` for `$phoneNumberId`.
     * `phone_number_id` is unique across all tenants, so the lookup is
     * intentionally unscoped by tenant: by the time this runs, the
     * caller has already ruled out a cross-tenant conflict (design.md
     * D2's 409 case), and reconnecting the same tenant's own line must
     * find it regardless of tenant-scoping helpers.
     */
    protected function upsertWhatsAppLine(
        Tenant $tenant,
        string $phoneNumberId,
        string $wabaId,
        WhatsAppConnectionMode $connectionMode,
        string $name,
    ): WhatsAppLine {
        $attributes = [
            'tenant_id' => $tenant->getKey(),
            'name' => $name,
            'phone_number_id' => $phoneNumberId,
            'waba_id' => $wabaId,
            'connection_mode' => $connectionMode,
            'status' => 'active',
            'is_enabled' => true,
        ];

        $existing = WhatsAppLine::query()->where('phone_number_id', $phoneNumberId)->first();

        if ($existing !== null) {
            $existing->forceFill($attributes)->save();

            return $existing;
        }

        try {
            return WhatsAppLine::query()->create($attributes);
        } catch (QueryException $exception) {
            if (! $this->isDuplicatePhoneNumberException($exception)) {
                throw $exception;
            }

            // Lost the race: a concurrent connect for the same number
            // committed between our SELECT and this INSERT. Retry as an
            // update against the row it created instead of failing or
            // leaving partial state.
            $line = WhatsAppLine::query()->where('phone_number_id', $phoneNumberId)->firstOrFail();
            $line->forceFill($attributes)->save();

            return $line;
        }
    }

    /**
     * Upserts a line-scoped credential, rotating the secret in place on
     * reconnection rather than duplicating the row (matches the
     * `firstOrNew` + `forceFill` idiom used by
     * `GoogleCalendarOAuthService::upsertCredential()`).
     */
    protected function upsertLineCredential(
        Tenant $tenant,
        WhatsAppLine $line,
        string $credentialKey,
        string $secret,
    ): ApiCredential {
        $credential = ApiCredential::query()->firstOrNew([
            'tenant_id' => $tenant->getKey(),
            'scope_key' => TenantScopeKey::forWhatsAppLine($line),
            'provider' => self::PROVIDER,
            'credential_key' => $credentialKey,
        ]);

        $credential->forceFill([
            'tenant_id' => $tenant->getKey(),
            'whatsapp_line_id' => $line->getKey(),
            'scope_type' => 'whatsapp_line',
            'scope_key' => TenantScopeKey::forWhatsAppLine($line),
            'provider' => self::PROVIDER,
            'credential_key' => $credentialKey,
            'secret' => $secret,
            'metadata' => $credential->metadata ?? [],
        ])->save();

        return $credential;
    }

    protected function isDuplicatePhoneNumberException(QueryException $exception): bool
    {
        $sqlState = $exception->errorInfo[0] ?? null;

        return in_array($sqlState, ['23000', '23505'], true)
            && str_contains($exception->getMessage(), 'phone_number_id');
    }

    /**
     * Subscribes the platform's app to the WABA so Graph delivers
     * webhook events for it. Idempotent — safe to retry.
     */
    protected function subscribeWabaApp(string $wabaId, string $accessToken): void
    {
        $response = $this->http
            ->baseUrl(rtrim((string) config('services.whatsapp.meta.base_url'), '/'))
            ->withToken($accessToken)
            ->acceptJson()
            ->post(sprintf('/%s/%s/subscribed_apps', config('services.whatsapp.meta.api_version'), $wabaId));

        if (! $response->successful()) {
            throw new EmbeddedSignupProvisioningFailedException(__('api.whatsapp.embedded_signup.subscribe_failed'));
        }
    }

    /**
     * Registers the phone number for Cloud API messaging with a
     * freshly generated 6-digit PIN, returning the PIN on success.
     */
    protected function registerPhoneNumber(string $phoneNumberId, string $accessToken): string
    {
        $pin = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);

        $response = $this->http
            ->baseUrl(rtrim((string) config('services.whatsapp.meta.base_url'), '/'))
            ->withToken($accessToken)
            ->acceptJson()
            ->post(sprintf('/%s/%s/register', config('services.whatsapp.meta.api_version'), $phoneNumberId), [
                'messaging_product' => 'whatsapp',
                'pin' => $pin,
            ]);

        if (! $response->successful()) {
            throw new EmbeddedSignupProvisioningFailedException(__('api.whatsapp.embedded_signup.register_failed'));
        }

        return $pin;
    }
}
