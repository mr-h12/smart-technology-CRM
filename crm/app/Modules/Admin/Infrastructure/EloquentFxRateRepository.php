<?php

declare(strict_types=1);

namespace App\Modules\Admin\Infrastructure;

use App\Modules\Admin\Domain\Contracts\FxRateRepositoryInterface;
use App\Modules\Admin\Domain\Listing\ListingQuery;
use App\Modules\Admin\Domain\Listing\Page;
use App\Modules\Admin\Domain\Money\CurrencyCode;
use App\Modules\Admin\Domain\Money\ExchangeRate;
use App\Modules\Admin\Domain\Money\RateAlreadyRecorded;
use App\Modules\Admin\Domain\Money\RecordedRate;
use App\Modules\Admin\Infrastructure\Eloquent\Currency as CurrencyRow;
use App\Modules\Admin\Infrastructure\Eloquent\FxRate as FxRateRow;
use DateTimeImmutable;
use Illuminate\Database\QueryException;

/**
 * `fx_rates`, mapped back into the domain objects the rest of the system speaks.
 *
 * ── Why two queries and no join ────────────────────────────────────────────
 *
 * The rows are keyed on `currencies.id` (`DB-04` wants a real foreign key, and
 * the code's uniqueness is partial so it cannot be one's target), while the API
 * speaks in codes. A join would answer that in one round trip and hand back a
 * model carrying two aliased columns that static analysis can only see as
 * `mixed`. There are three currencies; the map is loaded whole, once, and the
 * mapping stays typed. If that table ever grows past a screenful this is worth
 * revisiting, and `AP-08` says it might.
 *
 * **The map includes archived currencies.** A rate recorded against a currency
 * that has since been archived is still a fact about the past — dropping it
 * from the history would rewrite it, which is the opposite of `AP-06`. What an
 * archived currency stops is a *new* rate, and that refusal lives in the
 * Application layer where "this system does not offer EUR" is answerable.
 */
final readonly class EloquentFxRateRepository implements FxRateRepositoryInterface
{
    public function history(ListingQuery $query): Page
    {
        $codes = $this->codesById();

        // §6.2's documented default order. `created_at` breaks the tie because
        // two pairs may share a moment, and a paginated listing with an
        // unstable order drops and repeats rows between pages.
        $rows = FxRateRow::query()
            ->orderByDesc('effective_from')
            ->orderByDesc('created_at')
            ->offset($query->offset())
            ->limit($query->perPage)
            ->get();

        $rates = [];

        foreach ($rows as $row) {
            $rate = $this->map($row, $codes);

            if ($rate instanceof RecordedRate) {
                $rates[] = $rate;
            }
        }

        return new Page(
            items: $rates,
            total: FxRateRow::query()->count(),
            page: $query->page,
            perPage: $query->perPage,
        );
    }

    public function record(ExchangeRate $rate, DateTimeImmutable $effectiveFrom): RecordedRate
    {
        $row = new FxRateRow;
        $row->fill([
            'from_currency_id' => $this->identifierOf($rate->from()),
            'to_currency_id' => $this->identifierOf($rate->to()),
            'rate' => $rate->rate(),
            'effective_from' => $effectiveFrom,
        ]);

        try {
            $row->save();
        } catch (QueryException $refused) {
            // 23505 is `unique_violation`, which on this table can only be
            // `fx_rates_pair_moment_unique_alive`. Read from the database's own
            // refusal rather than from a read-then-write check, which two
            // administrators saving at once would both pass.
            if ($refused->getCode() === '23505') {
                throw new RateAlreadyRecorded;
            }

            throw $refused;
        }

        $stored = $this->map($row->refresh(), $this->codesById());

        if (! $stored instanceof RecordedRate) {
            // Unreachable through the Application layer, which resolved both
            // codes before calling. Stated rather than assumed: a null here
            // would otherwise surface as a type error two frames away.
            throw new RateAlreadyRecorded;
        }

        return $stored;
    }

    public function effectiveRate(CurrencyCode $from, CurrencyCode $to, DateTimeImmutable $at): ?ExchangeRate
    {
        // A currency against itself needs no row, and `fx_rates_distinct_currencies`
        // means it could never have one — the identity is a fact, not a price.
        if ($from === $to) {
            return ExchangeRate::identity($from);
        }

        // Codes to ids in two `value()` reads rather than a join, for the reason
        // the class docblock gives: a joined, aliased column is `mixed` to static
        // analysis. An absent row means "this system does not offer that
        // currency", which is no rate rather than an error on a read.
        $fromId = CurrencyRow::query()->where('code', $from->value)->value('id');
        $toId = CurrencyRow::query()->where('code', $to->value)->value('id');

        if (! is_string($fromId) || ! is_string($toId)) {
            return null;
        }

        // `fx_rates_pair_recent` (Point 1.2) is this query: the pair, newest
        // effective_from first, capped at the moment. `SoftDeletes` drops a
        // withdrawn rate without a `where` of its own.
        $row = FxRateRow::query()
            ->where('from_currency_id', $fromId)
            ->where('to_currency_id', $toId)
            ->where('effective_from', '<=', $at)
            ->orderByDesc('effective_from')
            ->first();

        if (! $row instanceof FxRateRow) {
            return null;
        }

        return ExchangeRate::of($from, $to, $row->rate);
    }

    /** @return array<string, CurrencyCode> */
    private function codesById(): array
    {
        $codes = [];

        foreach (CurrencyRow::withTrashed()->get() as $row) {
            $code = CurrencyCode::tryFrom($row->code);

            if ($code instanceof CurrencyCode) {
                $codes[$row->id] = $code;
            }
        }

        return $codes;
    }

    /**
     * A row whose currency is not one of `CurrencyCode`'s cases is skipped
     * rather than mapped to a guess — the rule `EloquentCurrencyRepository`
     * already applies to `currencies` itself.
     *
     * @param  array<string, CurrencyCode>  $codes
     */
    private function map(FxRateRow $row, array $codes): ?RecordedRate
    {
        $from = $codes[$row->from_currency_id] ?? null;
        $to = $codes[$row->to_currency_id] ?? null;

        if (! $from instanceof CurrencyCode || ! $to instanceof CurrencyCode) {
            return null;
        }

        return new RecordedRate(
            id: $row->id,
            rate: ExchangeRate::of($from, $to, $row->rate),
            effectiveFrom: DateTimeImmutable::createFromInterface($row->effective_from),
            recordedAt: DateTimeImmutable::createFromInterface($row->created_at),
        );
    }

    private function identifierOf(CurrencyCode $code): string
    {
        // `firstOrFail()` and not a null check: the Application layer has
        // already asked `CurrencyRepositoryInterface::find()` about both codes,
        // so an absent row here is a bug rather than a refusal.
        return CurrencyRow::query()->where('code', $code->value)->firstOrFail()->id;
    }
}
