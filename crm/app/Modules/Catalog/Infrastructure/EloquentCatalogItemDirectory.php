<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Infrastructure;

use App\Modules\Catalog\Domain\Contracts\CatalogItemDirectoryInterface;
use App\Modules\Catalog\Domain\Importing\ImportSummary;
use App\Modules\Catalog\Domain\Listing\CatalogItemListCriteria;
use App\Modules\Catalog\Domain\Listing\CatalogItemPage;
use App\Modules\Catalog\Domain\Listing\CatalogItemSummary;
use App\Modules\Catalog\Domain\Writing\CatalogItemDraft;
use App\Modules\Catalog\Infrastructure\Eloquent\CatalogItem;
use App\Support\Search\SearchIndex;
use App\Support\Search\SearchService;
use DateTimeImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * {@see CatalogItemDirectoryInterface} over `catalog_items`.
 *
 * ── `q` goes through `SearchService`, and narrows rather than replaces ─────
 *
 * `D-48` and `OpenAPI §6.2`: the free-text parameter "always passes through
 * `SearchService`". The service answers with **ids**, which are intersected
 * with the query already built here — so a search can only ever shrink the set,
 * never widen it past a filter the caller asked for.
 *
 * ⚠️ **The inherited ceiling, and the one filter pushed inside it.**
 * `PostgresSearchDriver` caps at `MAX_RESULTS = 500`, so a `q` matching more
 * than 500 rows is truncated *before* this class applies `filter[...]`.
 * `filter[kind]` is therefore passed **into** the search rather than applied
 * after it: §7.3's two tabs are separate screens and the build plan asks that a
 * service appear "separate from products", so a truncated search inside the
 * Service tab that had been filled up by products would show the wrong rows
 * rather than fewer of the right ones. `category` and `is_active` are left
 * outside — neither splits the screen in two, and the cap is the driver's
 * ceiling to lift in Module 15, not this point's.
 */
