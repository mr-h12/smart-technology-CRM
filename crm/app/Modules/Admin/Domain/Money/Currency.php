<?php

declare(strict_types=1);

namespace App\Modules\Admin\Domain\Money;

/**
 * A currency the system supports, with the two things `§5.3` gives it: a
 * rounding unit and whether rounding is on (`D-52`, `D-65`).
 *
 * There is deliberately no exchange rate here. A rate belongs to a moment and
 * has history (`§13` screen 5); a currency does not.
 *
 * ── `$id` is optional, because a currency has two sources ──────────────────
 *
 * A currency read from `currencies` carries the table's UUID; the canonical
 * definitions in {@see Currencies} are code-first and have none — they describe
 * the set §5.3 names, not rows. So `$id` defaults to null, and `id()` answers
 * null for a code-defined currency. A caller that needs the stored id — Module
 * 7 captures `quotations.currency_id` from the currency the request's code
 * names — reads it from a repository result, where it is always present.
 */
final readonly class Currency
{
    public function __construct(
        private CurrencyCode $code,
        private RoundingRule $rounding,
        private bool $isBase,
        private ?string $id = null,
    ) {}

    /** The `currencies` row id, or null for a code-defined currency ({@see Currencies}). */
    public function id(): ?string
    {
        return $this->id;
    }

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
