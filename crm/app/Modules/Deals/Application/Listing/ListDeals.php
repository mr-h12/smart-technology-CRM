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
use App\Modules\Identity\Domain\Contracts\UserFactsInterface;

/**
 * §10's deal list and detail, with `SEC-08`'s reach applied once — `ListCustomers`'s
 * shape (Module 3 Point 3.2), on the same reasoning: a controller that resolved
 * its own scope would be a second answer to the same question.
 */
final readonly class ListDeals
{
    public function __construct(
        private DealDirectoryInterface $deals,
        private CustomerNamesInterface $customers,
        private UserFactsInterface $users,
    ) {}

    /**
     * F-07 · 1.5 (`D-83`): the page carries its customers' names, read
     * through Customers' contract **once** for the page's distinct ids —
     * `ListQuotations::handle()`'s shape. Its owners' names likewise, through
     * Identity's (`D-109`, F-41 · 1.6b); a deal with no owner asks nothing.
     *
     * @param  list<string>  $heldScopes  §3.2 codes, as the authorisation decision reports them
     */
    public function handle(DealListCriteria $criteria, array $heldScopes, string $actorId): DealPage
    {
        $page = $this->deals->list($criteria, DealRowScope::resolve($heldScopes, $actorId, $this->users->teamMemberIdsOf(...)));
        $names = $this->customers->namesOf(array_values(array_unique(array_map(static fn (DealSummary $row): string => $row->customerId, $page->items))));
        $owners = $this->users->namesOf(array_values(array_unique(array_filter(array_map(static fn (DealSummary $row): ?string => $row->ownerId, $page->items)))));

        return new DealPage($page->items, $page->total, $page->page, $page->perPage, $names, $owners);
    }

    /**
     * `D-108`: the owner filter's choices — the visible deals' owners, named
     * through Identity and sorted by name. One the contract does not name (the
     * hidden Super Admin, a soft-deleted account) is left out, as `D-109` leaves
     * its name out of a row.
     *
     * ponytail: unpaginated — bounded by the number of users who own a visible
     * deal; page it if that ever outgrows one select.
     *
     * @param  list<string>  $heldScopes
     * @return list<array{id: string, name: string}>
     */
    public function owners(array $heldScopes, string $actorId): array
    {
        $names = $this->users->namesOf($this->deals->ownerIds(DealRowScope::resolve($heldScopes, $actorId, $this->users->teamMemberIdsOf(...))));
        asort($names);

        return array_map(static fn (string $id, string $name): array => ['id' => $id, 'name' => $name], array_keys($names), array_values($names));
    }

    /**
     * @param  list<string>  $heldScopes
     *
     * @throws DealNotFound when the row is absent **or** outside the caller's reach
     */
    public function one(string $dealId, array $heldScopes, string $actorId): DealSummary
    {
        $deal = $this->deals->find($dealId, DealRowScope::resolve($heldScopes, $actorId, $this->users->teamMemberIdsOf(...)));

        if (! $deal instanceof DealSummary) {
            // §5.1: 404 for "does not exist **or** is not visible to the
            // caller. Do not reveal which case applies."
            throw DealNotFound::of($dealId);
        }

        return $deal;
    }

    /**
     * `D-109`: the deal page's owner name, `ListCustomers::salesOwnerName()`'s
     * shape. `null` with no owner, or when the contract names nobody (the
     * hidden Super Admin, a soft-deleted account) — never the identifier.
     */
    public function ownerName(DealSummary $deal): ?string
    {
        $ownerId = $deal->ownerId;

        return $ownerId === null ? null : ($this->users->namesOf([$ownerId])[$ownerId] ?? null);
    }
}
