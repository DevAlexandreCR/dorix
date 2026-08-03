<?php

namespace Tests\Feature\Operations;

use App\Enums\ConversationStatus;
use App\Enums\TenantRole;
use App\Enums\TenantStatus;
use App\Models\AgentConfig;
use App\Models\ApiCredential;
use App\Models\Conversation;
use App\Models\Tenant;
use App\Models\TenantUser;
use App\Models\User;
use App\Models\WhatsAppLine;
use App\Support\Tenancy\TenantScopeKey;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile as TestUploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Covers task 2.4 of `enforce-tenant-status`: the `EnsureTenantIsActive`
 * ("tenant.active") gate on the operational prefixes (conversations,
 * agent-sandbox, data-sources), the deliberate exemption of `/admin/**`
 * (design.md decision 4), and both reactivation paths.
 */
class TenantStatusGateTest extends TestCase
{
    use RefreshDatabase;

    // -----------------------------------------------------------------
    // Conversations prefix
    // -----------------------------------------------------------------

    public function test_paused_tenant_is_blocked_from_conversations_index_and_manual_reply(): void
    {
        [$tenant, , $conversation] = $this->conversationFixtures(status: ConversationStatus::HumanHandoff);
        $operator = $this->tenantUser($tenant, TenantRole::Operator);

        $tenant->update(['status' => TenantStatus::Paused]);

        $this->actingAs($operator)
            ->withHeader('X-Tenant-Id', (string) $tenant->id)
            ->getJson('/api/v1/conversations')
            ->assertForbidden()
            ->assertJsonPath('code', 'tenant_paused');

        $this->actingAs($operator)
            ->withCsrf()
            ->withHeader('X-Tenant-Id', (string) $tenant->id)
            ->postJson("/api/v1/conversations/{$conversation->id}/manual-reply", [
                'body' => 'No debería enviarse.',
            ])
            ->assertForbidden()
            ->assertJsonPath('code', 'tenant_paused');
    }

    public function test_active_tenant_conversations_endpoints_work_normally(): void
    {
        [$tenant, $line, $conversation] = $this->conversationFixtures(status: ConversationStatus::HumanHandoff);
        $operator = $this->tenantUser($tenant, TenantRole::Operator);
        $this->createWhatsappCredential($tenant, $line);

        Http::fake([
            'https://graph.facebook.com/*' => Http::response([
                'messages' => [
                    ['id' => 'wamid.gate.100'],
                ],
            ], 200),
        ]);

        $this->actingAs($operator)
            ->withHeader('X-Tenant-Id', (string) $tenant->id)
            ->getJson('/api/v1/conversations')
            ->assertOk()
            ->assertJsonPath('data.0.id', $conversation->id);

        $this->actingAs($operator)
            ->withCsrf()
            ->withHeader('X-Tenant-Id', (string) $tenant->id)
            ->postJson("/api/v1/conversations/{$conversation->id}/manual-reply", [
                'body' => 'Te ayudo con eso.',
            ])
            ->assertCreated()
            ->assertJsonPath('data.body', 'Te ayudo con eso.');
    }

    // -----------------------------------------------------------------
    // Agent sandbox prefix
    // -----------------------------------------------------------------

    public function test_paused_tenant_is_blocked_from_agent_sandbox_index_and_message_endpoint(): void
    {
        [$tenant, $line] = $this->sandboxFixtures();
        $tenantAdmin = $this->tenantUser($tenant, TenantRole::TenantAdmin);

        $sessionResponse = $this->actingAs($tenantAdmin)
            ->withCsrf()
            ->withHeader('X-Tenant-Id', (string) $tenant->id)
            ->postJson('/api/v1/agent-sandbox/sessions', [
                'whatsapp_line_id' => $line->id,
                'label' => 'Antes de pausar',
            ])
            ->assertCreated();

        $conversationId = $sessionResponse->json('data.conversation.id');

        $tenant->update(['status' => TenantStatus::Paused]);

        $this->actingAs($tenantAdmin)
            ->withHeader('X-Tenant-Id', (string) $tenant->id)
            ->getJson('/api/v1/agent-sandbox/sessions')
            ->assertForbidden()
            ->assertJsonPath('code', 'tenant_paused');

        $this->actingAs($tenantAdmin)
            ->withCsrf()
            ->withHeader('X-Tenant-Id', (string) $tenant->id)
            ->postJson("/api/v1/agent-sandbox/sessions/{$conversationId}/messages", [
                'body' => 'No debería procesarse.',
            ])
            ->assertForbidden()
            ->assertJsonPath('code', 'tenant_paused');
    }

