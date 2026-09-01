<?php

declare(strict_types=1);

namespace App\Modules\Deals\Application\CustomerStatus;

use App\Modules\Deals\Domain\Contracts\DealDirectoryInterface;

/**
 * `J-02`'s nightly half (§4.5, `D-49`) — the other trigger Point 3.1 deferred:
 * "the first scheduled job this codebase would implement at all... deserves
 * its own point rather than riding in on this one's size."
 *
 * ── Why this reuses `RecomputeCustomerStatus` rather than re-deriving §4.5 ──
 *
 * The event-triggered half already does exactly what a nightly sweep needs
 * per customer — read every deal, derive the status, write it if it
 * changed. The only thing this class adds is *which* customers to ask and
 * *when* to ask them; §4.5's five rules stay in exactly one place,
 * {@see \App\Modules\Deals\Domain\CustomerStatus\CustomerStatusDerivation}.
 *
 * ── Why no catch-up entry, on `J-15`'s own precedent ────────────────────────
 *
 * `D-55`/`ST-05` want jobs missed during downtime to run on startup — but
 * `routes/console.php`'s own note on `J-15` records the one case that does
 * not need it: a job that is idempotent and always evaluates against *now*
 * repairs a missed week with its next single run rather than by replaying
 * the nights that did not happen. `CustomerStatusDerivation::derive()` is
 * exactly that kind of function — a pure read of current deals against the
 * current clock — so a night missed by downtime is repaired by tomorrow
 * night's run reading the same *current* truth, not by re-running for every
 * night in between.
 */
final readonly class RecomputeStaleCustomerStatuses
{
    public function __construct(
        private DealDirectoryInterface $deals,
        private RecomputeCustomerStatus $recompute,
    ) {}

    /** @return int how many customers were considered */
    public function run(): int
    {
        $customerIds = $this->deals->customerIdsWithActiveDeals();

        foreach ($customerIds as $customerId) {
            $this->recompute->forCustomer($customerId);
        }

        return count($customerIds);
    }
}
