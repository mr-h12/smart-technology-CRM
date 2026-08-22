<?php

declare(strict_types=1);

namespace App\Modules\Admin\Domain\Money;

/**
 * The two columns §5.2 produces at the end of a quotation: `final_total` and
 * `rounding_diff`.
 *
 * They travel together because the second is only meaningful beside the first,
 * and because `design/DATABASE.md` writes a constraint over the pair:
 * `CHECK (rounding_enabled OR rounding_diff = 0)`.
 *
 * Both are decimal strings at `D-68`'s money scale. Never a PHP number:
 * `DB-07` forbids float anywhere near a price, and an int cannot hold six
 * decimals.
 */
final readonly class RoundedTotal
{
    /**
     * `D-68`'s money scale.
     *
     * Restated rather than imported: this is a Domain class, and both
     * `deptrac` configurations forbid Domain from reaching outside itself —
     * `App\Support\Database\Precision` included. `CurrencyMatrixDataTest`
     * asserts the two agree, which is the drift this would otherwise invite.
     */
    public const SCALE = 6;

    /**
     * @param  numeric-string  $finalTotal
     * @param  numeric-string  $roundingDiff
     */
    public function __construct(
        private string $finalTotal,
        private string $roundingDiff,
    ) {}

    /** @return numeric-string */
    public function finalTotal(): string
    {
        return $this->finalTotal;
    }

    /** `final_total − total_before_round`. Zero whenever rounding is off (`D-65`). */
    /** @return numeric-string */
    public function roundingDiff(): string
    {
        return $this->roundingDiff;
    }
}
