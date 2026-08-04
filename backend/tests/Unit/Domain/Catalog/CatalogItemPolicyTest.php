<?php

namespace Tests\Unit\Domain\Catalog;

use App\Domain\Catalog\CatalogItemPolicy;
use App\Domain\Catalog\Exceptions\InvalidAssessmentLinkException;
use App\Models\CatalogItem;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CatalogItemPolicyTest extends TestCase
{
    use RefreshDatabase;

    private CatalogItemPolicy $policy;

    protected function setUp(): void
    {
        parent::setUp();

        $this->policy = new CatalogItemPolicy();
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

    public function test_service_without_assessment_and_positive_duration_is_bookable(): void
    {
        $tenant = $this->makeTenant();
        $item = $this->makeItem($tenant);

        $this->assertTrue($this->policy->isBookable($item));
    }

    public function test_product_is_never_bookable_even_with_a_duration(): void
    {
        $tenant = $this->makeTenant();
        $item = $this->makeItem($tenant, [
            'kind' => 'product',
            'duration_minutes' => 30,
        ]);

        $this->assertFalse($this->policy->isBookable($item));
    }

    public function test_item_requiring_assessment_is_not_directly_bookable(): void
    {
        $tenant = $this->makeTenant();
        $assessment = $this->makeItem($tenant, ['name' => 'Valoracion']);
        $item = $this->makeItem($tenant, [
            'name' => 'Acido hialuronico',
            'assessment_item_id' => $assessment->id,
        ]);

        $this->assertFalse($this->policy->isBookable($item));
        $this->assertTrue($this->policy->isBookable($assessment));
    }

    public function test_service_without_a_positive_duration_is_not_bookable(): void
    {
        $tenant = $this->makeTenant();

        $noDuration = $this->makeItem($tenant, ['duration_minutes' => null]);
        $zeroDuration = $this->makeItem($tenant, ['duration_minutes' => 0]);

        $this->assertFalse($this->policy->isBookable($noDuration));
        $this->assertFalse($this->policy->isBookable($zeroDuration));
    }

    public function test_assessment_link_is_a_no_op_when_target_is_null(): void
    {
        $tenant = $this->makeTenant();
        $item = $this->makeItem($tenant);

        $this->policy->assertValidAssessmentLink($item, null);

        $this->addToAssertionCount(1);
    }

    public function test_assessment_link_can_be_shared_by_two_items_of_the_same_tenant(): void
    {
        $tenant = $this->makeTenant();
        $assessment = $this->makeItem($tenant, ['name' => 'Valoracion']);
        $first = $this->makeItem($tenant, ['name' => 'Servicio A', 'assessment_item_id' => $assessment->id]);
        $second = $this->makeItem($tenant, ['name' => 'Servicio B', 'assessment_item_id' => $assessment->id]);

        $this->policy->assertValidAssessmentLink($first, $assessment);
        $this->policy->assertValidAssessmentLink($second, $assessment);

        $this->addToAssertionCount(1);
    }

    public function test_assessment_link_rejects_a_target_from_a_different_tenant(): void
    {
        $tenantA = $this->makeTenant('acme');
        $tenantB = $this->makeTenant('globex');
        $item = $this->makeItem($tenantA);
        $assessment = $this->makeItem($tenantB, ['name' => 'Valoracion']);

        $this->expectException(InvalidAssessmentLinkException::class);

        $this->policy->assertValidAssessmentLink($item, $assessment);
    }

    public function test_assessment_link_rejects_a_target_that_itself_requires_an_assessment(): void
    {
        $tenant = $this->makeTenant();
        $rootAssessment = $this->makeItem($tenant, ['name' => 'Valoracion raiz']);
        $chainedTarget = $this->makeItem($tenant, [
            'name' => 'Valoracion intermedia',
            'assessment_item_id' => $rootAssessment->id,
        ]);
        $item = $this->makeItem($tenant, ['name' => 'Servicio']);

        $this->expectException(InvalidAssessmentLinkException::class);

        $this->policy->assertValidAssessmentLink($item, $chainedTarget);
    }

    public function test_assessment_link_rejects_an_item_already_referenced_as_an_assessment(): void
    {
        $tenant = $this->makeTenant();
        $assessment = $this->makeItem($tenant, ['name' => 'Valoracion']);
        $this->makeItem($tenant, ['name' => 'Servicio dependiente', 'assessment_item_id' => $assessment->id]);
        $otherAssessment = $this->makeItem($tenant, ['name' => 'Otra valoracion']);

        $this->expectException(InvalidAssessmentLinkException::class);

        $this->policy->assertValidAssessmentLink($assessment, $otherAssessment);
    }

    public function test_assessment_link_rejects_self_reference(): void
    {
        $tenant = $this->makeTenant();
        $item = $this->makeItem($tenant);

        $this->expectException(InvalidAssessmentLinkException::class);

        $this->policy->assertValidAssessmentLink($item, $item);
    }
}
