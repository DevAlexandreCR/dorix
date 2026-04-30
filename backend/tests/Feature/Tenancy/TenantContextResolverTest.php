<?php

namespace Tests\Feature\Tenancy;

use App\Http\Middleware\ResolveTenantContext;
use App\Models\Tenant;
use App\Support\Tenancy\TenantContextManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class TenantContextResolverTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        if (! Route::has('test.tenant-context')) {
            Route::middleware(ResolveTenantContext::class)
                ->get('/api/_test/tenant-context/{tenant?}', function (Request $request, TenantContextManager $manager) {
                    $context = $manager->require();

                    return response()->json([
                        'tenant_id' => $context->tenantId(),
                        'tenant_slug' => $context->tenant->slug,
                        'source' => $context->source,
                    ]);
                })
                ->name('test.tenant-context');
        }
    }

    public function test_it_resolves_tenant_context_from_header(): void
    {
        $tenant = Tenant::query()->create([
            'name' => 'Acme',
            'slug' => 'acme',
        ]);

        $this->getJson('/api/_test/tenant-context', [
            'X-Tenant-Id' => (string) $tenant->id,
        ])->assertOk()->assertExactJson([
            'tenant_id' => $tenant->id,
            'tenant_slug' => 'acme',
            'source' => 'header:id',
        ]);
    }

    public function test_it_resolves_tenant_context_from_route_parameter(): void
    {
        $tenant = Tenant::query()->create([
            'name' => 'Globex',
            'slug' => 'globex',
        ]);

        $this->getJson("/api/_test/tenant-context/{$tenant->slug}")
            ->assertOk()
            ->assertExactJson([
                'tenant_id' => $tenant->id,
                'tenant_slug' => 'globex',
                'source' => 'route',
            ]);
    }

    public function test_it_requires_explicit_tenant_context(): void
    {
        $this->getJson('/api/_test/tenant-context', [
            'Accept-Language' => 'es-CO',
        ])
            ->assertBadRequest()
            ->assertJsonPath('code', 'tenant_context_required')
            ->assertJsonPath('message', 'Hace falta el contexto del tenant. Envía el parámetro de ruta {tenant} o los encabezados X-Tenant-Id / X-Tenant-Slug.');
    }

    public function test_it_translates_tenant_context_errors_to_english_when_requested(): void
    {
        $this->getJson('/api/_test/tenant-context', [
            'Accept-Language' => 'en',
        ])
            ->assertBadRequest()
            ->assertJsonPath('code', 'tenant_context_required')
            ->assertJsonPath('message', 'Tenant context is required. Send the {tenant} route parameter or the X-Tenant-Id / X-Tenant-Slug headers.');
    }
}
