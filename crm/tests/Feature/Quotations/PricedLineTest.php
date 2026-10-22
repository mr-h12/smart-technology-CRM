<?php

declare(strict_types=1);

namespace Tests\Feature\Quotations;

use App\Modules\Admin\Domain\Money\CurrencyCode;
use App\Modules\Admin\Domain\Money\ExchangeRate;
use App\Modules\Quotations\Domain\Pricing\PricedLine;
use App\Support\Database\Precision;
use PHPUnit\Framework\TestCase;

/**
 * Module 7, Point 2.1 — §5.1's four formulas.
 *
 * Extends PHPUnit's own `TestCase` rather than `Tests\TestCase`: `PricedLine`
 * is a pure Domain class that touches no database and boots no application,
 * the same shape `CurrencyMatrixDataTest` already tests the money classes in.
 *
 * The build plan's first three acceptance rows for Module 7 are the first three
 * tests here, named after the rows so `CHECKLIST.md` and this file can be read
 * against each other.
 */
final class PricedLineTest extends TestCase
{
    // ───────────────────────────────── the acceptance rows

    public function test_a_cost_of_a_thousand_at_twenty_percent_prices_at_twelve_hundred(): void
    {
        // Acceptance row 1, and `D-04` itself.
        $line = PricedLine::from(
            unitCostBase: '1000',
            marginPercent: null,
            defaultMargin: '20.000',
            quantity: '1',
        );

        self::assertSame('1200.000000', $line->unitPrice());
        self::assertSame('1000.000000', $line->unitCostBase());
    }

    public function test_a_line_margin_is_added_to_the_quotation_margin(): void
    {
        // Acceptance row 2, as `D-106` amends it: the line margin is an extra
        // on top of the quotation's, summed and applied once — 20 + 10 → 30%.
        $line = PricedLine::from(
            unitCostBase: '1000',
            marginPercent: '10.000',
            defaultMargin: '20.000',
            quantity: '1',
        );

        self::assertSame('1300.000000', $line->unitPrice());
    }

    public function test_a_supplier_price_converts_at_the_rate_captured_on_the_line(): void
    {
        // Acceptance row 3. The rate is the one stored on the line, never a
        // rate looked up now — §5.6 and `D-09`. Editing the FX table later
        // cannot reach this calculation because the number arrives as an
        // argument, not as a lookup.
        $line = PricedLine::from(
            unitCostBase: ExchangeRate::of(CurrencyCode::Usd, CurrencyCode::Egp, '48.5')->convert('100', PricedLine::SCALE),
            marginPercent: null,
            defaultMargin: '20.000',
            quantity: '2',
        );

        self::assertSame('4850.000000', $line->unitCostBase());
        self::assertSame('5820.000000', $line->unitPrice());
        self::assertSame('11640.000000', $line->lineTotal());
        self::assertSame('9700.000000', $line->lineCost());
    }

    // ───────────────────────────────── what "if empty" means (D-03, D-106)

    public function test_an_absent_line_margin_inherits_the_quotation_margin(): void
    {
        $line = PricedLine::from(
            unitCostBase: '1000',
            marginPercent: null,
            defaultMargin: '20.000',
            quantity: '1',
        );

        self::assertSame('1200.000000', $line->unitPrice());
    }

    public function test_a_zero_line_margin_adds_nothing(): void
    {
        // `D-106`: an extra of 0 is no extra — the quotation margin alone.
        $line = PricedLine::from(
            unitCostBase: '1000',
            marginPercent: '0',
            defaultMargin: '20.000',
            quantity: '1',
        );

        self::assertSame('1200.000000', $line->unitPrice());
    }

    public function test_a_negative_line_margin_lowers_the_quotation_margin_to_sell_at_cost(): void
    {
        // The owner's ruling of 2026-10-09 under `D-106`: selling one line at
        // cost is a line margin of minus the quotation margin.
        $line = PricedLine::from(
            unitCostBase: '1000',
            marginPercent: '-20.000',
            defaultMargin: '20.000',
            quantity: '1',
        );

        self::assertSame('1000.000000', $line->unitPrice());
    }

    public function test_a_whole_margin_of_minus_a_hundred_prices_the_line_at_zero(): void
    {
        // The request's floor (`D-106`) stops exactly here: `unit_price >= 0`.
        $line = PricedLine::from(
            unitCostBase: '1000',
            marginPercent: '-120.000',
            defaultMargin: '20.000',
            quantity: '1',
        );

        self::assertSame('0.000000', $line->unitPrice());
    }

    public function test_the_effective_margin_is_the_sum_and_an_empty_line_counts_as_zero(): void
    {
        // The one place the sum is written: the price reads it (`D-106`);
        // the detail shows the achieved margin instead (`D-148`).
        self::assertSame('30.000', PricedLine::effectiveMargin('10.000', '20.000', 3));
        self::assertSame('20.000', PricedLine::effectiveMargin(null, '20.000', 3));
        self::assertSame('0.000', PricedLine::effectiveMargin('-20.000', '20.000', 3));
    }

    public function test_a_negative_margin_prices_below_cost_rather_than_being_refused(): void
    {
        // Point 1.3 left `margin_percent` free of a non-negativity CHECK on
        // purpose, while `unit_price >= 0` still binds. The engine must not
        // invent a refusal the schema declined to make.
        $line = PricedLine::from(
            unitCostBase: '1000',
            marginPercent: '-30.000',
            defaultMargin: '20.000',
            quantity: '1',
        );

        // 20 − 30 = −10% (`D-106`).
        self::assertSame('900.000000', $line->unitPrice());
    }

