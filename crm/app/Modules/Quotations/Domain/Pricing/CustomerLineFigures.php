<?php

declare(strict_types=1);

namespace App\Modules\Quotations\Domain\Pricing;

/**
 * F-40 · 1.4 — the per-line figures `D-107`'s Commercial Offer prints, beside
 * {@see QuotationTotals} and on its terms: BCMath at {@see PricedLine::SCALE},
 * multiply at three extra places, divide back to the scale. The PDF rounds to
 * two places only when it prints (`D-99`).
 *
 * `D-107` ruling (1): the discount is spread over the lines before VAT, so
 * line VAT = line total × (1 − discount/100) × tax/100 and the line's *Total*
 * = the discounted line + its VAT. The VAT row stays §5.2's `tax_amount`; a
 * cents difference between the column's sum and that row is accepted, not
 * corrected. Published to Pdf through `QuotationsContract` (deptrac).
 */
final class CustomerLineFigures
{
    /**
     * `D-68`'s money scale. Restated rather than imported, for the reason
     * {@see PricedLine::SCALE} gives: this class is published to Pdf through
     * `QuotationsContract`, and deptrac forbids a contract from reaching
     * `PricedLine`. `CustomerLineFiguresTest` asserts the two agree.
     */
    public const SCALE = 6;

    /**
     * `D-63`: an exempt quotation (`taxPercent` null) has no VAT — not a zero.
     *
     * @param  numeric-string  $lineTotal
     * @param  numeric-string  $discountPercent
     * @param  numeric-string|null  $taxPercent
     * @return numeric-string|null
     */
    public static function vat(string $lineTotal, string $discountPercent, ?string $taxPercent): ?string
    {
        if ($taxPercent === null) {
            return null;
        }

        $scale = self::SCALE;

        return bcdiv(bcmul(self::discounted($lineTotal, $discountPercent), $taxPercent, $scale + 3), '100', $scale);
    }

    /**
     * @param  numeric-string  $lineTotal
     * @param  numeric-string  $discountPercent
     * @param  numeric-string|null  $taxPercent
     * @return numeric-string
     */
    public static function total(string $lineTotal, string $discountPercent, ?string $taxPercent): string
    {
        return bcadd(
            self::discounted($lineTotal, $discountPercent),
            self::vat($lineTotal, $discountPercent, $taxPercent) ?? '0',
            self::SCALE,
        );
    }

    /**
     * `D-107`'s *Total Amount Excl. VAT*: the items plus the additional items (`D-62`, never taxed).
     *
     * @param  numeric-string  $subtotal
     * @param  numeric-string  $additionalTotal
     * @return numeric-string
     */
    public static function excludingVat(string $subtotal, string $additionalTotal): string
    {
        return bcadd($subtotal, $additionalTotal, self::SCALE);
    }

    /**
     * @param  numeric-string  $lineTotal
     * @param  numeric-string  $discountPercent
     * @return numeric-string
     */
    private static function discounted(string $lineTotal, string $discountPercent): string
    {
        // ponytail: the discounted line is cut to scale 6 before VAT is taken on
        // it, so a line can differ from D-107's exact product by up to 1e-6 —
        // invisible at the 2 places the PDF prints, and QuotationTotals' recipe.
        // Carry scale + 3 through `vat()` if a figure is ever compared unrounded.
        $scale = self::SCALE;

        return bcdiv(bcmul($lineTotal, bcsub('100', $discountPercent, $scale + 3), $scale + 3), '100', $scale);
    }
}
