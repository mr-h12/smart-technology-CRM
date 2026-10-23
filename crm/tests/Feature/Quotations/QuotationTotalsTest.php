<?php

declare(strict_types=1);

namespace Tests\Feature\Quotations;

use App\Modules\Admin\Domain\Money\RoundingRule;
use App\Modules\Quotations\Domain\Pricing\PricedLine;
use App\Modules\Quotations\Domain\Pricing\QuotationTotals;
use PHPUnit\Framework\TestCase;

/**
 * Module 7, Point 2.2 — §5.2, with `D-157`'s per-line tax (QS.2a).
 *
 * Pure, so it extends PHPUnit's own `TestCase` for the reason `PricedLineTest`
 * gives.
 *
 * `D-157`: tax is per line. The discount is spread over the lines in
 * proportion to their amounts, each line is taxed at its own rate on its
 * discounted amount, and the sum is the VAT line — `CustomerLineFigures::vat()`
 * summed. No line carries a tax (`tax_exempt`) → no tax line at all (`D-63`).
 * There is no quotation tax rate any more, so the totals take none.
 */
final class QuotationTotalsTest extends TestCase
{
    /**
     * A line whose `line_total` is exactly $amount, so a totals test reads as
     * arithmetic over known numbers rather than as a second margin test. A
     * taxed line is typed at `amount × (1 + t/100)`, so the tax taken back out
     * leaves `amount` once the unit price rounds to 2 places (`D-156`, `D-142`).
     *
     * @param  numeric-string  $amount  at most 2 places
     * @param  numeric-string|null  $taxPercent
     */
    private static function lineTotalling(string $amount, ?string $taxPercent = null): PricedLine
    {
        return PricedLine::fromEntered(
            enteredUnitPrice: $taxPercent === null ? $amount : bcmul($amount, bcadd('1', bcdiv($taxPercent, '100', 8), 8), 6),
            taxPercent: $taxPercent,
            marginPercent: '0',
            quantity: '1',
        );
    }

    // ───────────────────────────────── D-156's worked examples

    public function test_e1_a_tax_included_line_is_taxed_at_its_own_rate_after_the_discount(): void
    {
        // 114 at 14%, margin 20%, × 3 → 360.00; discount 10% → 36.00; base
        // 324.00; VAT 324 × 14% = 45.36; delivery 50 untaxed; 360 + 50 − 36 =
        // 374.00; + 45.36 = 419.36.
        $totals = QuotationTotals::from(
            [PricedLine::fromEntered(enteredUnitPrice: '114', taxPercent: '14', marginPercent: '20', quantity: '3')],
            ['50'],
            '10',
        );

        self::assertSame('360.000000', $totals->subtotal());
        self::assertSame('36.000000', $totals->discountAmount());
        self::assertSame('324.000000', $totals->taxBase());
        self::assertSame('45.360000', $totals->taxAmount());
        self::assertSame('50.000000', $totals->additionalTotal());
        self::assertSame('374.000000', $totals->netAmount());
        self::assertSame('419.360000', $totals->totalBeforeRound());

        // `D-65`: rounding off keeps 419.36; on, at EGP's unit 1, it is 419.00.
        $off = RoundingRule::disabled('1')->apply($totals->totalBeforeRound());
        self::assertSame(['419.360000', '0.000000'], [$off->finalTotal(), $off->roundingDiff()]);

        $on = RoundingRule::to('1')->apply($totals->totalBeforeRound());
        self::assertSame(['419.000000', '-0.360000'], [$on->finalTotal(), $on->roundingDiff()]);
    }

    public function test_e2_a_tax_that_carries_three_places_is_kept_until_print(): void
    {
        // 90 at 10%, margin 20% → 98.18; VAT 98.18 × 10% = 9.818; 107.998. The
        // PDF prints 9.82 and 108.00 (`D-99`: two places at print only).
        $totals = QuotationTotals::from(
            [PricedLine::fromEntered(enteredUnitPrice: '90', taxPercent: '10', marginPercent: '20', quantity: '1')],
            [],
            '0',
        );

        self::assertSame('98.180000', $totals->subtotal());
        self::assertSame('9.818000', $totals->taxAmount());
        self::assertSame('107.998000', $totals->totalBeforeRound());
        self::assertSame(['9.82', '108.00'], [bcround((string) $totals->taxAmount(), 2), bcround($totals->totalBeforeRound(), 2)]);
    }

