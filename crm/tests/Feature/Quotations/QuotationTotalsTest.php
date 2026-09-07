<?php

declare(strict_types=1);

namespace Tests\Feature\Quotations;

use App\Modules\Admin\Domain\Money\RoundingRule;
use App\Modules\Quotations\Domain\Pricing\PricedLine;
use App\Modules\Quotations\Domain\Pricing\QuotationTotals;
use PHPUnit\Framework\TestCase;

/**
 * Module 7, Point 2.2 — §5.2 as far as the tax base.
 *
 * Pure, so it extends PHPUnit's own `TestCase` for the reason `PricedLineTest`
 * gives.
 */
final class QuotationTotalsTest extends TestCase
{
    /**
     * A line whose `line_total` is exactly $amount, so a totals test reads as
     * arithmetic over known numbers rather than as a second margin test.
     *
     * @param  numeric-string  $amount
     */
    private static function lineTotalling(string $amount): PricedLine
    {
        return PricedLine::from(
            unitCost: $amount,
            fxRateAtTime: '1',
            marginPercent: '0',
            defaultMargin: '0',
            quantity: '1',
        );
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
            null,
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
            null,
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
        $totals = QuotationTotals::from([self::lineTotalling('10000')], [], '1.000', null);

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
            null,
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
            null,
        );

        self::assertSame('15.750000', $totals->subtotal());
        self::assertSame('3.500000', $totals->additionalTotal());
    }

    public function test_a_quotation_with_nothing_in_it_totals_a_scaled_zero(): void
    {
        // Not `'0'`. Every other total in the system carries `D-68`'s scale and
        // this one has to as well, or the empty quotation is the single row
        // whose columns were written in a different shape.
        $totals = QuotationTotals::from([], [], '0', null);

        self::assertSame('0.000000', $totals->subtotal());
        self::assertSame('0.000000', $totals->additionalTotal());
        self::assertSame('0.000000', $totals->discountAmount());
        self::assertSame('0.000000', $totals->taxBase());
    }

    public function test_a_zero_discount_takes_nothing_off(): void
    {
        $totals = QuotationTotals::from([self::lineTotalling('10000')], [], '0', null);

        self::assertSame('0.000000', $totals->discountAmount());
        self::assertSame('10000.000000', $totals->taxBase());
    }

    // ───────────────────────────────── D-68, the same way PricedLine reaches it

    // ───────────────────────────────── D-63, and the rest of §5.2

    public function test_the_documented_tax_row(): void
    {
        // Acceptance row 7, complete: tax base 9,900 at 14% is 1,386.
        $totals = QuotationTotals::from(
            [self::lineTotalling('10000')],
            ['1000'],
            '1.000',
            '14.000',
        );

        self::assertSame('9900.000000', $totals->taxBase());
        self::assertSame('1386.000000', $totals->taxAmount());
        self::assertSame('10900.000000', $totals->netAmount());
        self::assertSame('12286.000000', $totals->totalBeforeRound());
    }

    public function test_an_exempt_quotation_has_no_tax_line_at_all(): void
    {
        // Acceptance row 8, and `D-63`. Null, not zero: Point 1.1's
        // `quotations_tax_percent_not_zero` refuses a zero percent and Point
        // 1.2's `quotations_tax_amount_matches_tax_percent` refuses the mixed
        // pair, so a zero here would be unstorable as well as wrong.
        $totals = QuotationTotals::from([self::lineTotalling('10000')], ['1000'], '1.000', null);

        self::assertNull($totals->taxAmount());
        self::assertSame('10900.000000', $totals->netAmount());
        self::assertSame('10900.000000', $totals->totalBeforeRound(),
            'An exempt total is its net amount unchanged.');
    }

    public function test_the_additional_items_rejoin_the_net_amount(): void
    {
        // The half of `D-62` that is easy to miss. Delivery sits out of the tax
        // base and then counts in full towards what the customer owes — §5.2
        // line 501. A net amount of 9,900 would mean it had been dropped.
        $totals = QuotationTotals::from([self::lineTotalling('10000')], ['1000'], '1.000', '14.000');

        self::assertSame('10900.000000', $totals->netAmount());
        self::assertNotSame('9900.000000', $totals->netAmount());
    }

    public function test_the_remaining_identities_hold_on_the_values_the_row_will_store(): void
    {
        // `quotations_net_amount_includes_additional` and
        // `quotations_total_before_round_adds_tax`, recomputed the way the
        // database will.
        $totals = QuotationTotals::from(
            [self::lineTotalling('7368.42')],
            ['250.75'],
            '1.000',
            '14.000',
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
        // §5.2's PO #226 figures under the documented ordering. `RoundingRule`
        // is imported here and nowhere in `Domain/Pricing`: deptrac analyses
        // `app/Modules` only, so a test may compose what the engine may not
        // import, and this is the seam Step 3's Application layer will make.
        $totals = QuotationTotals::from([self::lineTotalling('7368.42')], [], '1.000', '14.000');

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
        // 0.777777 at 1% is 0.00777777 exactly, so the sixth decimal has to be
        // decided here the same way it is for every other product in the chain.
        $totals = QuotationTotals::from([self::lineTotalling('0.777777')], [], '0', '1.000');

        self::assertSame('0.007777', $totals->taxAmount());
        self::assertNotSame('0.007778', $totals->taxAmount());
    }

    public function test_a_discount_beyond_the_money_scale_is_truncated_not_rounded(): void
    {
        // 0.777777 × 1% is 0.00777777 exactly — eight decimals, so the sixth
        // has to be decided. Truncation gives ...7777; half-up would give
        // ...7778 and disagree with every other product in the chain.
        $totals = QuotationTotals::from([self::lineTotalling('0.777777')], [], '1.000', null);

        self::assertSame('0.007777', $totals->discountAmount());
        self::assertNotSame('0.007778', $totals->discountAmount());
        self::assertSame('0.770000', $totals->taxBase());
    }
}
