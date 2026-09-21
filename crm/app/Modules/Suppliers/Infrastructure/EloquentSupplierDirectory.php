<?php

declare(strict_types=1);

namespace App\Modules\Suppliers\Infrastructure;

use App\Modules\Suppliers\Domain\Contracts\SupplierDirectoryInterface;
use App\Modules\Suppliers\Domain\Listing\SupplierListCriteria;
use App\Modules\Suppliers\Domain\Listing\SupplierPage;
use App\Modules\Suppliers\Domain\Listing\SupplierSummary;
use App\Modules\Suppliers\Domain\Writing\SupplierDraft;
use App\Modules\Suppliers\Infrastructure\Eloquent\Supplier;
use App\Support\Search\SearchIndex;
use App\Support\Search\SearchService;
use DateTimeImmutable;
use Illuminate\Database\Eloquent\Builder;

/**
 * {@see SupplierDirectoryInterface} over `suppliers`.
 *
 * ── `q` goes through `SearchService`, and narrows rather than replaces ─────
 *
 * `D-48` and `OpenAPI §6.2`: the free-text parameter "always passes through
 * `SearchService`". The service answers with **ids**, which are intersected
 * with the query already built here — so a search can only ever shrink the set,
 * never widen it past a filter the caller asked for.
 *
 * ⚠️ **The inherited ceiling.** `PostgresSearchDriver` caps at
 * `MAX_RESULTS = 500` and says so. A `q` matching more than 500 suppliers by
 * name is truncated before this class applies `filter[...]`, so a combined
 * search-and-filter could miss a row beyond the cap. That ceiling is the
 * driver's, not this point's, and it is the same one Module 3 lives with. It
 * needs an offset/limit search to lift, which is Module 15's.
 *
 * Unlike Module 3, no filter is pushed *into* the search: `SearchIndex` names
 * the fields that must narrow inside it, and for customers those are the ones
 * **always** applied (the owner scope and the archived flag). Suppliers has no
 * always-applied filter — §3.7 has no scope and §10.4's hiding rule belongs to
 * a selection list, not to this screen — so there is nothing that must go in.
 */
final readonly class EloquentSupplierDirectory implements SupplierDirectoryInterface
{
    public function __construct(private SearchService $search) {}

    public function list(SupplierListCriteria $criteria): SupplierPage
    {
        $query = Supplier::query();

        $this->applyFilters($query, $criteria);

        $total = $query->count();

        foreach ($criteria->sorts as $sort) {
            $query->orderBy('suppliers.'.$sort['field'], $sort['descending'] ? 'desc' : 'asc');
        }

        // A deterministic tiebreak. Two suppliers with the same name would
        // otherwise page non-deterministically: PostgreSQL is free to return
        // equal sort keys in any order, so a row can appear on page 1 and page
        // 2 of the same listing, or on neither.
        $query->orderBy('suppliers.id');

        $rows = $query->offset($criteria->offset())->limit($criteria->perPage)->get();

        $items = [];

        foreach ($rows as $row) {
            $items[] = self::hydrate($row);
        }

        return new SupplierPage($items, $total, $criteria->page, $criteria->perPage);
    }

    public function find(string $supplierId): ?SupplierSummary
    {
        $row = Supplier::query()->whereKey($supplierId)->first();

        return $row === null ? null : self::hydrate($row);
    }

    public function create(SupplierDraft $draft, string $actorId): SupplierSummary
    {
        $row = new Supplier;
        $row->fill($draft->attributes);

        // `DB-02`. No model observer fills these: one guessing the actor would
        // be wrong in exactly the cases that matter, so it is passed in from
        // the request instead — the same arrangement Module 3 uses.
        $row->created_by = $actorId;
        $row->updated_by = $actorId;
        $row->save();

        // `color_rating` and the two flags are filled by the columns' own
        // DEFAULTs (Point 1.1), so the in-memory model still holds null for any
        // the caller did not send until it is read back.
        $row->refresh();

        return self::hydrate($row);
    }

    public function update(string $supplierId, SupplierDraft $draft, string $actorId): ?SupplierSummary
    {
        $row = Supplier::query()->whereKey($supplierId)->first();

        if ($row === null) {
            return null;
        }

        $row->fill($draft->attributes);
        $row->updated_by = $actorId;
        $row->save();

        return self::hydrate($row->refresh());
    }

    /** @param Builder<Supplier> $query */
    private function applyFilters(Builder $query, SupplierListCriteria $criteria): void
    {
        if ($criteria->colorRating !== null) {
            $query->where('suppliers.color_rating', $criteria->colorRating);
        }

        if ($criteria->type !== null) {
            $query->where('suppliers.type', $criteria->type);
        }

        // Tri-state: unset lists both. See `SupplierListCriteria` for why this
        // differs from Module 3's archived customers.
        if ($criteria->isActive !== null) {
            $query->where('suppliers.is_active', $criteria->isActive);
        }

        if ($criteria->hasOpenAccount !== null) {
            $query->where('suppliers.has_open_account', $criteria->hasOpenAccount);
        }

        if ($criteria->isIncomplete !== null) {
            $query->where('suppliers.is_incomplete', $criteria->isIncomplete);
        }

        if ($criteria->q !== null) {
            $query->whereIn('suppliers.id', $this->search->search(SearchIndex::Suppliers, $criteria->q));
        }
    }

    private static function hydrate(Supplier $row): SupplierSummary
    {
        return new SupplierSummary(
            id: $row->id,
            name: $row->name,
            type: $row->type,
            colorRating: $row->color_rating,
            phone: $row->phone,
            contactPerson: $row->contact_person,
            hasOpenAccount: $row->has_open_account,
            isActive: $row->is_active,
            isIncomplete: $row->is_incomplete,
            // `DB-08`: stored UTC. The immutable copies keep a caller from
            // mutating the model's Carbon instance through the read model.
            createdAt: new DateTimeImmutable((string) $row->created_at?->toIso8601String()),
            updatedAt: new DateTimeImmutable((string) $row->updated_at?->toIso8601String()),
        );
    }
}
