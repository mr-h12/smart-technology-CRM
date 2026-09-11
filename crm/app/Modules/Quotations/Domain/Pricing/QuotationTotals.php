<?php

declare(strict_types=1);

namespace App\Modules\Quotations\Domain\Pricing;

/**
 * A quotation's totals, as far as the tax base. §5.2's first four lines.
 *
 * ```
 * subtotal           = Σ line_total
 * additional_total   = Σ additional items (delivery / installation)
 * discount_amount    = subtotal × discount_percent / 100        (D-07)
 * tax_base           = subtotal − discount_amount               (D-64)
 * tax_amount         = tax_base × tax_percent / 100             (D-63)
 * net_amount         = subtotal + additional_total − discount_amount
 * total_before_round = net_amount + tax_amount
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
 * **An exempt quotation has no tax line at all** (`D-63`) — `tax_amount` is
 * `null`, never `'0'`. Point 1.1's `quotations_tax_percent_not_zero` refuses a
 * zero percent outright and Point 1.2's `quotations_tax_amount_matches_tax_percent`
 * refuses the mixed pair, so a zero here would be unstorable as well as wrong:
 * §16's PDF decides whether to print the line by whether the amount exists.
 *
 * Note `additional_total` rejoins the arithmetic in `net_amount` after sitting
 * out of `tax_base`. It is excluded from the tax, not from the quotation — the
 * distinction CLAUDE.md's summary of `D-62` leaves open and §5.2 line 501
 * settles.
 *
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
     * @param  numeric-string|null  $taxAmount
     * @param  numeric-string  $netAmount
     * @param  numeric-string  $totalBeforeRound
     */
    private function __construct(
        private string $subtotal,
        private string $additionalTotal,
        private string $discountAmount,
        private string $taxBase,
        private ?string $taxAmount,
        private string $netAmount,
        private string $totalBeforeRound,
    ) {}

    /**
     * @param  list<PricedLine>  $lines
     * @param  list<numeric-string>  $additionalAmounts  each already in the quotation's currency
     * @param  numeric-string  $discountPercent  NUMERIC(6,3), and `< 100` by Point 1.1's CHECK
     * @param  numeric-string|null  $taxPercent  null when the quotation is exempt (`D-63`)
     *
     * No default on `$taxPercent`. Defaulting it to null would make a caller
     * that simply forgot the argument produce a silently untaxed quotation —
     * the failure would be a missing tax line on a customer's PDF, not an
     * error. Every caller states its tax intent.
     */
    public static function from(
        array $lines,
        array $additionalAmounts,
        string $discountPercent,
        ?string $taxPercent,
    ): self {
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

        // `D-64`. Both operands are already quantized, so this subtraction is
        // exact and Point 1.2's `tax_base = subtotal - discount_amount` holds on
        // the values the row will actually store.
        $taxBase = bcsub($subtotal, $discountAmount, $scale);

        // `D-63`: no percent means no tax line, not a zero one.
        $taxAmount = $taxPercent === null
            ? null
            : bcdiv(bcmul($taxBase, $taxPercent, $scale + 3), '100', $scale);

        // §5.2 line 501 — revenue excluding tax. The additional items rejoin
        // here, which is the half of `D-62` that is easy to miss.
        $netAmount = bcsub(bcadd($subtotal, $additionalTotal, $scale), $discountAmount, $scale);

        return new self(
            $subtotal,
            $additionalTotal,
            $discountAmount,
            $taxBase,
            $taxAmount,
            $netAmount,
            // Point 1.2 writes this one with `COALESCE(tax_amount, 0)`, so an
            // exempt quotation's total is its net amount unchanged.
            bcadd($netAmount, $taxAmount ?? '0', $scale),
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

    /**
     * `tax_base × tax_percent / 100`, or **null** when the quotation is exempt.
     *
     * Null and not `'0'` (`D-63`). `§16` prints a tax line only when there is
     * an amount, and Point 1.2's CHECK pairs this with `tax_percent` so the two
     * are null together or neither is.
     *
     * @return numeric-string|null
     */
    public function taxAmount(): ?string
    {
        return $this->taxAmount;
    }

    /**
     * `subtotal + additional_total − discount_amount` — revenue excluding tax,
     * and §5.4's input once profit has somewhere to live.
     *
     * @return numeric-string
     */
    public function netAmount(): string
    {
        return $this->netAmount;
    }

    /**
     * What `RoundingRule::apply()` is handed. The last thing this class knows.
     *
     * @return numeric-string
     */
    public function totalBeforeRound(): string
    {
        return $this->totalBeforeRound;
    }
}
