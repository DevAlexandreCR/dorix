<?php

namespace App\Support\Auth;

use App\Enums\Permission;
use App\Enums\TenantRole;
use App\Models\Tenant;
use App\Models\User;

class TenantAccess
{
    /**
     * @var array<string, list<string>>
     */
    private const TENANT_ROLE_PERMISSIONS = [
        TenantRole::TenantAdmin->value => [
            Permission::ManageTenant->value,
            Permission::ManageTenantUsers->value,
            Permission::ManageAgentConfig->value,
            Permission::ViewConversations->value,
            Permission::ReplyToConversations->value,
            Permission::ManageHandoffs->value,
            Permission::ViewCredentialMetadata->value,
        ],
        TenantRole::Operator->value => [
            Permission::ViewConversations->value,
            Permission::ReplyToConversations->value,
            Permission::ManageHandoffs->value,
        ],
        TenantRole::Viewer->value => [
            Permission::ViewConversations->value,
        ],
    ];

    public function allows(User $user, Permission $permission, ?Tenant $tenant = null): bool
    {
        if ($permission === Permission::ManagePlatform) {
            return $user->isPlatformAdmin();
        }

        if ($user->isPlatformAdmin()) {
            return true;
        }

        if (! $tenant) {
            return false;
        }

        $role = $user->tenantRole($tenant);

        if (! $role) {
            return false;
        }

        return in_array($permission->value, self::TENANT_ROLE_PERMISSIONS[$role->value] ?? [], true);
    }
}
