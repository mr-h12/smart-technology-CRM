<?php

declare(strict_types=1);

namespace App\Modules\Customers\Domain\Contracts;

/**
 * `customer_status`'s one legitimate writer — §4.5, `D-49`.
 *
 * `Customer.php` and `2026_08_29_000000_create_customers.php` both say the
 * same thing in their own words: *"nothing here derives anything ·
 * `recompute_customer_status` is Module 5's."* Module 5 owns the rule and the
 * data the rule reads (`deals.status`, `deals.last_activity_at`); it does not
 * own the `customers` table, so it reaches it through this interface rather
 * than through `Customer` the model — `CLAUDE.md`'s "cross-module work goes
 * through interfaces or events, never another module's models."
 *
 * ── Write-only, on purpose ─────────────────────────────────────────────────
 *
 * Module 5 never needs to *read* a customer's stored status back — the whole
 * point of §4.5 is that it is derived fresh from `deals` every time, not
 * compared against what was there before. A narrower crossing than a full
 * `CustomerDirectoryInterface` dependency is the one this file's own
 * `deptrac.modules.yaml` header asks for: *"each crossing is a decision that
 * shows up in a diff"* — this diff shows exactly one method.
 */
interface CustomerStatusWriterInterface
{
    /**
     * Writes $status if it differs from what is stored; a no-op otherwise.
     *
     * Silently no-op on an unknown $customerId rather than throwing: the only
     * caller is {@see \App\Modules\Deals\Application\CustomerStatus\RecomputeCustomerStatus},
     * itself only ever called with a `customer_id` a foreign key on `deals`
     * already guarantees exists.
     */
    public function write(string $customerId, string $status): void;
}
