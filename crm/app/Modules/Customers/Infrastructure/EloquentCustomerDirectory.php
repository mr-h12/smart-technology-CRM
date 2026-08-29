<?php

declare(strict_types=1);

namespace App\Modules\Customers\Infrastructure;

use App\Modules\Customers\Domain\Access\CustomerRowScope;
use App\Modules\Customers\Domain\Contracts\CustomerDirectoryInterface;
use App\Modules\Customers\Domain\Listing\CustomerListCriteria;
use App\Modules\Customers\Domain\Listing\CustomerPage;
use App\Modules\Customers\Domain\Listing\CustomerSummary;
use App\Modules\Customers\Infrastructure\Eloquent\Customer;
use App\Modules\Identity\Domain\Administration\UserListCriteria;
use App\Modules\Identity\Domain\Contracts\UserDirectoryInterface;
use App\Support\Search\SearchIndex;
use App\Support\Search\SearchService;
use DateTimeImmutable;
use Illuminate\Database\Eloquent\Builder;

/**
 * {@see CustomerDirectoryInterface} over `customers`.
 *
 * ── The scope is applied by construction, not by remembering ───────────────
 *
 * Every read starts from {@see self::scoped()}, the only builder factory here.
 * `SEC-08` is row-level security and a filter each method remembers to add is a
 * filter one method will forget — and the row that leaks is somebody else's
 * customer.
 *
 * ── `q` goes through `SearchService`, and narrows rather than replaces ─────
 *
 * `D-48` and `OpenAPI §6.2`: the free-text parameter "always passes through
 * `SearchService`". The service answers with **ids**, which are then intersected
 * with the scoped query — so a search can only ever shrink what the caller was
 * already allowed to see. Handing the search a scope filter instead and trusting
 * its answer would put an authorisation decision inside a component whose driver
 * is scheduled to be replaced by Meilisearch in Module 15.
 *
 * ── §10.1's filter asks Identity rather than joining `users` ───────────────
 *
 * "Customers of deactivated employees" needs to know which owners are inactive,
 * and that fact lives in another module's table. `CLAUDE.md` is explicit —
 * modules communicate through interfaces, "never direct cross-module database
 * access" — so this asks {@see UserDirectoryInterface} and filters on the ids it
 * returns. A `join users` here would be faster and would be the exact edge
 * `AP-02` exists to prevent.
 */
final readonly class EloquentCustomerDirectory implements CustomerDirectoryInterface
{
    public function __construct(
        private SearchService $search,
        private UserDirectoryInterface $users,
    ) {}

    public function list(CustomerListCriteria $criteria, CustomerRowScope $scope): CustomerPage
    {
        $query = $this->scoped($scope);

        if ($query === null) {
            return new CustomerPage([], 0, $criteria->page, $criteria->perPage);
        }

        $this->applyFilters($query, $criteria);

        // OpenAPI §6.1: "Pagination always happens **after** authorization
        // scoping" — the scope is already on the builder, and the count below is
        // taken from that same one rather than from a fresh query.
        $total = $query->count();

        foreach ($criteria->sorts as $sort) {
            $query->orderBy('customers.'.$sort['field'], $sort['descending'] ? 'desc' : 'asc');
        }

        // A deterministic tiebreak. Two customers named Ahmed would otherwise
        // page non-deterministically: PostgreSQL is free to return equal sort
        // keys in any order, so a row can appear on page 1 and page 2 of the
        // same listing, or on neither.
        $query->orderBy('customers.id');

        $rows = $query->offset($criteria->offset())->limit($criteria->perPage)->get();

        $items = [];

        foreach ($rows as $row) {
            $items[] = self::hydrate($row);
        }

        return new CustomerPage($items, $total, $criteria->page, $criteria->perPage);
    }

    public function find(string $customerId, CustomerRowScope $scope): ?CustomerSummary
    {
        $query = $this->scoped($scope);

        if ($query === null) {
            return null;
        }

        $row = $query->whereKey($customerId)->first();

        return $row === null ? null : self::hydrate($row);
    }

    /**
     * The scoped builder, or null when the caller's reach selects no rows.
     *
     * Null rather than an always-false builder, so the caller returns early
     * instead of asking PostgreSQL a question whose answer is already known.
     *
     * @return Builder<Customer>|null
     */
    private function scoped(CustomerRowScope $scope): ?Builder
    {
        if ($scope->permitsNothing()) {
            return null;
        }

        $query = Customer::query();

        if (! $scope->unrestricted) {
            $query->whereIn('customers.sales_owner_id', $scope->ownerIds);
        }

        return $query;
    }

    /** @param  Builder<Customer>  $query */
    private function applyFilters(Builder $query, CustomerListCriteria $criteria): void
    {
        $query->where('customers.is_archived', $criteria->isArchived);

        if ($criteria->customerStatus !== null) {
            $query->where('customers.customer_status', $criteria->customerStatus);
        }

        if ($criteria->sector !== null) {
            $query->where('customers.sector', $criteria->sector);
        }

        if ($criteria->isIncomplete !== null) {
            $query->where('customers.is_incomplete', $criteria->isIncomplete);
        }

        if ($criteria->ownerInactive) {
            $query->whereIn('customers.sales_owner_id', $this->deactivatedOwnerIds());
        }

        if ($criteria->q !== null) {
            $query->whereIn('customers.id', $this->search->search(SearchIndex::Customers, $criteria->q));
        }
    }

    /**
     * The ids of every deactivated employee, from Identity's own contract.
     *
     * ponytail: pages through `UserDirectoryInterface` at the contract's maximum
     * page size rather than adding a bulk method to Identity, because adding one
     * is a cross-module edit that module isolation forbids from a Customers
     * point. Fine for an internal CRM whose whole staff is a few dozen rows; if
     * the employee count ever makes this hurt, the upgrade is a dedicated
     * `inactiveUserIds()` on the interface, agreed with Identity.
     *
     * @return list<string>
     */
    private function deactivatedOwnerIds(): array
    {
        $ids = [];
        $page = 1;

        do {
            $result = $this->users->list(new UserListCriteria(
                page: $page,
                perPage: UserListCriteria::MAX_PER_PAGE,
                isActive: false,
            ));

            foreach ($result->items as $user) {
                $ids[] = $user->id;
            }

            $page++;
        } while ($result->hasNextPage());

        return $ids;
    }

    private static function hydrate(Customer $row): CustomerSummary
    {
        return new CustomerSummary(
            id: $row->id,
            name: $row->name,
            customerStatus: $row->customer_status,
            sector: $row->sector,
            region: $row->region,
            contactPerson: $row->contact_person,
            phone: $row->phone,
            phone2: $row->phone2,
            whatsapp: $row->whatsapp,
            email: $row->email,
            salesOwnerId: $row->sales_owner_id,
            startDate: $row->start_date?->toDateTimeImmutable(),
            notes: $row->notes,
            isArchived: $row->is_archived,
            isIncomplete: $row->is_incomplete,
            createdAt: new DateTimeImmutable((string) $row->created_at?->toIso8601String()),
            updatedAt: new DateTimeImmutable((string) $row->updated_at?->toIso8601String()),
        );
    }
}
