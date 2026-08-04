<?php

namespace Tests\Unit\Domain\Catalog;

use App\Domain\Catalog\CatalogItemPricePresenter;
use App\Models\CatalogItem;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CatalogItemPricePresenterTest extends TestCase
{
    use RefreshDatabase;

    private CatalogItemPricePresenter $presenter;

    protected function setUp(): void
    {
        parent::setUp();

        $this->presenter = new CatalogItemPricePresenter();
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

    public function test_fixed_price_is_formatted_with_currency(): void
    {
        $tenant = $this->makeTenant();
        $item = $this->makeItem($tenant, ['price_amount' => 150000]);

        $this->assertSame('150,000 COP', $this->presenter->present($item));
    }

    public function test_fixed_price_respects_a_non_default_currency(): void
    {
        $tenant = $this->makeTenant();
        $item = $this->makeItem($tenant, ['price_amount' => 25, 'currency' => 'USD']);

        $this->assertSame('25 USD', $this->presenter->present($item));
    }

    public function test_fixed_price_keeps_a_fractional_amount(): void
    {
        $tenant = $this->makeTenant();
        $item = $this->makeItem($tenant, ['price_amount' => 19.99, 'currency' => 'USD']);

        $this->assertSame('19.99 USD', $this->presenter->present($item));
    }

    public function test_from_price_uses_the_from_copy(): void
    {
        $tenant = $this->makeTenant();
        $item = $this->makeItem($tenant, ['price_type' => 'from', 'price_amount' => 80000]);

        $this->assertSame(__('api.catalog.price_from', ['amount' => '80,000 COP']), $this->presenter->present($item));
    }

    public function test_range_price_uses_the_range_copy(): void
    {
        $tenant = $this->makeTenant();
        $item = $this->makeItem($tenant, [
            'price_type' => 'range',
            'price_amount' => null,
            'price_min' => 50000,
            'price_max' => 120000,
        ]);

        $this->assertSame(
            __('api.catalog.price_range', ['min' => '50,000 COP', 'max' => '120,000 COP']),
            $this->presenter->present($item),
        );
    }

    public function test_zero_fixed_price_shows_free_copy_not_zero(): void
    {
        $tenant = $this->makeTenant();
        $item = $this->makeItem($tenant, ['price_amount' => 0]);

        $this->assertSame(__('api.catalog.price_free'), $this->presenter->present($item));
    }

    public function test_zero_from_price_shows_free_copy_not_zero(): void
    {
        $tenant = $this->makeTenant();
        $item = $this->makeItem($tenant, ['price_type' => 'from', 'price_amount' => 0]);

        $this->assertSame(__('api.catalog.price_free'), $this->presenter->present($item));
    }

    public function test_item_requiring_assessment_without_a_price_shows_assessment_copy(): void
    {
        $tenant = $this->makeTenant();
        $assessment = $this->makeItem($tenant, ['name' => 'Valoracion']);
        $item = $this->makeItem($tenant, [
            'name' => 'Acido hialuronico',
            'price_amount' => null,
            'assessment_item_id' => $assessment->id,
        ]);

        $this->assertSame(__('api.catalog.price_on_assessment'), $this->presenter->present($item));
    }

    public function test_item_requiring_assessment_with_an_explicit_price_still_shows_that_price(): void
    {
        $tenant = $this->makeTenant();
        $assessment = $this->makeItem($tenant, ['name' => 'Valoracion']);
        $item = $this->makeItem($tenant, [
            'name' => 'Acido hialuronico',
            'price_amount' => 300000,
            'assessment_item_id' => $assessment->id,
        ]);

        $this->assertSame('300,000 COP', $this->presenter->present($item));
    }

    public function test_free_assessment_item_itself_shows_free_copy(): void
    {
        $tenant = $this->makeTenant();
        $assessment = $this->makeItem($tenant, ['name' => 'Valoracion', 'price_amount' => 0]);

        $this->assertSame(__('api.catalog.price_free'), $this->presenter->present($assessment));
    }

    public function test_translations_exist_for_both_locales(): void
    {
        $keys = ['price_free', 'price_on_assessment', 'price_from', 'price_range'];

        foreach (['en', 'es_CO'] as $locale) {
            foreach ($keys as $key) {
                $this->assertNotSame(
                    "api.catalog.$key",
                    trans("api.catalog.$key", [], $locale),
                    "Missing translation api.catalog.$key for locale $locale",
                );
            }
        }
    }
}
