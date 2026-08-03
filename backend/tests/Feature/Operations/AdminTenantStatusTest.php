<?php

namespace Tests\Feature\Operations;

use App\Enums\TenantRole;
use App\Enums\TenantStatus;
use App\Models\Tenant;
use App\Models\TenantUser;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminTenantStatusTest extends TestCase
{
    use RefreshDatabase;

    public function test_tenant_admin_can_pause_tenant_via_enum_status(): void
    {
        $tenant = Tenant::query()->create([
            'name' => 'Acme Status',
            'slug' => 'acme-status',
            'status' => TenantStatus::Active,
        ]);
        $tenantAdmin = $this->tenantUser($tenant, TenantRole::TenantAdmin);

        $this->actingAs($tenantAdmin)
            ->withCsrf()
            ->withHeader('X-Tenant-Id', (string) $tenant->id)
            ->patchJson("/api/v1/admin/tenants/{$tenant->id}", [
                'status' => 'paused',
            ])
            ->assertOk()
            ->assertJsonPath('data.status', 'paused');

        $this->assertSame(TenantStatus::Paused, $tenant->fresh()->status);
    }

    public function test_update_tenant_rejects_status_outside_enum(): void
    {
        $tenant = Tenant::query()->create([
            'name' => 'Acme Status Invalid',
            'slug' => 'acme-status-invalid',
            'status' => TenantStatus::Active,
        ]);
        $tenantAdmin = $this->tenantUser($tenant, TenantRole::TenantAdmin);

        $this->actingAs($tenantAdmin)
            ->withCsrf()
            ->withHeader('X-Tenant-Id', (string) $tenant->id)
            ->patchJson("/api/v1/admin/tenants/{$tenant->id}", [
                'status' => 'archivado',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['status']);

        $this->assertSame(TenantStatus::Active, $tenant->fresh()->status);
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
}
