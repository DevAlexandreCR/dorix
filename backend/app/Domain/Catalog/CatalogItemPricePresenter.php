<?php

namespace App\Domain\Catalog;

use App\Models\CatalogItem;

class CatalogItemPricePresenter
{
    /**
     * Builds a human-readable price string for the item, honoring its
     * price_type and the assessment-link / zero-amount rules from D3 and
     * the "Open Questions" of design.md: an item awaiting assessment never
     * shows as unpriced, and a zero amount is always "free" copy, never
     * "$0".
     */
    public function present(CatalogItem $item): string
    {
        if ($item->assessment_item_id !== null && $item->price_amount === null) {
            return (string) __('api.catalog.price_on_assessment');
        }

        if (in_array($item->price_type, ['fixed', 'from'], true) && $this->isZeroAmount($item->price_amount)) {
            return (string) __('api.catalog.price_free');
        }

        return match ($item->price_type) {
            'from' => (string) __('api.catalog.price_from', [
                'amount' => $this->formatAmount($item->price_amount, $item->currency),
            ]),
            'range' => (string) __('api.catalog.price_range', [
                'min' => $this->formatAmount($item->price_min, $item->currency),
                'max' => $this->formatAmount($item->price_max, $item->currency),
            ]),
            default => $this->formatAmount($item->price_amount, $item->currency),
        };
    }

    private function isZeroAmount(null|string|int|float $amount): bool
    {
        return $amount !== null && (float) $amount === 0.0;
    }

    private function formatAmount(null|string|int|float $amount, ?string $currency): string
    {
        $value = (float) ($amount ?? 0);
        $decimals = $this->hasFractionalPart($value) ? 2 : 0;

        return sprintf('%s %s', number_format($value, $decimals), $currency ?: 'COP');
    }

    private function hasFractionalPart(float $value): bool
    {
        return abs($value - round($value)) > 0.001;
    }
}
