<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Modules\Admin\Domain\Contracts\FxRateRepositoryInterface;
use App\Modules\Admin\Domain\Money\CurrencyCode;
use App\Modules\Admin\Domain\Money\ExchangeRate;
use Database\Seeders\CurrencySeeder;
use DateTimeImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Module 7, Point 3.3a — `FxRateRepositoryInterface::effectiveRate()`, the read
 * §5.1's `fx_rate_at_time` needs and the append-only history did not yet offer.
 *
 * `D-09` fixes a quotation's rate at creation, so the read takes the moment
 * rather than assuming now: a rate recorded to start *after* that moment is not
 * yet in force. The pair is consulted in one direction only — a rate is an
 * entered fact (§5.6), and inverting `USD→EGP` into `EGP→USD` would invent a
 * precision `D-68` never recorded.
 */
final class EloquentFxRateRepositoryTest extends TestCase
{
    use RefreshDatabase;

    private FxRateRepositoryInterface $rates;

    protected function setUp(): void
    {
        parent::setUp();

        // A rate needs two live currencies to be a rate between.
        $this->seed(CurrencySeeder::class);

        $rates = $this->app->make(FxRateRepositoryInterface::class);
        self::assertInstanceOf(FxRateRepositoryInterface::class, $rates);
        $this->rates = $rates;
    }

    /**
     * A currency against itself is the identity — rate `1`, and no row required,
     * which is just as well: `fx_rates_distinct_currencies` forbids the table
     * from ever holding one.
     */
    public function test_that_a_currency_against_itself_is_the_identity(): void
    {
        $rate = $this->rates->effectiveRate(CurrencyCode::Egp, CurrencyCode::Egp, new DateTimeImmutable);

        self::assertInstanceOf(ExchangeRate::class, $rate);
        self::assertSame('1.00000000', $rate->rate());
        self::assertSame(CurrencyCode::Egp, $rate->from());
        self::assertSame(CurrencyCode::Egp, $rate->to());
    }

    /** A pair with nothing recorded has no rate — the caller decides what that means. */
    public function test_that_an_unrecorded_pair_has_no_rate(): void
    {
        self::assertNull(
            $this->rates->effectiveRate(CurrencyCode::Usd, CurrencyCode::Egp, new DateTimeImmutable),
        );
    }

    /**
     * The newest rate at or before the moment is the one in force. Two rates for
     * the same pair, and the moment picks between them.
     */
    public function test_that_the_newest_rate_at_or_before_the_moment_is_used(): void
    {
        $this->rates->record(
            ExchangeRate::of(CurrencyCode::Usd, CurrencyCode::Egp, '30'),
            new DateTimeImmutable('2026-01-01'),
        );
        $this->rates->record(
            ExchangeRate::of(CurrencyCode::Usd, CurrencyCode::Egp, '48'),
            new DateTimeImmutable('2026-06-01'),
        );

        $march = $this->rates->effectiveRate(CurrencyCode::Usd, CurrencyCode::Egp, new DateTimeImmutable('2026-03-01'));
        self::assertInstanceOf(ExchangeRate::class, $march);
        self::assertSame('30.00000000', $march->rate());

        $july = $this->rates->effectiveRate(CurrencyCode::Usd, CurrencyCode::Egp, new DateTimeImmutable('2026-07-01'));
        self::assertInstanceOf(ExchangeRate::class, $july);
        self::assertSame('48.00000000', $july->rate());
    }

    /** A rate that only starts applying later is not in force yet (`D-09`). */
    public function test_that_a_rate_effective_after_the_moment_is_ignored(): void
    {
        $this->rates->record(
            ExchangeRate::of(CurrencyCode::Usd, CurrencyCode::Egp, '48'),
            new DateTimeImmutable('2026-06-01'),
        );

        self::assertNull(
            $this->rates->effectiveRate(CurrencyCode::Usd, CurrencyCode::Egp, new DateTimeImmutable('2026-05-01')),
        );
    }

    /** One direction only: `USD→EGP` recorded does not answer `EGP→USD`. */
    public function test_that_the_inverse_pair_is_not_derived(): void
    {
        $this->rates->record(
            ExchangeRate::of(CurrencyCode::Usd, CurrencyCode::Egp, '48'),
            new DateTimeImmutable('2026-01-01'),
        );

        self::assertNull(
            $this->rates->effectiveRate(CurrencyCode::Egp, CurrencyCode::Usd, new DateTimeImmutable),
        );
    }
}