    public function test_active_tenant_agent_sandbox_endpoints_work_normally(): void
    {
        [$tenant, $line] = $this->sandboxFixtures();
        $tenantAdmin = $this->tenantUser($tenant, TenantRole::TenantAdmin);

        Http::fake([
            'https://api.openai.com/v1/responses' => Http::response([
                'output_text' => json_encode([
                    'outcome' => 'request_missing_information',
                    'reply_text' => 'Respuesta sandbox.',
                    'handoff_reason' => '',
                    'tool_name' => '',
                    'tool_arguments_json' => '{}',
                    'missing_information_fields' => ['interest_summary'],
                    'current_intent' => 'customer_data_capture',
                    'internal_notes' => 'Reply locally.',
                ], JSON_THROW_ON_ERROR),
            ], 200),
        ]);

        $sessionResponse = $this->actingAs($tenantAdmin)
            ->withCsrf()
            ->withHeader('X-Tenant-Id', (string) $tenant->id)
            ->postJson('/api/v1/agent-sandbox/sessions', [
                'whatsapp_line_id' => $line->id,
                'label' => 'Tenant activo',
            ])
            ->assertCreated();

        $conversationId = $sessionResponse->json('data.conversation.id');

        $this->actingAs($tenantAdmin)
            ->withHeader('X-Tenant-Id', (string) $tenant->id)
            ->getJson('/api/v1/agent-sandbox/sessions')
            ->assertOk()
            ->assertJsonPath('data.0.id', $conversationId);

        $this->actingAs($tenantAdmin)
            ->withCsrf()
            ->withHeader('X-Tenant-Id', (string) $tenant->id)
            ->postJson("/api/v1/agent-sandbox/sessions/{$conversationId}/messages", [
                'body' => 'Hola sandbox',
            ])
            ->assertCreated()
            ->assertJsonPath('data.last_turn.runtime_outcome', 'request_missing_information');
    }

    // -----------------------------------------------------------------
    // Data sources prefix
    // -----------------------------------------------------------------

    public function test_paused_tenant_is_blocked_from_data_sources_index_and_store(): void
    {
        Storage::fake('local');

        $tenant = Tenant::query()->create([
            'name' => 'Acme Gate Paused',
            'slug' => 'acme-gate-paused',
            'status' => TenantStatus::Paused,
        ]);
        $tenantAdmin = $this->tenantUser($tenant, TenantRole::TenantAdmin);

        $this->actingAs($tenantAdmin)
            ->withHeader('X-Tenant-Id', (string) $tenant->id)
            ->getJson('/api/v1/data-sources')
            ->assertForbidden()
            ->assertJsonPath('code', 'tenant_paused');

        $this->actingAs($tenantAdmin)
            ->withCsrf()
            ->withHeader('X-Tenant-Id', (string) $tenant->id)
            ->post('/api/v1/data-sources', [
                'name' => 'No debería crearse',
                'file' => TestUploadedFile::fake()->createWithContent(
                    'politicas.txt',
                    'Contenido que no debería importarse.',
                ),
            ])
            ->assertForbidden()
            ->assertJsonPath('code', 'tenant_paused');
    }

    public function test_active_tenant_data_sources_endpoints_work_normally(): void
    {
        Storage::fake('local');

        $tenant = Tenant::query()->create([
            'name' => 'Acme Gate Active',
            'slug' => 'acme-gate-active',
            'status' => TenantStatus::Active,
        ]);
        $tenantAdmin = $this->tenantUser($tenant, TenantRole::TenantAdmin);

        $this->actingAs($tenantAdmin)
            ->withHeader('X-Tenant-Id', (string) $tenant->id)
            ->getJson('/api/v1/data-sources')
            ->assertOk();

        $this->actingAs($tenantAdmin)
            ->withCsrf()
            ->withHeader('X-Tenant-Id', (string) $tenant->id)
            ->post('/api/v1/data-sources', [
                'name' => 'Politicas',
                'file' => TestUploadedFile::fake()->createWithContent(
                    'politicas.txt',
                    'Contenido válido para importar.',
                ),
            ])
            ->assertCreated()
            ->assertJsonPath('data.name', 'Politicas');
    }

    // -----------------------------------------------------------------
    // /admin/** regression guard: must stay open with the tenant paused,
    // even after 2.2 split the shared middleware group. This is the
    // self-lockout hazard the whole change exists to avoid — an
    // incomplete split here would leave a tenant_admin unable to see or
    // reverse their own pause.
    // -----------------------------------------------------------------

    public function test_admin_configuration_routes_stay_available_while_tenant_is_paused_guarding_against_a_partial_2_2_split(): void
    {
        $tenant = Tenant::query()->create([
            'name' => 'Acme Gate Admin',
            'slug' => 'acme-gate-admin',
            'status' => TenantStatus::Active,
        ]);
        $tenantAdmin = $this->tenantUser($tenant, TenantRole::TenantAdmin);
        $line = WhatsAppLine::query()->create([
            'tenant_id' => $tenant->id,
            'name' => 'Linea Admin',
            'phone_number_id' => 'gate-admin-line-id',
            'display_phone_number' => '+57 300 222 3344',
            'status' => 'active',
            'is_enabled' => true,
        ]);

        $tenant->update(['status' => TenantStatus::Paused]);

        $this->actingAs($tenantAdmin)
            ->withHeader('X-Tenant-Id', (string) $tenant->id)
            ->getJson('/api/v1/admin/overview')
            ->assertOk()
            ->assertJsonPath('data.tenant.id', $tenant->id)
            ->assertJsonPath('data.tenant.status', 'paused');

        $this->actingAs($tenantAdmin)
            ->withCsrf()
            ->withHeader('X-Tenant-Id', (string) $tenant->id)
            ->patchJson("/api/v1/admin/whatsapp-lines/{$line->id}", [
                'name' => 'Linea Admin Renombrada',
            ])
            ->assertOk()
            ->assertJsonPath('data.name', 'Linea Admin Renombrada');
    }

