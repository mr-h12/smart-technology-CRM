<?php

declare(strict_types=1);

namespace Tests\Feature\Quotations;

use App\Modules\Quotations\Domain\Pricing\PricedLine;
use App\Support\Database\Precision;
use PHPUnit\Framework\TestCase;

/**
 * Module 7, Point 2.1 — §5.1's formulas, as `D-156` restates them (QS.2a).
 *
 * Extends PHPUnit's own `TestCase` rather than `Tests\TestCase`: `PricedLine`
 * is a pure Domain class that touches no database and boots no application,
 * the same shape `CurrencyMatrixDataTest` already tests the money classes in.
 *
 * `D-156`: with `t` the line's tax % and `m` the line's margin %,
 * `net = entered ÷ (1 + t/100)` truncated to 6 places, `unit_price =
 * round2(net × (1 + m/100))` (`D-142`), `line_amount = round2(unit_price ×
 * quantity)`, `line_cost = net × quantity`. The quotation margin and `D-106`'s
 * sum are gone; a line with no tax (`D-157`'s toggle off) is priced from what
 * was typed. No FX on a new line (`D-164`).
 */
final class PricedLineTest extends TestCase
{
    // ───────────────────────────────── D-156's worked examples

    public function test_a_tax_included_line_takes_its_tax_out_then_adds_its_own_margin(): void
    {
        // `D-156` example (1): 114 at 14% is a net 100; + 20% is 120.00; × 3 is 360.00.
        $line = PricedLine::fromEntered(
            enteredUnitPrice: '114',
            taxPercent: '14',
            marginPercent: '20',
            quantity: '3',
        );

        self::assertSame('100.000000', $line->unitCostBase(), 'The unit cost stored is the net (D-156).');
        self::assertSame('120.000000', $line->unitPrice());
        self::assertSame('360.000000', $line->lineTotal());
        self::assertSame('300.000000', $line->lineCost());
    }

    public function test_a_net_that_does_not_divide_evenly_is_truncated_then_priced(): void
    {
        // `D-156` example (2): 90 ÷ 1.1 = 81.8181818… → 81.818181 (truncated, the
        // owner's answer 2); × 1.2 = 98.1818172 → 98.18 (`D-142`).
        $line = PricedLine::fromEntered(
            enteredUnitPrice: '90',
            taxPercent: '10',
            marginPercent: '20',
            quantity: '1',
        );

        self::assertSame('81.818181', $line->unitCostBase());
        self::assertNotSame('81.818182', $line->unitCostBase(), 'D-156 truncates the net; it never rounds it.');
        self::assertSame('98.180000', $line->unitPrice());
        self::assertSame('98.180000', $line->lineTotal());
        self::assertSame('81.818181', $line->lineCost());
    }

    public function test_a_line_with_no_tax_is_priced_from_what_was_typed(): void
    {
        // `D-157`, toggle off: no extraction — the typed 100 is the net.
        $line = PricedLine::fromEntered(
            enteredUnitPrice: '100',
            taxPercent: null,
            marginPercent: '20',
            quantity: '3',
        );

        self::assertSame('100.000000', $line->unitCostBase());
        self::assertSame('120.000000', $line->unitPrice());
        self::assertSame('360.000000', $line->lineTotal());
        self::assertSame('300.000000', $line->lineCost());
    }

    public function test_a_zero_percent_line_takes_nothing_out(): void
    {
        // `D-157`: a 0% line inside a tax-inclusive quotation is allowed — 50 ÷ 1 = 50.
        $line = PricedLine::fromEntered(
            enteredUnitPrice: '50',
            taxPercent: '0',
            marginPercent: '10',
            quantity: '1',
        );

        self::assertSame('50.000000', $line->unitCostBase());
        self::assertSame('55.000000', $line->unitPrice());
    }

    // ───────────────────────────────── the acceptance row, and the margin's sign

    public function test_a_cost_of_a_thousand_at_twenty_percent_prices_at_twelve_hundred(): void
    {
        // Acceptance row 1, and `D-04` itself — the margin is the line's own now (`D-156`).
        $line = PricedLine::fromEntered(
            enteredUnitPrice: '1000',
            taxPercent: null,
            marginPercent: '20.000',
            quantity: '1',
        );

        self::assertSame('1200.000000', $line->unitPrice());
        self::assertSame('1000.000000', $line->unitCostBase());
    }

    public function test_a_margin_of_minus_a_hundred_prices_the_line_at_zero(): void
    {
        // The `unit_price >= 0` CHECK stops exactly here.
        $line = PricedLine::fromEntered(
            enteredUnitPrice: '1000',
            taxPercent: null,
            marginPercent: '-100.000',
            quantity: '1',
        );

        self::assertSame('0.000000', $line->unitPrice());
    }

