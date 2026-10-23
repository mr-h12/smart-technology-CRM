<?php

declare(strict_types=1);

namespace Tests\Feature\Quotations;

use App\Modules\Quotations\Domain\Pricing\CustomerLineFigures;
use App\Modules\Quotations\Domain\Pricing\PricedLine;
use App\Modules\Quotations\Domain\Pricing\QuotationTotals;
use PHPUnit\Framework\TestCase;

/**
 * F-40 · 1.4 — `D-107` ruling (1): a line's VAT is charged after the discount
 * is spread over the lines. Line VAT = line total × (1 − discount/100) ×
 * tax/100. `D-142` amends ruling (1) for the *Total* only: the line's *Total*
 * is quantity × unit price — no discount, no VAT. The VAT row
 * stays §5.2's `tax_amount`, and a cents difference between the column's sum
 * and that row is accepted. Scale 6 like {@see QuotationTotals}; the PDF
 * rounds to 2 places only when it prints.
 */
final class CustomerLineFiguresTest extends TestCase
{
    /** The restated scale cannot drift from the one it restates. */
    public function test_the_scale_is_the_money_scale(): void
    {
        self::assertSame(PricedLine::SCALE, CustomerLineFigures::SCALE);
    }

    /** With no discount the figures are the reference PDFs'. */
    public function test_no_discount_gives_the_reference_figures(): void
    {
        self::assertSame('140.000000', CustomerLineFigures::vat('1000.00', '0', '14'));
    }

    public function test_a_ten_percent_discount_reduces_the_vat_base(): void
    {
        self::assertSame('126.000000', CustomerLineFigures::vat('1000.00', '10', '14'));
    }

    /** `D-64`'s three-decimal percentages, as `discount_percent` stores them. */
    public function test_fractional_percentages_keep_full_scale(): void
    {
        // 10438.60 × 0.95 × 0.14 = 1388.3338.
        self::assertSame('1388.333800', CustomerLineFigures::vat('10438.60', '5.000', '14.000'));
    }

    /** `D-63`: an exempt quotation has no VAT at all — not a zero. */
    public function test_an_exempt_line_has_no_vat(): void
    {
        self::assertNull(CustomerLineFigures::vat('1000.00', '10', null));
    }

    /**
     * The accepted difference, pinned so nobody "fixes" it: three lines of
     * 0.05 at 10% each carry 0.005 VAT, printed 0.01 each (0.03), while §5.2's
     * row is 0.015, printed 0.02. Neither figure is adjusted to match.
     */
    public function test_the_cents_case_is_left_as_computed(): void
    {
        $lines = ['0.05', '0.05', '0.05'];

        $printedColumn = '0.00';
        foreach ($lines as $lineTotal) {
            $vat = CustomerLineFigures::vat($lineTotal, '0', '10');
            self::assertSame('0.005000', $vat);
            $printedColumn = bcadd($printedColumn, bcround($vat, 2), 2);
        }

        $row = QuotationTotals::from(
            array_map(static fn (string $amount): PricedLine => PricedLine::fromEntered(
                enteredUnitPrice: $amount, taxPercent: '10', marginPercent: '0', quantity: '1',
            ), $lines),
            [],
            '0',
        )->taxAmount();

        self::assertSame('0.015000', $row);
        self::assertSame('0.03', $printedColumn);
        self::assertSame('0.02', bcround((string) $row, 2));
    }

    /** `D-107`'s default: *Total Amount Excl. VAT* = `subtotal + additional_total`. */
    public function test_total_excluding_vat_adds_the_additional_items(): void
    {
        self::assertSame('35104.100000', CustomerLineFigures::excludingVat('34854.10', '250.00'));
        self::assertSame('1000.000000', CustomerLineFigures::excludingVat('1000.00', '0.00'));
    }

    // ── QS.2a: a tax-included PDF prints prices including tax (`D-156`, `D-157`) ──

    /**
     * `D-156`'s first example: unit price 120.00 at the line's 14 % prints
     * round2(120.00 × 1.14) = 136.80. A 0 % line inside a tax-included
     * quotation (allowed, `D-157`) prints its price unchanged.
     */
    public function test_a_gross_unit_price_adds_the_lines_own_tax(): void
    {
        self::assertSame('136.800000', CustomerLineFigures::grossUnitPrice('120.00', '14.000'));
        self::assertSame('60.000000', CustomerLineFigures::grossUnitPrice('60.00', '0.000'));
    }

    /** `D-142` on the gross figures: the printed gross unit × quantity is the printed gross line, 136.80 × 3 = 410.40. */
    public function test_a_gross_line_is_the_gross_unit_price_times_the_quantity(): void
    {
        self::assertSame('410.400000', CustomerLineFigures::grossLineTotal('136.800000', '3.0000'));
    }

    /**
     * The unit rounds to 2 places before the quantity multiplies it (`D-142`,
     * half away from zero): 33.33 × 1.14 = 37.9962 → 38.00, and 38.00 × 3 =
     * 114.00 — the gross line a reader can check against the printed unit.
     * Taken from the unrounded unit it would be 37.9962 × 3 = 113.9886 → 113.99.
     */
    public function test_a_gross_unit_price_rounds_before_the_quantity_multiplies_it(): void
    {
        $unit = CustomerLineFigures::grossUnitPrice('33.33', '14.000');

        self::assertSame('38.000000', $unit);
        self::assertSame('114.000000', CustomerLineFigures::grossLineTotal($unit, '3'));
    }
}
