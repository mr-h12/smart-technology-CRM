<?php

declare(strict_types=1);

namespace App\Modules\Admin\Domain\Money;

use InvalidArgumentException;

/**
 * The one place a money value becomes a checked decimal string.
 *
 * `DB-07` forbids float anywhere near a price, which in PHP means every amount
 * travels as a string — and a string is only a number by convention until
 * something checks. Static analysis said so first: BCMath's parameters are
 * `numeric-string`, and passing a bare `string` is a level-10 error rather than
 * a nuisance. The fix is the invariant, not a suppression.
 *
 * Stricter than `is_numeric()` on purpose. That function accepts `1e5`,
 * leading whitespace and a trailing dot; a rounding unit or an FX rate that
 * arrived in exponent notation would multiply through every line of a
 * quotation without anyone reading it as odd.
 */
final class Decimal
{
    /**
     * @return numeric-string
     *
     * @throws InvalidArgumentException when $value is not a plain decimal
     */
    public static function of(string $value, string $what): string
    {
        if (preg_match('/^-?\d+(\.\d+)?$/D', $value) !== 1 || ! is_numeric($value)) {
            throw new InvalidArgumentException(
                "DB-07: {$what} must be a plain decimal string, not '{$value}'."
            );
        }

        return $value;
    }

    /**
     * @return numeric-string
     *
     * @throws InvalidArgumentException when $value is not a plain decimal above zero
     */
    public static function positive(string $value, string $what): string
    {
        $decimal = self::of($value, $what);

        if (bccomp($decimal, '0', 8) !== 1) {
            throw new InvalidArgumentException("{$what} must be greater than zero, not '{$value}'.");
        }

        return $decimal;
    }
}
