<?php

namespace Tests\Feature\Operations;

use App\Enums\TenantRole;
use App\Models\AgentConfig;
use App\Models\ApiCredential;
use App\Models\AgentEvent;
use App\Models\DataSource;
use App\Models\Tenant;
use App\Models\TenantToolConfig;
use App\Models\TenantUser;
use App\Models\ToolExecution;
use App\Models\User;
use App\Models\WhatsAppLine;
use App\Support\Tenancy\TenantScopeKey;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile as TestUploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class AdminPanelTest extends TestCase
{
    use RefreshDatabase;

    public function test_tenant_admin_can_manage_panel_resources_and_view_safe_metadata(): void
    {
        $tenant = Tenant::query()->create([
            'name' => 'Acme Admin',
            'slug' => 'acme-admin',
        ]);
        $tenantAdmin = $this->tenantUser($tenant, TenantRole::TenantAdmin);
        $line = $this->line($tenant, 'Primary Line', 'primary-line-id');
        $dataSource = DataSource::query()->create([
            'tenant_id' => $tenant->id,
            'name' => 'Catalogo Base',
            'type' => 'excel',
            'status' => 'ready',
            'metadata' => [
                'source_kind' => 'excel_upload',
            ],
            'last_synced_at' => now(),
        ]);

        ApiCredential::query()->create([
            'tenant_id' => $tenant->id,
            'scope_type' => 'tenant',
            'scope_key' => TenantScopeKey::forTenant($tenant),
            'provider' => 'whatsapp_meta',
            'credential_key' => 'access_token',
            'secret' => 'hidden-token',
        ]);

        AgentEvent::query()->create([
            'tenant_id' => $tenant->id,
            'event_type' => 'tool_data_source_resolved',
            'payload' => [
                'token' => 'should-not-leak',
                'raw' => ['full' => 'payload'],
            ],
            'occurred_at' => now(),
        ]);

        ToolExecution::query()->create([
            'tenant_id' => $tenant->id,
            'tool_name' => 'search_inventory',
            'status' => 'succeeded',
            'input_summary' => [
                'arguments' => [
                    'customer_data' => [
                        'name' => 'Ana Cliente',
                    ],
                ],
            ],
            'output_summary' => [
                'matches' => [
                    [
                        'id' => 1,
                        'content_text' => 'Mesa auxiliar color natural con precio 159900 COP.',
                        'structured_payload' => [
                            'sku' => 'SKU-100',
                        ],
                    ],
                ],
            ],
            'metadata' => [
                'secret' => 'execution-secret',
            ],
            'executed_at' => now(),
        ]);

        $this->actingAs($tenantAdmin)
            ->withCsrf()
            ->withHeader('X-Tenant-Id', (string) $tenant->id)
            ->postJson('/api/v1/admin/tenant-users', [
                'name' => 'Operator Added',
                'email' => 'operator-added@example.com',
                'password' => 'secret-pass',
                'role' => TenantRole::Operator->value,
            ])
            ->assertCreated()
            ->assertJsonPath('data.user.email', 'operator-added@example.com')
            ->assertJsonPath('data.role', TenantRole::Operator->value);

        $this->actingAs($tenantAdmin)
            ->withCsrf()
            ->withHeader('X-Tenant-Id', (string) $tenant->id)
            ->postJson('/api/v1/admin/whatsapp-lines', [
                'name' => 'Secondary Line',
                'phone_number_id' => 'secondary-line-id',
                'display_phone_number' => '+573001119999',
                'status' => 'active',
                'is_enabled' => true,
            ])
            ->assertCreated()
            ->assertJsonPath('data.name', 'Secondary Line');

        $this->actingAs($tenantAdmin)
            ->withCsrf()
            ->withHeader('X-Tenant-Id', (string) $tenant->id)
            ->putJson('/api/v1/admin/agent-configs/tenant', [
                'name' => 'Tenant Agent',
                'model' => 'gpt-5.1',
                'prompt_version' => 'v2',
                'is_active' => true,
                'automation_enabled' => false,
                'system_prompt' => 'Habla solo con información confirmada.',
                'agent_pack_key' => 'sales_support_v1',
                'handoff_customer_message' => 'Te conectaré con una persona del equipo.',
            ])
            ->assertOk()
            ->assertJsonPath('data.automation_enabled', false)
            ->assertJsonPath('data.system_prompt', 'Habla solo con información confirmada.')
            ->assertJsonPath('data.agent_pack_key', 'sales_support_v1')
            ->assertJsonPath('data.handoff_customer_message', 'Te conectaré con una persona del equipo.')
            ->assertJsonPath('meta.available_agent_packs.0.key', 'sales_support_v1');

        $this->actingAs($tenantAdmin)
            ->withCsrf()
            ->withHeader('X-Tenant-Id', (string) $tenant->id)
            ->putJson('/api/v1/admin/tool-configs/tenant/search_inventory', [
                'enabled' => true,
                'timeout_seconds' => 12,
                'data_source_id' => $dataSource->id,
            ])
            ->assertOk()
            ->assertJsonPath('data.data_source_id', $dataSource->id);

        $response = $this->actingAs($tenantAdmin)
            ->withHeader('X-Tenant-Id', (string) $tenant->id)
            ->getJson('/api/v1/admin/overview');

        $response->assertOk()
            ->assertJsonPath('data.tenant.id', $tenant->id)
            ->assertJsonPath('data.agent_configs.0.automation_enabled', false)
            ->assertJsonPath('data.agent_configs.0.agent_pack_key', 'sales_support_v1')
            ->assertJsonPath('data.agent_configs.0.handoff_customer_message', 'Te conectaré con una persona del equipo.')
            ->assertJsonPath('data.tool_configs.0.data_source_id', $dataSource->id)
            ->assertJsonPath('data.credential_metadata.0.provider', 'whatsapp_meta')
            ->assertJsonPath('data.credential_metadata.0.has_secret', true)
            ->assertJsonPath('data.available_agent_packs.0.key', 'sales_support_v1');

        $response->assertJsonFragment([
            'email' => 'operator-added@example.com',
        ])->assertJsonFragment([
            'name' => 'Secondary Line',
        ]);

        $this->assertArrayNotHasKey('secret', $response->json('data.credential_metadata.0'));
        $this->assertSame('[redacted]', $response->json('data.logs.agent_events.0.payload.token'));
        $this->assertTrue($response->json('data.logs.agent_events.0.payload.raw.redacted'));
        $this->assertSame(['field_count' => 1, 'keys' => ['name']], $response->json('data.logs.tool_executions.0.input_summary.arguments.customer_data'));
        $this->assertSame('[redacted]', $response->json('data.logs.tool_executions.0.metadata.secret'));
        $this->assertDatabaseHas('tenant_tool_configs', [
            'tenant_id' => $tenant->id,
            'scope_key' => TenantScopeKey::forTenant($tenant),
            'tool_name' => 'search_inventory',
        ]);
        $this->assertDatabaseHas('audit_events', [
            'tenant_id' => $tenant->id,
            'event_type' => 'tenant_user_added',
        ]);
    }

    public function test_platform_admin_can_upsert_credentials_but_tenant_admin_cannot(): void
    {
        $tenant = Tenant::query()->create([
            'name' => 'Acme Secure',
            'slug' => 'acme-secure',
        ]);
        $line = $this->line($tenant, 'Credential Line', 'credential-line-id');
        $tenantAdmin = $this->tenantUser($tenant, TenantRole::TenantAdmin);
        $platformAdmin = User::factory()->platformAdmin()->create();

        $this->actingAs($platformAdmin)
            ->withCsrf()
            ->withHeader('X-Tenant-Id', (string) $tenant->id)
            ->putJson('/api/v1/admin/credentials', [
                'scope_type' => 'whatsapp_line',
                'whatsapp_line_id' => $line->id,
                'provider' => 'whatsapp_meta',
                'credential_key' => 'access_token',
                'secret' => 'super-secret-token',
            ])
            ->assertOk()
            ->assertJsonPath('data.scope_type', 'whatsapp_line')
            ->assertJsonPath('data.whatsapp_line_id', $line->id)
            ->assertJsonPath('data.has_secret', true);

        $credential = ApiCredential::query()->firstOrFail();

        $this->assertSame('super-secret-token', $credential->secret);
        $this->assertNotSame('super-secret-token', $credential->getRawOriginal('secret'));

        $this->actingAs($tenantAdmin)
            ->withCsrf()
            ->withHeader('X-Tenant-Id', (string) $tenant->id)
            ->putJson('/api/v1/admin/credentials', [
                'scope_type' => 'tenant',
                'provider' => 'whatsapp_meta',
                'credential_key' => 'access_token',
                'secret' => 'should-not-pass',
            ])
            ->assertForbidden();
    }

    public function test_viewer_cannot_access_admin_panel_or_manage_data_sources(): void
    {
        Storage::fake('local');

        $tenant = Tenant::query()->create([
            'name' => 'Acme Viewer',
            'slug' => 'acme-viewer',
        ]);
        $viewer = $this->tenantUser($tenant, TenantRole::Viewer);

        $this->actingAs($viewer)
            ->withHeader('X-Tenant-Id', (string) $tenant->id)
            ->getJson('/api/v1/admin/overview')
            ->assertForbidden();

        $this->actingAs($viewer)
            ->withCsrf()
            ->withHeader('X-Tenant-Id', (string) $tenant->id)
            ->post('/api/v1/data-sources/excel', [
                'name' => 'No Access',
                'file' => TestUploadedFile::fake()->create('catalogo.xlsx', 32),
            ])
            ->assertForbidden();
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

    protected function line(Tenant $tenant, string $name, string $phoneNumberId): WhatsAppLine
    {
        return WhatsAppLine::query()->create([
            'tenant_id' => $tenant->id,
            'name' => $name,
            'phone_number_id' => $phoneNumberId,
            'display_phone_number' => '+573001110000',
            'status' => 'active',
            'is_enabled' => true,
        ]);
    }
}
