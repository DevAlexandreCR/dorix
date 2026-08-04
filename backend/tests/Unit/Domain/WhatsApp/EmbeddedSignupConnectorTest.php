<?php

namespace Tests\Unit\Domain\WhatsApp;

use App\Domain\WhatsApp\EmbeddedSignupConnector;
use App\Domain\WhatsApp\Exceptions\EmbeddedSignupExchangeFailedException;
use App\Domain\WhatsApp\Exceptions\EmbeddedSignupProvisioningFailedException;
use App\Enums\WhatsAppConnectionMode;
use App\Models\ApiCredential;
use App\Models\Tenant;
use App\Models\WhatsAppLine;
use App\Support\Tenancy\TenantScopeKey;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class EmbeddedSignupConnectorTest extends TestCase
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

    public function test_exchange_code_returns_the_business_token_on_success(): void
    {
        Http::fake([
            'https://graph.facebook.com/v23.0/oauth/access_token*' => Http::response([
                'access_token' => 'business-token-abc',
                'token_type' => 'bearer',
            ], 200),
        ]);

        $connector = new EmbeddedSignupConnector(app(HttpFactory::class));

        $token = $connector->exchangeCode('a-one-time-code');

        $this->assertSame('business-token-abc', $token);

        Http::assertSent(function ($request): bool {
            return str_starts_with($request->url(), 'https://graph.facebook.com/v23.0/oauth/access_token')
                && $request['client_id'] === 'test-app-id'
                && $request['client_secret'] === 'test-app-secret'
                && $request['code'] === 'a-one-time-code';
        });
    }

    public function test_exchange_code_throws_a_translatable_exception_when_graph_rejects_the_code(): void
    {
        Http::fake([
            'https://graph.facebook.com/v23.0/oauth/access_token*' => Http::response([
                'error' => [
                    'message' => 'This authorization code has expired.',
                    'type' => 'OAuthException',
                    'code' => 100,
                ],
            ], 400),
        ]);

        $connector = new EmbeddedSignupConnector(app(HttpFactory::class));

        try {
            $connector->exchangeCode('an-expired-code');
            $this->fail('Expected EmbeddedSignupExchangeFailedException was not thrown.');
        } catch (EmbeddedSignupExchangeFailedException $exception) {
            $this->assertSame(__('api.whatsapp.embedded_signup.exchange_failed'), $exception->getMessage());
        }
    }

    public function test_exchange_code_throws_when_graph_responds_successfully_without_an_access_token(): void
    {
        Http::fake([
            'https://graph.facebook.com/v23.0/oauth/access_token*' => Http::response([
                'token_type' => 'bearer',
            ], 200),
        ]);

        $connector = new EmbeddedSignupConnector(app(HttpFactory::class));

        $this->expectException(EmbeddedSignupExchangeFailedException::class);

        $connector->exchangeCode('a-one-time-code');
    }

    public function test_provision_phone_number_subscribes_the_waba_and_registers_in_cloud_api_mode(): void
    {
        Http::fake([
            'https://graph.facebook.com/v23.0/waba-123/subscribed_apps' => Http::response(['success' => true], 200),
            'https://graph.facebook.com/v23.0/phone-456/register' => Http::response(['success' => true], 200),
        ]);

        $connector = new EmbeddedSignupConnector(app(HttpFactory::class));

        $pin = $connector->provisionPhoneNumber('waba-123', 'phone-456', 'business-token-abc', WhatsAppConnectionMode::CloudApi);

        $this->assertMatchesRegularExpression('/^\d{6}$/', $pin);

        Http::assertSent(function ($request) {
            return $request->url() === 'https://graph.facebook.com/v23.0/waba-123/subscribed_apps'
                && $request->hasHeader('Authorization', 'Bearer business-token-abc');
        });

        Http::assertSent(function ($request) use ($pin) {
            return $request->url() === 'https://graph.facebook.com/v23.0/phone-456/register'
                && $request->hasHeader('Authorization', 'Bearer business-token-abc')
                && $request['messaging_product'] === 'whatsapp'
                && $request['pin'] === $pin;
        });
    }

    public function test_provision_phone_number_subscribes_but_does_not_register_in_coexistence_mode(): void
    {
        Http::fake([
            'https://graph.facebook.com/v23.0/waba-123/subscribed_apps' => Http::response(['success' => true], 200),
            'https://graph.facebook.com/v23.0/phone-456/register' => Http::response(['success' => true], 200),
        ]);

        $connector = new EmbeddedSignupConnector(app(HttpFactory::class));

        $pin = $connector->provisionPhoneNumber('waba-123', 'phone-456', 'business-token-abc', WhatsAppConnectionMode::Coexistence);

        $this->assertNull($pin);

        Http::assertSent(function ($request) {
            return $request->url() === 'https://graph.facebook.com/v23.0/waba-123/subscribed_apps';
        });

        Http::assertNotSent(function ($request) {
            return $request->url() === 'https://graph.facebook.com/v23.0/phone-456/register';
        });
    }

    public function test_provision_phone_number_throws_and_registers_nothing_when_the_waba_subscription_fails(): void
    {
        Http::fake([
            'https://graph.facebook.com/v23.0/waba-123/subscribed_apps' => Http::response([
                'error' => ['message' => 'Invalid OAuth access token.', 'code' => 190],
            ], 400),
            'https://graph.facebook.com/v23.0/phone-456/register' => Http::response(['success' => true], 200),
        ]);

        $connector = new EmbeddedSignupConnector(app(HttpFactory::class));

        try {
            $connector->provisionPhoneNumber('waba-123', 'phone-456', 'business-token-abc', WhatsAppConnectionMode::CloudApi);
            $this->fail('Expected EmbeddedSignupProvisioningFailedException was not thrown.');
        } catch (EmbeddedSignupProvisioningFailedException $exception) {
            $this->assertSame(__('api.whatsapp.embedded_signup.subscribe_failed'), $exception->getMessage());
        }

        Http::assertNotSent(function ($request) {
            return $request->url() === 'https://graph.facebook.com/v23.0/phone-456/register';
        });
    }

    public function test_provision_phone_number_throws_when_the_registration_fails(): void
    {
        Http::fake([
            'https://graph.facebook.com/v23.0/waba-123/subscribed_apps' => Http::response(['success' => true], 200),
            'https://graph.facebook.com/v23.0/phone-456/register' => Http::response([
                'error' => ['message' => 'PIN registration failed.', 'code' => 133010],
            ], 400),
        ]);

        $connector = new EmbeddedSignupConnector(app(HttpFactory::class));

        try {
            $connector->provisionPhoneNumber('waba-123', 'phone-456', 'business-token-abc', WhatsAppConnectionMode::CloudApi);
            $this->fail('Expected EmbeddedSignupProvisioningFailedException was not thrown.');
        } catch (EmbeddedSignupProvisioningFailedException $exception) {
            $this->assertSame(__('api.whatsapp.embedded_signup.register_failed'), $exception->getMessage());
        }
    }

    public function test_fetch_initial_line_name_returns_the_verified_name_when_present(): void
    {
        Http::fake([
            'https://graph.facebook.com/v23.0/phone-456*' => Http::response([
                'verified_name' => 'Acme Support',
                'display_phone_number' => '+1 555 010 0100',
            ], 200),
        ]);

        $connector = new EmbeddedSignupConnector(app(HttpFactory::class));

        $name = $connector->fetchInitialLineName('phone-456', 'business-token-abc');

        $this->assertSame('Acme Support', $name);

        Http::assertSent(function ($request) {
            return str_starts_with($request->url(), 'https://graph.facebook.com/v23.0/phone-456')
                && $request->hasHeader('Authorization', 'Bearer business-token-abc')
                && $request['fields'] === 'verified_name,display_phone_number';
        });
    }

    public function test_fetch_initial_line_name_falls_back_to_the_display_phone_number(): void
    {
        Http::fake([
            'https://graph.facebook.com/v23.0/phone-456*' => Http::response([
                'display_phone_number' => '+1 555 010 0100',
            ], 200),
        ]);

        $connector = new EmbeddedSignupConnector(app(HttpFactory::class));

        $name = $connector->fetchInitialLineName('phone-456', 'business-token-abc');

        $this->assertSame('+1 555 010 0100', $name);
    }

    public function test_fetch_initial_line_name_throws_when_the_request_fails(): void
    {
        Http::fake([
            'https://graph.facebook.com/v23.0/phone-456*' => Http::response([
                'error' => ['message' => 'Unsupported get request.', 'code' => 100],
            ], 400),
        ]);

        $connector = new EmbeddedSignupConnector(app(HttpFactory::class));

        try {
            $connector->fetchInitialLineName('phone-456', 'business-token-abc');
            $this->fail('Expected EmbeddedSignupProvisioningFailedException was not thrown.');
        } catch (EmbeddedSignupProvisioningFailedException $exception) {
            $this->assertSame(__('api.whatsapp.embedded_signup.line_name_fetch_failed'), $exception->getMessage());
        }
    }

    public function test_persist_connection_creates_a_new_line_and_credential_on_fresh_connect(): void
    {
        $tenant = $this->makeTenant();
        $connector = new EmbeddedSignupConnector(app(HttpFactory::class));

        $line = $connector->persistConnection(
            $tenant,
            'phone-456',
            'waba-123',
            WhatsAppConnectionMode::CloudApi,
            'Acme Support',
            'business-token-abc',
            '123456',
        );

        $this->assertSame($tenant->getKey(), $line->tenant_id);
        $this->assertSame('phone-456', $line->phone_number_id);
        $this->assertSame('waba-123', $line->waba_id);
        $this->assertSame(WhatsAppConnectionMode::CloudApi, $line->connection_mode);
        $this->assertSame('active', $line->status);
        $this->assertTrue($line->is_enabled);
        $this->assertSame('Acme Support', $line->name);

        $tokenCredential = $this->lineCredential($line, 'access_token');
        $this->assertSame('business-token-abc', $tokenCredential->secret);

        $pinCredential = $this->lineCredential($line, 'registration_pin');
        $this->assertSame('123456', $pinCredential->secret);
    }

    public function test_persist_connection_does_not_create_a_pin_credential_when_none_was_generated(): void
    {
        $tenant = $this->makeTenant();
        $connector = new EmbeddedSignupConnector(app(HttpFactory::class));

        $line = $connector->persistConnection(
            $tenant,
            'phone-456',
            'waba-123',
            WhatsAppConnectionMode::Coexistence,
            'Acme Support',
            'business-token-abc',
            null,
        );

        $this->assertNull(
            ApiCredential::query()
                ->where('whatsapp_line_id', $line->getKey())
                ->where('credential_key', 'registration_pin')
                ->first()
        );
    }

    public function test_persist_connection_reconnecting_the_same_tenant_rotates_the_token_in_the_same_row(): void
    {
        $tenant = $this->makeTenant();
        $connector = new EmbeddedSignupConnector(app(HttpFactory::class));

        $firstLine = $connector->persistConnection(
            $tenant,
            'phone-456',
            'waba-123',
            WhatsAppConnectionMode::CloudApi,
            'Acme Support',
            'old-token',
            '111111',
        );
        $firstCredential = $this->lineCredential($firstLine, 'access_token');

        $secondLine = $connector->persistConnection(
            $tenant,
            'phone-456',
            'waba-123',
            WhatsAppConnectionMode::CloudApi,
            'Acme Support',
            'new-token',
            '222222',
        );

        $this->assertSame($firstLine->getKey(), $secondLine->getKey());
        $this->assertSame(1, WhatsAppLine::query()->where('phone_number_id', 'phone-456')->count());

        $secondCredential = $this->lineCredential($secondLine, 'access_token');
        $this->assertSame($firstCredential->getKey(), $secondCredential->getKey());
        $this->assertSame(
            1,
            ApiCredential::query()
                ->where('whatsapp_line_id', $secondLine->getKey())
                ->where('credential_key', 'access_token')
                ->count()
        );
        $this->assertSame('new-token', $secondCredential->secret);

        $pinCredential = $this->lineCredential($secondLine, 'registration_pin');
        $this->assertSame('222222', $pinCredential->secret);
    }

    protected function makeTenant(): Tenant
    {
        return Tenant::query()->create(['name' => 'Estetica Bella', 'slug' => 'estetica-bella-'.uniqid()]);
    }

    protected function lineCredential(WhatsAppLine $line, string $credentialKey): ApiCredential
    {
        return ApiCredential::query()
            ->where('scope_key', TenantScopeKey::forWhatsAppLine($line))
            ->where('credential_key', $credentialKey)
            ->firstOrFail();
    }
}
