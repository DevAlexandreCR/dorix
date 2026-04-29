<?php

namespace Tests\Feature\Database;

use App\Enums\ConversationStatus;
use App\Enums\MessageDirection;
use App\Models\Conversation;
use App\Models\ConversationMessage;
use App\Models\ConversationState;
use App\Models\Tenant;
use App\Models\WhatsAppLine;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DomainSchemaConstraintsTest extends TestCase
{
    use RefreshDatabase;

    public function test_phone_number_id_is_globally_unique(): void
    {
        $firstTenant = $this->createTenant('acme');
        $secondTenant = $this->createTenant('globex');

        WhatsAppLine::query()->create([
            'tenant_id' => $firstTenant->id,
            'name' => 'Primary',
            'phone_number_id' => 'phone-number-id',
        ]);

        $this->expectException(QueryException::class);

        WhatsAppLine::query()->create([
            'tenant_id' => $secondTenant->id,
            'name' => 'Secondary',
            'phone_number_id' => 'phone-number-id',
        ]);
    }

    public function test_conversation_state_is_unique_per_conversation(): void
    {
        $conversation = $this->createConversation('acme');

        ConversationState::query()->create([
            'tenant_id' => $conversation->tenant_id,
            'conversation_id' => $conversation->id,
            'current_intent' => 'inventory_lookup',
        ]);

        $this->expectException(QueryException::class);

        ConversationState::query()->create([
            'tenant_id' => $conversation->tenant_id,
            'conversation_id' => $conversation->id,
            'current_intent' => 'faq_lookup',
        ]);
    }

    public function test_provider_message_id_is_unique_within_tenant_scope_only(): void
    {
        $firstConversation = $this->createConversation('acme');
        $secondConversation = $this->createConversation('globex');

        ConversationMessage::query()->create([
            'tenant_id' => $firstConversation->tenant_id,
            'conversation_id' => $firstConversation->id,
            'direction' => MessageDirection::Inbound,
            'message_type' => 'text',
            'provider_message_id' => 'wamid.1',
            'body' => 'Hola',
        ]);

        ConversationMessage::query()->create([
            'tenant_id' => $secondConversation->tenant_id,
            'conversation_id' => $secondConversation->id,
            'direction' => MessageDirection::Inbound,
            'message_type' => 'text',
            'provider_message_id' => 'wamid.1',
            'body' => 'Hola',
        ]);

        $this->expectException(QueryException::class);

        ConversationMessage::query()->create([
            'tenant_id' => $firstConversation->tenant_id,
            'conversation_id' => $firstConversation->id,
            'direction' => MessageDirection::Inbound,
            'message_type' => 'text',
            'provider_message_id' => 'wamid.1',
            'body' => 'Hola otra vez',
        ]);
    }

    protected function createTenant(string $slug): Tenant
    {
        return Tenant::query()->create([
            'name' => ucfirst($slug),
            'slug' => $slug,
        ]);
    }

    protected function createConversation(string $tenantSlug): Conversation
    {
        $tenant = $this->createTenant($tenantSlug);

        $line = WhatsAppLine::query()->create([
            'tenant_id' => $tenant->id,
            'name' => 'Primary',
            'phone_number_id' => "{$tenantSlug}-phone-number-id",
        ]);

        return Conversation::query()->create([
            'tenant_id' => $tenant->id,
            'whatsapp_line_id' => $line->id,
            'contact_phone' => '+573001112233',
            'status' => ConversationStatus::BotActive,
        ]);
    }
}
