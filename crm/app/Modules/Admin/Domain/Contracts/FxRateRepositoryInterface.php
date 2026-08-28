<?php

declare(strict_types=1);

namespace App\Modules\Admin\Domain\Contracts;

use App\Modules\Admin\Domain\Listing\ListingQuery;
use App\Modules\Admin\Domain\Listing\Page;
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
}
