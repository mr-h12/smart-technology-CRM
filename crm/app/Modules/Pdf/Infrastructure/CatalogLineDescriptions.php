<?php

declare(strict_types=1);

namespace App\Modules\Pdf\Infrastructure;

use App\Modules\Catalog\Domain\Contracts\CatalogItemLabelsInterface;
use App\Modules\Pdf\Domain\Contracts\LineDescriptionsInterface;
use App\Modules\SupplierQuotations\Domain\Contracts\SupplierItemPricingInterface;

/**
 * Module 9, Point 3.2 — `LineDescriptionsInterface` answered from the catalog.
 *
 * A supplier line names its catalog item (`SupplierItemPrice::$catalogItemId`),
 * and the catalog names the item (§7.3's label, `CatalogItemLabelsInterface`).
 * That label is what the customer reads. **Nothing else of `SupplierItemPrice`
 * leaves this class**: it carries the supplier's price, and the answer is keyed
 * by the supplier line and valued by a catalog label only (§3.12 rule 2, §14.6).
 *
 * A supplier line that is gone (`priceFor()` null — its offer was archived) or
 * whose item the catalog cannot name has no entry, and
 * `CustomerQuotationViewMapper` refuses the view for it rather than printing a
 * blank description.
 *
 * ponytail: the join `ShowQuotation::lineNames()` already makes (F-16), which
 * `Pdf` may not call — Module 7's Application layer is not in
 * `QuotationsContract`. Deleted the day `QuotationLine` carries the name. One
 * `priceFor()` read per line, as `lineNames()` does.
 */
final readonly class CatalogLineDescriptions implements LineDescriptionsInterface
{
    public function __construct(
        private SupplierItemPricingInterface $prices,
        private CatalogItemLabelsInterface $labels,
    ) {}

    public function descriptionsOf(array $supplierQuotationItemIds): array
    {
        $catalogItemIds = $this->catalogItemIdsOf($supplierQuotationItemIds);

        return self::byLine($catalogItemIds, $this->labels->labelsOf(array_values(array_unique($catalogItemIds))));
    }

    public function unitsOf(array $supplierQuotationItemIds): array
    {
        $catalogItemIds = $this->catalogItemIdsOf($supplierQuotationItemIds);

        return self::byLine($catalogItemIds, $this->labels->unitsOf(array_values(array_unique($catalogItemIds))));
    }

    /**
     * @param  list<string>  $supplierQuotationItemIds
     * @return array<string, string> supplier-quotation item id => catalog item id
     */
    private function catalogItemIdsOf(array $supplierQuotationItemIds): array
    {
        $catalogItemIds = [];

        foreach ($supplierQuotationItemIds as $supplierQuotationItemId) {
            $price = $this->prices->priceFor($supplierQuotationItemId);

            if ($price !== null) {
                $catalogItemIds[$supplierQuotationItemId] = $price->catalogItemId;
            }
        }

        return $catalogItemIds;
    }

    /**
     * @template T
     *
     * @param  array<string, string>  $catalogItemIds
     * @param  array<string, T>  $byCatalogItem
     * @return array<string, T>
     */
    private static function byLine(array $catalogItemIds, array $byCatalogItem): array
    {
        $byLine = [];

        foreach ($catalogItemIds as $supplierQuotationItemId => $catalogItemId) {
            if (isset($byCatalogItem[$catalogItemId])) {
                $byLine[$supplierQuotationItemId] = $byCatalogItem[$catalogItemId];
            }
        }

        return $byLine;
    }
}
