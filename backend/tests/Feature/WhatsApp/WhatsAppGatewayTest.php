<?php

namespace Tests\Feature\WhatsApp;

use App\Domain\WhatsApp\Contracts\OutboundMessageSender;
use App\Domain\WhatsApp\DTO\OutboundMessageData;
use App\Enums\ConversationStatus;
use App\Enums\MessageDirection;
use App\Enums\TenantStatus;
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

    public function test_it_returns_a_localized_error_for_invalid_webhook_verification_requests(): void
    {
        $this->getJson('/api/webhooks/meta/whatsapp?hub.mode=subscribe&hub.verify_token=bad-token&hub.challenge=12345', [
            'Accept-Language' => 'en',
        ])
            ->assertForbidden()
            ->assertJsonPath('code', 'webhook_verification_request_invalid')
            ->assertJsonPath('message', 'The webhook verification request is invalid.');
    }

    public function test_it_returns_a_localized_error_for_invalid_webhook_payloads(): void
    {
        $this->postJson('/api/webhooks/meta/whatsapp', [
            'object' => 'whatsapp_business_account',
            'entry' => [
                [
                    'changes' => [
                        [
                            'field' => 'messages',
                            'value' => null,
                        ],
                    ],
                ],
            ],
        ], [
            'Accept-Language' => 'es-CO',
        ])
            ->assertUnprocessable()
            ->assertJsonPath('code', 'invalid_whatsapp_webhook_payload')
            ->assertJsonPath('message', 'Al payload del webhook le falta el valor del cambio de mensajes.');
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

    public function test_it_skips_persisting_an_inbound_message_and_dispatching_a_job_for_a_paused_tenant(): void
    {
        Queue::fake();

        $tenant = $this->createTenant('wayne-paused');
        $tenant->update(['status' => TenantStatus::Paused]);
        $line = $this->createLine($tenant, 'wayne-phone-number-id');

        $payload = $this->inboundPayload($line->phone_number_id, 'wamid.inbound.paused.1', 'Hola desde WhatsApp pausado');

        $this->postJson('/api/webhooks/meta/whatsapp', $payload)
            ->assertOk()
            ->assertExactJson([
                'status' => 'accepted',
                'received_messages' => 0,
                'deduplicated_messages' => 0,
                'status_updates' => 0,
                'jobs_dispatched' => 0,
            ]);

        $this->assertDatabaseCount('conversation_messages', 0);

        Queue::assertNotPushed(ProcessIncomingMessageJob::class);

        $this->assertDatabaseHas('agent_events', [
            'tenant_id' => $tenant->id,
            'event_type' => 'webhook_skipped_tenant_paused',
        ]);
    }

    public function test_it_resumes_normal_processing_after_a_paused_tenant_is_reactivated(): void
    {
        Queue::fake();

        $tenant = $this->createTenant('stark-reactivated');
        $tenant->update(['status' => TenantStatus::Paused]);
        $line = $this->createLine($tenant, 'stark-phone-number-id');

        $pausedPayload = $this->inboundPayload($line->phone_number_id, 'wamid.inbound.paused.2', 'Mensaje mientras está pausado');

        $this->postJson('/api/webhooks/meta/whatsapp', $pausedPayload)->assertOk();

        $this->assertDatabaseCount('conversation_messages', 0);
        Queue::assertNotPushed(ProcessIncomingMessageJob::class);

        $tenant->update(['status' => TenantStatus::Active]);

        $payload = $this->inboundPayload($line->phone_number_id, 'wamid.inbound.reactivated.1', 'Hola de nuevo');

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

        $this->assertSame('wamid.inbound.reactivated.1', $message->provider_message_id);

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

    /**
     * Deliberate carve-out, not an oversight (design.md decision 5): status
     * updates are pure delivery bookkeeping for messages already sent to the
     * customer. Persisting them does not invoke the agent and does not
     * serve a new customer request, so a paused tenant must NOT stop them
     * from being recorded — dropping this "for symmetry" with the inbound
     * skip in task 3.1 would silently freeze the delivery state of messages
     * that went out before the pause, so a `failed` delivery would keep
     * showing as `accepted`/`sent` forever, which is the most harmful case
     * since the operator would believe the message was delivered.
     *
     * If a future refactor extends the paused-tenant skip from the inbound
     * loop into `persistStatusUpdate`, this test must fail loudly.
     */
    public function test_it_still_persists_a_failed_status_update_while_the_tenant_is_paused(): void
    {
        $tenant = $this->createTenant('umbrella');
        $tenant->update(['status' => TenantStatus::Paused]);

        $line = $this->createLine($tenant, 'umbrella-phone-number-id');
        $conversation = $this->createConversation($tenant, $line);

        $message = ConversationMessage::query()->create([
            'tenant_id' => $tenant->id,
            'conversation_id' => $conversation->id,
            'direction' => MessageDirection::Outbound,
            'message_type' => 'text',
            'body' => 'Mensaje saliente antes de pausar',
            'provider_message_id' => 'wamid.outbound.paused.1',
            'idempotency_key' => 'outbound-key-paused-1',
            'status' => 'accepted',
            'payload' => [
                'source' => 'outbound_sender',
            ],
        ]);

        $this->postJson('/api/webhooks/meta/whatsapp', $this->statusPayload($line->phone_number_id, 'wamid.outbound.paused.1'))
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

        $this->assertDatabaseHas('agent_events', [
            'tenant_id' => $tenant->id,
            'event_type' => 'whatsapp_status_updated',
            'conversation_message_id' => $message->id,
        ]);
    }

    /**
     * Same carve-out as above, for a non-failure status: a `read` receipt on
     * an already-sent message must also keep updating while the tenant is
     * paused, since it is bookkeeping too, not a new customer interaction.
     */
    public function test_it_still_persists_a_read_status_update_while_the_tenant_is_paused(): void
    {
        $tenant = $this->createTenant('initrode');
        $tenant->update(['status' => TenantStatus::Paused]);

        $line = $this->createLine($tenant, 'initrode-phone-number-id');
        $conversation = $this->createConversation($tenant, $line);

        $message = ConversationMessage::query()->create([
            'tenant_id' => $tenant->id,
            'conversation_id' => $conversation->id,
            'direction' => MessageDirection::Outbound,
            'message_type' => 'text',
            'body' => 'Mensaje saliente antes de pausar',
            'provider_message_id' => 'wamid.outbound.paused.2',
            'idempotency_key' => 'outbound-key-paused-2',
            'status' => 'sent',
            'sent_at' => now(),
            'payload' => [
                'source' => 'outbound_sender',
            ],
        ]);

        $this->postJson(
            '/api/webhooks/meta/whatsapp',
            $this->statusPayload($line->phone_number_id, 'wamid.outbound.paused.2', 'read', [])
        )
            ->assertOk()
            ->assertExactJson([
                'status' => 'accepted',
                'received_messages' => 0,
                'deduplicated_messages' => 0,
                'status_updates' => 1,
                'jobs_dispatched' => 0,
            ]);

        $message->refresh();

        $this->assertSame('read', $message->status);
        $this->assertSame('read', data_get($message->payload, 'latest_status.status'));
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

    /**
     * @param  array<int, array<string, string>>|null  $errors  Pass `[]` explicitly to omit
     *                                                          the `errors` key (e.g. for a `read`/`delivered` status).
     *                                                          Defaults to the historical `failed` error so existing
     *                                                          callers are unaffected.
     */
    protected function statusPayload(
        string $phoneNumberId,
        string $providerMessageId,
        string $status = 'failed',
        ?array $errors = [
            [
                'code' => '131047',
                'title' => 'Message failed to send because the customer is unavailable.',
            ],
        ],
    ): array {
        $statusEntry = [
            'id' => $providerMessageId,
            'status' => $status,
            'timestamp' => '1714305100',
        ];

        if ($errors !== []) {
            $statusEntry['errors'] = $errors;
        }

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
                                'statuses' => [$statusEntry],
                            ],
                        ],
                    ],
                ],
            ],
        ];
    }
}
