<?php

declare(strict_types=1);

namespace App\Modules\SupplierQuotations\Domain\Contracts;

use App\Modules\SupplierQuotations\Domain\Pricing\SupplierItemPrice;

/**
 * The read §5.6 forces on Module 7: given a supplier line, what does it cost?
 *
 * A second interface beside {@see SupplierQuotationDirectoryInterface} rather
 * than a method on it, on the `CatalogProductProvisionerInterface` precedent —
 * the published cross-module surface is exactly the one thing another module
 * needs, not the whole screen's directory. A customer quotation reads a single
 * line's price by its id; it has no use for `find()`'s header-and-lines detail
 * and no scope to pass (§3.6 grants every role `All` here), so handing it the
 * directory would be a wider promise than the crossing requires.
 *
 * Keyed by the line's own id, not by the offer's: Module 7's
 * `quotation_items.supplier_quotation_item_id` points at one line, and the
 * quotation never names the offer it belongs to.
 */
interface SupplierItemPricingInterface
{
    /**
     * The price of one `supplier_quotation_items` row, or **null** when it is
     * absent or `DB-01` soft-deleted — the same null-means-gone contract
     * `SupplierQuotationDirectoryInterface::find()` already uses, decided once
     * here rather than once per caller. A soft-deleted offer takes its lines
     * with it: a quotation may not be priced from an archived offer.
     *
     * §5.6's "price missing at the supplier → block save" is the caller's to
     * enforce: a `null` return and a present row whose currency is unknown (see
     * {@see SupplierItemPrice}) are both "cannot be priced", and Module 7 turns
     * either into the blocked save.
     */
    public function priceFor(string $supplierQuotationItemId): ?SupplierItemPrice;
}