    public function test_e3_an_exempt_quotation_prices_what_was_typed_and_has_no_tax_line(): void
    {
        // `D-157` toggle off: 100 as typed, margin 20% → 120.00 × 3 = 360.00;
        // discount 36.00; delivery 50; no tax at all → 374.00.
        $totals = QuotationTotals::from(
            [PricedLine::fromEntered(enteredUnitPrice: '100', taxPercent: null, marginPercent: '20', quantity: '3')],
            ['50'],
            '10',
        );

        self::assertSame('360.000000', $totals->subtotal());
        self::assertSame('36.000000', $totals->discountAmount());
        self::assertNull($totals->taxAmount(), 'D-63: an exempt quotation has no tax line, not a zero one.');
        self::assertSame('374.000000', $totals->netAmount());
        self::assertSame('374.000000', RoundingRule::to('1')->apply($totals->totalBeforeRound())->finalTotal());
    }

    public function test_each_line_is_taxed_at_its_own_rate_on_its_discounted_amount(): void
    {
        // `D-157`, two rates in one quotation, discount 10%, delivery 50:
        //   A: 114 at 14%, margin 20%, × 3 → net 100 → 120.00 → 360.00
        //   B: 110 at 10%, margin  0%, × 2 → net 100 → 100.00 → 200.00
        // subtotal 560.00; discount 56.00; base 504.00;
        // VAT A = 360 × 0.90 × 14% = 45.36; VAT B = 200 × 0.90 × 10% = 18.00; Σ = 63.36;
        // net 560 + 50 − 56 = 554.00; total 554.00 + 63.36 = 617.36.
        $totals = QuotationTotals::from(
            [
                PricedLine::fromEntered(enteredUnitPrice: '114', taxPercent: '14', marginPercent: '20', quantity: '3'),
                PricedLine::fromEntered(enteredUnitPrice: '110', taxPercent: '10', marginPercent: '0', quantity: '2'),
            ],
            ['50'],
            '10',
        );

        self::assertSame('560.000000', $totals->subtotal());
        self::assertSame('56.000000', $totals->discountAmount());
        self::assertSame('504.000000', $totals->taxBase());
        self::assertSame('63.360000', $totals->taxAmount());
        self::assertNotSame('70.560000', $totals->taxAmount(),
            'D-157: one rate over the whole base (504 × 14%) is the retired quotation tax.');
        self::assertSame('554.000000', $totals->netAmount());
        self::assertSame('617.360000', $totals->totalBeforeRound());
    }

    public function test_a_zero_percent_line_adds_no_tax_but_leaves_the_quotation_taxed(): void
    {
        // `D-157`: a 0% line inside a tax-inclusive quotation. 360.00 at 14% and
        // 55.00 at 0%, discount 10%: VAT 324 × 14% + 49.5 × 0% = 45.36 — a tax
        // line, not none.
        $totals = QuotationTotals::from(
            [
                PricedLine::fromEntered(enteredUnitPrice: '114', taxPercent: '14', marginPercent: '20', quantity: '3'),
                PricedLine::fromEntered(enteredUnitPrice: '50', taxPercent: '0', marginPercent: '10', quantity: '1'),
            ],
            [],
            '10',
        );

        self::assertSame('415.000000', $totals->subtotal());
        self::assertSame('373.500000', $totals->taxBase());
        self::assertSame('45.360000', $totals->taxAmount());
    }

    // ───────────────────────────────── the acceptance row

    public function test_the_documented_discount_and_tax_base_row(): void
    {
        // Acceptance row 7, first half: items 10,000 + delivery 1,000,
        // discount 1% → tax base 9,900.
        $totals = QuotationTotals::from(
            [self::lineTotalling('10000')],
            ['1000'],
            '1.000',
        );

        self::assertSame('10000.000000', $totals->subtotal());
        self::assertSame('1000.000000', $totals->additionalTotal());
        self::assertSame('100.000000', $totals->discountAmount());
        self::assertSame('9900.000000', $totals->taxBase());
    }

    // ───────────────────────────────── D-62 / OD-01, which no CHECK can police

