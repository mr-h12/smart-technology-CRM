<?php

declare(strict_types=1);

namespace App\Modules\Admin\Domain\Money;

use RuntimeException;

/**
 * The currency definitions a fresh database starts with.
 *
 * `§5.3`'s table is the whole of it: EGP rounds to the pound, USD and EUR to
 * the cent, and `D-65` lets any of them switch rounding off later under
 * `System Settings → Currencies`. Those are values a new installation begins
 * at, not rules — `AP-08` and `§3.12` rule 5 both put them in the database,
 * where they are edited.
 *
 * **Two things are stated here as assumptions rather than as findings, because
 * the specification does not give them:**
 *
 * 1. **EGP is the base currency.** `§13` screen 5 names "base currency" as a
 *    settings field and never says which. EGP is the company's own currency —
 *    §5.2's worked example is PO #226 in pounds, and §5.3 writes its unit as
 *    "1 pound" — so EGP is the only reading, but it is a reading.
 * 2. **Rounding starts on.** `§5.3` describes switching it *off* as the edit,
 *    which makes on the state it is edited from.
 *
 * **And one thing is deliberately absent: exchange rates.** `§13` screen 5
 * makes every rate manual and `J-12` alerts when one is stale, so no rate for
 * USD or EUR is seeded. `D-09` captures the rate onto a quotation at creation
 * and forbids recomputation — a placeholder would therefore not stay a
 * placeholder, it would be frozen onto an issued document as though it were
 * real. The only rate below is the base against itself, which is an identity.
 */
final class Currencies
{
    /** @return list<Currency> */
    public static function all(): array
    {
        return [
            // §5.3: "EGP | 1 pound".
            new Currency(CurrencyCode::Egp, RoundingRule::to('1'), true),

            // §5.3: "USD | 0.01 dollar".
            new Currency(CurrencyCode::Usd, RoundingRule::to('0.01'), false),

            // §5.3: "EUR | 0.01 euro".
            new Currency(CurrencyCode::Eur, RoundingRule::to('0.01'), false),
        ];
    }

    public static function find(CurrencyCode $code): ?Currency
    {
        foreach (self::all() as $currency) {
            if ($currency->code() === $code) {
                return $currency;
            }
        }

        return null;
    }

    public static function base(): Currency
    {
        foreach (self::all() as $currency) {
            if ($currency->isBase()) {
                return $currency;
            }
        }

        throw new RuntimeException('No base currency is defined; DB-06 has nothing to express base_amount in.');
    }

    /**
     * Every rate this system may assert on its own.
     *
     * Exactly one, and it is a tautology.
     *
     * @return list<ExchangeRate>
     */
    public static function seededRates(): array
    {
        return [ExchangeRate::identity(self::base()->code())];
    }
}
