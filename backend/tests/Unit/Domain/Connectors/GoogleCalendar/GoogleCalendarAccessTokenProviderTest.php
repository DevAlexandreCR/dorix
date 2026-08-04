<?php

namespace Tests\Unit\Domain\Connectors\GoogleCalendar;

use App\Domain\Connectors\GoogleCalendar\CalendarConnectionStatus;
use App\Domain\Connectors\GoogleCalendar\Exceptions\CalendarConnectionUnavailableException;
use App\Domain\Connectors\GoogleCalendar\GoogleCalendarAccessTokenProvider;
use App\Models\ApiCredential;
use App\Models\Tenant;
use App\Models\WhatsAppLine;
use App\Support\Tenancy\TenantScopeKey;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class GoogleCalendarAccessTokenProviderTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // The array cache store is a singleton kept alive across tests in the
        // same process; RefreshDatabase rolls back transactions (reusing the
        // same auto-increment ids), so a stale cached token from a previous
        // test would otherwise leak into this one under the same cache key.
        Cache::flush();
    }

    public function test_it_refreshes_and_caches_the_access_token(): void
    {
        $fake = $this->fakeGoogleCalendar();
        [$line, $credential] = $this->lineWithCredential('refresh-token-abc');

        $provider = app(GoogleCalendarAccessTokenProvider::class);

        $token = $provider->getAccessToken($line);

        $this->assertSame('fake-access-token-refresh-token-abc', $token);
        $this->assertSame(['refresh-token-abc'], $fake->refreshedTokens());
        $this->assertNotNull($credential->fresh()->last_used_at);
    }

    public function test_a_cached_token_is_reused_without_refreshing_again(): void
    {
        $fake = $this->fakeGoogleCalendar();
        [$line] = $this->lineWithCredential('refresh-token-abc');

        $provider = app(GoogleCalendarAccessTokenProvider::class);

        $first = $provider->getAccessToken($line);
        $second = $provider->getAccessToken($line);

        $this->assertSame($first, $second);
        $this->assertCount(1, $fake->refreshedTokens());
    }

    public function test_last_used_at_is_updated_on_every_call_including_cache_hits(): void
    {
        $this->fakeGoogleCalendar();
        [$line, $credential] = $this->lineWithCredential('refresh-token-abc');

        $provider = app(GoogleCalendarAccessTokenProvider::class);
        $provider->getAccessToken($line);

        $firstUse = $credential->fresh()->last_used_at;
        $this->assertNotNull($firstUse);

        CarbonImmutable::setTestNow(CarbonImmutable::now()->addMinutes(2));

        $provider->getAccessToken($line);

        $secondUse = $credential->fresh()->last_used_at;
        $this->assertTrue($secondUse->greaterThan($firstUse));

        CarbonImmutable::setTestNow();
    }

    public function test_invalid_grant_throws_and_marks_the_connection_broken(): void
    {
        $fake = $this->fakeGoogleCalendar();
        $fake->markConnectionBroken();
        [$line, $credential] = $this->lineWithCredential('revoked-refresh-token');

        $provider = app(GoogleCalendarAccessTokenProvider::class);

        try {
            $provider->getAccessToken($line);
            $this->fail('Expected CalendarConnectionUnavailableException to be thrown.');
        } catch (CalendarConnectionUnavailableException) {
            // expected
        }

        $this->assertSame(CalendarConnectionStatus::Broken, CalendarConnectionStatus::forCredential($credential->fresh()));
    }

    public function test_no_credential_reports_none_and_throws_connection_unavailable(): void
    {
        $this->fakeGoogleCalendar();
        $tenant = Tenant::query()->create(['name' => 'Sin Calendario', 'slug' => 'sin-calendario']);
        $line = WhatsAppLine::query()->create([
            'tenant_id' => $tenant->id,
            'name' => 'Line without calendar',
            'phone_number_id' => 'phone-id-none',
            'display_phone_number' => '+573000000000',
            'waba_id' => 'waba-none',
            'is_enabled' => true,
        ]);

        $this->assertSame(CalendarConnectionStatus::None, CalendarConnectionStatus::forCredential(null));

        $provider = app(GoogleCalendarAccessTokenProvider::class);

        $this->expectException(CalendarConnectionUnavailableException::class);

        $provider->getAccessToken($line);
    }

    public function test_a_successful_refresh_self_heals_a_stale_broken_flag(): void
    {
        $fake = $this->fakeGoogleCalendar();
        [$line, $credential] = $this->lineWithCredential('refresh-token-abc');
        CalendarConnectionStatus::markBroken($credential);

        $provider = app(GoogleCalendarAccessTokenProvider::class);
        $provider->getAccessToken($line);

        $this->assertSame(CalendarConnectionStatus::Connected, CalendarConnectionStatus::forCredential($credential->fresh()));
    }

    /**
     * @return array{0: WhatsAppLine, 1: ApiCredential}
     */
    protected function lineWithCredential(string $refreshToken): array
    {
        $tenant = Tenant::query()->create(['name' => 'Estetica Bella', 'slug' => 'estetica-bella-'.uniqid()]);
        $line = WhatsAppLine::query()->create([
            'tenant_id' => $tenant->id,
            'name' => 'Sede Principal',
            'phone_number_id' => 'phone-id-'.uniqid(),
            'display_phone_number' => '+573001112233',
            'waba_id' => 'waba-'.uniqid(),
            'is_enabled' => true,
        ]);

        $credential = ApiCredential::query()->create([
            'tenant_id' => $tenant->id,
            'whatsapp_line_id' => $line->id,
            'scope_type' => 'whatsapp_line',
            'scope_key' => TenantScopeKey::forWhatsAppLine($line),
            'provider' => GoogleCalendarAccessTokenProvider::PROVIDER,
            'credential_key' => GoogleCalendarAccessTokenProvider::CREDENTIAL_KEY,
            'secret' => $refreshToken,
        ]);

        return [$line, $credential];
    }
}
