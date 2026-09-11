<?php

declare(strict_types=1);

namespace App\Modules\Admin\Domain\Contracts;

use App\Modules\Admin\Domain\Listing\ListingQuery;
use App\Modules\Admin\Domain\Listing\Page;
use App\Modules\Admin\Domain\Money\CurrencyCode;
use App\Modules\Admin\Domain\Money\ExchangeRate;
use App\Modules\Admin\Domain\Money\RateAlreadyRecorded;
use App\Modules\Admin\Domain\Money\RecordedRate;
use DateTimeImmutable;

/**
 * `fx_rates` — §13 screen 5's *rate history*, and the one way to add to it.
 *
 * **There is no update and there never will be.** §5.6: *"Changing an FX rate
 * never affects an existing quotation"*, and `AP-06` files rates under
 * append-only critical data. Point 1.2 put that in the database as a `BEFORE
 * UPDATE` trigger; this interface says the same thing in the type system, so a
 * later module cannot reach for a method that does not exist.
 */
interface FxRateRepositoryInterface
{
    /** @return Page<RecordedRate> */
    public function history(ListingQuery $query): Page;

    /**
     * Append one rate. Both currencies must be live rows; the caller checks
     * that, because *"this system does not offer GBP"* is a refusal the
     * Application layer answers and not a database error.
     *
     * @throws RateAlreadyRecorded when the pair already has a rate at that moment
     */
    public function record(ExchangeRate $rate, DateTimeImmutable $effectiveFrom): RecordedRate;

    /**
     * The rate that converts `$from` into `$to` as of `$at`: the newest recorded
     * rate whose `effective_from` is at or before that moment, the identity when
     * the two codes are the same, or `null` when the pair has no such rate.
     *
     * Reading the history is not editing it — this leaves `AP-06`'s append-only
     * rule untouched. `§5.1`'s `fx_rate_at_time` and `D-09` are why the moment is
     * a parameter and not `now()`: a quotation captures the rate that was in
     * force when it was created, and a rate recorded to start later is not yet
     * that rate. Only the exact directional pair is consulted; an inverse rate
     * is never divided into one, because a rate is a number the business enters
     * (§5.6) and inverting it at another scale would invent precision `D-68`
     * did not record.
     */
    public function effectiveRate(CurrencyCode $from, CurrencyCode $to, DateTimeImmutable $at): ?ExchangeRate;
}
