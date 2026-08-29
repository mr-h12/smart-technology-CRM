<?php

declare(strict_types=1);

namespace App\Modules\Customers\Application\Listing;

use App\Modules\Customers\Domain\Access\CustomerRowScope;
use App\Modules\Customers\Domain\Contracts\CustomerDirectoryInterface;
use App\Modules\Customers\Domain\Listing\CustomerListCriteria;
use App\Modules\Customers\Domain\Listing\CustomerNotFound;
use App\Modules\Customers\Domain\Listing\CustomerPage;
use App\Modules\Customers\Domain\Listing\CustomerSummary;

/**
 * §10's customer list and detail, with `SEC-08`'s reach applied once.
 *
 * The scope arrives as §3.2's plain scope codes rather than as Identity's
 * `Scope` enum, and is resolved here into {@see CustomerRowScope}. That keeps
 * the resolution in one place: a controller that built its own scope would be a
 * second answer to the same question, and the two would eventually differ.
 */
final readonly class ListCustomers
{
    public function __construct(private CustomerDirectoryInterface $customers) {}

    /** @param  list<string>  $heldScopes  §3.2 codes, as the authorisation decision reports them */
    public function handle(CustomerListCriteria $criteria, array $heldScopes, string $actorId): CustomerPage
    {
        return $this->customers->list($criteria, CustomerRowScope::resolve($heldScopes, $actorId));
    }

    /**
     * @param  list<string>  $heldScopes
     *
     * @throws CustomerNotFound when the row is absent **or** outside the caller's reach
     */
    public function one(string $customerId, array $heldScopes, string $actorId): CustomerSummary
    {
        $customer = $this->customers->find($customerId, CustomerRowScope::resolve($heldScopes, $actorId));

        if (! $customer instanceof CustomerSummary) {
            // §5.1: 404 for "does not exist **or** is not visible to the
            // caller. Do not reveal which case applies." A 403 here would
            // confirm the row exists to somebody forbidden from seeing it.
            throw CustomerNotFound::of($customerId);
        }

        return $customer;
    }
}
