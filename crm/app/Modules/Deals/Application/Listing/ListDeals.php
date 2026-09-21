<?php

declare(strict_types=1);

namespace App\Modules\Deals\Application\Listing;

use App\Modules\Customers\Domain\Contracts\CustomerNamesInterface;
use App\Modules\Deals\Domain\Access\DealRowScope;
use App\Modules\Deals\Domain\Contracts\DealDirectoryInterface;
use App\Modules\Deals\Domain\Listing\DealListCriteria;
use App\Modules\Deals\Domain\Listing\DealNotFound;
use App\Modules\Deals\Domain\Listing\DealPage;
use App\Modules\Deals\Domain\Listing\DealSummary;

/**
 * §10's deal list and detail, with `SEC-08`'s reach applied once — `ListCustomers`'s
 * shape (Module 3 Point 3.2), on the same reasoning: a controller that resolved
 * its own scope would be a second answer to the same question.
 */
final readonly class ListDeals
{
    public function __construct(private DealDirectoryInterface $deals, private CustomerNamesInterface $customers) {}

    /**
     * F-07 · 1.5 (`D-83`): the page carries its customers' names, read
     * through Customers' contract **once** for the page's distinct ids —
     * `ListQuotations::handle()`'s shape.
     *
     * @param  list<string>  $heldScopes  §3.2 codes, as the authorisation decision reports them
     */
    public function handle(DealListCriteria $criteria, array $heldScopes, string $actorId): DealPage
    {
        $page = $this->deals->list($criteria, DealRowScope::resolve($heldScopes, $actorId));
        $names = $this->customers->namesOf(array_values(array_unique(array_map(static fn (DealSummary $row): string => $row->customerId, $page->items))));

        return new DealPage($page->items, $page->total, $page->page, $page->perPage, $names);
    }

    /**
     * @param  list<string>  $heldScopes
     *
     * @throws DealNotFound when the row is absent **or** outside the caller's reach
     */
    public function one(string $dealId, array $heldScopes, string $actorId): DealSummary
    {
        $deal = $this->deals->find($dealId, DealRowScope::resolve($heldScopes, $actorId));

        if (! $deal instanceof DealSummary) {
            // §5.1: 404 for "does not exist **or** is not visible to the
            // caller. Do not reveal which case applies."
            throw DealNotFound::of($dealId);
        }

        return $deal;
    }
}
