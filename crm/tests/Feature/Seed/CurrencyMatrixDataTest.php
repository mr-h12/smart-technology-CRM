<?php

declare(strict_types=1);

namespace Tests\Feature\Seed;

use App\Modules\Admin\Domain\Money\Currencies;
use App\Modules\Admin\Domain\Money\Currency;
use App\Modules\Admin\Domain\Money\CurrencyCode;
use App\Modules\Admin\Domain\Money\ExchangeRate;
use App\Modules\Admin\Domain\Money\RoundedTotal;
use App\Modules\Admin\Domain\Money\RoundingRule;
use App\Support\Database\Precision;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Point 7.3 — the three currencies, their rounding, and what an FX rate is.
 *
 * `D-52` fixes a rounding unit per currency (EGP `1`, USD and EUR `0.01`),
 * `D-65` makes rounding itself optional per currency, `D-06` confines it to the
 * final total, and `D-09` captures an FX rate onto a quotation at creation and
 * never recomputes it. `DB-07` forbids float anywhere near a price and `D-68`
 * fixes the precisions.
 *
 * Two things are asserted here that are easy to state and easy to lose:
 *
 * 1. **Rounding off means `final_total = total_before_round` and
 *    `rounding_diff = 0`** — the same invariant `design/DATABASE.md` writes as
 *    `CHECK (rounding_enabled OR rounding_diff = 0)`.
 * 2. **No float touches any of it.** Not asserted by reading the code, which is
 *    how a float survives review, but by tokenising it: a float literal, a
 *    `(float)` cast or a call to `round()` fails this file.
 */
final class CurrencyMatrixDataTest extends TestCase
{
    // ────────────────────────────────────── the currencies themselves

    public function test_the_three_documented_currencies_are_defined(): void
    {
        self::assertSame(
            ['EGP', 'USD', 'EUR'],
            array_map(static fn (Currency $c): string => $c->code()->value, Currencies::all()),
        );
    }

    /** @return array<string, array{0: CurrencyCode, 1: string}> */
    public static function documentedUnits(): array
    {
        // §5.3's table, verbatim: "EGP | 1 pound", "USD | 0.01 dollar",
        // "EUR | 0.01 euro".
        return [
            'EGP rounds to the pound' => [CurrencyCode::Egp, '1'],
            'USD rounds to the cent' => [CurrencyCode::Usd, '0.01'],
            'EUR rounds to the cent' => [CurrencyCode::Eur, '0.01'],
        ];
    }

    #[DataProvider('documentedUnits')]
    public function test_the_rounding_units_match_the_documented_table(CurrencyCode $code, string $unit): void
    {
        self::assertSame($unit, Currencies::find($code)?->rounding()->unit());
    }

    public function test_rounding_starts_enabled_for_every_currency(): void
    {
        // §5.3 describes switching rounding *off* as the edit, which makes on
        // the state it is edited from. D-65 keeps it a per-currency setting
        // either way; this is the value a fresh database starts at, not a rule.
        foreach (Currencies::all() as $currency) {
            self::assertTrue($currency->rounding()->isEnabled(),
                "{$currency->code()->value} ships with rounding switched off.");
        }
    }

    public function test_a_rounding_unit_is_a_positive_decimal_string(): void
    {
        foreach (Currencies::all() as $currency) {
            $unit = $currency->rounding()->unit();

            self::assertMatchesRegularExpression('/^\d+(\.\d+)?$/D', $unit,
                "DB-07: {$currency->code()->value}'s unit must be a decimal string, not a number.");
            self::assertSame(1, bccomp($unit, '0', 8),
                "A unit of {$unit} would make rounding either meaningless or a division by zero.");
        }
    }

    public function test_exactly_one_currency_is_the_base(): void
    {
        $base = array_filter(Currencies::all(), static fn (Currency $c): bool => $c->isBase());

        self::assertCount(1, $base);
        self::assertSame(CurrencyCode::Egp, Currencies::base()->code());
    }

    // ─────────────────────────────────────────── rounding behaviour

    public function test_rounding_on_rounds_the_final_total_to_the_currency_unit(): void
    {
        // §5.2's worked example — PO #226 under the documented ordering.
        $rounded = Currencies::base()->rounding()->apply('8315.9988');

        self::assertSame('8316.000000', $rounded->finalTotal());
        self::assertSame('0.001200', $rounded->roundingDiff());
    }

    /** @return array<string, array{0: string, 1: string, 2: string, 3: string}> */
    public static function roundingCases(): array
    {
        // The build plan's own acceptance rows for Module 7.
        return [
            'EGP 1234.67 to the pound' => ['1', '1234.67', '1235.000000', '0.330000'],
            'USD 1234.678 to the cent' => ['0.01', '1234.678', '1234.680000', '0.002000'],
            'an exact unit is left alone' => ['1', '1234.000000', '1234.000000', '0.000000'],
        ];
    }

