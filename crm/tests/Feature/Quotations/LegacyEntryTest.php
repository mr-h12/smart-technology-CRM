<?php

declare(strict_types=1);

namespace Tests\Feature\Quotations;

use App\Modules\Quotations\Domain\Pricing\LegacyEntry;
use App\Modules\Quotations\Domain\Pricing\PricedLine;
use App\Modules\Quotations\Domain\Pricing\PricingMode;
use App\Modules\Quotations\Domain\Pricing\QuotationTotals;
use PHPUnit\Framework\TestCase;

/**
 * QS.2a — `D-165`: a legacy Draft converts to the new pricing at its next save,
 * so the builder loads it as entry values. A taxed legacy quotation (its header
 * `tax_percent` not null) becomes `tax_included` with the quotation's rate on
 * each line; an exempt one becomes `tax_exempt`. Either way each line is typed
 * at its `unit_cost_base` exactly — the entered price is the cost before tax
 * (`D-167`; owner, 2026-10-23: tax is added, never removed) — and its margin is
 * `D-106`'s whole margin, `default_margin + (line margin ?? 0)` — the one place
 * that sum survives (`D-156` removes it from the pricing).
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

    public function test_e4_a_taxed_line_is_typed_at_its_cost_with_its_whole_margin(): void
    {
        // E4: cost 100 at 14% → typed 100, the 14% beside it; margin 10 + 5 = 15.
        self::assertSame(
            ['entered_unit_price' => '100.000000', 'tax_percent' => '14.000', 'margin_percent' => '15.000'],
            LegacyEntry::line(unitCostBase: '100.000000', lineMargin: '5.000', defaultMargin: '10.000', taxPercent: '14.000'),
        );
    }

    public function test_a_taxed_line_converts_to_the_same_cost(): void
    {
        // D-165: the legacy Draft "keeps its price" — the typed price is the cost, to the last place.
        $entered = LegacyEntry::line(unitCostBase: '33.333333', lineMargin: null, defaultMargin: '0', taxPercent: '14.000')['entered_unit_price'];

        self::assertSame('33.333333', $entered);
        self::assertSame('33.333333', PricedLine::fromEntered($entered, '14.000', '0', '1')->unitCostBase());
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

    public function test_the_typed_price_is_the_cost_at_the_money_scale(): void
    {
        // `D-167`: no gross figure to round — a taxed line is typed at its cost (`D-165`).
        self::assertSame(
            '1234.567891',
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
        // QT-2026-0135 (`D-142`): cost 26315.789474 at 14% is entered as the cost,
        // 26315.789474, so the selling price is still 26315.79, the line still 263157.90.
        $entry = LegacyEntry::line(unitCostBase: '26315.789474', lineMargin: null, defaultMargin: '0.000', taxPercent: '14.000');
        $line = PricedLine::fromEntered(
            enteredUnitPrice: $entry['entered_unit_price'],
            taxPercent: $entry['tax_percent'],
            marginPercent: $entry['margin_percent'],
            quantity: '10',
        );

        self::assertSame('26315.789474', $entry['entered_unit_price']);
        self::assertSame('26315.790000', $line->unitPrice());
        self::assertSame('263157.900000', $line->lineTotal());
    }
}
