<?php

namespace Tests\Feature\Agent\Tools;

use App\Domain\Agent\DTO\AgentContext;
use App\Domain\Tools\DTO\EnabledTool;
use App\Domain\Tools\DTO\ToolInvocation;
use App\Domain\Tools\Exceptions\InvalidToolArgumentsException;
use App\Domain\Tools\ToolNextAction;
use App\Domain\Tools\Tools\GetServiceDetailsTool;
use App\Enums\MessageDirection;
use App\Models\AgentConfig;
use App\Models\CatalogItem;
use App\Models\Conversation;
use App\Models\ConversationMessage;
use App\Models\ConversationState;
use App\Models\Tenant;
use App\Models\TenantToolConfig;
use App\Models\WhatsAppLine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GetServiceDetailsToolTest extends TestCase
{
    use RefreshDatabase;

    private GetServiceDetailsTool $tool;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tool = app(GetServiceDetailsTool::class);
    }

    public function test_it_returns_the_detail_of_a_directly_bookable_service(): void
    {
        $tenant = $this->makeTenant();
        $item = $this->makeItem($tenant, [
            'name' => 'Limpieza facial',
            'description' => 'Limpieza facial profunda con extraccion.',
            'price_amount' => 150000,
            'duration_minutes' => 60,
        ]);

        $result = $this->tool->execute($this->invocation($tenant, ['item_id' => $item->id]));

        $this->assertSame(ToolNextAction::ContinueWithRetrievedContext, $result->nextAction);
        $this->assertTrue($result->outputSummary['found']);
        $this->assertSame($item->id, $result->outputSummary['item_id']);
        $this->assertSame('service', $result->outputSummary['kind']);
        $this->assertSame('Limpieza facial', $result->outputSummary['name']);
        $this->assertSame('Limpieza facial profunda con extraccion.', $result->outputSummary['description']);
        $this->assertSame('150,000 COP', $result->outputSummary['price']);
        $this->assertSame(60, $result->outputSummary['duration_minutes']);
        $this->assertTrue($result->outputSummary['bookable']);
        $this->assertFalse($result->outputSummary['requires_assessment']);
        $this->assertNull($result->outputSummary['assessment_item']);
        $this->assertSame([$result->outputSummary], $result->metadata['retrieved_context']);

        $this->assertDatabaseHas('agent_events', [
            'tenant_id' => $tenant->id,
            'event_type' => 'catalog_item_lookup_completed',
        ]);
    }

    public function test_it_surfaces_the_assessment_policy_when_the_item_requires_a_prior_assessment(): void
    {
        $tenant = $this->makeTenant();
        $assessment = $this->makeItem($tenant, [
            'name' => 'Valoracion dermatologica',
            'price_amount' => 0,
            'duration_minutes' => 30,
        ]);
        $item = $this->makeItem($tenant, [
            'name' => 'Acido hialuronico',
            'price_amount' => null,
            'duration_minutes' => null,
            'assessment_item_id' => $assessment->id,
        ]);

        $result = $this->tool->execute($this->invocation($tenant, ['item_id' => $item->id]));

        $this->assertTrue($result->outputSummary['found']);
        $this->assertFalse($result->outputSummary['bookable']);
        $this->assertTrue($result->outputSummary['requires_assessment']);
        $this->assertSame(__('api.catalog.price_on_assessment'), $result->outputSummary['price']);
        $this->assertSame([
            'id' => $assessment->id,
            'name' => 'Valoracion dermatologica',
            'price' => __('api.catalog.price_free'),
            'duration_minutes' => 30,
        ], $result->outputSummary['assessment_item']);

        $this->assertDatabaseHas('agent_events', [
            'tenant_id' => $tenant->id,
            'event_type' => 'catalog_item_lookup_completed',
        ]);
    }

    public function test_it_returns_the_detail_of_a_product_which_is_never_bookable(): void
    {
        $tenant = $this->makeTenant();
        $item = $this->makeItem($tenant, [
            'kind' => 'product',
            'name' => 'Serum reparador',
            'duration_minutes' => null,
        ]);

        $result = $this->tool->execute($this->invocation($tenant, ['item_id' => $item->id]));

        $this->assertTrue($result->outputSummary['found']);
        $this->assertSame('product', $result->outputSummary['kind']);
        $this->assertFalse($result->outputSummary['bookable']);
        $this->assertFalse($result->outputSummary['requires_assessment']);
        $this->assertNull($result->outputSummary['duration_minutes']);
    }

    public function test_it_reports_not_found_for_an_unknown_item_id(): void
    {
        $tenant = $this->makeTenant();

        $result = $this->tool->execute($this->invocation($tenant, ['item_id' => 999999]));

        $this->assertSame(ToolNextAction::ContinueWithRetrievedContext, $result->nextAction);
        $this->assertFalse($result->outputSummary['found']);
        $this->assertSame(999999, $result->outputSummary['item_id']);
        $this->assertSame(__('api.catalog.item_not_found'), $result->outputSummary['reason']);
        $this->assertSame([$result->outputSummary], $result->metadata['retrieved_context']);

        $this->assertDatabaseHas('agent_events', [
            'tenant_id' => $tenant->id,
            'event_type' => 'catalog_item_lookup_not_found',
        ]);
    }

    public function test_it_reports_not_found_for_an_inactive_item(): void
    {
        $tenant = $this->makeTenant();
        $item = $this->makeItem($tenant, ['active' => false]);

        $result = $this->tool->execute($this->invocation($tenant, ['item_id' => $item->id]));

        $this->assertFalse($result->outputSummary['found']);
    }

    public function test_it_does_not_leak_an_item_belonging_to_another_tenant(): void
    {
        $tenantA = $this->makeTenant('acme');
        $tenantB = $this->makeTenant('globex');
        $otherTenantItem = $this->makeItem($tenantB, ['name' => 'Servicio de Globex']);

        $result = $this->tool->execute($this->invocation($tenantA, ['item_id' => $otherTenantItem->id]));

        $this->assertFalse($result->outputSummary['found']);
        $this->assertArrayNotHasKey('name', $result->outputSummary);
        $this->assertStringNotContainsString('Globex', json_encode($result->outputSummary));

        $this->assertDatabaseHas('agent_events', [
            'tenant_id' => $tenantA->id,
            'event_type' => 'catalog_item_lookup_not_found',
        ]);
    }

    public function test_it_rejects_a_non_positive_item_id(): void
    {
        $tenant = $this->makeTenant();

        $this->expectException(InvalidToolArgumentsException::class);

        $this->tool->execute($this->invocation($tenant, ['item_id' => 0]));
    }

    public function test_it_rejects_a_missing_item_id(): void
    {
        $tenant = $this->makeTenant();

        $this->expectException(InvalidToolArgumentsException::class);

        $this->tool->execute($this->invocation($tenant, []));
    }

    private function makeTenant(string $slug = 'acme'): Tenant
    {
        return Tenant::query()->create([
            'name' => 'Acme',
            'slug' => $slug,
        ]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function makeItem(Tenant $tenant, array $overrides = []): CatalogItem
    {
        return CatalogItem::create(array_merge([
            'tenant_id' => $tenant->id,
            'kind' => 'service',
            'name' => 'Limpieza facial',
            'price_type' => 'fixed',
            'price_amount' => 100000,
            'duration_minutes' => 60,
        ], $overrides));
    }

    /**
     * @param  array<string, mixed>  $arguments
     */
    private function invocation(Tenant $tenant, array $arguments): ToolInvocation
    {
        $line = WhatsAppLine::query()->create([
            'tenant_id' => $tenant->id,
            'name' => 'Primary Line',
            'phone_number_id' => 'phone-number-id-'.$tenant->id,
            'display_phone_number' => '+573001112233',
            'status' => 'active',
            'is_enabled' => true,
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
            'body' => 'Quiero saber el detalle de un servicio.',
            'provider_message_id' => 'wamid.inbound.'.$conversation->id,
            'status' => 'received',
            'received_at' => now(),
        ]);

        $context = new AgentContext(
            tenant: $tenant,
            line: $line,
            conversation: $conversation,
            state: new ConversationState(['collected_data' => []]),
            agentConfig: new AgentConfig(['settings' => []]),
            triggeringMessage: $message,
            recentMessages: [],
            enabledTools: [],
        );

        return new ToolInvocation(
            context: $context,
            tool: new EnabledTool($this->tool->definition(), new TenantToolConfig()),
            arguments: $arguments,
        );
    }
}
