<?php

namespace App\Http\Requests\Api;

use App\Domain\Catalog\CatalogItemPolicy;
use App\Domain\Catalog\Exceptions\InvalidAssessmentLinkException;
use App\Models\CatalogItem;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class UpsertCatalogItemRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $tenantId = $this->tenantId();

        return [
            'kind' => ['required', 'string', 'in:service,product'],
            'name' => ['required', 'string', 'max:190'],
            'category' => ['nullable', 'string', 'max:120'],
            'description' => ['nullable', 'string'],
            'price_type' => ['required', 'string', 'in:fixed,from,range'],
            'price_amount' => ['nullable', 'numeric', 'min:0'],
            'price_min' => ['nullable', 'numeric', 'min:0'],
            'price_max' => ['nullable', 'numeric', 'min:0'],
            'currency' => ['nullable', 'string', 'size:3'],
            'duration_minutes' => ['nullable', 'integer', 'min:1'],
            'assessment_item_id' => [
                'nullable',
                'integer',
                Rule::exists('catalog_items', 'id')->where(
                    fn ($query) => $query->where('tenant_id', $tenantId)
                ),
            ],
            'active' => ['required', 'boolean'],
            'metadata' => ['sometimes', 'nullable', 'array'],
        ];
    }

    /**
     * Coherence checks that span multiple fields (D3/D4 of design.md):
     * a fixed price needs an amount unless the price is only known after
     * assessment, a range needs min < max, a bookable item (a service with
     * no assessment link) needs a positive duration, and a product can
     * never carry duration/assessment fields. The assessment link itself
     * is re-validated through the domain policy so the no-chains /
     * same-tenant invariants stay in one place.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $data = $validator->getData();

            $kind = $data['kind'] ?? null;
            $priceType = $data['price_type'] ?? null;
            $assessmentItemId = $this->filledInt($data['assessment_item_id'] ?? null);
            $durationMinutes = $data['duration_minutes'] ?? null;

            if ($kind === 'product') {
                if (! $this->blank($durationMinutes)) {
                    $validator->errors()->add('duration_minutes', __('api.catalog.product_duration_not_allowed'));
                }

                if ($assessmentItemId !== null) {
                    $validator->errors()->add('assessment_item_id', __('api.catalog.product_assessment_not_allowed'));
                }
            }

            if ($priceType === 'fixed' && $assessmentItemId === null && $this->blank($data['price_amount'] ?? null)) {
                $validator->errors()->add('price_amount', __('api.catalog.fixed_price_requires_amount'));
            }

            if ($priceType === 'range') {
                $min = $data['price_min'] ?? null;
                $max = $data['price_max'] ?? null;

                if ($this->blank($min) || $this->blank($max)) {
                    $validator->errors()->add('price_min', __('api.catalog.range_requires_min_and_max'));
                } elseif ((float) $min >= (float) $max) {
                    $validator->errors()->add('price_min', __('api.catalog.range_min_must_be_lower_than_max'));
                }
            }

            if ($kind === 'service' && $assessmentItemId === null) {
                if ($this->blank($durationMinutes) || (int) $durationMinutes <= 0) {
                    $validator->errors()->add('duration_minutes', __('api.catalog.bookable_requires_positive_duration'));
                }
            }

            if ($assessmentItemId !== null) {
                $this->assertAssessmentLink($validator, $assessmentItemId);
            }
        });
    }

    protected function assertAssessmentLink(Validator $validator, int $assessmentItemId): void
    {
        $tenantId = $this->tenantId();

        if ($tenantId === null) {
            return;
        }

        $assessmentItem = CatalogItem::query()->forTenant($tenantId)->find($assessmentItemId);

        if ($assessmentItem === null) {
            // Already flagged by the exists rule above.
            return;
        }

        try {
            app(CatalogItemPolicy::class)->assertValidAssessmentLink($this->subjectItem($tenantId), $assessmentItem);
        } catch (InvalidAssessmentLinkException $exception) {
            $validator->errors()->add('assessment_item_id', $exception->getMessage());
        }
    }

    protected function subjectItem(int $tenantId): CatalogItem
    {
        $bound = $this->route('catalogItem');

        if ($bound instanceof CatalogItem && $bound->belongsToTenant($tenantId)) {
            return $bound;
        }

        return CatalogItem::make(['tenant_id' => $tenantId]);
    }

    protected function tenantId(): ?int
    {
        /** @var TenantContext|null $context */
        $context = $this->attributes->get(TenantContext::class);

        return $context?->tenant->getKey();
    }

    protected function blank(mixed $value): bool
    {
        return $value === null || $value === '';
    }

    protected function filledInt(mixed $value): ?int
    {
        return $this->blank($value) ? null : (int) $value;
    }
}
