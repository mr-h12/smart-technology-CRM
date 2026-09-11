<?php

declare(strict_types=1);

namespace Tests\Feature\Quotations;

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
            unitCost: '1000',
            fxRateAtTime: '1',
            marginPercent: null,
            defaultMargin: '20.000',
            quantity: '1',
        );

        self::assertSame('1200.000000', $line->unitPrice());
        self::assertSame('1000.000000', $line->unitCostBase());
    }

    public function test_a_line_margin_overrides_the_quotation_margin(): void
    {
        // Acceptance row 2. `D-03`: the line wins wherever it has an opinion.
        $line = PricedLine::from(
            unitCost: '1000',
            fxRateAtTime: '1',
            marginPercent: '30.000',
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
            unitCost: '100',
            fxRateAtTime: '48.50000000',
            marginPercent: null,
            defaultMargin: '20.000',
            quantity: '2',
        );

        self::assertSame('4850.000000', $line->unitCostBase());
        self::assertSame('5820.000000', $line->unitPrice());
        self::assertSame('11640.000000', $line->lineTotal());
        self::assertSame('9700.000000', $line->lineCost());
    }

    // ───────────────────────────────── what "if empty" means (D-03)

    public function test_an_absent_line_margin_inherits_the_quotation_margin(): void
    {
        $line = PricedLine::from(
            unitCost: '1000',
            fxRateAtTime: '1',
            marginPercent: null,
            defaultMargin: '20.000',
            quantity: '1',
        );

        self::assertSame('1200.000000', $line->unitPrice());
    }

    public function test_a_zero_line_margin_is_not_an_absent_one(): void
    {
        // The whole point of `??` over `?:`. A Team Leader who sets a line to
        // 0% is selling it at cost deliberately; inheriting 20% there would
        // overcharge the customer and no error would ever be raised.
        $line = PricedLine::from(
            unitCost: '1000',
            fxRateAtTime: '1',
            marginPercent: '0',
            defaultMargin: '20.000',
            quantity: '1',
        );

        self::assertSame('1000.000000', $line->unitPrice());
    }

    public function test_a_negative_margin_prices_below_cost_rather_than_being_refused(): void
    {
        // Point 1.3 left `margin_percent` free of a non-negativity CHECK on
        // purpose, while `unit_price >= 0` still binds. The engine must not
        // invent a refusal the schema declined to make.
        $line = PricedLine::from(
            unitCost: '1000',
            fxRateAtTime: '1',
            marginPercent: '-10.000',
            defaultMargin: '20.000',
            quantity: '1',
        );

        self::assertSame('900.000000', $line->unitPrice());
    }

    // ───────────────────────────────── the two line figures

    public function test_the_line_figures_multiply_the_values_the_row_will_store(): void
    {
        // `quantity` is NUMERIC(14,4), a different scale from money, and both
        // products are taken against the already-quantized unit values.
        $line = PricedLine::from(
            unitCost: '10.5',
            fxRateAtTime: '1',
            marginPercent: '10.000',
            defaultMargin: '20.000',
            quantity: '2.5000',
        );

        self::assertSame('10.500000', $line->unitCostBase());
        self::assertSame('11.550000', $line->unitPrice());
        self::assertSame('28.875000', $line->lineTotal());
        self::assertSame('26.250000', $line->lineCost());
    }

    // ───────────────────────────────── D-68, and how a value reaches scale 6

    public function test_a_product_beyond_the_money_scale_is_truncated_not_rounded(): void
    {
        // NUMERIC(18,6) × NUMERIC(18,8) is exact at scale 14, so something must
        // decide the sixth decimal. BCMath truncates and PostgreSQL rounds
        // half-up; the owner's ruling of 2026-09-07 pins truncation, so the
        // engine must reach scale 6 itself rather than letting the column do it.
        $line = PricedLine::from(
            unitCost: '1',
            fxRateAtTime: '1.23456789',
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
