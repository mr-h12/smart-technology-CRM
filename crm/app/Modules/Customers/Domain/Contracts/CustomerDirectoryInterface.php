<?php

declare(strict_types=1);

namespace App\Modules\Customers\Domain\Contracts;

use App\Modules\Customers\Domain\Access\CustomerRowScope;
use App\Modules\Customers\Domain\Listing\CustomerListCriteria;
use App\Modules\Customers\Domain\Listing\CustomerPage;
use App\Modules\Customers\Domain\Listing\CustomerSummary;

/**
 * The `customers` table as §10's screens need to read it.
 *
 * ── The scope is a parameter, not a caller's responsibility ────────────────
 *
 * Both methods take a {@see CustomerRowScope}, and neither has an overload that
 * omits it. `SEC-08` is row-level security, and a reader that could be called
 * without a scope is a reader somebody will one day call without one — which
 * returns every customer in the company to whoever asked.
 *
 * `find()` answers null for a row outside the scope, exactly as it does for a
 * row that is not there. `OpenAPI §5.1` requires that: 404 covers "does not
 * exist **or** is not visible to the caller. Do not reveal which case applies."
 * Distinguishing them here would push the leak up into the controller.
 */
interface CustomerDirectoryInterface
{
    public function list(CustomerListCriteria $criteria, CustomerRowScope $scope): CustomerPage;

    /** Null when the row is absent **or** outside the scope — the caller cannot tell, by design. */
    public function find(string $customerId, CustomerRowScope $scope): ?CustomerSummary;
}