    // ───────────────────────────────── the two line figures

    public function test_the_line_figures_multiply_the_values_the_row_will_store(): void
    {
        // `quantity` is NUMERIC(14,4), a different scale from money, and both
        // products are taken against the already-quantized unit values.
        $line = PricedLine::from(
            unitCostBase: '10.5',
            marginPercent: '10.000',
            defaultMargin: '20.000',
            quantity: '2.5000',
        );

        self::assertSame('10.500000', $line->unitCostBase());
        self::assertSame('13.650000', $line->unitPrice());   // 10.5 × (1 + 0.20 + 0.10), D-106
        self::assertSame('34.130000', $line->lineTotal());   // 34.125 rounded to 2 places, D-142
        self::assertSame('26.250000', $line->lineCost());
    }

    // ───────────────────────────────── D-142: the selling price at 2 places, then × quantity

    public function test_the_selling_unit_price_is_rounded_to_two_places_before_it_is_multiplied(): void
    {
        // `D-142`'s own case (QT-2026-0135): a tax-inclusive 30000 entering net
        // at 14% (`D-105`) is 26315.789474; printed 26315.79 × 10 must be the
        // line, 263157.90 — not 263157.89. Only the selling price rounds: the
        // cost figures stay at `D-68`'s scale.
        $line = PricedLine::from(
            unitCostBase: '26315.789474',
            marginPercent: null,
            defaultMargin: '0.000',
            quantity: '10',
        );

        self::assertSame('26315.790000', $line->unitPrice());
        self::assertSame('263157.900000', $line->lineTotal());
        self::assertSame('26315.789474', $line->unitCostBase());
        self::assertSame('263157.894740', $line->lineCost());
    }

    public function test_a_selling_price_exactly_on_the_half_rounds_away_from_zero(): void
    {
        // `D-142`: half away from zero (`bcround`'s default). 12.025 is the
        // half whose even neighbour is below it, so truncation and half-even
        // would both give 12.02.
        $line = PricedLine::from(
            unitCostBase: '12.025',
            marginPercent: '0',
            defaultMargin: '0',
            quantity: '3',
        );

        self::assertSame('12.030000', $line->unitPrice());
        self::assertSame('36.090000', $line->lineTotal());
    }

    public function test_an_fx_converted_price_is_rounded_after_the_margin_is_applied(): void
    {
        // `D-142` over `D-106`: 19.99 USD × 48.53 = 970.1147 EGP; × 1.20 =
        // 1164.13764 → 1164.14; × 3 = 3492.42. The converted cost is not
        // rounded (`D-09`, `D-68`), and the line cost is still taken on it.
        $line = PricedLine::from(
            unitCostBase: ExchangeRate::of(CurrencyCode::Usd, CurrencyCode::Egp, '48.53')->convert('19.99', PricedLine::SCALE),
            marginPercent: null,
            defaultMargin: '20.000',
            quantity: '3',
        );

        self::assertSame('970.114700', $line->unitCostBase());
        self::assertSame('1164.140000', $line->unitPrice());
        self::assertSame('3492.420000', $line->lineTotal());
        self::assertSame('2910.344100', $line->lineCost());
    }

    public function test_a_fractional_quantity_line_amount_is_rounded_to_two_places_but_its_cost_is_not(): void
    {
        // `D-142` (owner, 2026-10-20): 13.65 × 2.5 = 34.125 would print 34.13 per
        // cell yet add 34.125 to the subtotal; the line amount itself rounds,
        // half away from zero. The cost keeps `D-68`'s six places.
        $line = PricedLine::from(
            unitCostBase: '13.65',
            marginPercent: null,
            defaultMargin: '0',
            quantity: '2.5',
        );

        self::assertSame('13.650000', $line->unitPrice());
        self::assertSame('34.130000', $line->lineTotal());
        self::assertSame('34.125000', $line->lineCost());
    }

    // ───────────────────────────────── D-68, and how a value reaches scale 6

    public function test_a_product_beyond_the_money_scale_is_truncated_not_rounded(): void
    {
        // NUMERIC(18,6) × NUMERIC(18,8) is exact at scale 14, so something must
        // decide the sixth decimal. BCMath truncates and PostgreSQL rounds
        // half-up; the owner's ruling of 2026-09-07 pins truncation, so the
        // engine must reach scale 6 itself rather than letting the column do it.
        $line = PricedLine::from(
            unitCostBase: ExchangeRate::of(CurrencyCode::Usd, CurrencyCode::Egp, '1.23456789')->convert('1', PricedLine::SCALE),
            marginPercent: '0',
            defaultMargin: '20.000',
            quantity: '1',
        );

        self::assertSame('1.234567', $line->unitCostBase());
        self::assertNotSame('1.234568', $line->unitCostBase(),
            'Half-up here would disagree with BCMath everywhere else in the chain.');
    }

    public function test_line_values_are_carried_at_the_money_precision_d68_fixes(): void
    {
        self::assertSame(Precision::MONEY_SCALE, PricedLine::SCALE,
            'D-68 puts money at NUMERIC(18,6); two copies of that number will drift.');
    }
}
