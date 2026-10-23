<?php

declare(strict_types=1);

namespace App\Modules\Quotations\Domain\Pricing;

/**
 * `D-165`: a `legacy` quotation as entry values, so the builder loads it and the next save
 * converts it. A taxed one (header `tax_percent` not null) becomes `tax_included` with the
 * quotation's rate on each line; an exempt one becomes `tax_exempt`. Either way each line is
 * typed at its `unit_cost_base` exactly (`D-167`; owner, 2026-10-23: tax is added, never
 * removed). The margin is `D-106`'s whole margin, `default_margin + (line ?? 0)` — the one
 * place that sum survives.
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
        return [
            'entered_unit_price' => bcadd($unitCostBase, '0', PricedLine::SCALE),
            'tax_percent' => $taxPercent,
            'margin_percent' => bcadd($defaultMargin, $lineMargin ?? '0', 3),
        ];
    }
}
