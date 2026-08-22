<?php

declare(strict_types=1);

namespace App\Modules\Admin\Domain\Money;

/**
 * One currency's rounding setting: a unit, and whether it is used at all.
 *
 * `D-52` makes the unit per-currency and configurable; `D-65` makes rounding
 * itself optional, so switching it off leaves `final_total = total_before_round`
 * and `rounding_diff = 0`. `D-06` confines it to the final total — nothing here
 * may be applied to an intermediate value.
 *
 * The unit is kept even when rounding is off. `D-65` flips a switch beside the
 * unit rather than erasing it, and a quotation captures both onto itself at
 * creation (`design/DATABASE.md` §10), the same way it captures the FX rate.
 *
 * All arithmetic is BCMath over decimal strings. `DB-07` forbids float, and
 * this is the one place in the system where the difference is visible to the
 * naked eye: `0.145 / 0.01` is `14.499999999999998` as a double, so a float
 * implementation rounds the cent *down* and quietly undercharges.
 */
final readonly class RoundingRule
{
    /** @param numeric-string $unit */
    private function __construct(
        private string $unit,
        private bool $enabled,
    ) {}

    /** Rounding on, to $unit. */
    public static function to(string $unit): self
    {
        return new self(Decimal::positive($unit, 'a rounding unit'), true);
    }

    /** Rounding off (`D-65`). The unit stays configured; only the switch moves. */
    public static function disabled(string $unit): self
    {
        return new self(Decimal::positive($unit, 'a rounding unit'), false);
    }

    /** @return numeric-string */
    public function unit(): string
    {
        return $this->unit;
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    /**
     * `§5.2`'s last two lines, and nothing else.
     *
     * Halves go up. `§5.2` writes `round(total_before_round, currency unit)`
     * and does not say which way a half falls; half-up is the conventional
     * financial reading and it is pinned by a test so that changing it is a
     * decision rather than a side effect.
     */
    public function apply(string $totalBeforeRound): RoundedTotal
    {
        $totalBeforeRound = Decimal::of($totalBeforeRound, 'a total before rounding');

        $final = $this->enabled
            ? $this->toNearestUnit($totalBeforeRound)
            : bcadd($totalBeforeRound, '0', RoundedTotal::SCALE);

        return new RoundedTotal(
            $final,
            bcsub($final, $totalBeforeRound, RoundedTotal::SCALE),
        );
    }

    /**
     * @param  numeric-string  $total
     * @return numeric-string
     */
    private function toNearestUnit(string $total): string
    {
        // Two guard digits below the money scale, so the division that decides
        // the half is itself exact enough to decide it.
        $quotient = bcdiv($total, $this->unit, RoundedTotal::SCALE + 2);

        // bcadd at scale 0 truncates toward zero, which turns "+ a half" into
        // half-up for a positive number and half-away-from-zero for a negative
        // one. Measured, not assumed: bcadd('14.5', '0.5', 0) is '15' and
        // bcadd('-2.5', '-0.5', 0) is '-3'.
        $whole = bccomp($quotient, '0', RoundedTotal::SCALE + 2) < 0
            ? bcsub($quotient, '0.5', 0)
            : bcadd($quotient, '0.5', 0);

        return bcmul($whole, $this->unit, RoundedTotal::SCALE);
    }
}
