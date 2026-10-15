<?php

declare(strict_types=1);

namespace App\Modules\Customers\Domain\Contracts;

/**
 * `customer_status`'s derived writer — §4.5, `D-49`. `D-123`'s status set by
 * hand is the Customers module's own write, not this one; this contract only
 * decides when a derived status replaces it.
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
     * A deal's status changed: writes $status and clears a status set by hand
     * (`D-123`, its `status_reason`), even when $status is the same code; a
     * no-op when neither differs.
     *
     * Silently no-op on an unknown $customerId rather than throwing: the only
     * caller is {@see \App\Modules\Deals\Application\CustomerStatus\RecomputeCustomerStatus},
     * itself only ever called with a `customer_id` a foreign key on `deals`
     * already guarantees exists.
     */
    public function write(string $customerId, string $status): void;

    /**
     * `D-123` — the nightly correction (`J-02`): as {@see self::write()}, but
     * a status set by hand is left alone until a deal next moves.
     */
    public function writeUnlessSetByHand(string $customerId, string $status): void;
}
