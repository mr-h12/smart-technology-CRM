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
 * `id` is deliberately absent. §7.2 names three things on a line and nothing
 * addresses a single line yet — `PATCH` is Point 2.4, and that is the point
 * that gets to decide whether it edits lines by identity or replaces the set.
 * A field with no caller is the "unused component" the waste audit names.
 */
final readonly class SupplierQuotationLine
{
    public function __construct(
        public string $catalogItemId,
        public string $unitPrice,
        public string $quantity,
    ) {}
}
