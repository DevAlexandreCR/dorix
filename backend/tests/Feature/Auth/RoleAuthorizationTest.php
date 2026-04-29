<?php

namespace Tests\Feature\Auth;

use App\Enums\Permission;
use App\Enums\TenantRole;
use App\Models\Tenant;
use App\Models\TenantUser;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Tests\TestCase;

class RoleAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_platform_admin_has_global_access_and_tenant_roles_require_explicit_tenant_scope(): void
    {
        $tenant = Tenant::query()->create([
            'name' => 'Acme',
            'slug' => 'acme',
        ]);

        $platformAdmin = User::factory()->platformAdmin()->create();
        $tenantAdmin = User::factory()->create();
        $operator = User::factory()->create();
        $viewer = User::factory()->create();
        $outsider = User::factory()->create();

        TenantUser::query()->create([
            'tenant_id' => $tenant->id,
            'user_id' => $tenantAdmin->id,
            'role' => TenantRole::TenantAdmin,
        ]);

        TenantUser::query()->create([
            'tenant_id' => $tenant->id,
            'user_id' => $operator->id,
            'role' => TenantRole::Operator,
        ]);

        TenantUser::query()->create([
            'tenant_id' => $tenant->id,
            'user_id' => $viewer->id,
            'role' => TenantRole::Viewer,
        ]);

        $this->assertTrue(Gate::forUser($platformAdmin)->allows(Permission::ManagePlatform->value));
        $this->assertTrue(Gate::forUser($platformAdmin)->allows(Permission::ManageTenant->value, $tenant));

        $this->assertTrue(Gate::forUser($tenantAdmin)->allows(Permission::ManageTenant->value, $tenant));
        $this->assertTrue(Gate::forUser($tenantAdmin)->allows(Permission::ViewCredentialMetadata->value, $tenant));
        $this->assertFalse(Gate::forUser($tenantAdmin)->allows(Permission::ManagePlatform->value));

        $this->assertTrue(Gate::forUser($operator)->allows(Permission::ReplyToConversations->value, $tenant));
        $this->assertFalse(Gate::forUser($operator)->allows(Permission::ManageTenant->value, $tenant));
        $this->assertFalse(Gate::forUser($operator)->allows(Permission::ReplyToConversations->value));

        $this->assertTrue(Gate::forUser($viewer)->allows(Permission::ViewConversations->value, $tenant));
        $this->assertFalse(Gate::forUser($viewer)->allows(Permission::ReplyToConversations->value, $tenant));

        $this->assertFalse(Gate::forUser($outsider)->allows(Permission::ViewConversations->value, $tenant));
    }
}
