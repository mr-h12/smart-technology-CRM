<?php

declare(strict_types=1);

namespace App\Modules\Pdf\Infrastructure;

use App\Modules\Catalog\Domain\Contracts\CatalogItemLabelsInterface;
use App\Modules\Pdf\Domain\Contracts\LineDescriptionsInterface;

/**
 * Module 9, Point 3.2 — `LineDescriptionsInterface` answered from the catalog.
 *
 * A quotation line names its own catalog item (QS.1 · `D-155`), and the catalog
 * names the item (§7.3's label and its unit, `CatalogItemLabelsInterface`). That
 * label is what the customer reads; nothing of a supplier is read here at all
 * (§3.12 rule 2, §14.6).
 *
 * An item the catalog cannot name has no entry, and `CustomerQuotationViewMapper`
 * refuses the view for it rather than printing a blank description.
 */
final readonly class CatalogLineDescriptions implements LineDescriptionsInterface
{
    public function __construct(
        private CatalogItemLabelsInterface $labels,
    ) {}

    public function descriptionsOf(array $catalogItemIds): array
    {
        return $this->labels->labelsOf($catalogItemIds);
    }

    public function unitsOf(array $unitCodes): array
    {
        return $this->labels->unitsOf($unitCodes);
    }
}
