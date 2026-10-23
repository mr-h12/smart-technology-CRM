<?php

declare(strict_types=1);

namespace Tests\Feature\Quotations;

use App\Modules\Quotations\Domain\Pricing\IncludedTax;
use App\Modules\Quotations\Domain\Pricing\LegacyEntry;
use App\Modules\Quotations\Domain\Pricing\PricedLine;
use App\Modules\Quotations\Domain\Pricing\PricingMode;
use App\Modules\Quotations\Domain\Pricing\QuotationTotals;
use PHPUnit\Framework\TestCase;

/**
 * QS.2a — `D-165`: a legacy Draft converts to the new pricing at its next save,
 * so the builder loads it as entry values. A taxed legacy quotation (its header
 * `tax_percent` not null) becomes `tax_included`: each line is typed at
 * `trunc6(unit_cost_base × (1 + t/100))` with the line's tax `t`; an exempt one
 * becomes `tax_exempt` and is typed at `unit_cost_base`. Either way the line's
 * margin is `D-106`'s whole margin, `default_margin + (line margin ?? 0)` — the
 * one place that sum survives (`D-156` removes it from the pricing).
 *
 * Pure, so PHPUnit's own `TestCase`, for the reason `PricedLineTest` gives.
 */
final class LegacyEntryTest extends TestCase
{
    public function test_a_taxed_legacy_quotation_becomes_tax_included(): void
    {
        self::assertSame(PricingMode::TaxIncluded, LegacyEntry::mode('14.000'));
    }

    public function test_an_exempt_legacy_quotation_becomes_tax_exempt(): void
    {
        self::assertSame(PricingMode::TaxExempt, LegacyEntry::mode(null));
    }

    public function test_e4_a_taxed_line_is_typed_including_its_tax_with_its_whole_margin(): void
    {
        // E4: cost 100 at 14% → typed 114; margin 10 + 5 = 15.
        self::assertSame(
            ['entered_unit_price' => '114.000000', 'tax_percent' => '14.000', 'margin_percent' => '15.000'],
            LegacyEntry::line(unitCostBase: '100.000000', lineMargin: '5.000', defaultMargin: '10.000', taxPercent: '14.000'),
        );
    }

    public function test_a_taxed_line_converts_back_to_the_same_net_cost(): void
    {
        // D-165: the legacy Draft "keeps its price" — typed price ÷ (1 + t) must land on the cost, to the last place.
        $entered = LegacyEntry::line(unitCostBase: '33.333333', lineMargin: null, defaultMargin: '0', taxPercent: '14.000')['entered_unit_price'];

        self::assertSame('33.333333', IncludedTax::net($entered, '14.000'));
    }

    public function test_an_exempt_line_is_typed_at_its_cost_with_no_tax(): void
    {
        self::assertSame(
            ['entered_unit_price' => '100.000000', 'tax_percent' => null, 'margin_percent' => '15.000'],
            LegacyEntry::line(unitCostBase: '100.000000', lineMargin: '5.000', defaultMargin: '10.000', taxPercent: null),
        );
    }

    public function test_an_empty_line_margin_counts_as_zero_and_a_negative_one_lowers_the_whole(): void
    {
        // `D-106`, carried into the entry: an empty line margin adds nothing; the
        // owner's ruling of 2026-10-09 sells a line at cost with minus the quotation's.
        self::assertSame('10.000', LegacyEntry::line(unitCostBase: '100.000000', lineMargin: null, defaultMargin: '10.000', taxPercent: null)['margin_percent']);
        self::assertSame('0.000', LegacyEntry::line(unitCostBase: '100.000000', lineMargin: '-10.000', defaultMargin: '10.000', taxPercent: null)['margin_percent']);
    }

    public function test_the_typed_price_is_truncated_to_the_money_scale(): void
    {
        // 1234.567891 × 1.14 = 1407.40739574 → 1407.407396: rounded up, so `IncludedTax::net`'s truncation returns the cost (`D-165`).
        self::assertSame(
            '1407.407396',
            LegacyEntry::line(unitCostBase: '1234.567891', lineMargin: null, defaultMargin: '0.000', taxPercent: '14.000')['entered_unit_price'],
        );
    }

    public function test_e4_priced_again_from_its_entry_keeps_its_total(): void
    {
        // E4 stored today: unit 115.00, line 345.00, discount 34.50, VAT 43.47,
        // delivery 50, final 403.97. Saved through the new pricing from its
        // entry, the figures do not move (`D-165`).
        $entry = LegacyEntry::line(unitCostBase: '100.000000', lineMargin: '5.000', defaultMargin: '10.000', taxPercent: '14.000');
        $line = PricedLine::fromEntered(
            enteredUnitPrice: $entry['entered_unit_price'],
            taxPercent: $entry['tax_percent'],
            marginPercent: $entry['margin_percent'],
            quantity: '3',
        );
        $totals = QuotationTotals::from([$line], ['50'], '10');

        self::assertSame('115.000000', $line->unitPrice());
        self::assertSame('345.000000', $line->lineTotal());
        self::assertSame('34.500000', $totals->discountAmount());
        self::assertSame('43.470000', $totals->taxAmount());
        self::assertSame('403.970000', $totals->totalBeforeRound());
    }

    public function test_d142s_line_priced_again_from_its_entry_keeps_its_price(): void
    {
        // QT-2026-0135 (`D-142`): cost 26315.789474 at 14% is typed 30000.000000;
        // taken back out it is 26315.789473 — a millionth lower — and the
        // selling price is still 26315.79, the line still 263157.90.
        $entry = LegacyEntry::line(unitCostBase: '26315.789474', lineMargin: null, defaultMargin: '0.000', taxPercent: '14.000');
        $line = PricedLine::fromEntered(
            enteredUnitPrice: $entry['entered_unit_price'],
            taxPercent: $entry['tax_percent'],
            marginPercent: $entry['margin_percent'],
            quantity: '10',
        );

        // 26315.789474 × 1.14 = 30000.00000036 → rounded up, so 30000.000001 ÷ 1.14 truncates back to the cost.
        self::assertSame('30000.000001', $entry['entered_unit_price']);
        self::assertSame('26315.790000', $line->unitPrice());
        self::assertSame('263157.900000', $line->lineTotal());
    }
}
