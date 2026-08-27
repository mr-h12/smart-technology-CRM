<?php

declare(strict_types=1);

namespace App\Modules\Admin\Application\Money;

use App\Modules\Admin\Domain\Contracts\CurrencyRepositoryInterface;
use App\Modules\Admin\Domain\Contracts\FxRateRepositoryInterface;
use App\Modules\Admin\Domain\Money\Currency;
use App\Modules\Admin\Domain\Money\CurrencyCode;
use App\Modules\Admin\Domain\Money\ExchangeRate;
use App\Modules\Admin\Domain\Money\RateAlreadyRecorded;
use App\Modules\Admin\Domain\Money\RecordedRate;
use App\Modules\Audit\Domain\AuditEvent;
use App\Modules\Audit\Domain\Contracts\AuditRecorderInterface;
use DateTimeImmutable;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Validation\ValidationException;

/**
 * §13 screen 5's *manual rate per currency* — one new rate, appended.
 *
 * **A new price is a new row, and this class never attempts otherwise.** §5.6:
 * *"Changing an FX rate never affects an existing quotation"*, `AP-06` files
 * rates under append-only critical data, and Point 1.2 made an `UPDATE` a
 * database error (`FXH01`). There is therefore no edit path to guard here —
 * the guarantee is structural rather than enforced by this code, which is why
 * it survives a future module that forgets it exists.
 *
 * **The audit entry is mandatory, not discretionary.** §3.12 rule 4 lists *"FX
 * rate change"* among the nine, so unlike `SETTINGS_UPDATED` (Point 3.1) and
 * `CURRENCY_ROUNDING_UPDATED` (Point 3.2) — both written on `AUD-01`'s general
 * grounds — this one is named by the document. `AuditEvent::fxRateChanged()`
 * exists for exactly this caller.
 *
 * **`oldValues` is null, deliberately.** The audit contract says *"absent on a
 * create"* and this is a create: the previous rate for the pair is not being
 * replaced, it is still in `fx_rates` and still applies to everything issued
 * under it. Copying it into the entry would put a second copy of history in a
 * table that cannot be corrected (`AUD-03`) if the two ever disagreed.
 *
 * **One transaction** (`DB-11`) around the insert and its entry: a rate that
 * exists with no audit row is precisely what §3.12 rule 4 forbids.
 */
final readonly class RecordFxRate
{
    public function __construct(
        private ConnectionInterface $connection,
        private CurrencyRepositoryInterface $currencies,
        private FxRateRepositoryInterface $rates,
        private AuditRecorderInterface $audit,
    ) {}

    /**
     * @throws ValidationException when a currency is not offered, the pair is
     *                             one currency, or the moment is taken
     */
    public function handle(string $from, string $to, string $rate, DateTimeImmutable $effectiveFrom): RecordedRate
    {
        $fromCode = $this->offered($from, 'from_currency');
        $toCode = $this->offered($to, 'to_currency');

        // `fx_rates_distinct_currencies` says this too, and says it last. The
        // boundary says it first so the caller gets the field name rather than
        // a 500 carrying a constraint name.
        if ($fromCode === $toCode) {
            throw ValidationException::withMessages([
                'to_currency' => __('admin.fx_rate.same_currency'),
            ]);
        }

        return $this->connection->transaction(function () use ($fromCode, $toCode, $rate, $effectiveFrom): RecordedRate {
            try {
                $recorded = $this->rates->record(
                    ExchangeRate::of($fromCode, $toCode, $rate),
                    $effectiveFrom,
                );
            } catch (RateAlreadyRecorded) {
                throw ValidationException::withMessages([
                    'effective_from' => __('admin.fx_rate.already_recorded'),
                ]);
            }

            $this->audit->record(
                AuditEvent::fxRateChanged(),
                'fx_rates',
                $recorded->id,
                null,
                [
                    'from_currency' => $fromCode->value,
                    'to_currency' => $toCode->value,
                    'rate' => $recorded->rate->rate(),
                    'effective_from' => $recorded->effectiveFrom->format(DATE_ATOM),
                ],
            );

            return $recorded;
        });
    }

    /**
     * A code this system offers *right now*. `find()` excludes archived rows
     * (`D-34`), which is the difference between "GBP was never a currency here"
     * and "EUR is no longer one" — neither may receive a new rate, and both are
     * the same answer to the caller.
     *
     * @throws ValidationException
     */
    private function offered(string $code, string $field): CurrencyCode
    {
        $currency = CurrencyCode::tryFrom(strtoupper($code));

        if ($currency instanceof CurrencyCode && $this->currencies->find($currency) instanceof Currency) {
            return $currency;
        }

        throw ValidationException::withMessages([$field => __('admin.fx_rate.unknown_currency')]);
    }
}
