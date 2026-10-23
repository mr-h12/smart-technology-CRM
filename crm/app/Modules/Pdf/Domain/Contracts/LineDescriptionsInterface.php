<?php

declare(strict_types=1);

namespace App\Modules\Pdf\Domain\Contracts;

/**
 * What each quotation line *is*, in words the customer may read.
 *
 * ── Why the PDF has to ask ─────────────────────────────────────────────────
 *
 * Module 7's `QuotationLine` carries no description: a line names its catalog
 * item (`catalogItemId`, QS.1 · `D-155`) plus its figures. The words live in
 * Catalog, which is Yousef's. A customer document cannot print a line without
 * saying what it is, so the PDF names the fact it needs here and does not
 * reach for it.
 *
 * ── Why a port in `Pdf` rather than a call into his modules ───────────────
 *
 * The per-module rule: nothing is written inside a module this developer does
 * not own. The facts live in Catalog, so `Pdf` reads them through the contract
 * its owner published (F-16) and answers here in
 * `Infrastructure\CatalogLineDescriptions` (Point 3.2) — never a stub that
 * answers blanks, which would be the silent gap this module exists to refuse.
 *
 * ── What an implementation must never return ──────────────────────────────
 *
 * The catalog item's customer-facing description, and only that. Not the
 * supplier's own wording of the part, not the supplier's name, not a price:
 * the id going in is a catalog item's, and no supplier identity may come back
 * out with the answer (§3.12 rule 2, §14.6).
 */
interface LineDescriptionsInterface
{
    /**
     * Catalog item id => description, one read for the whole quotation. An id
     * the lookup cannot describe has **no entry**; the caller decides what
     * that means, and the mapper refuses the view.
     *
     * @param  list<string>  $catalogItemIds
     * @return array<string, string>
     */
    public function descriptionsOf(array $catalogItemIds): array;

    /**
     * F-40 · 1.4 (`D-107`): catalog item id => its unit in both languages.
     * No unit, no entry — the PDF prints `—`.
     *
     * @param  list<string>  $catalogItemIds
     * @return array<string, array{en: string, ar: string}>
     */
    public function unitsOf(array $catalogItemIds): array;
}
