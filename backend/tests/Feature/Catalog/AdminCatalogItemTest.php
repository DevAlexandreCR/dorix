<?php

namespace Tests\Feature\Catalog;

use App\Enums\TenantRole;
use App\Enums\TenantStatus;
use App\Models\CatalogItem;
use App\Models\Tenant;
use App\Models\TenantUser;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminCatalogItemTest extends TestCase
{
    use RefreshDatabase;

    public function test_tenant_admin_can_run_full_catalog_item_crud(): void
    {
        $tenant = $this->tenant('Acme Wellness');
        $admin = $this->tenantUser($tenant, TenantRole::TenantAdmin);

        $created = $this->actingAs($admin)
            ->withCsrf()
            ->withHeader('X-Tenant-Id', (string) $tenant->id)
            ->postJson('/api/v1/admin/catalog-items', [
                'kind' => 'service',
                'name' => 'Limpieza facial',
                'category' => 'Facial',
                'price_type' => 'fixed',
                'price_amount' => 120000,
                'currency' => 'COP',
                'duration_minutes' => 45,
                'active' => true,
            ])
            ->assertCreated()
            ->assertJsonPath('data.name', 'Limpieza facial')
            ->assertJsonPath('data.is_bookable', true);

        $itemId = $created->json('data.id');

        $this->actingAs($admin)
            ->withHeader('X-Tenant-Id', (string) $tenant->id)
            ->getJson('/api/v1/admin/catalog-items')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $itemId);

        $this->actingAs($admin)
            ->withCsrf()
            ->withHeader('X-Tenant-Id', (string) $tenant->id)
            ->putJson("/api/v1/admin/catalog-items/{$itemId}", [
                'kind' => 'service',
                'name' => 'Limpieza facial profunda',
                'category' => 'Facial',
                'price_type' => 'fixed',
                'price_amount' => 150000,
                'currency' => 'COP',
                'duration_minutes' => 60,
                'active' => true,
            ])
            ->assertOk()
            ->assertJsonPath('data.name', 'Limpieza facial profunda')
            ->assertJsonPath('data.duration_minutes', 60);

        $this->actingAs($admin)
            ->withCsrf()
            ->withHeader('X-Tenant-Id', (string) $tenant->id)
            ->deleteJson("/api/v1/admin/catalog-items/{$itemId}")
            ->assertNoContent();

        $this->assertDatabaseMissing('catalog_items', ['id' => $itemId]);
        $this->assertDatabaseHas('audit_events', [
            'tenant_id' => $tenant->id,
            'event_type' => 'catalog_item_deleted',
        ]);
    }

    public function test_paused_tenant_can_still_manage_its_catalog(): void
    {
        $tenant = $this->tenant('Acme Paused');
        $tenant->update(['status' => TenantStatus::Paused]);
        $admin = $this->tenantUser($tenant, TenantRole::TenantAdmin);

        $this->actingAs($admin)
            ->withCsrf()
            ->withHeader('X-Tenant-Id', (string) $tenant->id)
            ->postJson('/api/v1/admin/catalog-items', [
                'kind' => 'product',
                'name' => 'Crema hidratante',
                'price_type' => 'fixed',
                'price_amount' => 45000,
                'active' => true,
            ])
            ->assertCreated();
    }

    public function test_range_price_requires_min_lower_than_max(): void
    {
        $tenant = $this->tenant('Acme Range');
        $admin = $this->tenantUser($tenant, TenantRole::TenantAdmin);

        $this->actingAs($admin)
            ->withCsrf()
            ->withHeader('X-Tenant-Id', (string) $tenant->id)
            ->postJson('/api/v1/admin/catalog-items', [
                'kind' => 'service',
                'name' => 'Depilación láser',
                'price_type' => 'range',
                'price_min' => 200000,
                'price_max' => 150000,
                'duration_minutes' => 30,
                'active' => true,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['price_min']);
    }

    public function test_fixed_price_requires_amount(): void
    {
        $tenant = $this->tenant('Acme Fixed');
        $admin = $this->tenantUser($tenant, TenantRole::TenantAdmin);

        $this->actingAs($admin)
            ->withCsrf()
            ->withHeader('X-Tenant-Id', (string) $tenant->id)
            ->postJson('/api/v1/admin/catalog-items', [
                'kind' => 'service',
                'name' => 'Masaje relajante',
                'price_type' => 'fixed',
                'duration_minutes' => 60,
                'active' => true,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['price_amount']);
    }

    public function test_bookable_service_requires_positive_duration(): void
    {
        $tenant = $this->tenant('Acme Duration');
        $admin = $this->tenantUser($tenant, TenantRole::TenantAdmin);

        $this->actingAs($admin)
            ->withCsrf()
            ->withHeader('X-Tenant-Id', (string) $tenant->id)
            ->postJson('/api/v1/admin/catalog-items', [
                'kind' => 'service',
                'name' => 'Peeling químico',
                'price_type' => 'fixed',
                'price_amount' => 90000,
                'active' => true,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['duration_minutes']);
    }

    public function test_product_cannot_carry_duration_or_assessment_link(): void
    {
        $tenant = $this->tenant('Acme Product');
        $admin = $this->tenantUser($tenant, TenantRole::TenantAdmin);
        $assessment = $this->bookableService($tenant, 'Valoración');

        $this->actingAs($admin)
            ->withCsrf()
            ->withHeader('X-Tenant-Id', (string) $tenant->id)
            ->postJson('/api/v1/admin/catalog-items', [
                'kind' => 'product',
                'name' => 'Crema hidratante',
                'price_type' => 'fixed',
                'price_amount' => 50000,
                'duration_minutes' => 10,
                'assessment_item_id' => $assessment->id,
                'active' => true,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['duration_minutes', 'assessment_item_id']);
    }

    public function test_assessment_link_rejects_self_reference_and_chains(): void
    {
        $tenant = $this->tenant('Acme Assessment');
        $admin = $this->tenantUser($tenant, TenantRole::TenantAdmin);

        $assessment = $this->bookableService($tenant, 'Valoración inicial');
        $chained = CatalogItem::query()->create([
            'tenant_id' => $tenant->id,
            'kind' => 'service',
            'name' => 'Ácido hialurónico',
            'price_type' => 'fixed',
            'price_amount' => null,
            'assessment_item_id' => $assessment->id,
            'active' => true,
        ]);
        $otherAssessment = $this->bookableService($tenant, 'Otra valoración');

        // Self-reference.
        $this->actingAs($admin)
            ->withCsrf()
            ->withHeader('X-Tenant-Id', (string) $tenant->id)
            ->putJson("/api/v1/admin/catalog-items/{$assessment->id}", [
                'kind' => 'service',
                'name' => $assessment->name,
                'price_type' => 'fixed',
                'price_amount' => 0,
                'duration_minutes' => 20,
                'assessment_item_id' => $assessment->id,
                'active' => true,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['assessment_item_id']);

        // Chained target: $chained already requires its own assessment.
        $this->actingAs($admin)
            ->withCsrf()
            ->withHeader('X-Tenant-Id', (string) $tenant->id)
            ->postJson('/api/v1/admin/catalog-items', [
                'kind' => 'service',
                'name' => 'Plasma rico en plaquetas',
                'price_type' => 'fixed',
                'price_amount' => null,
                'assessment_item_id' => $chained->id,
                'active' => true,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['assessment_item_id']);

        // $assessment is already referenced as the assessment of $chained,
        // so it cannot itself be turned into an item that requires one.
        $this->actingAs($admin)
            ->withCsrf()
            ->withHeader('X-Tenant-Id', (string) $tenant->id)
            ->putJson("/api/v1/admin/catalog-items/{$assessment->id}", [
                'kind' => 'service',
                'name' => $assessment->name,
                'price_type' => 'fixed',
                'price_amount' => null,
                'assessment_item_id' => $otherAssessment->id,
                'active' => true,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['assessment_item_id']);
    }

    public function test_cross_tenant_assessment_link_is_rejected(): void
    {
        $tenantA = $this->tenant('Acme Cross A');
        $tenantB = $this->tenant('Acme Cross B');
        $admin = $this->tenantUser($tenantA, TenantRole::TenantAdmin);
        $foreignAssessment = $this->bookableService($tenantB, 'Valoración ajena');

        $this->actingAs($admin)
            ->withCsrf()
            ->withHeader('X-Tenant-Id', (string) $tenantA->id)
            ->postJson('/api/v1/admin/catalog-items', [
                'kind' => 'service',
                'name' => 'Procedimiento con valoración ajena',
                'price_type' => 'fixed',
                'price_amount' => null,
                'assessment_item_id' => $foreignAssessment->id,
                'active' => true,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['assessment_item_id']);
    }

    public function test_tenant_isolation_prevents_cross_tenant_read_and_writes(): void
    {
        $tenantA = $this->tenant('Acme Isolation A');
        $tenantB = $this->tenant('Acme Isolation B');
        $adminA = $this->tenantUser($tenantA, TenantRole::TenantAdmin);
        $itemB = $this->bookableService($tenantB, 'Servicio de otro tenant');

        $this->actingAs($adminA)
            ->withHeader('X-Tenant-Id', (string) $tenantA->id)
            ->getJson('/api/v1/admin/catalog-items')
            ->assertOk()
            ->assertJsonCount(0, 'data');

        $this->actingAs($adminA)
            ->withCsrf()
            ->withHeader('X-Tenant-Id', (string) $tenantA->id)
            ->putJson("/api/v1/admin/catalog-items/{$itemB->id}", [
                'kind' => 'service',
                'name' => 'Intento cruzado',
                'price_type' => 'fixed',
                'price_amount' => 10000,
                'duration_minutes' => 15,
                'active' => true,
            ])
            ->assertNotFound();

        $this->actingAs($adminA)
            ->withCsrf()
            ->withHeader('X-Tenant-Id', (string) $tenantA->id)
            ->deleteJson("/api/v1/admin/catalog-items/{$itemB->id}")
            ->assertNotFound();

        $this->assertDatabaseHas('catalog_items', ['id' => $itemB->id]);
    }

    public function test_viewer_cannot_manage_catalog(): void
    {
        $tenant = $this->tenant('Acme Viewer');
        $viewer = $this->tenantUser($tenant, TenantRole::Viewer);

        $this->actingAs($viewer)
            ->withHeader('X-Tenant-Id', (string) $tenant->id)
            ->getJson('/api/v1/admin/catalog-items')
            ->assertForbidden();

        $this->actingAs($viewer)
            ->withCsrf()
            ->withHeader('X-Tenant-Id', (string) $tenant->id)
            ->postJson('/api/v1/admin/catalog-items', [
                'kind' => 'service',
                'name' => 'Intento sin permiso',
                'price_type' => 'fixed',
                'price_amount' => 30000,
                'duration_minutes' => 30,
                'active' => true,
            ])
            ->assertForbidden();
    }

    protected function tenant(string $name): Tenant
    {
        return Tenant::query()->create([
            'name' => $name,
            'slug' => str()->slug($name),
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

    protected function bookableService(Tenant $tenant, string $name): CatalogItem
    {
        return CatalogItem::query()->create([
            'tenant_id' => $tenant->id,
            'kind' => 'service',
            'name' => $name,
            'price_type' => 'fixed',
            'price_amount' => 0,
            'duration_minutes' => 20,
            'active' => true,
        ]);
    }
}