    #[DataProvider('roundingCases')]
    public function test_documented_rounding_rows(string $unit, string $total, string $final, string $diff): void
    {
        $rounded = RoundingRule::to($unit)->apply($total);

        self::assertSame($final, $rounded->finalTotal());
        self::assertSame($diff, $rounded->roundingDiff());
    }

    public function test_rounding_off_keeps_the_total_and_a_zero_difference(): void
    {
        // D-65, and the constraint design/DATABASE.md writes as
        // CHECK (rounding_enabled OR rounding_diff = 0).
        $rounded = RoundingRule::disabled('1')->apply('8315.9988');

        self::assertSame('8315.998800', $rounded->finalTotal());
        self::assertSame('0.000000', $rounded->roundingDiff());
    }

    public function test_switching_rounding_off_changes_the_answer(): void
    {
        // Otherwise the test above passes on a currency whose unit happens to
        // divide the total, and proves nothing about the switch.
        $on = RoundingRule::to('1')->apply('8315.9988');
        $off = RoundingRule::disabled('1')->apply('8315.9988');

        self::assertNotSame($on->finalTotal(), $off->finalTotal());
        self::assertSame('0.000000', $off->roundingDiff());
    }

    public function test_a_half_unit_rounds_up(): void
    {
        // 0.145 at a unit of 0.01 is the case that separates decimal
        // arithmetic from binary: 0.145 / 0.01 is 14.499999999999998 as a
        // double, so PHP's round() answers 14 and the total comes back 0.14.
        // BCMath divides exactly, giving 14.5, and half rounds up to 0.15.
        //
        // Half-up is an assumption: §5.2 writes round(total, unit) and never
        // says which way a half goes. It is the conventional financial
        // default and it is pinned here so a change to it is deliberate.
        self::assertSame('0.150000', RoundingRule::to('0.01')->apply('0.145')->finalTotal());
        self::assertSame('3.000000', RoundingRule::to('1')->apply('2.5')->finalTotal());
    }

    public function test_the_rounding_difference_keeps_full_money_precision(): void
    {
        // Twelve integer digits and six decimals is D-68's money column. The
        // difference is where a float would show first, because it is a small
        // number computed from two large ones.
        $rounded = RoundingRule::to('1')->apply('123456789012.345678');

        self::assertSame('123456789012.000000', $rounded->finalTotal());
        self::assertSame('-0.345678', $rounded->roundingDiff());
    }

    public function test_a_total_at_the_full_width_of_the_money_column_survives(): void
    {
        // Eighteen significant digits — D-68's NUMERIC(18,6) exactly at its
        // width. A double carries about sixteen, so this value becomes 1.0E+12
        // and the string it prints is not even a plain decimal any more.
        //
        $rounded = RoundingRule::to('1')->apply('999999999999.999999');

        self::assertSame('1000000000000.000000', $rounded->finalTotal());
        self::assertSame('0.000001', $rounded->roundingDiff());
    }

    public function test_the_cent_of_a_twelve_digit_total_is_still_exact(): void
    {
        // The one arithmetic case measured to notice a float, and it took
        // looking to find. PHP prints a double at precision=14, so
        // (float) 0.145 / (float) 0.01 comes back as the *string* "14.5" and a
        // float implementation gets every documented row right. At a twelve-
        // digit total against the cent the quotient reaches 1e14, where the
        // same conversion yields "1.0E+14" — which BCMath refuses outright.
        //
        // Everywhere below that width a float is silent, which is the whole
        // argument for test_no_float_appears_anywhere_in_the_money_namespace:
        // arithmetic review does not find this defect, reading the tokens does.
        $rounded = RoundingRule::to('0.01')->apply('999999999999.995');

        self::assertSame('1000000000000.000000', $rounded->finalTotal());
        self::assertSame('0.005000', $rounded->roundingDiff());
    }

    // ───────────────────────────────────────────────── exchange rates

    public function test_the_base_currency_converts_at_exactly_one(): void
    {
        $rates = Currencies::seededRates();

        self::assertCount(1, $rates, 'Only the base identity is a fact; every other rate is a market price.');

        $identity = $rates[0];
        self::assertSame(CurrencyCode::Egp, $identity->from());
        self::assertSame(CurrencyCode::Egp, $identity->to());
        self::assertSame('1.00000000', $identity->rate());
    }

    public function test_no_rate_is_invented_for_the_foreign_currencies(): void
    {
        // §13 screen 5 makes the rate manual and J-12 alerts when one goes
        // stale. A seeded placeholder would be captured onto a quotation by
        // D-09 and never recomputed — a wrong number, frozen, on an issued
        // document. Absence is the correct value until the business supplies
        // the real ones.
        foreach ([CurrencyCode::Usd, CurrencyCode::Eur] as $foreign) {
            foreach (Currencies::seededRates() as $rate) {
                self::assertNotSame($foreign, $rate->from(),
                    "A rate was seeded for {$foreign->value}; rates are entered, not guessed.");
            }
        }
    }

