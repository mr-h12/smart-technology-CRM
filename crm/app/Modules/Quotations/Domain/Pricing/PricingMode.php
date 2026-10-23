<?php

declare(strict_types=1);

namespace App\Modules\Quotations\Domain\Pricing;

/**
 * How a quotation was priced (`D-165`). `legacy` is every quotation saved before QS.2a: a
 * quotation margin and quotation tax rates, never re-priced. A new save is `tax_included`
 * (the typed price carries each line's tax, `D-157`) or `tax_exempt` (the typed price is
 * used as is, no tax line at all, `D-63`).
 */
enum PricingMode: string
{
    case Legacy = 'legacy';
    case TaxIncluded = 'tax_included';
    case TaxExempt = 'tax_exempt';
}
