<?php

declare(strict_types=1);

namespace App\Modules\Customers\Infrastructure;

use App\Modules\Customers\Domain\Access\CustomerRowScope;
use App\Modules\Customers\Domain\Contracts\CustomerDirectoryInterface;
use App\Modules\Customers\Domain\Listing\CustomerListCriteria;
use App\Modules\Customers\Domain\Listing\CustomerPage;
use App\Modules\Customers\Domain\Listing\CustomerSummary;
use App\Modules\Customers\Domain\Writing\CustomerDraft;
use App\Modules\Customers\Infrastructure\Eloquent\Customer;
use App\Modules\Identity\Domain\Administration\UserListCriteria;
use App\Modules\Identity\Domain\Contracts\UserDirectoryInterface;
use App\Support\Search\ArabicNormalisation;
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

    public function create(CustomerDraft $draft, string $actorId): CustomerSummary
    {
        $row = new Customer;
        $row->fill($draft->attributes);

        // `DB-02`. `HasStandardColumns` says plainly why no observer fills
        // these: "a model observer guessing it would be wrong in exactly the
        // cases that matter". The actor is passed in from the request instead.
        $row->created_by = $actorId;
        $row->updated_by = $actorId;
        $row->save();

        // `customer_status` is filled by the column's `DEFAULT 'prospect'`
        // (§4.5 rule 5) and nothing in this module sets it, so the in-memory
        // model still holds null until it is read back. Measured, not guessed:
        // without this the hydrate below died on a non-nullable argument.
        $row->refresh();

        return self::hydrate($row);
    }

    public function update(string $customerId, CustomerDraft $draft, CustomerRowScope $scope, ?string $actorId): ?CustomerSummary
    {
        $query = $this->scoped($scope);

        if ($query === null) {
            return null;
        }

        $row = $query->whereKey($customerId)->first();

        if ($row === null) {
            return null;
        }

        $row->fill($draft->attributes);
        $row->updated_by = $actorId;
        $row->save();

        return self::hydrate($row);
    }

    public function setArchived(string $customerId, bool $archived, CustomerRowScope $scope, string $actorId): ?CustomerSummary
    {
        $query = $this->scoped($scope);

        if ($query === null) {
            return null;
        }

        $row = $query->whereKey($customerId)->first();

        if ($row === null) {
            return null;
        }

        $row->is_archived = $archived;
        $row->updated_by = $actorId;
        $row->save();

        return self::hydrate($row);
    }

    /**
     * ponytail: five rows, because a "yellow warning listing the similar
     * customers" that lists forty is a dialog nobody reads. §10.2 gives no
     * number; raise it when a screen asks for one.
     */
    private const SIMILAR_LIMIT = 5;

    public function similarTo(string $name, string $threshold, CustomerRowScope $scope, ?string $excluding = null): array
    {
        $query = $this->scoped($scope);

        if ($query === null) {
            return [];
        }

        // The stored column is folded by `translate()` and the incoming name in
        // PHP, from the one declaration `ArabicNormalisation` owns — the same
        // arrangement `PostgresSearchDriver` uses, for the same reason: folding
        // every row in PHP would mean reading every row.
        $score = 'similarity(translate(customers.name, ?, ?), ?)';
        $folding = [ArabicNormalisation::FROM, ArabicNormalisation::TO, ArabicNormalisation::normalise($name)];

        // `?::real` rather than a PHP comparison: the threshold is a decimal
        // string (`DB-07`) and the score is produced by PostgreSQL, so the
        // comparison belongs where both values already are.
        $query->whereRaw($score.' >= ?::real', [...$folding, $threshold]);

        if ($excluding !== null) {
            // A record is never its own duplicate.
            $query->whereKeyNot($excluding);
        }

        $rows = $query->orderByRaw($score.' desc', $folding)
            ->orderBy('customers.id')
            ->limit(self::SIMILAR_LIMIT)
            ->get();

        $similar = [];

        foreach ($rows as $row) {
            $similar[] = self::hydrate($row);
        }

        return $similar;
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