    // -----------------------------------------------------------------
    // Reactivation
    // -----------------------------------------------------------------

    public function test_tenant_admin_can_reactivate_their_own_paused_tenant(): void
    {
        $tenant = Tenant::query()->create([
            'name' => 'Acme Reactivate Self',
            'slug' => 'acme-reactivate-self',
            'status' => TenantStatus::Paused,
        ]);
        $tenantAdmin = $this->tenantUser($tenant, TenantRole::TenantAdmin);

        $this->actingAs($tenantAdmin)
            ->withCsrf()
            ->withHeader('X-Tenant-Id', (string) $tenant->id)
            ->patchJson("/api/v1/admin/tenants/{$tenant->id}", [
                'status' => 'active',
            ])
            ->assertOk()
            ->assertJsonPath('data.status', 'active');

        $this->assertSame(TenantStatus::Active, $tenant->fresh()->status);
    }

    public function test_platform_admin_can_reactivate_a_paused_tenant_they_are_not_a_member_of(): void
    {
        $tenant = Tenant::query()->create([
            'name' => 'Acme Reactivate Platform',
            'slug' => 'acme-reactivate-platform',
            'status' => TenantStatus::Paused,
        ]);
        $platformAdmin = User::factory()->platformAdmin()->create();

        $this->actingAs($platformAdmin)
            ->withCsrf()
            ->patchJson("/api/v1/admin/tenants/{$tenant->id}", [
                'status' => 'active',
            ])
            ->assertOk()
            ->assertJsonPath('data.status', 'active');

        $this->assertSame(TenantStatus::Active, $tenant->fresh()->status);
    }

    // -----------------------------------------------------------------
    // Fixtures
    // -----------------------------------------------------------------

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

    /**
     * @return array{Tenant, WhatsAppLine, Conversation}
     */
    protected function conversationFixtures(
        ConversationStatus $status = ConversationStatus::BotActive,
    ): array {
        $tenant = Tenant::query()->create([
            'name' => 'Tenant Gate Ops',
            'slug' => 'tenant-gate-ops-'.uniqid(),
            'status' => TenantStatus::Active,
        ]);

        $line = WhatsAppLine::query()->create([
            'tenant_id' => $tenant->id,
            'name' => 'Linea Principal',
            'phone_number_id' => 'phone-number-gate-'.uniqid(),
            'display_phone_number' => '+57 300 000 0000',
            'status' => 'connected',
            'is_enabled' => true,
        ]);

        $conversation = Conversation::query()->create([
            'tenant_id' => $tenant->id,
            'whatsapp_line_id' => $line->id,
            'contact_phone' => '573001234567',
            'contact_name' => 'Cliente Gate',
            'status' => $status,
            'last_message_at' => now(),
            'last_customer_message_at' => now(),
        ]);

        return [$tenant, $line, $conversation];
    }

    protected function createWhatsappCredential(Tenant $tenant, WhatsAppLine $line): void
    {
        ApiCredential::query()->create([
            'tenant_id' => $tenant->id,
            'whatsapp_line_id' => $line->id,
            'scope_type' => 'whatsapp_line',
            'scope_key' => TenantScopeKey::forWhatsAppLine($line),
            'provider' => 'whatsapp_meta',
            'credential_key' => 'access_token',
            'secret' => 'test-access-token',
        ]);
    }

    /**
     * @return array{Tenant, WhatsAppLine}
     */
    protected function sandboxFixtures(): array
    {
        $tenant = Tenant::query()->create([
            'name' => 'Sandbox Gate Tenant',
            'slug' => 'sandbox-gate-tenant-'.uniqid(),
            'status' => TenantStatus::Active,
        ]);

        $line = WhatsAppLine::query()->create([
            'tenant_id' => $tenant->id,
            'name' => 'Sandbox Gate Line',
            'phone_number_id' => 'sandbox-gate-line-'.uniqid(),
            'display_phone_number' => '+57 300 111 2233',
            'status' => 'active',
            'is_enabled' => true,
        ]);

        AgentConfig::query()->create([
            'tenant_id' => $tenant->id,
            'whatsapp_line_id' => null,
            'scope_type' => 'tenant',
            'scope_key' => TenantScopeKey::forTenant($tenant),
            'name' => 'Sandbox Gate Agent',
            'model_key' => 'balanced',
            'prompt_version' => 'v1',
            'is_active' => true,
            'settings' => [
                'automation_enabled' => true,
            ],
        ]);

        config()->set('services.openai.api_key', 'test-openai-key');

        return [$tenant, $line];
    }
}
