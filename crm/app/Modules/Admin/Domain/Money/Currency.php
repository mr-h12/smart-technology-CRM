<?php

declare(strict_types=1);

namespace App\Modules\Admin\Domain\Money;

/**
 * A currency the system supports, with the two things `§5.3` gives it: a
 * rounding unit and whether rounding is on (`D-52`, `D-65`).
 *
 * There is deliberately no exchange rate here. A rate belongs to a moment and
 * has history (`§13` screen 5); a currency does not.
 */
final readonly class Currency
{
    public function __construct(
        private CurrencyCode $code,
        private RoundingRule $rounding,
        private bool $isBase,
    ) {}

    public function code(): CurrencyCode
    {
        return $this->code;
    }

    public function rounding(): RoundingRule
    {
        return $this->rounding;
    }

    /** The currency `base_amount` is expressed in (`DB-06`, `§13` screen 5). */
    public function isBase(): bool
    {
        return $this->isBase;
    }
}
