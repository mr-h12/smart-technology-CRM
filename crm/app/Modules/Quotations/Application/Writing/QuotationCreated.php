<?php

declare(strict_types=1);

namespace App\Modules\Quotations\Application\Writing;

use App\Modules\Quotations\Domain\Listing\QuotationSummary;

/**
 * What `CreateQuotation` returns: the quotation, and the non-blocking warnings
 * the create produced.
 *
 * §5.6 draws two lines the same read discovers: a price that is missing **blocks**
 * (that is {@see \App\Modules\Quotations\Domain\Pricing\QuotationNotPriceable},
 * thrown), while a requested quantity that exceeds the supplier's recorded amount
 * **warns and never blocks**. A warning is not a stored fact — nothing on
 * `quotations` records it — so it travels back to `POST /quotations` (Point 3.4)
 * here, as the 1-based line numbers that over-ran, matching `quotation_items.line_no`.
 * The endpoint renders them as the "inline red warning" §5.6 asks for.
 */
final readonly class QuotationCreated
{
    /** @param  list<int>  $quantityWarnings  1-based line numbers whose quantity exceeds the supplier's recorded amount */
    public function __construct(
        public QuotationSummary $quotation,
        public array $quantityWarnings,
    ) {}
}
