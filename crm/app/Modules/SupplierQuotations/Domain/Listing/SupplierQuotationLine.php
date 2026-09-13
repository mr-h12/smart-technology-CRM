<?php

declare(strict_types=1);

namespace App\Modules\SupplierQuotations\Domain\Listing;

/**
 * One row of §7.2's `Line items` — "Product · **price** · quantity" — as a
 * reader sees it.
 *
 * Both numbers are **strings**, for `SupplierQuotationSummary`'s reason:
 * `DB-07` forbids a float anywhere near a price, and `D-68` fixes money at six
 * decimals and quantity at four. They arrive from PostgreSQL as decimal
 * strings and stay strings all the way out.
 *
 * `id` was deliberately absent until Module 7 Point 6.2: §7.2 names three
 * things on a line and nothing addressed a single line — Module 6's `PATCH`
 * replaces the set. Module 7's builder does address one: a customer
 * quotation's line is a `supplier_quotation_item_id` (Module 7 Point 3.3), and
 * the screen has to see the id of the line it picks.
 */
final readonly class SupplierQuotationLine
{
    public function __construct(
        public string $id,
        public string $catalogItemId,
        public string $unitPrice,
        public string $quantity,
    ) {}
}
