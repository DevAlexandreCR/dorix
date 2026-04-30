<?php

namespace Tests\Feature\Observability;

use App\Logging\SanitizeContextTap;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Monolog\Handler\StreamHandler;
use Monolog\Logger;
use Tests\TestCase;

class ProductionHardeningTest extends TestCase
{
    use RefreshDatabase;

    public function test_health_endpoint_reports_runtime_checks(): void
    {
        $response = $this->getJson('/api/health');

        $response
            ->assertOk()
            ->assertJsonPath('status', 'ok')
            ->assertJsonPath('checks.database.status', 'ok')
            ->assertJsonPath('checks.cache.status', 'ok')
            ->assertJsonPath('checks.queue.status', 'ok');

        $this->assertContains($response->json('checks.horizon.status'), ['ok', 'warning']);
    }

    public function test_logging_tap_redacts_sensitive_context(): void
    {
        $path = storage_path('logs/observability-test-'.uniqid().'.log');

        try {
            $logger = new Logger('observability-test');
            $logger->pushHandler(new StreamHandler($path));

            app(SanitizeContextTap::class)($logger);

            $logger->warning('Outbound request failed for customer 573001234567', [
                'access_token' => 'super-secret-token',
                'payload' => [
                    'raw' => [
                        'document' => 'full sensitive payload',
                    ],
                    'matches' => [
                        [
                            'id' => 10,
                            'content_text' => 'Precio de la mesa auxiliar: 159900 COP y disponibilidad inmediata.',
                            'structured_payload' => [
                                'sku' => 'SKU-100',
                                'precio' => '159900',
                            ],
                            'source_ref' => [
                                'sheet_name' => 'Inventario',
                            ],
                            'metadata' => [
                                'datasets' => ['inventory'],
                            ],
                        ],
                    ],
                ],
            ]);

            $contents = file_get_contents($path);

            $this->assertIsString($contents);
            $this->assertStringNotContainsString('super-secret-token', $contents);
            $this->assertStringNotContainsString('full sensitive payload', $contents);
            $this->assertStringContainsString('[redacted]', $contents);
            $this->assertStringContainsString('mesa auxiliar', strtolower($contents));
        } finally {
            if (is_file($path)) {
                unlink($path);
            }
        }
    }
}
