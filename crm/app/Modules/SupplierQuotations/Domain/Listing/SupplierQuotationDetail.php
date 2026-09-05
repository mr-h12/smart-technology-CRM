<?php

declare(strict_types=1);

namespace App\Modules\SupplierQuotations\Domain\Listing;

/**
 * §7.2's screen in full: the header a list already shows, plus the lines only
 * the detail view needs.
 *
 * **Composed, not re-declared.** `SupplierQuotationSummary` already carries
 * every header field, and a second class repeating those nine properties would
 * be the duplicate the waste audit exists to catch — two shapes that must be
 * changed together and eventually are not. So the header is held, not copied,
 * and `SupplierQuotationPayload::of()` serialises it here exactly as it does
 * for a create.
 *
 * The lines are not on `SupplierQuotationSummary` itself because `create()`
 * returns that type and Point 2.2's 201 does not carry them (`OpenAPI §4.1`) —
 * a field nothing serialises there would be a field with no reader.
 */
final readonly class SupplierQuotationDetail
{
    /** @param list<SupplierQuotationLine> $lines */
    public function __construct(
        public SupplierQuotationSummary $header,
        public array $lines,
    ) {}
}
