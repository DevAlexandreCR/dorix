<?php

namespace App\Domain\Catalog;

use App\Domain\Catalog\Exceptions\InvalidAssessmentLinkException;
use App\Models\CatalogItem;

class CatalogItemPolicy
{
    /**
     * A product is never bookable. A service that requires a prior
     * assessment is not directly bookable either — the linked assessment
     * item is what gets booked. Otherwise, a service is only bookable when
     * it has a positive duration.
     */
    public function isBookable(CatalogItem $item): bool
    {
        if ($item->kind === 'product') {
            return false;
        }

        if ($item->assessment_item_id !== null) {
            return false;
        }

        return $item->duration_minutes !== null && $item->duration_minutes > 0;
    }

    /**
     * Validates that linking $item to $assessmentItem (via
     * assessment_item_id) does not violate the no-chains / same-tenant
     * invariants. Pass null when the item has no assessment link (a no-op).
     */
    public function assertValidAssessmentLink(CatalogItem $item, ?CatalogItem $assessmentItem): void
    {
        if ($assessmentItem === null) {
            return;
        }

        if ($item->getKey() !== null && $item->getKey() === $assessmentItem->getKey()) {
            throw InvalidAssessmentLinkException::selfReference($item);
        }

        if (! $assessmentItem->belongsToTenant($item->tenant_id)) {
            throw InvalidAssessmentLinkException::crossTenant($item, $assessmentItem);
        }

        if ($assessmentItem->assessment_item_id !== null) {
            throw InvalidAssessmentLinkException::chainedTarget($item, $assessmentItem);
        }

        if ($item->getKey() !== null && $this->isReferencedAsAssessment($item)) {
            throw InvalidAssessmentLinkException::alreadyReferencedAsAssessment($item);
        }
    }

    protected function isReferencedAsAssessment(CatalogItem $item): bool
    {
        return CatalogItem::query()
            ->forTenant($item->tenant_id)
            ->where('assessment_item_id', $item->getKey())
            ->exists();
    }
}
