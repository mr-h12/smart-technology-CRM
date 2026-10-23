<?php

declare(strict_types=1);

namespace Tests\Feature\Quotations;

use App\Modules\Quotations\Domain\Pricing\IncludedTax;
use PHPUnit\Framework\TestCase;

/**
 * QS.2a — `D-156`: a line's tax is taken out of the entered price,
 * `net = entered ÷ (1 + t/100)`, truncated to 6 places (a division; the
 * owner's answer 2). The divisor carries `PricedLine`'s two guard digits: a
 * NUMERIC(6,3) rate over 100 is exact at eight. Pure, so PHPUnit's own
 * `TestCase`, for the reason `PricedLineTest` gives.
 */
final class IncludedTaxTest extends TestCase
{
    public function test_fourteen_percent_comes_out_of_a_hundred_and_fourteen(): void
    {
        // `D-156` example (1).
        self::assertSame('100.000000', IncludedTax::net('114', '14'));
    }

    public function test_a_net_that_does_not_divide_evenly_is_truncated_not_rounded(): void
    {
        // `D-156` example (2): 90 ÷ 1.1 = 81.8181818…
        self::assertSame('81.818181', IncludedTax::net('90', '10'));
        self::assertNotSame('81.818182', IncludedTax::net('90', '10'));
    }

    public function test_a_zero_rate_takes_nothing_out(): void
    {
        // `D-157`: a 0% line is allowed inside a tax-inclusive quotation.
        self::assertSame('50.000000', IncludedTax::net('50', '0'));
    }

    public function test_a_three_place_rate_is_taken_out_exactly(): void
    {
        // The column's NUMERIC(6,3): 100 ÷ 1.01007 = 99.0030393… → 99.003039.
        self::assertSame('99.003039', IncludedTax::net('100', '1.007'));
    }
}
