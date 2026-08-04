<?php

namespace Tests\Feature\Agent;

use App\Domain\Agent\AgentContextLoader;
use App\Domain\Agent\PromptBuilder;
use App\Domain\Conversations\Contracts\ConversationStateRepository;
use App\Enums\MessageDirection;
use App\Models\AgentConfig;
use App\Models\CatalogItem;
use App\Models\Conversation;
use App\Models\ConversationMessage;
use App\Models\Tenant;
use App\Models\WhatsAppLine;
use App\Support\Tenancy\TenantScopeKey;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PromptBuilderCatalogIndexTest extends TestCase
{
    use RefreshDatabase;

    public function test_prompt_includes_the_catalog_index_with_ids_and_assessment_requirement(): void
    {
        [$tenant, , $conversation, $message] = $this->conversationFixtures();

        $assessment = CatalogItem::query()->create([
            'tenant_id' => $tenant->id,
            'kind' => 'service',
            'name' => 'Valoracion dermatologica',
            'price_type' => 'fixed',
            'price_amount' => 0,
            'duration_minutes' => 30,
            'active' => true,
        ]);

        $bookableItem = CatalogItem::query()->create([
            'tenant_id' => $tenant->id,
            'kind' => 'service',
            'name' => 'Limpieza facial',
            'price_type' => 'fixed',
            'price_amount' => 150000,
            'duration_minutes' => 60,
            'active' => true,
        ]);

        $assessmentRequiredItem = CatalogItem::query()->create([
            'tenant_id' => $tenant->id,
            'kind' => 'service',
            'name' => 'Acido hialuronico',
            'price_type' => 'fixed',
            'price_amount' => null,
            'duration_minutes' => null,
            'assessment_item_id' => $assessment->id,
            'active' => true,
        ]);

        CatalogItem::query()->create([
            'tenant_id' => $tenant->id,
            'kind' => 'service',
            'name' => 'Servicio inactivo',
            'price_type' => 'fixed',
            'price_amount' => 100000,
            'duration_minutes' => 45,
            'active' => false,
        ]);

        $state = app(ConversationStateRepository::class)->getOrCreate($conversation);
        $context = app(AgentContextLoader::class)->load($conversation, $message->id, $state);
        $prompt = app(PromptBuilder::class)->build($context);

        $payload = json_decode($prompt[1]['content'], true, flags: JSON_THROW_ON_ERROR);

        $this->assertArrayHasKey('catalog', $payload);
        $this->assertCount(3, $payload['catalog']);

        $byId = collect($payload['catalog'])->keyBy('id');

        $this->assertSame([
            'id' => $bookableItem->id,
            'name' => 'Limpieza facial',
            'price' => '150,000 COP',
            'duration_minutes' => 60,
            'requires_assessment_item_id' => null,
        ], $byId->get($bookableItem->id));

        $this->assertSame([
            'id' => $assessmentRequiredItem->id,
            'name' => 'Acido hialuronico',
            'price' => __('api.catalog.price_on_assessment'),
            'duration_minutes' => null,
            'requires_assessment_item_id' => $assessment->id,
        ], $byId->get($assessmentRequiredItem->id));

        $this->assertSame([
            'id' => $assessment->id,
            'name' => 'Valoracion dermatologica',
            'price' => __('api.catalog.price_free'),
            'duration_minutes' => 30,
            'requires_assessment_item_id' => null,
        ], $byId->get($assessment->id));
    }

    public function test_prompt_omits_the_catalog_section_when_the_tenant_has_no_active_items(): void
    {
        [, , $conversation, $message] = $this->conversationFixtures();

        $state = app(ConversationStateRepository::class)->getOrCreate($conversation);
        $context = app(AgentContextLoader::class)->load($conversation, $message->id, $state);
        $prompt = app(PromptBuilder::class)->build($context);

        $payload = json_decode($prompt[1]['content'], true, flags: JSON_THROW_ON_ERROR);

        $this->assertArrayNotHasKey('catalog', $payload);
    }

    /**
     * @return array{0: Tenant, 1: WhatsAppLine, 2: Conversation, 3: ConversationMessage}
     */
    protected function conversationFixtures(): array
    {
        $tenant = Tenant::query()->create([
            'name' => 'Acme',
            'slug' => 'acme',
        ]);

        $line = WhatsAppLine::query()->create([
            'tenant_id' => $tenant->id,
            'name' => 'Primary Line',
            'phone_number_id' => 'acme-phone-number-id',
            'display_phone_number' => '+573001112233',
            'status' => 'active',
            'is_enabled' => true,
        ]);

        AgentConfig::query()->create([
            'tenant_id' => $tenant->id,
            'whatsapp_line_id' => null,
            'scope_type' => 'tenant',
            'scope_key' => TenantScopeKey::forTenant($tenant),
            'name' => 'Default Agent',
            'model_key' => 'balanced',
            'prompt_version' => 'v1',
            'is_active' => true,
            'settings' => [
                'automation_enabled' => true,
            ],
        ]);

        $conversation = Conversation::query()->create([
            'tenant_id' => $tenant->id,
            'whatsapp_line_id' => $line->id,
            'contact_phone' => '573001234567',
            'contact_name' => 'Ana Cliente',
            'status' => 'BOT_ACTIVE',
            'last_message_at' => now(),
            'last_customer_message_at' => now(),
        ]);

        $message = ConversationMessage::query()->create([
            'tenant_id' => $tenant->id,
            'conversation_id' => $conversation->id,
            'direction' => MessageDirection::Inbound,
            'message_type' => 'text',
            'body' => 'Hola, necesito ayuda.',
            'provider_message_id' => 'wamid.inbound.100',
            'status' => 'received',
            'received_at' => now(),
        ]);

        return [$tenant, $line, $conversation, $message];
    }
}
