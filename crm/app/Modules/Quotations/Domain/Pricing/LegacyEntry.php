<?php

declare(strict_types=1);

namespace App\Modules\Quotations\Domain\Pricing;

/**
 * `D-165`: a `legacy` quotation as entry values, so the builder loads it and the next save
 * converts it. A taxed one (header `tax_percent` not null) becomes `tax_included`, each line
 * typed at `trunc6(unit_cost_base × (1 + t/100))`; an exempt one becomes `tax_exempt`, typed
 * at `unit_cost_base`. The margin is `D-106`'s whole margin, `default_margin + (line ?? 0)` —
 * the one place that sum survives.
 */
final class LegacyEntry
{
    /** @param  numeric-string|null  $taxPercent */
    public static function mode(?string $taxPercent): PricingMode
    {
        return $taxPercent === null ? PricingMode::TaxExempt : PricingMode::TaxIncluded;
    }

    /**
     * @param  numeric-string  $unitCostBase
     * @param  numeric-string|null  $lineMargin
     * @param  numeric-string  $defaultMargin
     * @param  numeric-string|null  $taxPercent
     * @return array{entered_unit_price: numeric-string, tax_percent: numeric-string|null, margin_percent: numeric-string}
     */
    public static function line(string $unitCostBase, ?string $lineMargin, string $defaultMargin, ?string $taxPercent): array
    {
        $scale = PricedLine::SCALE;

        return [
            'entered_unit_price' => $taxPercent === null ? bcadd($unitCostBase, '0', $scale) : self::grossCeil($unitCostBase, $taxPercent),
            'tax_percent' => $taxPercent,
            'margin_percent' => bcadd($defaultMargin, $lineMargin ?? '0', 3),
        ];
    }

    /**
     * `cost × (1 + t/100)` rounded **up** to 6 places: `IncludedTax::net` truncates, so a truncated
     * gross would come back one place short (33.333333 at 14 % → 37.999999 → 33.333332) and the
     * converted Draft would lose its price (`D-165`). The ceiling lands the quotient on the cost.
     *
     * @param  numeric-string  $cost
     * @param  numeric-string  $taxPercent
     * @return numeric-string
     */
    private static function grossCeil(string $cost, string $taxPercent): string
    {
        $scale = PricedLine::SCALE;
        $exact = bcmul($cost, bcadd('1', bcdiv($taxPercent, '100', $scale + 2), $scale + 2), $scale * 2 + 2);
        $down = bcadd($exact, '0', $scale);

        return bccomp($down, $exact, $scale * 2 + 2) < 0 ? bcadd($down, '0.000001', $scale) : $down;
    }
}
