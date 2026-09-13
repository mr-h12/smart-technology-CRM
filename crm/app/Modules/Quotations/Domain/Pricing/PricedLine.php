<?php

declare(strict_types=1);

namespace App\Modules\Quotations\Domain\Pricing;

/**
 * One quotation line, priced. §5.1's four formulas and nothing else.
 *
 * ```
 * unit_cost_base = unit_cost × fx_rate_at_time                     (D-09)
 * margin_percent = the line's margin; if absent, the quotation's   (D-03)
 * unit_price     = unit_cost_base × (1 + margin_percent / 100)     (D-04)
 * line_total     = unit_price × quantity
 * line_cost      = unit_cost_base × quantity
 * ```
 *
 * Pure by construction. `deptrac.layers.yaml` gives `Domain` an empty ruleset —
 * *"may depend on nothing"* — so this class reaches neither the framework, nor
 * `App\Support`, nor another module. Every value in and out is a decimal string:
 * `DB-07` forbids float anywhere near a price, and §5.6 repeats it.
 *
 * **Absent on purpose:** no rounding. `D-06` confines rounding to the final
 * total, and `Admin\Domain\Money\RoundingRule::apply()` already is §5.2's last
 * two lines. A line never rounds — it quantizes, which is a different act.
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
     * @param  numeric-string  $unitCost  the supplier's price, in the supplier's currency
     * @param  numeric-string  $fxRateAtTime  the rate captured on this line at creation (§5.6)
     * @param  numeric-string|null  $marginPercent  the line's own margin; null inherits (`D-03`)
     * @param  numeric-string  $defaultMargin  the quotation's margin
     * @param  numeric-string  $quantity
     */
    public static function from(
        string $unitCost,
        string $fxRateAtTime,
        ?string $marginPercent,
        string $defaultMargin,
        string $quantity,
    ): self {
        // `D-03` says a line margin is used "if empty". Empty means absent, and
        // absent means null — `0` is a margin a Team Leader can deliberately
        // set, so `??` is correct here and `?:` would silently overwrite it.
        // This is the numeric form of the distinction the drafts make with
        // `array_key_exists` rather than `??`.
        $margin = $marginPercent ?? $defaultMargin;

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
        $unitCostBase = bcmul($unitCost, $fxRateAtTime, self::SCALE);
        $unitPrice = bcmul($unitCostBase, $multiplier, self::SCALE);

        // Both line figures multiply the **quantized** values above, not the
        // exact ones, because the quantized values are what the row stores. A
        // line_total computed from an unquantized unit_price would not be the
        // product of the two columns a reader can see.
        return new self(
            $unitCostBase,
            $unitPrice,
            bcmul($unitPrice, $quantity, self::SCALE),
            bcmul($unitCostBase, $quantity, self::SCALE),
        );
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
