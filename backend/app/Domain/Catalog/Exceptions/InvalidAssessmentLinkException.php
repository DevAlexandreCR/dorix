<?php

namespace App\Domain\Catalog\Exceptions;

use App\Models\CatalogItem;
use RuntimeException;

class InvalidAssessmentLinkException extends RuntimeException
{
    public static function selfReference(CatalogItem $item): self
    {
        return new self(sprintf(
            'Catalog item [%s] cannot be linked to itself as an assessment.',
            $item->getKey(),
        ));
    }

    public static function crossTenant(CatalogItem $item, CatalogItem $assessmentItem): self
    {
        return new self(sprintf(
            'Catalog item [%s] cannot link to assessment item [%s] because it belongs to a different tenant.',
            $item->getKey(),
            $assessmentItem->getKey(),
        ));
    }

    public static function chainedTarget(CatalogItem $item, CatalogItem $assessmentItem): self
    {
        return new self(sprintf(
            'Catalog item [%s] cannot link to assessment item [%s] because that item already requires its own assessment.',
            $item->getKey(),
            $assessmentItem->getKey(),
        ));
    }

    public static function alreadyReferencedAsAssessment(CatalogItem $item): self
    {
        return new self(sprintf(
            'Catalog item [%s] cannot require an assessment because it is already used as the assessment for other items.',
            $item->getKey(),
        ));
    }
}
