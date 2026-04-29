<?php

namespace Tests\Feature\Conversations;

use App\Domain\Agent\AgentContextLoader;
use App\Domain\Agent\AgentDecisionApplier;
use App\Domain\Agent\Contracts\AgentRuntimeInterface;
use App\Domain\Conversations\Contracts\ConversationLockManager;
use App\Domain\Conversations\Contracts\ConversationResolver;
use App\Domain\Conversations\Contracts\ConversationStateRepository;
use App\Domain\Conversations\Contracts\ConversationStatusTransitioner;
use App\Domain\Conversations\Exceptions\InvalidConversationStatusTransitionException;
use App\Domain\WhatsApp\DTO\InboundMessageData;
use App\Domain\WhatsApp\DTO\ResolvedWhatsAppLine;
use App\Enums\ConversationStatus;
use App\Enums\MessageDirection;
use App\Jobs\ProcessIncomingMessageJob;
use App\Models\Conversation;
use App\Models\ConversationMessage;
use App\Models\ConversationState;
use App\Models\Tenant;
use App\Models\User;
use App\Models\WhatsAppLine;
use App\Support\AgentEvents\AgentEventRecorder;
use App\Support\Tenancy\TenantContextManager;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ConversationStateAndConcurrencyTest extends TestCase
{
    use RefreshDatabase;

    public function test_inbound_resolution_reuses_waiting_customer_conversation_and_creates_snapshot(): void
    {
        $tenant = $this->createTenant('acme');
        $line = $this->createLine($tenant, 'acme-phone-number-id');
        $conversation = $this->createConversation($tenant, $line, status: ConversationStatus::WaitingCustomer);

        $resolved = app(ConversationResolver::class)->resolveForInbound(
            new ResolvedWhatsAppLine($line, $tenant),
            $this->inboundMessage('wamid.inbound.10', '573001234567', 'Ana Cliente'),
        );

        $this->assertSame($conversation->id, $resolved->id);
        $this->assertSame(ConversationStatus::BotActive, $resolved->fresh()->status);
        $this->assertDatabaseHas('conversation_states', [
            'tenant_id' => $tenant->id,
            'conversation_id' => $conversation->id,
        ]);
    }

    public function test_inbound_resolution_creates_new_conversation_when_latest_one_is_closed(): void
    {
        $tenant = $this->createTenant('globex');
        $line = $this->createLine($tenant, 'globex-phone-number-id');
        $closedConversation = $this->createConversation($tenant, $line, status: ConversationStatus::Closed);

        $resolved = app(ConversationResolver::class)->resolveForInbound(
            new ResolvedWhatsAppLine($line, $tenant),
            $this->inboundMessage('wamid.inbound.11', '573001234567', 'Ana Cliente'),
        );

        $this->assertNotSame($closedConversation->id, $resolved->id);
        $this->assertSame(ConversationStatus::BotActive, $resolved->status);
        $this->assertDatabaseHas('conversation_states', [
            'tenant_id' => $tenant->id,
            'conversation_id' => $resolved->id,
        ]);
    }

    public function test_conversation_state_repository_expires_only_memory_summary(): void
    {
        $tenant = $this->createTenant('initech');
        $line = $this->createLine($tenant, 'initech-phone-number-id');
        $conversation = $this->createConversation($tenant, $line);

        $state = ConversationState::query()->create([
            'tenant_id' => $tenant->id,
            'conversation_id' => $conversation->id,
            'current_intent' => 'knowledge_lookup',
            'collected_data' => ['customer_name' => 'Ana'],
            'memory_summary' => 'Cliente preguntó por inventario.',
            'expires_at' => now()->subMinute(),
        ]);

        $expiredState = app(ConversationStateRepository::class)->expireOperationalMemory($state);

        $this->assertNull($expiredState->memory_summary);
        $this->assertSame(['customer_name' => 'Ana'], $expiredState->collected_data);
        $this->assertNull($expiredState->expires_at);
        $this->assertSame('knowledge_lookup', $expiredState->current_intent);
    }

    public function test_status_transitions_clear_assignment_when_bot_resumes_and_reject_invalid_paths(): void
    {
        $tenant = $this->createTenant('umbrella');
        $line = $this->createLine($tenant, 'umbrella-phone-number-id');
        $user = User::query()->create([
            'name' => 'Operator One',
            'email' => 'operator@example.com',
            'password' => bcrypt('secret'),
        ]);

        $conversation = $this->createConversation(
            $tenant,
            $line,
            status: ConversationStatus::HumanHandoff,
            assignedToUserId: $user->id,
        );

        $resumedConversation = app(ConversationStatusTransitioner::class)
            ->transition($conversation, ConversationStatus::BotActive);

        $this->assertSame(ConversationStatus::BotActive, $resumedConversation->status);
        $this->assertNull($resumedConversation->assigned_to_user_id);

        $closedConversation = app(ConversationStatusTransitioner::class)
            ->transition($resumedConversation, ConversationStatus::Closed);

        $this->expectException(InvalidConversationStatusTransitionException::class);

        app(ConversationStatusTransitioner::class)
            ->transition($closedConversation, ConversationStatus::WaitingCustomer);
    }

    public function test_lock_manager_serializes_work_per_conversation(): void
    {
        $tenant = $this->createTenant('soylent');
        $line = $this->createLine($tenant, 'soylent-phone-number-id');
        $conversation = $this->createConversation($tenant, $line);
        $lockManager = app(ConversationLockManager::class);

        $innerExecution = null;
        $outerExecution = $lockManager->runExclusive($tenant->id, $conversation->id, function () use ($lockManager, $tenant, $conversation, &$innerExecution): void {
            $innerExecution = $lockManager->runExclusive($tenant->id, $conversation->id, function (): void {
            });
        });

        $this->assertTrue($outerExecution);
        $this->assertFalse($innerExecution);
    }

    public function test_processing_job_expires_operational_memory_before_runtime_placeholder(): void
    {
        $tenant = $this->createTenant('wayne');
        $line = $this->createLine($tenant, 'wayne-phone-number-id');
        $conversation = $this->createConversation($tenant, $line);
        $message = $this->createInboundMessage($tenant, $conversation, 'wamid.inbound.20');

        ConversationState::query()->create([
            'tenant_id' => $tenant->id,
            'conversation_id' => $conversation->id,
            'collected_data' => ['topic' => 'pricing'],
            'memory_summary' => 'Cliente preguntó por precios.',
            'expires_at' => now()->subMinute(),
        ]);

        $job = new ProcessIncomingMessageJob($tenant->id, $conversation->id, $message->id);
        $job->handle(
            app(TenantContextManager::class),
            app(AgentEventRecorder::class),
            app(ConversationLockManager::class),
            app(ConversationStateRepository::class),
            app(AgentContextLoader::class),
            app(AgentRuntimeInterface::class),
            app(AgentDecisionApplier::class),
        );

        $state = ConversationState::query()->where('conversation_id', $conversation->id)->firstOrFail();

        $this->assertNull($state->memory_summary);
        $this->assertSame(['topic' => 'pricing'], $state->collected_data);
        $this->assertDatabaseHas('agent_events', [
            'tenant_id' => $tenant->id,
            'event_type' => 'processing_job_started',
            'conversation_message_id' => $message->id,
        ]);
        $this->assertDatabaseHas('agent_events', [
            'tenant_id' => $tenant->id,
            'event_type' => 'processing_job_completed',
            'conversation_message_id' => $message->id,
        ]);
    }

    public function test_processing_job_skips_conflicting_execution_when_lock_is_busy(): void
    {
        $tenant = $this->createTenant('stark');
        $line = $this->createLine($tenant, 'stark-phone-number-id');
        $conversation = $this->createConversation($tenant, $line);
        $message = $this->createInboundMessage($tenant, $conversation, 'wamid.inbound.21');
        $job = new ProcessIncomingMessageJob($tenant->id, $conversation->id, $message->id);
        $lockManager = app(ConversationLockManager::class);

        $lockManager->runExclusive($tenant->id, $conversation->id, function () use ($job): void {
            $job->handle(
                app(TenantContextManager::class),
                app(AgentEventRecorder::class),
                app(ConversationLockManager::class),
                app(ConversationStateRepository::class),
                app(AgentContextLoader::class),
                app(AgentRuntimeInterface::class),
                app(AgentDecisionApplier::class),
            );
        });

        $this->assertDatabaseHas('agent_events', [
            'tenant_id' => $tenant->id,
            'event_type' => 'processing_job_released_due_to_lock',
            'conversation_message_id' => $message->id,
        ]);
        $this->assertDatabaseMissing('agent_events', [
            'tenant_id' => $tenant->id,
            'event_type' => 'processing_job_started',
            'conversation_message_id' => $message->id,
        ]);
        $this->assertDatabaseMissing('agent_events', [
            'tenant_id' => $tenant->id,
            'event_type' => 'processing_job_completed',
            'conversation_message_id' => $message->id,
        ]);
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

    protected function createConversation(
        Tenant $tenant,
        WhatsAppLine $line,
        ConversationStatus $status = ConversationStatus::BotActive,
        ?int $assignedToUserId = null,
    ): Conversation {
        return Conversation::query()->create([
            'tenant_id' => $tenant->id,
            'whatsapp_line_id' => $line->id,
            'contact_phone' => '573001234567',
            'contact_name' => 'Ana Cliente',
            'status' => $status,
            'assigned_to_user_id' => $assignedToUserId,
            'last_message_at' => now()->subMinutes(10),
            'last_customer_message_at' => now()->subMinutes(10),
        ]);
    }

    protected function createInboundMessage(Tenant $tenant, Conversation $conversation, string $providerMessageId): ConversationMessage
    {
        return ConversationMessage::query()->create([
            'tenant_id' => $tenant->id,
            'conversation_id' => $conversation->id,
            'direction' => MessageDirection::Inbound,
            'message_type' => 'text',
            'body' => 'Hola',
            'provider_message_id' => $providerMessageId,
            'status' => 'received',
            'received_at' => now(),
        ]);
    }

    protected function inboundMessage(string $providerMessageId, string $contactPhone, ?string $contactName): InboundMessageData
    {
        return new InboundMessageData(
            phoneNumberId: 'phone-number-id',
            providerMessageId: $providerMessageId,
            contactPhone: $contactPhone,
            contactName: $contactName,
            messageType: 'text',
            body: 'Hola desde WhatsApp',
            providerMessageType: 'text',
            payload: ['text' => ['body' => 'Hola desde WhatsApp']],
            receivedAt: CarbonImmutable::now(),
        );
    }
}
