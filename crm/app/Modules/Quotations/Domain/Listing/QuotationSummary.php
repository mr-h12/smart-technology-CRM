<?php

declare(strict_types=1);

namespace App\Modules\Quotations\Domain\Listing;

/**
 * What a create hands back — `SupplierQuotationSummary`'s shape (Module 6 Point
 * 1.3), and deliberately two fields where that one carries nine.
 *
 * The rule is that summary's own: "a field with no reader is the unused
 * component the waste audit exists to catch". §10's header is twenty-odd
 * columns and **nothing reads them yet** — `GET /{quotation_id}` and the
 * serialiser that shapes it are Step 3. What a caller of `create()` cannot do
 * without is the row it just made and the number that was allocated for it, so
 * that is what this carries. Adding a field is additive and cheap; carrying
 * twenty that no test asserts is not.
 *
 * Both are strings, and `code` is `QT-YYYY-NNNN` (§4.7).
 */
final readonly class QuotationSummary
{
    public function __construct(
        public string $id,
        public string $code,
        public int $version,
    ) {}
}
