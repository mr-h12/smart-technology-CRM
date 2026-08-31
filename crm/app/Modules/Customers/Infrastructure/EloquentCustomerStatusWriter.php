<?php

declare(strict_types=1);

namespace App\Modules\Customers\Infrastructure;

use App\Modules\Customers\Domain\Contracts\CustomerStatusWriterInterface;
use App\Modules\Customers\Infrastructure\Eloquent\Customer;

/**
 * {@see CustomerStatusWriterInterface} over `customers.customer_status`.
 *
 * A conditional `UPDATE`, not a read-then-compare-then-write: the `WHERE`
 * clause is the comparison, so a recompute that lands on the same status
 * touches no row and leaves `updated_at` alone — `Customer` still carries
 * `DB-02`'s columns, and a status recompute is not an edit anybody made.
 *
 * ⚠️ **Measured against `AuditEnforcementTest`'s scanner, not assumed to be
 * invisible the way `EloquentCustomerDirectory` and its siblings are.** It
 * is not: this class is a database writer in the scanner's own register
 * below, one more instance of the "hole" those siblings' comments describe.
 * Not audited, and not meant to be: `AUD-01` is satisfied one layer out, by
 * whichever `Deals` write triggered the recompute (`DEAL_CREATED` or
 * `DEAL_STATUS_CHANGED`) — a derived projection of an already-audited fact
 * is not a second decision to record.
 */
final readonly class EloquentCustomerStatusWriter implements CustomerStatusWriterInterface
{
    public function write(string $customerId, string $status): void
    {
        Customer::query()
            ->whereKey($customerId)
            ->where('customer_status', '!=', $status)
            ->update(['customer_status' => $status]);
    }
}
