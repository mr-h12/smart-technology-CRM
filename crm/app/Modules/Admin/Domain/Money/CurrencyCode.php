<?php

declare(strict_types=1);

namespace App\Modules\Admin\Domain\Money;

/**
 * The currencies §5.3 names.
 *
 * `AP-08` puts configuration in the database, so a fourth currency is a
 * settings change rather than a deployment. What this enum fixes is the set the
 * seeded definitions describe — the three the specification actually gives
 * rounding units for.
 */
enum CurrencyCode: string
{
    case Egp = 'EGP';
    case Usd = 'USD';
    case Eur = 'EUR';
}
