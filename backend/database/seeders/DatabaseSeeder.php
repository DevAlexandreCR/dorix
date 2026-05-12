<?php

namespace Database\Seeders;

use App\Enums\TenantRole;
use App\Models\AgentConfig;
use App\Models\Tenant;
use App\Models\TenantToolConfig;
use App\Models\TenantUser;
use App\Models\User;
use App\Models\WhatsAppLine;
use App\Support\Tenancy\TenantScopeKey;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $tenant = Tenant::query()->create([
            'name' => 'Acme Demo',
            'slug' => 'acme-demo',
            'status' => 'active',
            'metadata' => [
                'seeded_by' => static::class,
            ],
        ]);

        User::factory()->platformAdmin()->create([
            'name' => 'Platform Admin',
            'email' => 'platform-admin@example.com',
        ]);

        $tenantAdmin = User::factory()->create([
            'name' => 'Tenant Admin',
            'email' => 'tenant-admin@example.com',
        ]);

        $operator = User::factory()->create([
            'name' => 'Operator User',
            'email' => 'operator@example.com',
        ]);

        $viewer = User::factory()->create([
            'name' => 'Viewer User',
            'email' => 'viewer@example.com',
        ]);

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

        $line = WhatsAppLine::query()->create([
            'tenant_id' => $tenant->id,
            'name' => 'Primary Support Line',
            'phone_number_id' => 'seed-phone-number-id',
            'display_phone_number' => '+573001112233',
            'status' => 'active',
            'is_enabled' => true,
            'metadata' => [
                'seeded_by' => static::class,
            ],
        ]);

        AgentConfig::query()->create([
            'tenant_id' => $tenant->id,
            'whatsapp_line_id' => null,
            'scope_type' => 'tenant',
            'scope_key' => TenantScopeKey::forTenant($tenant),
            'name' => 'Default Tenant Agent',
            'model_key' => 'balanced',
            'prompt_version' => 'v1',
            'is_active' => true,
            'settings' => [
                'automation_enabled' => true,
            ],
        ]);

        AgentConfig::query()->create([
            'tenant_id' => $tenant->id,
            'whatsapp_line_id' => $line->id,
            'scope_type' => 'whatsapp_line',
            'scope_key' => TenantScopeKey::forWhatsAppLine($line),
            'name' => 'Primary Line Override',
            'model_key' => 'balanced',
            'prompt_version' => 'v1',
            'is_active' => true,
            'settings' => [
                'automation_enabled' => true,
            ],
        ]);

        TenantToolConfig::query()->create([
            'tenant_id' => $tenant->id,
            'whatsapp_line_id' => null,
            'scope_type' => 'tenant',
            'scope_key' => TenantScopeKey::forTenant($tenant),
            'tool_name' => 'handoff_to_human',
            'enabled' => true,
        ]);
    }
}
