<?php

declare(strict_types=1);

namespace App\Modules\Pdf\Domain\Contracts;

/**
 * What each quotation line *is*, in words the customer may read.
 *
 * ── Why the PDF has to ask ─────────────────────────────────────────────────
 *
 * Module 7's `QuotationLine` carries no description: a line is a
 * `supplierQuotationItemId` plus its figures, and the quotation detail screen
 * shows a line number and nothing else. The words live two modules away —
 * `supplier_quotation_items.catalog_item_id` → Catalog — and both of those are
 * Yousef's. A customer document cannot print a line without saying what it is,
 * so the PDF names the fact it needs here and does not reach for it.
 *
 * ── Why a port in `Pdf` rather than a call into his modules ───────────────
 *
 * The per-module rule: nothing is written inside a module this developer does
 * not own. The lookup that answers this belongs to SupplierQuotations or
 * Catalog, and is requested from their owner. Until it is on `main` this
 * interface has **no implementation and no binding**, deliberately — nothing
 * resolves the mapper before Step 3's endpoint, and a stub answering blanks
 * would be the silent gap this module exists to refuse.
 *
 * ── What an implementation must never return ──────────────────────────────
 *
 * The catalog item's customer-facing description, and only that. Not the
 * supplier's own wording of the part, not the supplier's name, not a price:
 * the id going in is a supplier-quotation item's, and it is the easiest place
 * in the whole module for a supplier's identity to leak back out (§3.12 rule
 * 2, §14.6).
 */
interface LineDescriptionsInterface
{
    /**
     * Supplier-quotation item id => description, one read for the whole
     * quotation. An id the lookup cannot describe has **no entry**; the caller
     * decides what that means, and the mapper refuses the view.
     *
     * @param  list<string>  $supplierQuotationItemIds
     * @return array<string, string>
     */
    public function descriptionsOf(array $supplierQuotationItemIds): array;
}
