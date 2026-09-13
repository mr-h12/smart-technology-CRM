<?php

declare(strict_types=1);

namespace App\Modules\Quotations\Domain\Listing;

use DateTimeImmutable;

/**
 * One row of `GET /quotations` — §6.6's columns (Q6, Step 5) — and what a
 * create hands back.
 *
 * Born with two fields at Point 1.3 on the rule that "a field with no reader
 * is the unused component the waste audit exists to catch"; Point 5.3 is the
 * reader, so the row grows to what the list shows. **Nothing from the cost
 * side**: no margin, no supplier, no unit cost — `QuotationLine::COST_FIELDS`
 * is gated per caller on the detail and has no place on a list row at all.
 *
 * `code` is `QT-YYYY-NNNN` (§4.7); money is the `Precision::CAST_MONEY`
 * string (`DB-07`); dates are `Y-m-d`, instants ISO-8601 in UTC (`DB-08`).
 */
final readonly class QuotationSummary
{
    public function __construct(
        public string $id,
        public string $code,
        public int $version,
        public string $status,
        public string $customerId,
        public string $dealId,
        public string $currencyId,
        public string $finalTotal,
        public ?string $quotationDate,
        public ?string $validUntil,
        public ?string $submittedAt,
        public ?string $parentId,
        public DateTimeImmutable $createdAt,
        public DateTimeImmutable $updatedAt,
    ) {}
}
