<?php

declare(strict_types=1);

namespace App\Modules\Quotations\Domain\Pricing;

/**
 * A quotation's totals, as far as the tax base. §5.2's first four lines.
 *
 * ```
 * subtotal         = Σ line_total
 * additional_total = Σ additional items (delivery / installation)
 * discount_amount  = subtotal × discount_percent / 100          (D-07)
 * tax_base         = subtotal − discount_amount                 (D-64)
 * ```
 *
 * **The discount comes off before the tax**, which is the whole of `D-64`: it
 * reduces the base the tax is charged on. The company's own PO #226 taxes the
 * pre-discount amount and prints `8,326.32` where §5.2 produces `8,316`; `D-64`
 * records that `10.32` as accepted and settles the ordering in this direction.
 *
 * **Additional items are summed here and then left out of `tax_base`** (`D-62`,
 * `OD-01`, reconfirmed 2026-08-19). They rejoin the arithmetic in `net_amount`,
 * which Point 2.3 adds — excluded from the tax, not from the quotation. This is
 * the one exclusion Point 1.2's CHECK cannot police, because
 * `tax_base = subtotal - discount_amount` has no `additional_total` term to
 * constrain: a delivery charge wrongly taxed would satisfy every constraint the
 * database has. Only the test below catches it.
 *
 * Pure by construction, for the reason `PricedLine` gives.
 *
 * **Absent until Point 2.3:** `tax_amount`, `net_amount`, `total_before_round`.
 * **Absent permanently:** `final_total` and `rounding_diff` — `D-06` confines
 * rounding to the final total and `Admin\Domain\Money\RoundingRule::apply()`
 * already is §5.2's last two lines. Nothing consumes this object before Step 3,
 * so growing it a point at a time costs nothing.
 */
final readonly class QuotationTotals
{
    /**
     * @param  numeric-string  $subtotal
     * @param  numeric-string  $additionalTotal
     * @param  numeric-string  $discountAmount
     * @param  numeric-string  $taxBase
     */
    private function __construct(
        private string $subtotal,
        private string $additionalTotal,
        private string $discountAmount,
        private string $taxBase,
    ) {}

    /**
     * @param  list<PricedLine>  $lines
     * @param  list<numeric-string>  $additionalAmounts  each already in the quotation's currency
     * @param  numeric-string  $discountPercent  NUMERIC(6,3), and `< 100` by Point 1.1's CHECK
     */
    public static function from(array $lines, array $additionalAmounts, string $discountPercent): self
    {
        // `PricedLine::SCALE` rather than a second copy of the same number: it
        // is already `D-68`'s money scale, already documented, and already
        // pinned against `Precision::MONEY_SCALE` by a test. Two literals here
        // would be the drift that test exists to prevent.
        $scale = PricedLine::SCALE;

        // Every addend is already at scale 6, so these sums are exact and the
        // scale argument only fixes the string's shape. Both accumulators start
        // as a *scaled* zero — `bcadd('0', '0', 6)` is `'0.000000'`, the idiom
        // `RoundingRule` uses to normalise — because a quotation with no lines
        // must still produce a value the NUMERIC(18,6) column can hold, and a
        // bare `'0'` would be the one total in the system carrying no scale.
        $subtotal = bcadd('0', '0', $scale);
        foreach ($lines as $line) {
            $subtotal = bcadd($subtotal, $line->lineTotal(), $scale);
        }

        $additionalTotal = bcadd('0', '0', $scale);
        foreach ($additionalAmounts as $amount) {
            $additionalTotal = bcadd($additionalTotal, $amount, $scale);
        }

        // NUMERIC(18,6) × NUMERIC(6,3) is exact at scale 9, so the product is
        // taken there and only the division truncates to the money scale —
        // the owner's ruling of 2026-09-07, and the same truncation
        // `PricedLine` applies to every product it takes.
        $discountAmount = bcdiv(bcmul($subtotal, $discountPercent, $scale + 3), '100', $scale);

        return new self(
            $subtotal,
            $additionalTotal,
            $discountAmount,
            // `D-64`. Both operands are already quantized, so this subtraction
            // is exact and Point 1.2's `tax_base = subtotal - discount_amount`
            // holds on the values the row will actually store.
            bcsub($subtotal, $discountAmount, $scale),
        );
    }

    /**
     * `Σ line_total` — the lines alone, before anything is added or taken off.
     *
     * @return numeric-string
     */
    public function subtotal(): string
    {
        return $this->subtotal;
    }

    /**
     * `Σ` the delivery and installation items. Never taxed (`D-62`).
     *
     * @return numeric-string
     */
    public function additionalTotal(): string
    {
        return $this->additionalTotal;
    }

    /**
     * `subtotal × discount_percent / 100` (`D-07`) — a share of the lines only.
     *
     * @return numeric-string
     */
    public function discountAmount(): string
    {
        return $this->discountAmount;
    }

    /**
     * What the tax is charged on: the lines, less the discount (`D-64`).
     *
     * @return numeric-string
     */
    public function taxBase(): string
    {
        return $this->taxBase;
    }
}
