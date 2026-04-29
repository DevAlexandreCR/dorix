<?php

namespace Tests\Feature\Operations;

use App\Domain\WhatsApp\Contracts\OutboundMessageSender;
use App\Domain\WhatsApp\DTO\OutboundMessageData;
use App\Domain\WhatsApp\Exceptions\WhatsAppSendFailedException;
use App\Enums\ConversationStatus;
use App\Enums\TenantRole;
use App\Models\ApiCredential;
use App\Models\Conversation;
use App\Models\ConversationMessage;
use App\Models\Tenant;
use App\Models\TenantUser;
use App\Models\User;
use App\Models\WhatsAppLine;
use App\Support\Tenancy\TenantScopeKey;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class InternalConsoleTest extends TestCase
{
    use RefreshDatabase;

    public function test_auth_session_login_and_logout_flow_returns_tenant_memberships(): void
    {
        $tenant = Tenant::query()->create([
            'name' => 'Acme Ops',
            'slug' => 'acme-ops',
        ]);

        $user = User::factory()->create([
            'email' => 'operator@example.com',
            'password' => 'secret-pass',
        ]);

        TenantUser::query()->create([
            'tenant_id' => $tenant->id,
            'user_id' => $user->id,
            'role' => TenantRole::Operator,
        ]);

        $this->getJson('/api/v1/auth/session')
            ->assertOk()
            ->assertJson([
                'authenticated' => false,
                'user' => null,
                'memberships' => [],
            ]);

        $this->withCsrf()->postJson('/api/v1/auth/login', [
            'email' => 'operator@example.com',
            'password' => 'secret-pass',
        ])
            ->assertOk()
            ->assertJsonPath('authenticated', true)
            ->assertJsonPath('user.email', 'operator@example.com')
            ->assertJsonPath('memberships.0.tenant_id', $tenant->id)
            ->assertJsonPath('memberships.0.role', TenantRole::Operator->value);

        $this->withCsrf()->postJson('/api/v1/auth/logout')
            ->assertOk()
            ->assertJson([
                'authenticated' => false,
                'user' => null,
                'memberships' => [],
            ]);
    }

    public function test_operator_can_take_a_conversation_reply_manually_and_resume_the_bot(): void
    {
        [$tenant, $line, $conversation] = $this->conversationFixtures();
        $operator = $this->tenantUser($tenant, TenantRole::Operator);
        $this->createWhatsappCredential($tenant, $line);

        Http::fake([
            'https://graph.facebook.com/*' => Http::response([
                'messages' => [
                    ['id' => 'wamid.manual.100'],
                ],
            ], 200),
        ]);

        $this->actingAs($operator)
            ->withCsrf()
            ->withHeader('X-Tenant-Id', (string) $tenant->id)
            ->postJson("/api/v1/conversations/{$conversation->id}/handoff", [
                'reason' => 'Cliente pidió seguimiento humano.',
            ])
            ->assertOk()
            ->assertJsonPath('data.status', ConversationStatus::HumanHandoff->value)
            ->assertJsonPath('data.assigned_to_user.id', $operator->id)
            ->assertJsonPath('data.latest_handoff.status', 'accepted');

        $this->actingAs($operator)
            ->withCsrf()
            ->withHeader('X-Tenant-Id', (string) $tenant->id)
            ->postJson("/api/v1/conversations/{$conversation->id}/manual-reply", [
                'body' => 'Te ayudo manualmente con eso.',
            ])
            ->assertCreated()
            ->assertJsonPath('data.body', 'Te ayudo manualmente con eso.')
            ->assertJsonPath('data.direction', 'outbound')
            ->assertJsonPath('data.source', 'manual_console');

        $this->actingAs($operator)
            ->withCsrf()
            ->withHeader('X-Tenant-Id', (string) $tenant->id)
            ->postJson("/api/v1/conversations/{$conversation->id}/resume", [
                'target_status' => ConversationStatus::WaitingCustomer->value,
            ])
            ->assertOk()
            ->assertJsonPath('data.status', ConversationStatus::WaitingCustomer->value)
            ->assertJsonPath('data.assigned_to_user', null)
            ->assertJsonPath('data.latest_handoff.status', 'resolved');

        $conversation->refresh();
        $outboundMessage = ConversationMessage::query()
            ->where('conversation_id', $conversation->id)
            ->where('direction', 'outbound')
            ->firstOrFail();

        $this->assertSame(ConversationStatus::WaitingCustomer, $conversation->status);
        $this->assertNull($conversation->assigned_to_user_id);
        $this->assertSame('Te ayudo manualmente con eso.', $outboundMessage->body);

        $this->assertDatabaseHas('handoff_requests', [
            'tenant_id' => $tenant->id,
            'conversation_id' => $conversation->id,
            'status' => 'resolved',
            'assigned_to_user_id' => $operator->id,
        ]);
        $this->assertDatabaseHas('audit_events', [
            'tenant_id' => $tenant->id,
            'actor_user_id' => $operator->id,
            'event_type' => 'handoff_accepted',
        ]);
        $this->assertDatabaseHas('audit_events', [
            'tenant_id' => $tenant->id,
            'actor_user_id' => $operator->id,
            'event_type' => 'manual_reply_sent',
        ]);
        $this->assertDatabaseHas('audit_events', [
            'tenant_id' => $tenant->id,
            'actor_user_id' => $operator->id,
            'event_type' => 'bot_resumed',
        ]);
        $this->assertDatabaseHas('agent_events', [
            'tenant_id' => $tenant->id,
            'conversation_id' => $conversation->id,
            'event_type' => 'handoff_triggered',
        ]);
        $this->assertDatabaseHas('agent_events', [
            'tenant_id' => $tenant->id,
            'conversation_id' => $conversation->id,
            'event_type' => 'handoff_accepted',
        ]);
        $this->assertDatabaseHas('agent_events', [
            'tenant_id' => $tenant->id,
            'conversation_id' => $conversation->id,
            'event_type' => 'bot_resumed',
        ]);
    }

    public function test_viewer_can_open_the_inbox_but_cannot_take_control_or_reply(): void
    {
        [$tenant, , $conversation] = $this->conversationFixtures();
        $viewer = $this->tenantUser($tenant, TenantRole::Viewer);

        $this->actingAs($viewer)
            ->withHeader('X-Tenant-Id', (string) $tenant->id)
            ->getJson('/api/v1/conversations')
            ->assertOk()
            ->assertJsonPath('data.0.id', $conversation->id);

        $this->actingAs($viewer)
            ->withCsrf()
            ->withHeader('X-Tenant-Id', (string) $tenant->id)
            ->postJson("/api/v1/conversations/{$conversation->id}/handoff", [
                'reason' => 'Intento no autorizado.',
            ])
            ->assertForbidden();

        $this->actingAs($viewer)
            ->withCsrf()
            ->withHeader('X-Tenant-Id', (string) $tenant->id)
            ->postJson("/api/v1/conversations/{$conversation->id}/manual-reply", [
                'body' => 'No debería pasar.',
            ])
            ->assertForbidden();
    }

    public function test_automated_runtime_outbound_is_blocked_during_human_handoff_but_manual_console_is_allowed(): void
    {
        [$tenant, $line, $conversation] = $this->conversationFixtures(status: ConversationStatus::HumanHandoff);
        $this->createWhatsappCredential($tenant, $line);

        Http::fake([
            'https://graph.facebook.com/*' => Http::response([
                'messages' => [
                    ['id' => 'wamid.manual.200'],
                ],
            ], 200),
        ]);

        $sender = app(OutboundMessageSender::class);

        $this->expectException(WhatsAppSendFailedException::class);
        $this->expectExceptionMessage('Automated outbound messages are blocked while the conversation is in HUMAN_HANDOFF.');

        try {
            $sender->send(new OutboundMessageData(
                tenantId: $tenant->id,
                conversationId: $conversation->id,
                whatsAppLineId: $line->id,
                recipientPhone: $conversation->contact_phone,
                body: 'Auto reply',
                idempotencyKey: 'agent-runtime-blocked',
                payload: [
                    'source' => 'agent_runtime',
                ],
            ));
        } finally {
            $this->assertDatabaseCount('conversation_messages', 0);
        }
    }

    public function test_manual_console_outbound_can_still_send_during_human_handoff(): void
    {
        [$tenant, $line, $conversation] = $this->conversationFixtures(status: ConversationStatus::HumanHandoff);
        $this->createWhatsappCredential($tenant, $line);

        Http::fake([
            'https://graph.facebook.com/*' => Http::response([
                'messages' => [
                    ['id' => 'wamid.manual.201'],
                ],
            ], 200),
        ]);

        $message = app(OutboundMessageSender::class)->send(new OutboundMessageData(
            tenantId: $tenant->id,
            conversationId: $conversation->id,
            whatsAppLineId: $line->id,
            recipientPhone: $conversation->contact_phone,
            body: 'Manual reply',
            idempotencyKey: 'manual-console-send',
            payload: [
                'source' => 'manual_console',
            ],
        ));

        $this->assertSame('Manual reply', $message->body);
        $this->assertSame('accepted', $message->status);
        $this->assertDatabaseHas('conversation_messages', [
            'conversation_id' => $conversation->id,
            'idempotency_key' => 'manual-console-send',
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

    /**
     * @return array{Tenant, WhatsAppLine, Conversation}
     */
    protected function conversationFixtures(
        ConversationStatus $status = ConversationStatus::BotActive,
    ): array {
        $tenant = Tenant::query()->create([
            'name' => 'Tenant Ops',
            'slug' => 'tenant-ops',
        ]);

        $line = WhatsAppLine::query()->create([
            'tenant_id' => $tenant->id,
            'name' => 'Linea Principal',
            'phone_number_id' => 'phone-number-ops-1',
            'display_phone_number' => '+57 300 000 0000',
            'status' => 'connected',
            'is_enabled' => true,
        ]);

        $conversation = Conversation::query()->create([
            'tenant_id' => $tenant->id,
            'whatsapp_line_id' => $line->id,
            'contact_phone' => '573001234567',
            'contact_name' => 'Cliente Ops',
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

    protected function withCsrf(): static
    {
        return $this
            ->withSession(['_token' => 'test-csrf-token'])
            ->withHeader('X-CSRF-TOKEN', 'test-csrf-token');
    }
}
