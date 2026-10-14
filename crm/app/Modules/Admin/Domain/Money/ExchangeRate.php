<?php

declare(strict_types=1);

namespace App\Modules\Admin\Domain\Money;

/**
 * One manual rate between two currencies.
 *
 * `DB-06` requires every amount to store `amount · currency · fx_rate_at_time ·
 * base_amount`, and `D-09` fixes the rate onto a quotation at creation and
 * forbids recomputing it. Together those make a rate a **recorded fact about a
 * moment**, not a live lookup — which is why editing one preserves history
 * (`§13` screen 5), why the change is audited (`§3.12` rule 4), and why `J-12`
 * exists to complain when one goes stale.
 *
 * A decimal string at `D-68`'s FX scale. `DB-07` forbids float, and a rate is
 * the multiplier that reaches every line of every converted quotation (or,
 * reversed, the divisor — `D-115`).
 */
final readonly class ExchangeRate
{
    /**
     * `D-68`'s FX scale — `NUMERIC(18,8)`.
     *
     * Restated rather than imported, for the reason `RoundedTotal::SCALE`
     * gives: Domain may not reach `App\Support\Database\Precision`. The test
     * asserts the two agree.
     */
    public const SCALE = 8;

    /**
     * @param  numeric-string  $rate
     * @param  numeric-string|null  $divisor  the recorded rate this one reverses (`D-115`); null for a recorded direction
     */
    private function __construct(
        private CurrencyCode $from,
        private CurrencyCode $to,
        private string $rate,
        private ?string $divisor = null,
    ) {}

    public static function of(CurrencyCode $from, CurrencyCode $to, string $rate): self
    {
        return new self($from, $to, bcadd(Decimal::of($rate, 'an exchange rate'), '0', self::SCALE));
    }

    /**
     * A currency against itself.
     *
     * The only rate this system may state without being told it: it is an
     * identity, not a price. Every other rate is a number somebody in the
     * business enters.
     */
    public static function identity(CurrencyCode $code): self
    {
        return self::of($code, $code, '1');
    }

    /**
     * `D-115`: a recorded `from→to = r` also prices `to→from`. Its rate is 1/r at
     * this scale, but {@see convert()} divides by r itself, so 30000 at 30 is
     * exactly 1000 rather than 30000 × 0.03333333.
     */
    public static function inverseOf(self $recorded): self
    {
        return new self($recorded->to, $recorded->from, bcdiv('1', $recorded->rate, self::SCALE), $recorded->rate);
    }

    /**
     * An amount in `from`, in `to` — truncated at `$scale`, never rounded
     * (owner's ruling 2026-09-07). Multiplies by a recorded rate, divides by the
     * one an inverse reverses (`D-115`).
     *
     * @param  numeric-string  $amount
     * @return numeric-string
     */
    public function convert(string $amount, int $scale): string
    {
        return $this->divisor === null
            ? bcmul($amount, $this->rate, $scale)
            : bcdiv($amount, $this->divisor, $scale);
    }

    public function from(): CurrencyCode
    {
        return $this->from;
    }

    public function to(): CurrencyCode
    {
        return $this->to;
    }

    /** @return numeric-string */
    public function rate(): string
    {
        return $this->rate;
    }
}
