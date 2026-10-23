<?php

declare(strict_types=1);

namespace App\Modules\Quotations\Domain\Pricing;

/**
 * One quotation line, priced from what was typed. §5.1 as `D-167` restates it
 * (owner, 2026-10-23: tax is added, never removed) — the entered price is the
 * supplier's cost before tax, in both modes.
 *
 * ```
 * unit_cost  = entered, at the money scale
 * unit_price = entered × (1 + margin / 100), rounded to 2 places   (D-142)
 * line_total = unit_price × quantity, rounded to 2 places          (D-142)
 * line_cost  = entered × quantity
 * ```
 *
 * The line's tax is added on top of its discounted amount by `QuotationTotals` (`D-157`); it never
 * touches these figures. The margin is the line's own; there is no quotation margin and no FX on a
 * new line (`D-164`).
 *
 * Pure by construction. `deptrac.layers.yaml` gives `Domain` an empty ruleset —
 * *"may depend on nothing"* — so this class reaches neither the framework, nor
 * `App\Support`, nor another module. Every value in and out is a decimal string:
 * `DB-07` forbids float anywhere near a price, and §5.6 repeats it.
 *
 * **Two roundings only**, both to 2 places half away from zero (`D-142`): the selling
 * unit price, so the printed price × quantity is the line, and the line amount. Every
 * other figure quantizes (truncates); the final total's optional rounding stays
 * `Admin\Domain\Money\RoundingRule::apply()` (§5.2, `D-65`).
 *
 * The parameters are `numeric-string`; the runtime narrowing is `Decimal::of()` at the
 * Application boundary, which is where a request stops being text.
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
     * @param  numeric-string|null  $taxPercent
     */
    private function __construct(
        private string $unitCostBase,
        private string $unitPrice,
        private string $lineTotal,
        private string $lineCost,
        private ?string $taxPercent,
    ) {}

    /**
     * @param  numeric-string  $enteredUnitPrice  in the quotation's currency
     * @param  numeric-string|null  $taxPercent  the line's own rate, added on top in `QuotationTotals`; null when untaxed (`D-157`)
     * @param  numeric-string  $marginPercent  the line's margin
     * @param  numeric-string  $quantity
     */
    public static function fromEntered(
        string $enteredUnitPrice,
        ?string $taxPercent,
        string $marginPercent,
        string $quantity,
    ): self {
        // Two guard digits below the money scale, the idiom `RoundingRule` uses:
        // `margin_percent` is NUMERIC(6,3), so `margin / 100` needs five and the
        // multiplier is exact well inside eight.
        $multiplier = bcadd('1', bcdiv($marginPercent, '100', self::SCALE + 2), self::SCALE + 2);

        // Every product is truncated to the money scale as it is taken, never
        // rounded: BCMath truncates, PostgreSQL rounds half-up, and a value that
        // reached NUMERIC(18,6) by a different route than the engine's would
        // break the additive CHECKs by a millionth (owner's ruling, 2026-09-07).
        $unitCostBase = bcadd($enteredUnitPrice, '0', self::SCALE);
        // D-142: the selling price rounds to 2 places (bcround: half away from zero), stored at the money scale.
        $unitPrice = bcadd(bcround(bcmul($unitCostBase, $multiplier, self::SCALE), 2), '0', self::SCALE);

        // Both line figures multiply the **quantized** values above, not the
        // exact ones, because the quantized values are what the row stores.
        return new self(
            $unitCostBase,
            $unitPrice,
            // D-142: the line amount rounds as the unit price does, so the printed lines sum to the subtotal.
            bcadd(bcround(bcmul($unitPrice, $quantity, self::SCALE), 2), '0', self::SCALE),
            bcmul($unitCostBase, $quantity, self::SCALE),
            $taxPercent,
        );
    }

    /**
     * `D-148`: the margin the stored figures achieve, `(unit_price − unit_cost_base) / unit_cost_base × 100`,
     * three places half away from zero; null on a zero cost (the `unit_cost >= 0` CHECK allows it).
     * Display only.
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
     * The line's cost before tax, in the quotation's currency (`D-164`): the entered price at the money
     * scale (`D-167`; owner, 2026-10-23: tax is added, never removed).
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

    /**
     * The line's own rate, which `QuotationTotals` adds on top of its discounted amount (`D-157`); null when untaxed.
     *
     * @return numeric-string|null
     */
    public function taxPercent(): ?string
    {
        return $this->taxPercent;
    }
}
