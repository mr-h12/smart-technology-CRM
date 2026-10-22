<?php

declare(strict_types=1);

namespace App\Modules\Quotations\Domain\Pricing;

/**
 * One quotation line, priced. §5.1's four formulas, plus the display-only achieved margin (`D-148`).
 *
 * ```
 * unit_cost_base = unit_cost converted at fx_rate_at_time         (D-09, D-115)
 * margin_percent = the line's margin; if absent, the quotation's   (D-03)
 * unit_price     = unit_cost_base × (1 + margin_percent / 100),
 *                  rounded to 2 places half away from zero        (D-04, D-142)
 * line_total     = unit_price × quantity, rounded to 2 places     (D-142)
 * line_cost      = unit_cost_base × quantity
 * ```
 *
 * Pure by construction. `deptrac.layers.yaml` gives `Domain` an empty ruleset —
 * *"may depend on nothing"* — so this class reaches neither the framework, nor
 * `App\Support`, nor another module. Every value in and out is a decimal string:
 * `DB-07` forbids float anywhere near a price, and §5.6 repeats it.
 *
 * **Two roundings only**, both to 2 places half away from zero (`D-142`,
 * amending `D-06` for them): the selling unit price, so the printed price ×
 * quantity is the line; and the line amount, since a fractional quantity
 * (NUMERIC(14,4)) can carry it past the cent the PDF prints. Every other
 * figure — the cost ones included — quantizes; the final total's optional
 * rounding stays `Admin\Domain\Money\RoundingRule::apply()` (§5.2, `D-65`).
 *
 * **Also absent:** any validation that the inputs are numeric. The parameters
 * are `numeric-string` and PHPStan level 10 enforces that at every call site;
 * the runtime narrowing is `Decimal::of()` at the Application boundary, which
 * is where a request stops being text. Putting a second copy here would be the
 * duplication the waste rules refuse, and `Domain` may not import the first.
 */
final readonly class PricedLine
{
    /**
     * `D-68`'s money scale.
     *
     * Restated rather than imported, for the reason `RoundedTotal::SCALE` gives:
     * this is a Domain class and both deptrac configurations forbid it from
     * reaching `App\Support\Database\Precision`. `PricedLineTest` asserts the
     * two agree, which is the drift this would otherwise invite.
     */
    public const SCALE = 6;

    /**
     * @param  numeric-string  $unitCostBase
     * @param  numeric-string  $unitPrice
     * @param  numeric-string  $lineTotal
     * @param  numeric-string  $lineCost
     */
    private function __construct(
        private string $unitCostBase,
        private string $unitPrice,
        private string $lineTotal,
        private string $lineCost,
    ) {}

    /**
     * @param  numeric-string  $unitCostBase  the supplier's price already in the quotation's currency, converted
     *                                        by `ExchangeRate::convert()` at the rate captured on the line (§5.6, `D-115`)
     * @param  numeric-string|null  $marginPercent  the line's extra margin, added to the quotation's; null adds nothing (`D-106`)
     * @param  numeric-string  $defaultMargin  the quotation's margin
     * @param  numeric-string  $quantity
     */
    public static function from(
        string $unitCostBase,
        ?string $marginPercent,
        string $defaultMargin,
        string $quantity,
    ): self {
        // `D-106`: the line margin is an extra on the quotation's, summed and
        // applied once. Summed at the multiplier's scale, so a margin typed
        // with more than three decimals prices exactly as it did before.
        $margin = self::effectiveMargin($marginPercent, $defaultMargin, self::SCALE + 2);

        // Two guard digits below the money scale, the idiom `RoundingRule` uses:
        // `margin_percent` is NUMERIC(6,3), so `margin / 100` needs five and the
        // multiplier is exact well inside eight.
        $multiplier = bcadd('1', bcdiv($margin, '100', self::SCALE + 2), self::SCALE + 2);

        // Every product is truncated to the money scale as it is taken, never
        // rounded: BCMath truncates, PostgreSQL rounds half-up, and a value that
        // reached NUMERIC(18,6) by a different route than the engine's would
        // break Point 1.2's additive CHECKs by a millionth. The owner's ruling
        // of 2026-09-07 pins truncation; §5 does not say, so this is a decision
        // rather than a reading.
        $unitCostBase = bcadd($unitCostBase, '0', self::SCALE);
        // D-142: the selling price rounds to 2 places (bcround: half away from zero), stored at the money scale.
        $unitPrice = bcadd(bcround(bcmul($unitCostBase, $multiplier, self::SCALE), 2), '0', self::SCALE);

        // Both line figures multiply the **quantized** values above, not the
        // exact ones, because the quantized values are what the row stores. A
        // line_total computed from an unquantized unit_price would not be the
        // product of the two columns a reader can see.
        return new self(
            $unitCostBase,
            $unitPrice,
            // D-142: the line amount rounds as the unit price does, so the printed lines sum to the subtotal.
            bcadd(bcround(bcmul($unitPrice, $quantity, self::SCALE), 2), '0', self::SCALE),
            bcmul($unitCostBase, $quantity, self::SCALE),
        );
    }

    /**
     * The line's whole margin — the quotation's plus the line's extra, an empty
     * line counting as `0` (`D-106`). The one place the sum is written: the
     * price above reads it; the detail shows `achievedMargin()` instead (`D-148`).
     *
     * @param  numeric-string|null  $lineMargin
     * @param  numeric-string  $defaultMargin
     * @param  int  $scale  the pricing's own; `margin_percent`'s NUMERIC(6,3) is 3
     * @return numeric-string
     */
    public static function effectiveMargin(?string $lineMargin, string $defaultMargin, int $scale): string
    {
        return bcadd($defaultMargin, $lineMargin ?? '0', $scale);
    }

    /**
     * `D-148`: the margin the stored figures achieve, `(unit_price − unit_cost_base) / unit_cost_base × 100`,
     * three places half away from zero; null on a zero cost (the `unit_cost >= 0` CHECK allows it).
     * Display only — the pricing above keeps `effectiveMargin()`'s configured sum (`D-106`).
     *
     * @param  numeric-string  $unitPrice
     * @param  numeric-string  $unitCostBase
     * @return numeric-string|null
     */
    public static function achievedMargin(string $unitPrice, string $unitCostBase): ?string
    {
        if (bccomp($unitCostBase, '0', self::SCALE) === 0) {
            return null;
        }

        $ratio = bcdiv(bcmul(bcsub($unitPrice, $unitCostBase, self::SCALE), '100', self::SCALE), $unitCostBase, self::SCALE + 2);

        return bcadd(bcround($ratio, 3), '0', 3);
    }

    /**
     * `unit_cost × fx_rate_at_time`, in the quotation's currency (`D-09`).
     *
     * @return numeric-string
     */
    public function unitCostBase(): string
    {
        return $this->unitCostBase;
    }

    /**
     * The selling price of one unit (`D-04`).
     *
     * @return numeric-string
     */
    public function unitPrice(): string
    {
        return $this->unitPrice;
    }

    /**
     * `unit_price × quantity` — what the customer is charged for this line.
     *
     * @return numeric-string
     */
    public function lineTotal(): string
    {
        return $this->lineTotal;
    }

    /**
     * `unit_cost_base × quantity` — §5.4's input, never printed for a customer.
     *
     * @return numeric-string
     */
    public function lineCost(): string
    {
        return $this->lineCost;
    }
}
