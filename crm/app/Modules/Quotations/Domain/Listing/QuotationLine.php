<?php

declare(strict_types=1);

namespace App\Modules\Quotations\Domain\Listing;

/**
 * One `quotation_items` row as `GET /{id}` reads it (Module 7 Point 3.5) —
 * every column Point 1.3 built, as the decimal text PostgreSQL returns (`DB-07`).
 *
 * The four `unit_cost*` fields, `marginPercent` and `lineCost` are §3.5's
 * "view cost & margin" — carried here unconditionally, and dropped by the
 * serialiser for a caller without that grant. The domain object is the row;
 * what a caller may see of it is the presentation's decision, made on a
 * permission the use case resolved.
 */
final readonly class QuotationLine
{
    public function __construct(
        public string $id,
        public int $lineNo,
        public string $supplierQuotationItemId,
        public string $unitCost,
        public string $unitCostCurrency,
        public string $unitCostFxRateAtTime,
        public string $unitCostBase,
        public ?string $marginPercent,
        public string $unitPrice,
        public string $quantity,
        public string $lineTotal,
        public string $lineCost,
    ) {}
}
