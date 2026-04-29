<?php

namespace App\Providers;

use App\Enums\Permission;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Auth\TenantAccess;
use App\Support\Tenancy\Contracts\TenantContextResolver;
use App\Support\Tenancy\RequestTenantContextResolver;
use App\Support\Tenancy\TenantContextManager;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->scoped(TenantContextManager::class);
        $this->app->bind(TenantContextResolver::class, RequestTenantContextResolver::class);
        $this->app->singleton(TenantAccess::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $tenantAccess = $this->app->make(TenantAccess::class);

        foreach (Permission::cases() as $permission) {
            Gate::define($permission->value, function (User $user, ?Tenant $tenant = null) use ($tenantAccess, $permission): bool {
                return $tenantAccess->allows($user, $permission, $tenant);
            });
        }
    }
}