    public function test_a_negative_margin_prices_below_cost_rather_than_being_refused(): void
    {
        // Point 1.3 left `margin_percent` free of a non-negativity CHECK on
        // purpose, while `unit_price >= 0` still binds. The engine must not
        // invent a refusal the schema declined to make.
        $line = PricedLine::fromEntered(
            enteredUnitPrice: '1000',
            taxPercent: null,
            marginPercent: '-10.000',
            quantity: '1',
        );

        self::assertSame('900.000000', $line->unitPrice());
    }

    // ───────────────────────────────── the two line figures

    public function test_the_line_figures_multiply_the_values_the_row_will_store(): void
    {
        // `quantity` is NUMERIC(14,4), a different scale from money, and both
        // products are taken against the already-quantized unit values.
        $line = PricedLine::fromEntered(
            enteredUnitPrice: '10.5',
            taxPercent: null,
            marginPercent: '30.000',
            quantity: '2.5000',
        );

        self::assertSame('10.500000', $line->unitCostBase());
        self::assertSame('13.650000', $line->unitPrice());   // 10.5 × 1.30
        self::assertSame('34.130000', $line->lineTotal());   // 34.125 rounded to 2 places, D-142
        self::assertSame('26.250000', $line->lineCost());
    }

    // ───────────────────────────────── D-142: the selling price at 2 places, then × quantity

    public function test_the_selling_unit_price_is_rounded_to_two_places_before_it_is_multiplied(): void
    {
        // `D-142`'s own case (QT-2026-0135), typed the new way: 30000 including
        // 14% is a net 26315.789473 (`D-156` truncates); printed 26315.79 × 10
        // must be the line, 263157.90 — not 263157.89. Only the selling price
        // rounds: the cost figures stay at `D-68`'s scale.
        $line = PricedLine::fromEntered(
            enteredUnitPrice: '30000',
            taxPercent: '14',
            marginPercent: '0',
            quantity: '10',
        );

        self::assertSame('26315.789473', $line->unitCostBase());
        self::assertSame('26315.790000', $line->unitPrice());
        self::assertSame('263157.900000', $line->lineTotal());
        self::assertSame('263157.894730', $line->lineCost());
    }

    public function test_a_selling_price_exactly_on_the_half_rounds_away_from_zero(): void
    {
        // `D-142`: half away from zero (`bcround`'s default). 12.025 is the
        // half whose even neighbour is below it, so truncation and half-even
        // would both give 12.02.
        $line = PricedLine::fromEntered(
            enteredUnitPrice: '12.025',
            taxPercent: null,
            marginPercent: '0',
            quantity: '3',
        );

        self::assertSame('12.030000', $line->unitPrice());
        self::assertSame('36.090000', $line->lineTotal());
    }

    public function test_a_fractional_quantity_line_amount_is_rounded_to_two_places_but_its_cost_is_not(): void
    {
        // `D-142` (owner, 2026-10-20): 13.65 × 2.5 = 34.125 would print 34.13 per
        // cell yet add 34.125 to the subtotal; the line amount itself rounds,
        // half away from zero. The cost keeps `D-68`'s six places.
        $line = PricedLine::fromEntered(
            enteredUnitPrice: '13.65',
            taxPercent: null,
            marginPercent: '0',
            quantity: '2.5',
        );

        self::assertSame('13.650000', $line->unitPrice());
        self::assertSame('34.130000', $line->lineTotal());
        self::assertSame('34.125000', $line->lineCost());
    }

    // ───────────────────────────────── D-68, and how a value reaches scale 6

    public function test_a_product_beyond_the_money_scale_is_truncated_not_rounded(): void
    {
        // 81.818181 × 2.5 = 204.5454525 — seven decimals, so the sixth has to
        // be decided. The owner's ruling of 2026-09-07 pins truncation:
        // BCMath truncates and PostgreSQL rounds half-up, so the engine must
        // reach scale 6 itself rather than letting the column do it.
        $line = PricedLine::fromEntered(
            enteredUnitPrice: '90',
            taxPercent: '10',
            marginPercent: '20',
            quantity: '2.5',
        );

        self::assertSame('204.545452', $line->lineCost());
        self::assertNotSame('204.545453', $line->lineCost(),
            'Half-up here would disagree with BCMath everywhere else in the chain.');
    }

    public function test_line_values_are_carried_at_the_money_precision_d68_fixes(): void
    {
        self::assertSame(Precision::MONEY_SCALE, PricedLine::SCALE,
            'D-68 puts money at NUMERIC(18,6); two copies of that number will drift.');
    }
}
