<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Enums\PlatformRole;
use App\Enums\TenantRole;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

#[Fillable(['name', 'email', 'password', 'platform_role'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'platform_role' => PlatformRole::class,
        ];
    }

    public function tenantMemberships(): HasMany
    {
        return $this->hasMany(TenantUser::class);
    }

    public function tenants(): BelongsToMany
    {
        return $this->belongsToMany(Tenant::class, 'tenant_users')
            ->withPivot('role')
            ->withTimestamps();
    }

    public function assignedConversations(): HasMany
    {
        return $this->hasMany(Conversation::class, 'assigned_to_user_id');
    }

    public function isPlatformAdmin(): bool
    {
        return $this->platform_role === PlatformRole::PlatformAdmin;
    }

    public function tenantRole(Tenant|int $tenant): ?TenantRole
    {
        $tenantId = $tenant instanceof Tenant ? $tenant->getKey() : $tenant;

        $membership = $this->relationLoaded('tenantMemberships')
            ? $this->tenantMemberships->firstWhere('tenant_id', $tenantId)
            : $this->tenantMemberships()->where('tenant_id', $tenantId)->first();

        return $membership?->role;
    }

    public function hasTenantRole(Tenant|int $tenant, TenantRole ...$roles): bool
    {
        $role = $this->tenantRole($tenant);

        return $role !== null && in_array($role, $roles, true);
    }
}