final readonly class EloquentCatalogItemDirectory implements CatalogItemDirectoryInterface
{
    public function __construct(private SearchService $search) {}

    public function list(CatalogItemListCriteria $criteria): CatalogItemPage
    {
        $query = CatalogItem::query();

        $this->applyFilters($query, $criteria);

        $total = $query->count();

        // `API-06`'s grouping, expressed as the first ordering key so every row
        // of a company is adjacent and the caller's `sort` still applies inside
        // each group. `nulls last` is stated rather than inherited: §7.3 leaves
        // `company` optional, so the ungrouped rows need a defined place.
        // The column is one of `ALLOWED_GROUPS`, checked before it reaches here.
        if ($criteria->groupBy !== null) {
            // Written out per group rather than concatenated. PHPStan level 10
            // requires `orderByRaw` to receive a `literal-string`, and a `match`
            // is the honest way to give it one: the allowlist is already checked
            // in `CatalogItemListCriteria`, so the default arm is unreachable —
            // its job is to fail loudly if a group is ever added to
            // `ALLOWED_GROUPS` without an ordering to go with it, rather than to
            // let the endpoint accept a group it then silently ignores.
            $query->orderByRaw(match ($criteria->groupBy) {
                'company' => 'catalog_items.company asc nulls last',
                default => throw new InvalidArgumentException(
                    'No ordering is defined for the declared group `'.$criteria->groupBy.'`.'
                ),
            });
        }

        foreach ($criteria->sorts as $sort) {
            $query->orderBy('catalog_items.'.$sort['field'], $sort['descending'] ? 'desc' : 'asc');
        }

        // A deterministic tiebreak. Two items with the same name would otherwise
        // page non-deterministically: PostgreSQL is free to return equal sort
        // keys in any order, so a row can appear on page 1 and page 2 of the
        // same listing, or on neither.
        $query->orderBy('catalog_items.id');

        $rows = $query->offset($criteria->offset())->limit($criteria->perPage)->get();

        $items = [];

        foreach ($rows as $row) {
            $items[] = self::hydrate($row);
        }

        return new CatalogItemPage($items, $total, $criteria->page, $criteria->perPage);
    }

    public function find(string $catalogItemId): ?CatalogItemSummary
    {
        $row = CatalogItem::query()->whereKey($catalogItemId)->first();

        return $row === null ? null : self::hydrate($row);
    }

    /**
     * Module 6 Point 3.1. `lower(name) = lower(?)` rather than `ILIKE`: the
     * caller's name is a literal, and `ILIKE` would read `%` and `_` in it as
     * wildcards — a product genuinely called "50% glycol" would match rows it
     * is not.
     *
     * `SoftDeletes` on the model supplies `DB-01`; `kind` and the ordering are
     * stated here. `orderBy('id')` makes the answer deterministic if two live
     * rows already share a name, which nothing prevents — the column has no
     * unique index and this lookup is the convention, not a constraint.
     */
    public function findProductIdByName(string $name): ?string
    {
        $id = CatalogItem::query()
            ->whereRaw('lower(name) = lower(?)', [$name])
            ->where('kind', 'product')
            ->orderBy('id')
            ->value('id');

        return is_string($id) ? $id : null;
    }

    public function create(CatalogItemDraft $draft, string $actorId): CatalogItemSummary
    {
        $row = new CatalogItem;
        $row->fill($draft->attributes);

        // `DB-02`. No model observer fills these: one guessing the actor would
        // be wrong in exactly the cases that matter, so it is passed in from
        // the request instead — the same arrangement Modules 3 and 4 use.
        $row->created_by = $actorId;
        $row->updated_by = $actorId;
        $row->save();

        // `is_active` is filled by the column's own DEFAULT (Point 1.2), so the
        // in-memory model still holds null for it until it is read back.
        $row->refresh();

        return self::hydrate($row);
    }

    /**
     * The query builder, not a model: the import is the only writer until
     * point 1.7, and `EloquentSupplierDirectory::recordImportBatch` writes its
     * batch the same way. `D-61`'s uuid7.
     */
    public function link(string $catalogItemId, string $supplierId, string $actorId): string
    {
        $id = Str::uuid7()->toString();

        DB::table('catalog_item_suppliers')->insert([
            'id' => $id,
            'catalog_item_id' => $catalogItemId,
            'supplier_id' => $supplierId,
            'created_by' => $actorId,
            'updated_by' => $actorId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $id;
    }

    public function supplierIdsOf(string $catalogItemId): array
    {
        $ids = DB::table('catalog_item_suppliers')
            ->where('catalog_item_id', $catalogItemId)
            ->whereNull('deleted_at')
            ->orderBy('id')
            ->pluck('supplier_id')
            ->all();

        return array_values(array_filter($ids, 'is_string'));
    }

    public function unlinkSupplier(string $catalogItemId, string $supplierId, string $actorId): ?string
    {
        $id = DB::table('catalog_item_suppliers')
            ->where('catalog_item_id', $catalogItemId)
            ->where('supplier_id', $supplierId)
            ->whereNull('deleted_at')
            ->value('id');

        if (! is_string($id)) {
            return null;
        }

        DB::table('catalog_item_suppliers')->where('id', $id)->update([
            'deleted_at' => now(),
            'updated_by' => $actorId,
            'updated_at' => now(),
        ]);

        return $id;
    }

    public function recordImportBatch(
        string $originalFilename,
        int $rowCount,
        int $importedCount,
        int $incompleteCount,
        string $actorId,
    ): ImportSummary {
        $id = Str::uuid7()->toString();

        DB::table('catalog_import_batches')->insert([
            'id' => $id,
            'original_filename' => $originalFilename,
            'row_count' => $rowCount,
            'imported_count' => $importedCount,
            'incomplete_count' => $incompleteCount,
            'created_by' => $actorId,
            'updated_by' => $actorId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return new ImportSummary($id, $originalFilename, $rowCount, $importedCount, $incompleteCount);
    }

    public function update(string $catalogItemId, CatalogItemDraft $draft, string $actorId): ?CatalogItemSummary
    {
        $row = CatalogItem::query()->whereKey($catalogItemId)->first();

        if ($row === null) {
            return null;
        }

        $row->fill($draft->attributes);
        $row->updated_by = $actorId;
        $row->save();

        return self::hydrate($row->refresh());
    }

    /** @param Builder<CatalogItem> $query */
    private function applyFilters(Builder $query, CatalogItemListCriteria $criteria): void
    {
        if ($criteria->kind !== null) {
            $query->where('catalog_items.kind', $criteria->kind);
        }

        if ($criteria->category !== null) {
            $query->where('catalog_items.category', $criteria->category);
        }

        // Matched as written, like `category`: both are the name of a thing
        // rather than a code. Left outside the `q` search below for the reason
        // the class comment gives — it does not split the screen in two.
        if ($criteria->company !== null) {
            $query->where('catalog_items.company', $criteria->company);
        }

        // Tri-state: unset lists both. See `CatalogItemListCriteria` for why.
        if ($criteria->isActive !== null) {
            $query->where('catalog_items.is_active', $criteria->isActive);
        }

        // `D-86` (F-10 · 1.6): the importer's flag, tri-state like `is_active`.
        if ($criteria->isIncomplete !== null) {
            $query->where('catalog_items.is_incomplete', $criteria->isIncomplete);
        }

        if ($criteria->q !== null) {
            $query->whereIn(
                'catalog_items.id',
                $this->search->search(
                    SearchIndex::Catalog,
                    $criteria->q,
                    // Only when it was asked for: the driver reads a null value
                    // as `is null`, so an unconditional key would search for the
                    // rows that have no tab at all.
                    $criteria->kind === null ? [] : ['kind' => $criteria->kind],
                ),
            );
        }
    }

    private static function hydrate(CatalogItem $row): CatalogItemSummary
    {
        return new CatalogItemSummary(
            id: $row->id,
            kind: $row->kind,
            name: $row->name,
            productCode: $row->product_code,
            category: $row->category,
            unit: $row->unit,
            serviceType: $row->service_type,
            company: $row->company,
            description: $row->description,
            notes: $row->notes,
            isActive: $row->is_active,
            isIncomplete: $row->is_incomplete,
            // `DB-08`: stored UTC. The immutable copies keep a caller from
            // mutating the model's Carbon instance through the read model.
            createdAt: new DateTimeImmutable((string) $row->created_at?->toIso8601String()),
            updatedAt: new DateTimeImmutable((string) $row->updated_at?->toIso8601String()),
        );
    }
}
