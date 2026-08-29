<?php

declare(strict_types=1);

namespace App\Modules\Customers\Domain\Writing;

use App\Modules\Customers\Domain\Listing\CustomerSummary;

/**
 * What a save returns: the record, and `D-35`'s warning about it.
 *
 * The two travel together because §10.2 attaches the warning to the save that
 * provoked it — *"on save, the system compares the name"* — and because a
 * second round trip to ask "was that a duplicate?" is a round trip the employee
 * would answer before it arrived.
 *
 * An empty `$similar` is the normal case and the only case while `OD-08`'s
 * threshold is unset.
 */
final readonly class CustomerWriteResult
{
    /** @param  list<CustomerSummary>  $similar */
    public function __construct(
        public CustomerSummary $customer,
        public array $similar = [],
    ) {}
}
