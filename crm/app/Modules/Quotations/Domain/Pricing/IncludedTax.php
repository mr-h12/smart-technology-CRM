<?php

declare(strict_types=1);

namespace App\Modules\Quotations\Domain\Pricing;

/**
 * `D-156`: a line's tax taken out of the entered price, `net = entered ÷ (1 + t/100)`,
 * truncated to 6 places (a division — the owner's answer 2).
 *
 * ponytail: a deliberate copy of `SupplierQuotationDraft::net` (SupplierQuotations module) —
 * Domain may depend on nothing, so it cannot call across; slice 4 deletes the original.
 */
final class IncludedTax
{
    /**
     * @param  numeric-string  $entered
     * @param  numeric-string  $taxPercent
     * @return numeric-string
     */
    public static function net(string $entered, string $taxPercent): string
    {
        // `PricedLine`'s two guard digits: a NUMERIC(6,3) rate over 100 is exact at eight.
        $divisor = bcadd('1', bcdiv($taxPercent, '100', PricedLine::SCALE + 2), PricedLine::SCALE + 2);

        return bcdiv($entered, $divisor, PricedLine::SCALE);
    }
}
