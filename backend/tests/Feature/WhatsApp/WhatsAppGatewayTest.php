<?php

namespace Tests\Feature\WhatsApp;

use App\Domain\WhatsApp\Contracts\OutboundMessageSender;
use App\Domain\WhatsApp\DTO\OutboundMessageData;
use App\Enums\ConversationStatus;
use App\Enums\MessageDirection;
use App\Enums\TenantStatus;
use App\Enums\WhatsAppConnectionMode;
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
use Illuminate\Support\Facades\Log;
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
        Config::set('services.whatsapp.meta.app_secret', 'test-app-secret');
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
        $this->postSignedJson('/api/webhooks/meta/whatsapp', [
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

        $this->postSignedJson('/api/webhooks/meta/whatsapp', $payload)
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

        $this->postSignedJson('/api/webhooks/meta/whatsapp', $payload)
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

        $this->postSignedJson('/api/webhooks/meta/whatsapp', $payload)
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

        $this->postSignedJson('/api/webhooks/meta/whatsapp', $pausedPayload)->assertOk();

        $this->assertDatabaseCount('conversation_messages', 0);
        Queue::assertNotPushed(ProcessIncomingMessageJob::class);

        $tenant->update(['status' => TenantStatus::Active]);

        $payload = $this->inboundPayload($line->phone_number_id, 'wamid.inbound.reactivated.1', 'Hola de nuevo');

        $this->postSignedJson('/api/webhooks/meta/whatsapp', $payload)
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

        $this->postSignedJson('/api/webhooks/meta/whatsapp', $this->statusPayload($line->phone_number_id, 'wamid.outbound.1'))
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

        $this->postSignedJson('/api/webhooks/meta/whatsapp', $this->statusPayload($line->phone_number_id, 'wamid.outbound.paused.1'))
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

        $this->postSignedJson(
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

    /**
     * design.md decision D6 / spec scenario "Echo de mensaje enviado desde
     * la app de WhatsApp Business": a `smb_message_echoes` entry must be
     * persisted as an outbound message with `payload.source = business_app`
     * without ever dispatching `ProcessIncomingMessageJob`.
     */
    public function test_it_persists_a_message_echo_as_outbound_without_dispatching_a_job(): void
    {
        Queue::fake();

        $tenant = $this->createTenant('coexistence-echo');
        $line = $this->createLine($tenant, 'echo-phone-number-id', WhatsAppConnectionMode::Coexistence->value);

        $payload = $this->echoPayload($line->phone_number_id, 'wamid.echo.1', '573009876543', 'Ya te contactamos desde la app');

        $this->postSignedJson('/api/webhooks/meta/whatsapp', $payload)
            ->assertOk()
            ->assertExactJson([
                'status' => 'accepted',
                'received_messages' => 0,
                'deduplicated_messages' => 0,
                'status_updates' => 0,
                'jobs_dispatched' => 0,
            ]);

        $message = ConversationMessage::query()->firstOrFail();
        $conversation = Conversation::query()->firstOrFail();

        $this->assertSame(MessageDirection::Outbound, $message->direction);
        $this->assertSame('text', $message->message_type);
        $this->assertSame('Ya te contactamos desde la app', $message->body);
        $this->assertSame('wamid.echo.1', $message->provider_message_id);
        $this->assertSame('business_app', data_get($message->payload, 'source'));
        $this->assertSame($line->id, $conversation->whatsapp_line_id);
        $this->assertSame('573009876543', $conversation->contact_phone);
        $this->assertSame(ConversationStatus::BotActive, $conversation->status);

        Queue::assertNotPushed(ProcessIncomingMessageJob::class);

        $this->assertDatabaseHas('agent_events', [
            'tenant_id' => $tenant->id,
            'event_type' => 'message_saved',
            'conversation_message_id' => $message->id,
        ]);

        // Replaying the same echo (Meta retry) must not duplicate the message
        // nor change the conversation status.
        $this->postSignedJson('/api/webhooks/meta/whatsapp', $payload)
            ->assertOk()
            ->assertExactJson([
                'status' => 'accepted',
                'received_messages' => 0,
                'deduplicated_messages' => 0,
                'status_updates' => 0,
                'jobs_dispatched' => 0,
            ]);

        $this->assertDatabaseCount('conversation_messages', 1);
        $this->assertSame(ConversationStatus::BotActive, $conversation->fresh()->status);
        $this->assertDatabaseHas('agent_events', [
            'tenant_id' => $tenant->id,
            'event_type' => 'message_deduplicated',
            'conversation_message_id' => $message->id,
        ]);
    }

    /**
     * design.md decision D6 / spec scenario "Echo de mensaje enviado desde
     * la app de WhatsApp Business": an echo on an existing conversation must
     * not disturb its current status (e.g. WAITING_CUSTOMER while the agent
     * awaits the customer's reply).
     */
    public function test_it_persists_a_message_echo_without_altering_an_existing_conversation_status(): void
    {
        Queue::fake();

        $tenant = $this->createTenant('coexistence-echo-existing');
        $line = $this->createLine($tenant, 'echo-existing-phone-number-id', WhatsAppConnectionMode::Coexistence->value);

        $conversation = Conversation::query()->create([
            'tenant_id' => $tenant->id,
            'whatsapp_line_id' => $line->id,
            'contact_phone' => '573001112222',
            'contact_name' => 'Cliente Existente',
            'status' => ConversationStatus::WaitingCustomer,
        ]);

        $payload = $this->echoPayload($line->phone_number_id, 'wamid.echo.2', '573001112222', 'Seguimos pendientes de tu respuesta');

        $this->postSignedJson('/api/webhooks/meta/whatsapp', $payload)->assertOk();

        $message = ConversationMessage::query()->firstOrFail();

        $this->assertSame($conversation->id, $message->conversation_id);
        $this->assertSame(ConversationStatus::WaitingCustomer, $conversation->fresh()->status);

        Queue::assertNotPushed(ProcessIncomingMessageJob::class);
    }

    /**
     * design.md decision D6 / spec scenario "Fields de historial y
     * sincronización de contactos": `history` and `smb_app_state_sync` must
     * be accepted with a 200 and discarded without persisting anything.
     */
    public function test_it_ignores_history_and_app_state_sync_fields_with_a_200(): void
    {
        Queue::fake();

        $tenant = $this->createTenant('coexistence-tolerated-fields');
        $line = $this->createLine($tenant, 'tolerated-phone-number-id', WhatsAppConnectionMode::Coexistence->value);

        foreach (['history', 'smb_app_state_sync', 'some_future_field'] as $field) {
            $this->postSignedJson('/api/webhooks/meta/whatsapp', [
                'object' => 'whatsapp_business_account',
                'entry' => [
                    [
                        'id' => 'entry-tolerated',
                        'changes' => [
                            [
                                'field' => $field,
                                'value' => [
                                    'messaging_product' => 'whatsapp',
                                    'metadata' => [
                                        'phone_number_id' => $line->phone_number_id,
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ])
                ->assertOk()
                ->assertExactJson([
                    'status' => 'accepted',
                    'received_messages' => 0,
                    'deduplicated_messages' => 0,
                    'status_updates' => 0,
                    'jobs_dispatched' => 0,
                ]);
        }

        $this->assertDatabaseCount('conversation_messages', 0);
        $this->assertDatabaseCount('conversations', 0);
        Queue::assertNotPushed(ProcessIncomingMessageJob::class);
    }

    /**
     * design.md decision D6 / spec scenario "Mensaje entrante en
     * coexistencia": the `messages` field pipeline must be unchanged for a
     * line in coexistence mode.
     */
    public function test_it_processes_the_messages_field_unchanged_for_a_coexistence_line(): void
    {
        Queue::fake();

        $tenant = $this->createTenant('coexistence-messages-intact');
        $line = $this->createLine($tenant, 'coexistence-messages-phone-number-id', WhatsAppConnectionMode::Coexistence->value);

        $payload = $this->inboundPayload($line->phone_number_id, 'wamid.coexistence.inbound.1', 'Hola en coexistencia');

        $this->postSignedJson('/api/webhooks/meta/whatsapp', $payload)
            ->assertOk()
            ->assertExactJson([
                'status' => 'accepted',
                'received_messages' => 1,
                'deduplicated_messages' => 0,
                'status_updates' => 0,
                'jobs_dispatched' => 1,
            ]);

        $message = ConversationMessage::query()->firstOrFail();

        $this->assertSame(MessageDirection::Inbound, $message->direction);
        $this->assertSame('wamid.coexistence.inbound.1', $message->provider_message_id);

        Queue::assertPushed(ProcessIncomingMessageJob::class, 1);
    }

    public function test_it_processes_a_webhook_request_with_a_valid_signature(): void
    {
        Queue::fake();

        $tenant = $this->createTenant('signature-valid');
        $line = $this->createLine($tenant, 'signature-valid-phone-number-id');

        $payload = $this->inboundPayload($line->phone_number_id, 'wamid.signature.valid.1', 'Hola con firma válida');

        $this->postSignedJson('/api/webhooks/meta/whatsapp', $payload)
            ->assertOk()
            ->assertExactJson([
                'status' => 'accepted',
                'received_messages' => 1,
                'deduplicated_messages' => 0,
                'status_updates' => 0,
                'jobs_dispatched' => 1,
            ]);
    }

    public function test_it_rejects_a_webhook_request_with_an_invalid_signature(): void
    {
        Queue::fake();

        $tenant = $this->createTenant('signature-invalid');
        $line = $this->createLine($tenant, 'signature-invalid-phone-number-id');

        $payload = $this->inboundPayload($line->phone_number_id, 'wamid.signature.invalid.1', 'Hola con firma inválida');

        $this->postJson('/api/webhooks/meta/whatsapp', $payload, [
            'X-Hub-Signature-256' => 'sha256=' . str_repeat('0', 64),
        ])
            ->assertForbidden()
            ->assertJsonPath('code', 'webhook_signature_invalid');

        $this->assertDatabaseCount('conversation_messages', 0);
        Queue::assertNotPushed(ProcessIncomingMessageJob::class);
    }

    public function test_it_rejects_a_webhook_request_without_a_signature_header(): void
    {
        Queue::fake();

        $tenant = $this->createTenant('signature-missing');
        $line = $this->createLine($tenant, 'signature-missing-phone-number-id');

        $payload = $this->inboundPayload($line->phone_number_id, 'wamid.signature.missing.1', 'Hola sin firma');

        $this->postJson('/api/webhooks/meta/whatsapp', $payload)
            ->assertForbidden()
            ->assertJsonPath('code', 'webhook_signature_invalid');

        $this->assertDatabaseCount('conversation_messages', 0);
        Queue::assertNotPushed(ProcessIncomingMessageJob::class);
    }

    public function test_it_rejects_a_webhook_request_when_the_app_secret_is_not_configured(): void
    {
        Config::set('services.whatsapp.meta.app_secret', null);

        Log::spy();

        $tenant = $this->createTenant('secret-missing');
        $line = $this->createLine($tenant, 'secret-missing-phone-number-id');

        $payload = $this->inboundPayload($line->phone_number_id, 'wamid.secret.missing.1', 'Hola sin secreto configurado');

        $this->postJson('/api/webhooks/meta/whatsapp', $payload, [
            'X-Hub-Signature-256' => 'sha256=' . str_repeat('0', 64),
        ])
            ->assertForbidden()
            ->assertJsonPath('code', 'webhook_signature_verification_unavailable');

        $this->assertDatabaseCount('conversation_messages', 0);

        Log::shouldHaveReceived('warning')
            ->once()
            ->with('Rejected WhatsApp webhook because META_APP_SECRET is not configured.');
    }

    protected function postSignedJson(string $uri, array $payload, array $headers = [])
    {
        $appSecret = config('services.whatsapp.meta.app_secret');
        $body = json_encode($payload);
        $signature = 'sha256=' . hash_hmac('sha256', $body, $appSecret);

        return $this->postJson($uri, $payload, array_merge($headers, [
            'X-Hub-Signature-256' => $signature,
        ]));
    }

    protected function createTenant(string $slug): Tenant
    {
        return Tenant::query()->create([
            'name' => strtoupper($slug),
            'slug' => $slug,
        ]);
    }

    protected function createLine(Tenant $tenant, string $phoneNumberId, ?string $connectionMode = null): WhatsAppLine
    {
        return WhatsAppLine::query()->create([
            'tenant_id' => $tenant->id,
            'name' => 'Primary Line',
            'phone_number_id' => $phoneNumberId,
            'display_phone_number' => '+573001112233',
            'status' => 'active',
            'is_enabled' => true,
            ...($connectionMode !== null ? ['connection_mode' => $connectionMode] : []),
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
     * `smb_message_echoes` shape per Meta's WhatsApp coexistence docs: the
     * echo mirrors `messages` but under `value.message_echoes[]`, with `to`
     * carrying the customer's wa_id (the business already sent the message
     * from the WhatsApp Business app, so there is no inbound `from`
     * customer here).
     */
    protected function echoPayload(string $phoneNumberId, string $providerMessageId, string $customerWaId, string $body): array
    {
        return [
            'object' => 'whatsapp_business_account',
            'entry' => [
                [
                    'id' => 'entry-echo',
                    'changes' => [
                        [
                            'field' => 'smb_message_echoes',
                            'value' => [
                                'messaging_product' => 'whatsapp',
                                'metadata' => [
                                    'display_phone_number' => '+573001112233',
                                    'phone_number_id' => $phoneNumberId,
                                ],
                                'contacts' => [
                                    [
                                        'profile' => [
                                            'name' => 'Cliente Coexistencia',
                                        ],
                                        'wa_id' => $customerWaId,
                                    ],
                                ],
                                'message_echoes' => [
                                    [
                                        'from' => $phoneNumberId,
                                        'to' => $customerWaId,
                                        'id' => $providerMessageId,
                                        'timestamp' => '1714305200',
                                        'type' => 'text',
                                        'text' => [
                                            'body' => $body,
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