    public function test_delivery_is_summed_and_still_kept_out_of_the_tax_base(): void
    {
        // Both halves matter. A delivery charge that never reached
        // `additional_total` would also keep the tax base at 9,900, and would
        // be just as wrong — it has to be counted *and* excluded.
        $totals = QuotationTotals::from(
            [self::lineTotalling('10000')],
            ['1000'],
            '1.000',
        );

        self::assertSame('1000.000000', $totals->additionalTotal(),
            'The delivery item was dropped rather than excluded.');
        self::assertNotSame('10900.000000', $totals->taxBase(),
            'D-62: additional items never enter the tax base.');
        self::assertNotSame('10791.000000', $totals->taxBase(),
            'D-62: nor may they enter it after the discount.');
    }

    public function test_the_discount_comes_off_before_the_tax_base_is_taken(): void
    {
        // `D-64`. PO #226 taxes the pre-discount amount and is the documented
        // wrong answer; the accepted 10.32 difference is recorded there.
        $totals = QuotationTotals::from([self::lineTotalling('10000')], [], '1.000');

        self::assertSame('9900.000000', $totals->taxBase());
        self::assertNotSame('10000.000000', $totals->taxBase(),
            'D-64: the discount reduces the base the tax is charged on.');
    }

    // ───────────────────────────────── Point 1.2's CHECK, on stored values

    public function test_the_tax_base_identity_holds_on_the_values_the_row_will_store(): void
    {
        // `quotations_tax_base_follows_discount`. The database compares the
        // stored columns, so the engine's own output must satisfy it exactly —
        // not a higher-precision value it happened to compute on the way.
        $totals = QuotationTotals::from(
            [self::lineTotalling('7368.42')],
            ['250.75'],
            '1.000',
        );

        self::assertSame(
            bcsub($totals->subtotal(), $totals->discountAmount(), PricedLine::SCALE),
            $totals->taxBase(),
        );
    }

    // ───────────────────────────────── sums, and the empty quotation

    public function test_every_line_is_added_not_merely_the_first(): void
    {
        $totals = QuotationTotals::from(
            [self::lineTotalling('10'), self::lineTotalling('5.5'), self::lineTotalling('0.25')],
            ['1', '2.5'],
            '0',
        );

        self::assertSame('15.750000', $totals->subtotal());
        self::assertSame('3.500000', $totals->additionalTotal());
    }

    public function test_the_subtotal_is_the_sum_of_the_rounded_line_amounts(): void
    {
        // `D-142`: each line is its 2-place selling price × quantity, and the
        // subtotal adds those — 26315.79 × 10 + 12.03 × 3 = 263157.90 + 36.09.
        $totals = QuotationTotals::from(
            [
                PricedLine::fromEntered(enteredUnitPrice: '26315.789474', taxPercent: null, marginPercent: '0', quantity: '10'),
                PricedLine::fromEntered(enteredUnitPrice: '12.025', taxPercent: null, marginPercent: '0', quantity: '3'),
            ],
            [],
            '0',
        );

        self::assertSame('263193.990000', $totals->subtotal());
    }

    public function test_a_quotation_with_nothing_in_it_totals_a_scaled_zero(): void
    {
        // Not `'0'`. Every other total in the system carries `D-68`'s scale and
        // this one has to as well, or the empty quotation is the single row
        // whose columns were written in a different shape.
        $totals = QuotationTotals::from([], [], '0');

        self::assertSame('0.000000', $totals->subtotal());
        self::assertSame('0.000000', $totals->additionalTotal());
        self::assertSame('0.000000', $totals->discountAmount());
        self::assertSame('0.000000', $totals->taxBase());
    }

    public function test_a_zero_discount_takes_nothing_off(): void
    {
        $totals = QuotationTotals::from([self::lineTotalling('10000')], [], '0');

        self::assertSame('0.000000', $totals->discountAmount());
        self::assertSame('10000.000000', $totals->taxBase());
    }

    // ───────────────────────────────── D-63, and the rest of §5.2

    public function test_the_documented_tax_row(): void
    {
        // Acceptance row 7, complete: tax base 9,900 at 14% is 1,386.
        $totals = QuotationTotals::from(
            [self::lineTotalling('10000', '14.000')],
            ['1000'],
            '1.000',
        );

        self::assertSame('9900.000000', $totals->taxBase());
        self::assertSame('1386.000000', $totals->taxAmount());
        self::assertSame('10900.000000', $totals->netAmount());
        self::assertSame('12286.000000', $totals->totalBeforeRound());
    }

