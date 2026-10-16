<?php

declare(strict_types=1);

namespace App\Modules\Quotations\Domain\Contracts;

/**
 * `D-126`, RPT-WK: how many purchase orders a user received in a period — a
 * count, so a report is not thereby granted a quotation's data.
 */
interface PurchaseOrderCountsInterface
{
    /**
     * Purchase orders on deals `$ownerId` owns whose PO date is in `[$from, $to]`
     * (`Y-m-d`, inclusive). The owner is the Deals seam's
     * (`DealFactsInterface::dealIdsOwnedBy`), never a join on `deals`.
     */
    public function countOnDealsOwnedBy(string $ownerId, string $from, string $to): int;
}
