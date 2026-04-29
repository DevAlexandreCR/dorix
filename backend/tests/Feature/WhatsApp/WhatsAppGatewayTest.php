<?php

namespace Tests\Feature\WhatsApp;

use App\Domain\WhatsApp\Contracts\OutboundMessageSender;
use App\Domain\WhatsApp\DTO\OutboundMessageData;
use App\Enums\ConversationStatus;
use App\Enums\MessageDirection;
use App\Jobs\ProcessIncomingMessageJob;
use App\Models\ApiCredential;
use App\Models\AgentEvent;
use App\Models\Conversation;
use App\Models\ConversationMessage;
use App\Models\ConversationState;
use App\Models\Tenant;
use App\Models\WhatsAppLine;
use App\Support\Tenancy\TenantScopeKey;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class WhatsAppGatewayTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Config::set('services.whatsapp.meta.webhook_verify_token', 'test-verify-token');
        Config::set('services.whatsapp.meta.api_version', 'v23.0');
        Config::set('services.whatsapp.meta.base_url', 'https://graph.facebook.com');
    }

    public function test_it_verifies_the_meta_webhook_challenge(): void
    {
        $this->get('/api/webhooks/meta/whatsapp?hub.mode=subscribe&hub.verify_token=test-verify-token&hub.challenge=12345')
            ->assertOk()
            ->assertSeeText('12345');
    }

    public function test_it_persists_an_inbound_message_deduplicates_replays_and_dispatches_the_processing_job(): void
    {
        Queue::fake();

        $tenant = $this->createTenant('acme');
        $line = $this->createLine($tenant, 'phone-number-id');

        $payload = $this->inboundPayload($line->phone_number_id, 'wamid.inbound.1', 'Hola desde WhatsApp');

        $this->postJson('/api/webhooks/meta/whatsapp', $payload)
            ->assertOk()
            ->assertExactJson([
                'status' => 'accepted',
                'received_messages' => 1,
                'deduplicated_messages' => 0,
                'status_updates' => 0,
                'jobs_dispatched' => 1,
            ]);

        $message = ConversationMessage::query()->firstOrFail();
        $conversation = Conversation::query()->firstOrFail();

        $this->assertSame(MessageDirection::Inbound, $message->direction);
        $this->assertSame('text', $message->message_type);
        $this->assertSame('Hola desde WhatsApp', $message->body);
        $this->assertSame('wamid.inbound.1', $message->provider_message_id);
        $this->assertSame('Ana Cliente', $conversation->contact_name);
        $this->assertSame($line->id, $conversation->whatsapp_line_id);
        $this->assertDatabaseHas('conversation_states', [
            'tenant_id' => $tenant->id,
            'conversation_id' => $conversation->id,
        ]);

        $this->postJson('/api/webhooks/meta/whatsapp', $payload)
            ->assertOk()
            ->assertExactJson([
                'status' => 'accepted',
                'received_messages' => 0,
                'deduplicated_messages' => 1,
                'status_updates' => 0,
                'jobs_dispatched' => 0,
            ]);

        $this->assertDatabaseCount('conversation_messages', 1);
        $this->assertDatabaseHas('agent_events', [
            'tenant_id' => $tenant->id,
            'event_type' => 'message_saved',
            'conversation_message_id' => $message->id,
        ]);
        $this->assertDatabaseHas('agent_events', [
            'tenant_id' => $tenant->id,
            'event_type' => 'message_deduplicated',
            'conversation_message_id' => $message->id,
        ]);

        Queue::assertPushed(ProcessIncomingMessageJob::class, 1);
        Queue::assertPushed(ProcessIncomingMessageJob::class, function (ProcessIncomingMessageJob $job) use ($tenant, $conversation, $message): bool {
            return $job->tenantId() === $tenant->id
                && $job->conversationId === $conversation->id
                && $job->messageId === $message->id;
        });
    }

    public function test_it_persists_status_callbacks_on_the_existing_outbound_message(): void
    {
        $tenant = $this->createTenant('globex');
        $line = $this->createLine($tenant, 'globex-phone-number-id');
        $conversation = $this->createConversation($tenant, $line);

        $message = ConversationMessage::query()->create([
            'tenant_id' => $tenant->id,
            'conversation_id' => $conversation->id,
            'direction' => MessageDirection::Outbound,
            'message_type' => 'text',
            'body' => 'Mensaje saliente',
            'provider_message_id' => 'wamid.outbound.1',
            'idempotency_key' => 'outbound-key-1',
            'status' => 'accepted',
            'payload' => [
                'source' => 'outbound_sender',
            ],
        ]);

        $this->postJson('/api/webhooks/meta/whatsapp', $this->statusPayload($line->phone_number_id, 'wamid.outbound.1'))
            ->assertOk()
            ->assertExactJson([
                'status' => 'accepted',
                'received_messages' => 0,
                'deduplicated_messages' => 0,
                'status_updates' => 1,
                'jobs_dispatched' => 0,
            ]);

        $message->refresh();

        $this->assertSame('failed', $message->status);
        $this->assertSame('131047', $message->error_code);
        $this->assertSame('Message failed to send because the customer is unavailable.', $message->error_message);
        $this->assertNotNull($message->failed_at);
        $this->assertSame('failed', data_get($message->payload, 'latest_status.status'));
        $this->assertCount(1, $message->payload['status_history']);

        $this->assertDatabaseHas('agent_events', [
            'tenant_id' => $tenant->id,
            'event_type' => 'whatsapp_status_updated',
            'conversation_message_id' => $message->id,
        ]);
    }

    public function test_it_persists_outbound_messages_before_calling_meta_and_reuses_the_existing_record_for_the_same_idempotency_key(): void
    {
        Http::fake([
            'https://graph.facebook.com/*' => Http::response([
                'messaging_product' => 'whatsapp',
                'contacts' => [
                    ['input' => '573001234567', 'wa_id' => '573001234567'],
                ],
                'messages' => [
                    ['id' => 'wamid.outbound.accepted.1'],
                ],
            ], 200),
        ]);

        $tenant = $this->createTenant('initech');
        $line = $this->createLine($tenant, 'initech-phone-number-id');
        $conversation = $this->createConversation($tenant, $line);

        ApiCredential::query()->create([
            'tenant_id' => $tenant->id,
            'whatsapp_line_id' => $line->id,
            'scope_type' => 'whatsapp_line',
            'scope_key' => TenantScopeKey::forWhatsAppLine($line),
            'provider' => 'whatsapp_meta',
            'credential_key' => 'access_token',
            'secret' => 'meta-access-token',
        ]);

        $sender = app(OutboundMessageSender::class);

        $payload = new OutboundMessageData(
            tenantId: $tenant->id,
            conversationId: $conversation->id,
            whatsAppLineId: $line->id,
            recipientPhone: '573001234567',
            body: 'Hola desde Dorix',
            idempotencyKey: 'idem-1',
            payload: ['source' => 'test'],
        );

        $message = $sender->send($payload);
        $sameMessage = $sender->send($payload);

        $this->assertSame($message->id, $sameMessage->id);
        $this->assertSame('wamid.outbound.accepted.1', $message->provider_message_id);
        $this->assertSame('accepted', $message->status);
        $this->assertNotNull($message->sent_at);
        $this->assertSame('wamid.outbound.accepted.1', data_get($message->payload, 'response.messages.0.id'));
        $this->assertDatabaseHas('conversation_states', [
            'tenant_id' => $tenant->id,
            'conversation_id' => $conversation->id,
        ]);
        $this->assertNotNull($conversation->fresh()->last_message_at);

        $this->assertDatabaseCount('conversation_messages', 1);
        $this->assertDatabaseHas('agent_events', [
            'tenant_id' => $tenant->id,
            'event_type' => 'whatsapp_message_send_requested',
            'conversation_message_id' => $message->id,
        ]);
        $this->assertDatabaseHas('agent_events', [
            'tenant_id' => $tenant->id,
            'event_type' => 'whatsapp_message_sent',
            'conversation_message_id' => $message->id,
        ]);

        Http::assertSentCount(1);
    }

    protected function createTenant(string $slug): Tenant
    {
        return Tenant::query()->create([
            'name' => strtoupper($slug),
            'slug' => $slug,
        ]);
    }

    protected function createLine(Tenant $tenant, string $phoneNumberId): WhatsAppLine
    {
        return WhatsAppLine::query()->create([
            'tenant_id' => $tenant->id,
            'name' => 'Primary Line',
            'phone_number_id' => $phoneNumberId,
            'display_phone_number' => '+573001112233',
            'status' => 'active',
            'is_enabled' => true,
        ]);
    }

    protected function createConversation(Tenant $tenant, WhatsAppLine $line): Conversation
    {
        return Conversation::query()->create([
            'tenant_id' => $tenant->id,
            'whatsapp_line_id' => $line->id,
            'contact_phone' => '573001234567',
            'contact_name' => 'Ana Cliente',
            'status' => ConversationStatus::BotActive,
        ]);
    }

    protected function inboundPayload(string $phoneNumberId, string $providerMessageId, string $body): array
    {
        return [
            'object' => 'whatsapp_business_account',
            'entry' => [
                [
                    'id' => 'entry-1',
                    'changes' => [
                        [
                            'field' => 'messages',
                            'value' => [
                                'messaging_product' => 'whatsapp',
                                'metadata' => [
                                    'display_phone_number' => '+573001112233',
                                    'phone_number_id' => $phoneNumberId,
                                ],
                                'contacts' => [
                                    [
                                        'profile' => [
                                            'name' => 'Ana Cliente',
                                        ],
                                        'wa_id' => '573001234567',
                                    ],
                                ],
                                'messages' => [
                                    [
                                        'from' => '573001234567',
                                        'id' => $providerMessageId,
                                        'timestamp' => '1714305000',
                                        'text' => [
                                            'body' => $body,
                                        ],
                                        'type' => 'text',
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ];
    }

    protected function statusPayload(string $phoneNumberId, string $providerMessageId): array
    {
        return [
            'object' => 'whatsapp_business_account',
            'entry' => [
                [
                    'id' => 'entry-2',
                    'changes' => [
                        [
                            'field' => 'messages',
                            'value' => [
                                'messaging_product' => 'whatsapp',
                                'metadata' => [
                                    'display_phone_number' => '+573001112233',
                                    'phone_number_id' => $phoneNumberId,
                                ],
                                'statuses' => [
                                    [
                                        'id' => $providerMessageId,
                                        'status' => 'failed',
                                        'timestamp' => '1714305100',
                                        'errors' => [
                                            [
                                                'code' => '131047',
                                                'title' => 'Message failed to send because the customer is unavailable.',
                                            ],
                                        ],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ];
    }
}