    public function test_an_exempt_quotation_has_no_tax_line_at_all(): void
    {
        // Acceptance row 8, and `D-63`: no line carries a tax, so there is no
        // tax line — null, not zero. §16's PDF decides whether to print the
        // line by whether the amount exists.
        $totals = QuotationTotals::from([self::lineTotalling('10000')], ['1000'], '1.000');

        self::assertNull($totals->taxAmount());
        self::assertSame('10900.000000', $totals->netAmount());
        self::assertSame('10900.000000', $totals->totalBeforeRound(),
            'An exempt total is its net amount unchanged.');
    }

    public function test_the_additional_items_rejoin_the_net_amount(): void
    {
        // The half of `D-62` that is easy to miss. Delivery sits out of the tax
        // base and then counts in full towards what the customer owes — §5.2.
        // A net amount of 9,900 would mean it had been dropped.
        $totals = QuotationTotals::from([self::lineTotalling('10000', '14.000')], ['1000'], '1.000');

        self::assertSame('10900.000000', $totals->netAmount());
        self::assertNotSame('9900.000000', $totals->netAmount());
    }

    public function test_the_remaining_identities_hold_on_the_values_the_row_will_store(): void
    {
        // `quotations_net_amount_includes_additional` and
        // `quotations_total_before_round_adds_tax`, recomputed the way the
        // database will.
        $totals = QuotationTotals::from(
            [self::lineTotalling('7368.42', '14.000')],
            ['250.75'],
            '1.000',
        );

        $scale = PricedLine::SCALE;

        self::assertSame(
            bcsub(bcadd($totals->subtotal(), $totals->additionalTotal(), $scale), $totals->discountAmount(), $scale),
            $totals->netAmount(),
        );
        self::assertSame(
            bcadd($totals->netAmount(), $totals->taxAmount() ?? '0', $scale),
            $totals->totalBeforeRound(),
        );
    }

    // ───────────────────────────────── §5.2's worked example, end to end

    public function test_the_documented_worked_example_reaches_the_documented_total(): void
    {
        // §5.2's PO #226 figures under the documented ordering, one line at
        // 14%. `RoundingRule` is imported here and nowhere in `Domain/Pricing`:
        // deptrac analyses `app/Modules` only, so a test may compose what the
        // engine may not import.
        $totals = QuotationTotals::from([self::lineTotalling('7368.42', '14.000')], [], '1.000');

        self::assertSame('73.684200', $totals->discountAmount());
        self::assertSame('7294.735800', $totals->taxBase());
        self::assertSame('1021.263012', $totals->taxAmount());
        self::assertSame('8315.998812', $totals->totalBeforeRound());

        // The document prints 8,315.9988. The exact value at D-68's scale
        // carries two more digits; the final total is the same either way,
        // which is why the printed figure was never wrong, only rounded.
        self::assertNotSame('8315.998800', $totals->totalBeforeRound());

        $rounded = RoundingRule::to('1')->apply($totals->totalBeforeRound());

        self::assertSame('8316.000000', $rounded->finalTotal());
        self::assertSame('0.001188', $rounded->roundingDiff());
    }

    public function test_a_tax_beyond_the_money_scale_is_truncated_not_rounded(): void
    {
        // 0.77 at 1.007% is 0.0077539 exactly, so the sixth decimal has to be
        // decided here the same way it is for every other product in the chain.
        $totals = QuotationTotals::from([self::lineTotalling('0.77', '1.007')], [], '0');

        self::assertSame('0.770000', $totals->subtotal());
        self::assertSame('0.007753', $totals->taxAmount());
        self::assertNotSame('0.007754', $totals->taxAmount());
    }

    public function test_a_discount_beyond_the_money_scale_is_truncated_not_rounded(): void
    {
        // 0.77 × 1.007% is 0.0077539 exactly — seven decimals, so the sixth
        // has to be decided. Truncation gives ...7753; half-up would give
        // ...7754 and disagree with every other product in the chain.
        $totals = QuotationTotals::from([self::lineTotalling('0.77')], [], '1.007');

        self::assertSame('0.007753', $totals->discountAmount());
        self::assertNotSame('0.007754', $totals->discountAmount());
        self::assertSame('0.762247', $totals->taxBase());
    }
}
