<?php

declare(strict_types=1);

namespace App\Support\Http;

use App\Support\Database\Precision;
use Closure;

/**
 * `D-118`: the tax rates a customer quotation and a tax-inclusive supplier
 * offer carry — typed by hand, at most five, each > 0, added on one base.
 * One place for both boundaries and both sums (F-48 · 2), so they cannot drift.
 */
final class TaxRates
{
    /** A rate as decimal text, the only form `bcadd` takes (`DB-07`). */
    private const DECIMAL = '/^\d+(\.\d+)?$/';

    /** @return list<mixed> at most five, and their sum within the NUMERIC(6,3) that stores it */
    public static function listRules(): array
    {
        return ['sometimes', 'array', 'max:5', static function (string $attribute, mixed $rates, Closure $fail): void {
            /** @var list<numeric-string> $valid decimal text, which is numeric */
            $valid = is_array($rates)
                ? array_values(array_filter($rates, static fn (mixed $rate): bool => is_string($rate) && preg_match(self::DECIMAL, $rate) === 1))
                : [];

            if (bccomp(self::sum($valid) ?? '0', '1000', Precision::PERCENT_SCALE) >= 0) {
                $fail(__('validation.lt.numeric', ['value' => '1000']));
            }
        }];
    }

    /**
     * One rate, at the percent scale: a finer one would be cut by `bcadd`,
     * `0.0004` to a zero the CHECK refuses with a 500.
     *
     * @return list<string>
     */
    public static function rateRules(): array
    {
        return ['required', 'string', 'regex:'.self::DECIMAL, 'numeric', 'gt:0', 'regex:/^\d+(\.\d{1,3})?$/'];
    }

    /**
     * @param  list<numeric-string>  $rates
     * @return numeric-string|null none is no tax (`D-63`)
     */
    public static function sum(array $rates): ?string
    {
        return $rates === []
            ? null
            : array_reduce($rates, static fn (string $sum, string $rate): string => bcadd($sum, $rate, Precision::PERCENT_SCALE), '0');
    }
}
