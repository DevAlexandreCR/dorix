<?php

namespace Tests\Feature\WhatsApp;

use App\Enums\TenantRole;
use App\Models\ApiCredential;
use App\Models\Tenant;
use App\Models\TenantUser;
use App\Models\User;
use App\Models\WhatsAppLine;
use App\Support\Tenancy\TenantScopeKey;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AdminWhatsAppLineConnectTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Config::set('services.whatsapp.meta.base_url', 'https://graph.facebook.com');
        Config::set('services.whatsapp.meta.api_version', 'v23.0');
        Config::set('services.whatsapp.meta.app_id', 'test-app-id');
        Config::set('services.whatsapp.meta.app_secret', 'test-app-secret');
    }

    public function test_tenant_admin_connects_a_line_in_cloud_api_mode(): void
    {
        $tenant = $this->tenant('Acme Cloud API');
        $admin = $this->tenantUser($tenant, TenantRole::TenantAdmin);
        $this->fakeGraph();

        $response = $this->actingAs($admin)
            ->withCsrf()
            ->withHeader('X-Tenant-Id', (string) $tenant->id)
            ->postJson('/api/v1/admin/whatsapp-lines/connect', [
                'code' => 'a-one-time-code',
                'phone_number_id' => 'phone-456',
                'waba_id' => 'waba-123',
                'connection_mode' => 'cloud_api',
            ]);

        $response->assertCreated()
            ->assertJsonPath('data.phone_number_id', 'phone-456')
            ->assertJsonPath('data.waba_id', 'waba-123')
            ->assertJsonPath('data.connection_mode', 'cloud_api')
            ->assertJsonPath('data.status', 'active')
            ->assertJsonPath('data.is_enabled', true)
            ->assertJsonPath('data.name', 'Acme Support');

        $this->assertArrayNotHasKey('access_token', $response->json('data'));
        $this->assertArrayNotHasKey('registration_pin', $response->json('data'));
        $this->assertStringNotContainsString('business-token-abc', $response->getContent());

        $line = WhatsAppLine::query()->where('phone_number_id', 'phone-456')->firstOrFail();

        $tokenCredential = $this->lineCredential($line, 'access_token');
        $this->assertSame('business-token-abc', $tokenCredential->secret);

        $pinCredential = $this->lineCredential($line, 'registration_pin');
        $this->assertMatchesRegularExpression('/^\d{6}$/', $pinCredential->secret);

        Http::assertSent(fn ($request) => $request->url() === 'https://graph.facebook.com/v23.0/phone-456/register');

        $this->assertDatabaseHas('audit_events', [
            'tenant_id' => $tenant->id,
            'event_type' => 'whatsapp_line_connected',
        ]);
    }

    public function test_tenant_admin_connects_a_line_in_coexistence_mode_without_registering(): void
    {
        $tenant = $this->tenant('Acme Coexistence');
        $admin = $this->tenantUser($tenant, TenantRole::TenantAdmin);
        $this->fakeGraph();

        $this->actingAs($admin)
            ->withCsrf()
            ->withHeader('X-Tenant-Id', (string) $tenant->id)
            ->postJson('/api/v1/admin/whatsapp-lines/connect', [
                'code' => 'a-one-time-code',
                'phone_number_id' => 'phone-456',
                'waba_id' => 'waba-123',
                'connection_mode' => 'coexistence',
            ])
            ->assertCreated()
            ->assertJsonPath('data.connection_mode', 'coexistence');

        Http::assertNotSent(fn ($request) => $request->url() === 'https://graph.facebook.com/v23.0/phone-456/register');

        $line = WhatsAppLine::query()->where('phone_number_id', 'phone-456')->firstOrFail();

        $this->assertNull(
            ApiCredential::query()
                ->where('whatsapp_line_id', $line->getKey())
                ->where('credential_key', 'registration_pin')
                ->first()
        );
    }

    public function test_invalid_code_returns_422_and_persists_nothing(): void
    {
        $tenant = $this->tenant('Acme Invalid Code');
        $admin = $this->tenantUser($tenant, TenantRole::TenantAdmin);

        Http::fake([
            'https://graph.facebook.com/v23.0/oauth/access_token*' => Http::response([
                'error' => ['message' => 'This authorization code has expired.', 'code' => 100],
            ], 400),
        ]);

        $this->actingAs($admin)
            ->withCsrf()
            ->withHeader('X-Tenant-Id', (string) $tenant->id)
            ->postJson('/api/v1/admin/whatsapp-lines/connect', [
                'code' => 'an-expired-code',
                'phone_number_id' => 'phone-456',
                'waba_id' => 'waba-123',
                'connection_mode' => 'cloud_api',
            ])
            ->assertUnprocessable()
            ->assertJsonPath('message', __('api.whatsapp.embedded_signup.exchange_failed'));

        $this->assertDatabaseMissing('whatsapp_lines', ['phone_number_id' => 'phone-456']);
        $this->assertDatabaseCount('api_credentials', 0);
    }

    public function test_number_already_connected_to_another_tenant_returns_409_without_leaking_the_tenant(): void
    {
        $ownerTenant = $this->tenant('Acme Owner');
        $requestingTenant = $this->tenant('Acme Requester');
        $admin = $this->tenantUser($requestingTenant, TenantRole::TenantAdmin);

        WhatsAppLine::query()->create([
            'tenant_id' => $ownerTenant->id,
            'name' => 'Owner line',
            'phone_number_id' => 'phone-456',
            'waba_id' => 'waba-999',
            'status' => 'active',
            'is_enabled' => true,
        ]);

        Http::fake();

        $response = $this->actingAs($admin)
            ->withCsrf()
            ->withHeader('X-Tenant-Id', (string) $requestingTenant->id)
            ->postJson('/api/v1/admin/whatsapp-lines/connect', [
                'code' => 'a-one-time-code',
                'phone_number_id' => 'phone-456',
                'waba_id' => 'waba-123',
                'connection_mode' => 'cloud_api',
            ]);

        $response->assertStatus(409);
        $this->assertStringNotContainsString((string) $ownerTenant->id, $response->getContent());
        $this->assertStringNotContainsString($ownerTenant->name, $response->getContent());

        Http::assertNothingSent();

        $this->assertSame(1, WhatsAppLine::query()->where('phone_number_id', 'phone-456')->count());
        $this->assertDatabaseHas('whatsapp_lines', [
            'phone_number_id' => 'phone-456',
            'tenant_id' => $ownerTenant->id,
        ]);
    }

    public function test_operator_and_viewer_cannot_connect_a_line(): void
    {
        $tenant = $this->tenant('Acme No Permission');
        $operator = $this->tenantUser($tenant, TenantRole::Operator);
        $viewer = $this->tenantUser($tenant, TenantRole::Viewer);

        Http::fake();

        $payload = [
            'code' => 'a-one-time-code',
            'phone_number_id' => 'phone-456',
            'waba_id' => 'waba-123',
            'connection_mode' => 'cloud_api',
        ];

        $this->actingAs($operator)
            ->withCsrf()
            ->withHeader('X-Tenant-Id', (string) $tenant->id)
            ->postJson('/api/v1/admin/whatsapp-lines/connect', $payload)
            ->assertForbidden();

        $this->actingAs($viewer)
            ->withCsrf()
            ->withHeader('X-Tenant-Id', (string) $tenant->id)
            ->postJson('/api/v1/admin/whatsapp-lines/connect', $payload)
            ->assertForbidden();

        Http::assertNothingSent();
        $this->assertDatabaseMissing('whatsapp_lines', ['phone_number_id' => 'phone-456']);
    }

    public function test_reconnecting_the_same_tenants_line_rotates_the_token_in_place(): void
    {
        $tenant = $this->tenant('Acme Reconnect');
        $admin = $this->tenantUser($tenant, TenantRole::TenantAdmin);

        $existingLine = WhatsAppLine::query()->create([
            'tenant_id' => $tenant->id,
            'name' => 'Old name',
            'phone_number_id' => 'phone-456',
            'waba_id' => 'waba-123',
            'status' => 'active',
            'is_enabled' => true,
        ]);

        ApiCredential::query()->create([
            'tenant_id' => $tenant->id,
            'whatsapp_line_id' => $existingLine->id,
            'scope_type' => 'whatsapp_line',
            'scope_key' => TenantScopeKey::forWhatsAppLine($existingLine),
            'provider' => 'whatsapp_meta',
            'credential_key' => 'access_token',
            'secret' => 'old-token',
        ]);

        $this->fakeGraph();

        $this->actingAs($admin)
            ->withCsrf()
            ->withHeader('X-Tenant-Id', (string) $tenant->id)
            ->postJson('/api/v1/admin/whatsapp-lines/connect', [
                'code' => 'a-one-time-code',
                'phone_number_id' => 'phone-456',
                'waba_id' => 'waba-123',
                'connection_mode' => 'cloud_api',
            ])
            ->assertCreated()
            ->assertJsonPath('data.id', $existingLine->id);

        $this->assertSame(1, WhatsAppLine::query()->where('phone_number_id', 'phone-456')->count());

        $rotatedCredential = ApiCredential::query()
            ->where('whatsapp_line_id', $existingLine->id)
            ->where('credential_key', 'access_token')
            ->firstOrFail();

        $this->assertSame('business-token-abc', $rotatedCredential->secret);
        $this->assertSame(
            1,
            ApiCredential::query()
                ->where('whatsapp_line_id', $existingLine->id)
                ->where('credential_key', 'access_token')
                ->count()
        );
    }

    protected function fakeGraph(): void
    {
        Http::fake([
            'https://graph.facebook.com/v23.0/oauth/access_token*' => Http::response([
                'access_token' => 'business-token-abc',
            ], 200),
            'https://graph.facebook.com/v23.0/waba-123/subscribed_apps' => Http::response(['success' => true], 200),
            'https://graph.facebook.com/v23.0/phone-456/register' => Http::response(['success' => true], 200),
            'https://graph.facebook.com/v23.0/phone-456*' => Http::response([
                'verified_name' => 'Acme Support',
                'display_phone_number' => '+1 555 010 0100',
            ], 200),
        ]);
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

    protected function lineCredential(WhatsAppLine $line, string $credentialKey): ApiCredential
    {
        return ApiCredential::query()
            ->where('scope_key', TenantScopeKey::forWhatsAppLine($line))
            ->where('credential_key', $credentialKey)
            ->firstOrFail();
    }
}
