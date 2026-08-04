<?php

namespace Tests\Feature\GoogleCalendar;

use App\Domain\Connectors\GoogleCalendar\CalendarConnectionStatus;
use App\Domain\Connectors\GoogleCalendar\GoogleCalendarAccessTokenProvider;
use App\Domain\Connectors\GoogleCalendar\GoogleCalendarConsentState;
use App\Enums\TenantRole;
use App\Enums\TenantStatus;
use App\Models\ApiCredential;
use App\Models\Tenant;
use App\Models\TenantUser;
use App\Models\User;
use App\Models\WhatsAppLine;
use App\Support\Tenancy\TenantScopeKey;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class GoogleCalendarOAuthTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    public function test_tenant_admin_receives_a_signed_consent_url(): void
    {
        $tenant = $this->tenant('Estetica Bella');
        $line = $this->line($tenant, 'Sede Principal');
        $admin = $this->tenantUser($tenant, TenantRole::TenantAdmin);

        $response = $this->actingAs($admin)
            ->withCsrf()
            ->withHeader('X-Tenant-Id', (string) $tenant->id)
            ->postJson("/api/v1/admin/calendar-connections/{$line->id}")
            ->assertOk();

        $consentUrl = $response->json('data.consent_url');

        $this->assertIsString($consentUrl);
        $this->assertStringContainsString('state=', $consentUrl);
        $this->assertStringContainsString('access_type=offline', $consentUrl);
        $this->assertStringContainsString('prompt=consent', $consentUrl);
    }

    public function test_viewer_cannot_request_a_consent_url(): void
    {
        $tenant = $this->tenant('Estetica Viewer');
        $line = $this->line($tenant, 'Sede Principal');
        $viewer = $this->tenantUser($tenant, TenantRole::Viewer);

        $this->actingAs($viewer)
            ->withCsrf()
            ->withHeader('X-Tenant-Id', (string) $tenant->id)
            ->postJson("/api/v1/admin/calendar-connections/{$line->id}")
            ->assertForbidden();
    }

    public function test_paused_tenant_can_still_request_a_consent_url(): void
    {
        $tenant = $this->tenant('Estetica Pausada');
        $tenant->update(['status' => TenantStatus::Paused]);
        $line = $this->line($tenant, 'Sede Principal');
        $admin = $this->tenantUser($tenant, TenantRole::TenantAdmin);

        $this->actingAs($admin)
            ->withCsrf()
            ->withHeader('X-Tenant-Id', (string) $tenant->id)
            ->postJson("/api/v1/admin/calendar-connections/{$line->id}")
            ->assertOk();
    }

    public function test_callback_persists_the_credential_and_redirects_with_success(): void
    {
        Http::fake([
            'https://oauth2.googleapis.com/token' => Http::response([
                'access_token' => 'at-xyz',
                'refresh_token' => 'rt-xyz',
                'expires_in' => 3600,
            ]),
        ]);

        $tenant = $this->tenant('Estetica Conexion');
        $line = $this->line($tenant, 'Sede Principal');
        $state = GoogleCalendarConsentState::issue($tenant, $line);

        $response = $this->getJson('/api/oauth/google/callback?'.http_build_query([
            'state' => $state,
            'code' => 'auth-code-123',
        ]));

        $response->assertStatus(302);
        $this->assertStringContainsString('/admin/connect/lines', (string) $response->headers->get('Location'));
        $this->assertStringContainsString('calendar_connection=success', (string) $response->headers->get('Location'));

        $credential = ApiCredential::query()
            ->where('tenant_id', $tenant->id)
            ->where('whatsapp_line_id', $line->id)
            ->where('provider', GoogleCalendarAccessTokenProvider::PROVIDER)
            ->where('credential_key', GoogleCalendarAccessTokenProvider::CREDENTIAL_KEY)
            ->first();

        $this->assertNotNull($credential);
        $this->assertSame('rt-xyz', $credential->secret);
        $this->assertSame('whatsapp_line', $credential->scope_type);
        $this->assertSame(TenantScopeKey::forWhatsAppLine($line), $credential->scope_key);
        $this->assertSame(CalendarConnectionStatus::Connected, CalendarConnectionStatus::forCredential($credential->fresh()));

        // The raw column must not contain the plaintext refresh token.
        $raw = DB::table('api_credentials')->where('id', $credential->id)->first();
        $this->assertStringNotContainsString('rt-xyz', (string) $raw->secret);

        $this->assertDatabaseHas('audit_events', [
            'tenant_id' => $tenant->id,
            'event_type' => 'google_calendar_connected',
        ]);
    }

    public function test_reconnecting_a_broken_line_marks_it_connected_again(): void
    {
        Http::fake([
            'https://oauth2.googleapis.com/token' => Http::response([
                'access_token' => 'at-new',
                'refresh_token' => 'rt-new',
                'expires_in' => 3600,
            ]),
        ]);

        $tenant = $this->tenant('Estetica Rota');
        $line = $this->line($tenant, 'Sede Principal');
        $credential = ApiCredential::query()->create([
            'tenant_id' => $tenant->id,
            'whatsapp_line_id' => $line->id,
            'scope_type' => 'whatsapp_line',
            'scope_key' => TenantScopeKey::forWhatsAppLine($line),
            'provider' => GoogleCalendarAccessTokenProvider::PROVIDER,
            'credential_key' => GoogleCalendarAccessTokenProvider::CREDENTIAL_KEY,
            'secret' => 'rt-old-revoked',
        ]);
        CalendarConnectionStatus::markBroken($credential);

        $state = GoogleCalendarConsentState::issue($tenant, $line);

        $this->getJson('/api/oauth/google/callback?'.http_build_query([
            'state' => $state,
            'code' => 'auth-code-456',
        ]))->assertStatus(302);

        $this->assertSame(CalendarConnectionStatus::Connected, CalendarConnectionStatus::forCredential($credential->fresh()));
        $this->assertSame('rt-new', $credential->fresh()->secret);
    }

    public function test_callback_rejects_a_tampered_state_without_persisting_anything(): void
    {
        Http::fake();

        $response = $this->getJson('/api/oauth/google/callback?'.http_build_query([
            'state' => 'not-a-real-signed-state',
            'code' => 'auth-code-123',
        ]));

        $response->assertStatus(302);
        $this->assertStringContainsString('calendar_connection=error', (string) $response->headers->get('Location'));
        $this->assertDatabaseCount('api_credentials', 0);
        Http::assertNothingSent();
    }

    public function test_callback_rejects_an_expired_state(): void
    {
        Http::fake();

        $tenant = $this->tenant('Estetica Expirada');
        $line = $this->line($tenant, 'Sede Principal');

        CarbonImmutable::setTestNow(CarbonImmutable::now());
        $state = GoogleCalendarConsentState::issue($tenant, $line, ttlMinutes: 1);
        CarbonImmutable::setTestNow(CarbonImmutable::now()->addMinutes(5));

        $response = $this->getJson('/api/oauth/google/callback?'.http_build_query([
            'state' => $state,
            'code' => 'auth-code-123',
        ]));

        $response->assertStatus(302);
        $this->assertStringContainsString('calendar_connection=error', (string) $response->headers->get('Location'));
        $this->assertDatabaseCount('api_credentials', 0);
        Http::assertNothingSent();
    }

    public function test_callback_rejects_a_state_issued_for_a_different_tenant(): void
    {
        Http::fake();

        $tenantA = $this->tenant('Estetica A');
        $lineA = $this->line($tenantA, 'Sede A');
        $tenantB = $this->tenant('Estetica B');

        // Signed for tenant B, but pointing at tenant A's line — the
        // callback must catch the tenant/line mismatch even though the
        // signature itself is valid (no tampering).
        $state = GoogleCalendarConsentState::issue($tenantB, $lineA);

        $response = $this->getJson('/api/oauth/google/callback?'.http_build_query([
            'state' => $state,
            'code' => 'auth-code-123',
        ]));

        $response->assertStatus(302);
        $this->assertStringContainsString('calendar_connection=error', (string) $response->headers->get('Location'));
        $this->assertDatabaseCount('api_credentials', 0);
        Http::assertNothingSent();
    }

    protected function tenant(string $name): Tenant
    {
        return Tenant::query()->create([
            'name' => $name,
            'slug' => str()->slug($name).'-'.uniqid(),
        ]);
    }

    protected function tenantUser(Tenant $tenant, TenantRole $role): User
    {
        $user = User::factory()->create();

        TenantUser::query()->create([
            'tenant_id' => $tenant->id,
            'user_id' => $user->id,
            'role' => $role,
        ]);

        return $user;
    }

    protected function line(Tenant $tenant, string $name): WhatsAppLine
    {
        return WhatsAppLine::query()->create([
            'tenant_id' => $tenant->id,
            'name' => $name,
            'phone_number_id' => 'phone-id-'.uniqid(),
            'display_phone_number' => '+573001112233',
            'waba_id' => 'waba-'.uniqid(),
            'is_enabled' => true,
        ]);
    }
}