    public function test_a_rate_is_carried_at_the_precision_d68_fixes(): void
    {
        $rate = ExchangeRate::of(CurrencyCode::Usd, CurrencyCode::Egp, '48.3');

        self::assertSame('48.30000000', $rate->rate());
        self::assertSame(Precision::FX_SCALE, ExchangeRate::SCALE,
            'D-68 puts FX rates at NUMERIC(18,8); two copies of that number will drift.');
    }

    public function test_totals_are_carried_at_the_money_precision_d68_fixes(): void
    {
        self::assertSame(Precision::MONEY_SCALE, RoundedTotal::SCALE,
            'D-68 puts money at NUMERIC(18,6); two copies of that number will drift.');
    }

    // ────────────────────────────── what a decimal string may not be

    /** @return array<string, array{0: string}> */
    public static function rejectedUnits(): array
    {
        // is_numeric() accepts all but the last of these. A rounding unit that
        // arrived as 1e2 would multiply through every line of every quotation
        // in that currency, and nothing about the string reads as wrong.
        return [
            'exponent notation' => ['1e2'],
            'leading whitespace' => [' 1'],
            'a trailing dot' => ['1.'],
            'a comma' => ['0,01'],
            'not a number at all' => ['one'],
            'zero, which divides into nothing' => ['0'],
            'negative' => ['-1'],
        ];
    }

    #[DataProvider('rejectedUnits')]
    public function test_a_rounding_unit_that_is_not_a_plain_positive_decimal_is_refused(string $unit): void
    {
        $this->expectException(InvalidArgumentException::class);

        RoundingRule::to($unit);
    }

    public function test_a_total_that_is_not_a_plain_decimal_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        RoundingRule::to('1')->apply('8.3e3');
    }

    public function test_an_exchange_rate_in_exponent_notation_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        ExchangeRate::of(CurrencyCode::Usd, CurrencyCode::Egp, '4.83e1');
    }

    // ─────────────────────────────────────── DB-07, checked as tokens

    public function test_no_float_appears_anywhere_in_the_money_namespace(): void
    {
        // Tokenised rather than grepped. A search for the word "float" hits
        // every comment that explains why there is none, and a search for
        // "round(" hits RoundingRule. The tokeniser sees only code: a float
        // literal, a (float)/(double) cast, or a call to one of the functions
        // that returns one.
        $forbidden = ['floatval', 'round', 'number_format', 'fdiv', 'floor', 'ceil', 'intdiv'];
        $offences = [];
        $files = 0;

        foreach (glob(self::moneyDirectory().'/*.php') ?: [] as $file) {
            $files++;
            $tokens = token_get_all((string) file_get_contents($file));

            foreach ($tokens as $index => $token) {
                if (! is_array($token)) {
                    continue;
                }

                if ($token[0] === T_DNUMBER) {
                    $offences[] = basename($file).':'.$token[2].' float literal '.$token[1];
                }

                if ($token[0] === T_DOUBLE_CAST) {
                    $offences[] = basename($file).':'.$token[2].' float cast';
                }

                if ($token[0] === T_STRING && in_array(strtolower($token[1]), $forbidden, true)
                    && self::isCall($tokens, $index)) {
                    $offences[] = basename($file).':'.$token[2].' call to '.$token[1].'()';
                }
            }
        }

        self::assertGreaterThan(3, $files, 'The scanner read almost nothing.');
        self::assertSame([], $offences,
            "DB-07 forbids float anywhere near a price:\n".implode("\n", $offences));
    }

    public function test_the_token_scanner_can_actually_see_a_float(): void
    {
        // The scanner above passes on an empty directory just as happily. This
        // runs the same recognition over a string that is known to contain
        // every shape it looks for.
        $tokens = token_get_all('<?php $a = 1.5; $b = (float) $a; $c = round($a);');
        $seen = [];

        foreach ($tokens as $index => $token) {
            if (! is_array($token)) {
                continue;
            }

            if ($token[0] === T_DNUMBER) {
                $seen[] = 'literal';
            }

            if ($token[0] === T_DOUBLE_CAST) {
                $seen[] = 'cast';
            }

            if ($token[0] === T_STRING && strtolower($token[1]) === 'round' && self::isCall($tokens, $index)) {
                $seen[] = 'call';
            }
        }

        self::assertSame(['literal', 'cast', 'call'], $seen);
    }

    /** @param  list<array{0: int, 1: string, 2: int}|string>  $tokens */
    private static function isCall(array $tokens, int $index): bool
    {
        for ($i = $index + 1, $n = count($tokens); $i < $n; $i++) {
            $next = $tokens[$i];

            if (is_array($next) && $next[0] === T_WHITESPACE) {
                continue;
            }

            return $next === '(';
        }

        return false;
    }

    private static function moneyDirectory(): string
    {
        return dirname(__DIR__, 3).'/app/Modules/Admin/Domain/Money';
    }
}
