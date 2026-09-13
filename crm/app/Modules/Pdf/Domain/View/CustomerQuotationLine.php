<?php

declare(strict_types=1);

namespace App\Modules\Pdf\Domain\View;

/**
 * One quotation line as the customer's PDF may show it.
 *
 * This is `QuotationLine` (Module 7 Point 3.5) with six fields **removed
 * rather than hidden**: the four `unit_cost*` columns, `margin_percent` and
 * `line_cost` — §3.5's "view cost & margin", which that class names as
 * `COST_FIELDS` — plus `supplierQuotationItemId`, the link to which supplier
 * quoted the part.
 *
 * The difference between removed and hidden is the whole reason this class
 * exists. `QuotationLine` carries the cost fields unconditionally and lets the
 * serialiser drop them for a caller without the grant, which is right for an
 * API whose caller may hold that grant. A customer PDF has no such caller:
 * §3.12 rule 2 and §14.6 exclude supplier names and prices **unconditionally**,
 * and Module 9's acceptance criterion asks for a model that *"structurally
 * cannot contain supplier, cost, or margin fields"*. A field that is absent
 * cannot be printed by a template written in a hurry, and cannot be exposed by
 * a serialiser configured wrongly.
 *
 * `unitPrice` is the customer's price, not the cost it was derived from. That
 * distinction is why `P-01` renamed PO #226's "UNIT COST" column to
 * "UNIT PRICE": the reference is a purchase order to a supplier, where cost is
 * the correct word, and this document is not.
 */
final readonly class CustomerQuotationLine
{
    public function __construct(
        public int $lineNo,
        public string $description,
        public string $quantity,
        public string $unitPrice,
        public string $lineTotal,
    ) {}
}
