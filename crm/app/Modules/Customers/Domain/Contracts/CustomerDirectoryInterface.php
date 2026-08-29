<?php

declare(strict_types=1);

namespace App\Modules\Customers\Domain\Contracts;

use App\Modules\Customers\Domain\Access\CustomerRowScope;
use App\Modules\Customers\Domain\Listing\CustomerListCriteria;
use App\Modules\Customers\Domain\Listing\CustomerPage;
use App\Modules\Customers\Domain\Listing\CustomerSummary;
use App\Modules\Customers\Domain\Writing\CustomerDraft;

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

    /**
     * Point 3.3.
     *
     * No scope parameter, and that is the one deliberate exception to the rule
     * above: a row that does not exist yet cannot be selected by a `WHERE`.
     * §3.3's create scope constrains the **owner** the record may be filed
     * under, and that is decided in the use case before this is called.
     */
    public function create(CustomerDraft $draft, string $actorId): CustomerSummary;

    /** Null on the same two indistinguishable cases as {@see find()} — absent, or out of reach. */
    public function update(string $customerId, CustomerDraft $draft, CustomerRowScope $scope, string $actorId): ?CustomerSummary;

    /**
     * §10.2's fuzzy match — the customers whose folded name scores at or above
     * $threshold against $name.
     *
     * **Scoped, and that costs something real.** A caller is not warned about a
     * customer they may not read: `SEC-08` is row-level security, and a warning
     * naming a row the caller cannot open would leak another owner's customer
     * through a convenience. The cost is that two people with disjoint scopes
     * can each create the same customer — recorded as an owner question rather
     * than resolved here.
     *
     * @param  string  $threshold  a decimal string in `(0, 1]` — `DB-07`, never a float
     * @return list<CustomerSummary>
     */
    public function similarTo(string $name, string $threshold, CustomerRowScope $scope, ?string $excluding = null): array;
}
