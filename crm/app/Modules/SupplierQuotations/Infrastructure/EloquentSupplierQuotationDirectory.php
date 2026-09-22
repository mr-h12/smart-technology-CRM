<?php

declare(strict_types=1);

namespace App\Modules\SupplierQuotations\Infrastructure;

use App\Modules\SupplierQuotations\Domain\Contracts\SupplierQuotationDirectoryInterface;
use App\Modules\SupplierQuotations\Domain\Listing\SupplierQuotationDetail;
use App\Modules\SupplierQuotations\Domain\Listing\SupplierQuotationLine;
use App\Modules\SupplierQuotations\Domain\Listing\SupplierQuotationListCriteria;
use App\Modules\SupplierQuotations\Domain\Listing\SupplierQuotationPage;
use App\Modules\SupplierQuotations\Domain\Listing\SupplierQuotationSummary;
use App\Modules\SupplierQuotations\Domain\Writing\SupplierQuotationDraft;
use App\Modules\SupplierQuotations\Infrastructure\Eloquent\SupplierQuotation;
use App\Support\Database\DocumentNumberAllocator;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;

/**
 * {@see SupplierQuotationDirectoryInterface} over `supplier_quotations` —
 * `EloquentDealDirectory`'s shape (Module 5 Point 2.3), minus the two things
 * §3.6 removes: there is no scoped builder factory and no `SearchService`
 * collaborator, because every role reads this resource with `Scope::All` and
 * nothing searches it until Step 4.
 *
 * ── The lines go in with one statement, and with no model of their own ─────
 *
 * Point 2.1. `supplier_quotation_items` has no Eloquent class: the only thing
 * this module does with a line is insert a batch of them and, from Point 2.2,
 * read them back — neither needs casts, events or a soft-delete trait, and a
 * model added for a second use nobody has asked for is the speculative file
 * `CLAUDE.md`'s waste audit names. `insert()` with the whole batch is also one
 * round trip where a model would be one per line.
 *
 * The transaction is **not** opened here. `CreateSupplierQuotation` owns it,
 * because `DB-11` puts the audit row inside the same commit and this class has
 * no audit vocabulary — the same division `SaveDeal` and `EloquentDealDirectory`
 * already draw.
 */
final readonly class EloquentSupplierQuotationDirectory implements SupplierQuotationDirectoryInterface
{
    /** §4.7: "SQ-2026-0001". */
    private const CODE_PREFIX = 'SQ';

    public function __construct(private ConnectionInterface $connection) {}

    public function create(SupplierQuotationDraft $draft, string $actorId): SupplierQuotationSummary
    {
        $row = new SupplierQuotation;
        $row->fill($draft->attributes);

        $row->code = (new DocumentNumberAllocator($this->connection, self::CODE_PREFIX))->next();
        $row->created_by = $actorId;
        $row->updated_by = $actorId;
        $row->save();

        $this->writeLines($row->id, $draft->items ?? [], $actorId);

        return self::hydrate($row);
    }

    /**
     * Point 2.4. `fill()` takes only what the draft kept, so a `PATCH` that
     * names three fields writes three fields; `DB-02`'s `updated_by` is the
     * caller and `created_by` is deliberately not touched.
     *
     * `save()` is called unconditionally rather than only when something
     * changed: Eloquent already writes nothing when no attribute is dirty, and
     * the use case decides separately whether an audit row is owed.
     */
    public function update(string $quotationId, SupplierQuotationDraft $draft, string $actorId): ?SupplierQuotationSummary
    {
        $row = SupplierQuotation::query()->whereKey($quotationId)->first();

        if (! $row instanceof SupplierQuotation) {
            return null;
        }

        $row->fill($draft->attributes);
        $row->updated_by = $actorId;
        $row->save();

        if ($draft->items !== null) {
            $this->replaceLines($row->id, $draft->items, $actorId);
        }

        return self::hydrate($row->refresh());
    }

    /**
     * The owner's ruling of 2026-09-02: a submitted `items` list replaces the
     * whole set rather than being merged into it.
     *
     * **Soft-deleted, not deleted.** `DB-01` forbids physically removing
     * business data, and a replaced line is business data — it is what the
     * supplier had quoted before this edit. `readLines()` filters on
     * `deleted_at`, so the old rows leave the read the moment they are stamped.
     * `updated_by` is stamped with them, because `AUD-02`'s question about a
     * removed line is who removed it.
     *
     * @param  list<array<string, mixed>>  $items
     */
    private function replaceLines(string $quotationId, array $items, string $actorId): void
    {
        $this->connection->table('supplier_quotation_items')
            ->where('supplier_quotation_id', $quotationId)
            ->whereNull('deleted_at')
            ->update(['deleted_at' => now(), 'updated_at' => now(), 'updated_by' => $actorId]);

        $this->writeLines($quotationId, $items, $actorId);
    }

    /**
     * Point 2.3. The header through the model — `SoftDeletes` is what makes an
     * archived offer invisible here, so `DB-01` costs no `where` of its own —
     * and the lines through the connection, for the reason `writeLines()`
     * gives: `supplier_quotation_items` has no Eloquent class and needs none to
     * be read.
     *
     * ── Ordered by `id`, which is stability rather than insertion order ────
     *
     * The table has no ordering column. Point 2.1 writes the whole batch with
     * one `insert()` under a single `now()`, and the ids are random UUIDs, so
     * the order the lines were typed in is not recorded anywhere and cannot be
     * recovered. Without an `ORDER BY`, PostgreSQL is free to return the rows
     * differently on two calls for the same offer. Ordering by `id` is
     * arbitrary but deterministic, which is the most this schema can honestly
     * offer; §7.2's "+ to add more" implies a user-visible order, and that gap
     * is on the debt register rather than invented here.
     */
    /**
     * Point 4.2. `SoftDeletes` on the model supplies `DB-01`, and §3.6 supplies
     * the absence of a scope — every role that reads this resource reads it
     * with `Scope::All`, "a shared screen, not restricted by ownership".
     *
     * The two filters and the sort allowlist were checked by
     * `SupplierQuotationListCriteria` (Point 4.1); what is decided here is what
     * the contract deliberately left to the query — where the nulls of a
     * nullable sort column go, and how two rows with the same sort key are
     * ordered.
     */
    public function list(SupplierQuotationListCriteria $criteria): SupplierQuotationPage
    {
        $query = SupplierQuotation::query();

        if ($criteria->supplierId !== null) {
            $query->where('supplier_quotations.supplier_id', $criteria->supplierId);
        }

        if ($criteria->dealIds !== null) {
            // `D-88`: an offer with no deal is never in a code's result —
            // `whereIn` on a null `deal_id` is false, and `[]` matches nothing.
            $query->whereIn('supplier_quotations.deal_id', $criteria->dealIds);
        }

        if ($criteria->dealId !== null) {
            $query->where('supplier_quotations.deal_id', $criteria->dealId);
        }

        // §6.1: `total` is the whole query's, counted before the page is taken.
        $total = $query->count();

        foreach ($criteria->sorts as $sort) {
            // Written out per case rather than concatenated: PHPStan level 10
            // requires `orderByRaw` to receive a `literal-string`, and the
            // allowlist is already checked in the criteria — so the default arm
            // is unreachable and exists to fail loudly if a field is ever added
            // to `ALLOWED_SORTS` without an ordering to go with it.
            // `EloquentCatalogItemDirectory` makes the same argument for its
            // `group_by`.
            //
            // **`nulls last` on both directions.** §7.2 marks neither date
            // required and Point 1.1 left `offer_date` nullable, and PostgreSQL
            // puts nulls first on a descending order — so the default order
            // would open the supplier's page with the offers nobody dated.
            // Ascending already behaves that way; it is written out anyway so
            // the two directions agree on the page rather than in a manual.
            $query->orderByRaw(match (true) {
                $sort['field'] === 'offer_date' && $sort['descending'] => 'supplier_quotations.offer_date desc nulls last',
                $sort['field'] === 'offer_date' => 'supplier_quotations.offer_date asc nulls last',
                $sort['field'] === 'created_at' && $sort['descending'] => 'supplier_quotations.created_at desc',
                $sort['field'] === 'created_at' => 'supplier_quotations.created_at asc',
                default => throw new InvalidArgumentException(
                    'No ordering is defined for the declared sort field `'.$sort['field'].'`.'
                ),
            });
        }

        // A deterministic tiebreak, for `EloquentCatalogItemDirectory`'s reason:
        // PostgreSQL may return equal sort keys in any order, so two offers of
        // the same date could appear on page 1 and page 2 of one listing, or on
        // neither.
        $query->orderBy('supplier_quotations.id');

        $rows = $query->offset($criteria->offset())->limit($criteria->perPage)->get();

        $items = [];

        foreach ($rows as $row) {
            $items[] = self::hydrate($row);
        }

        return new SupplierQuotationPage($items, $total, $criteria->page, $criteria->perPage);
    }

    public function find(string $quotationId): ?SupplierQuotationDetail
    {
        $row = SupplierQuotation::query()->whereKey($quotationId)->first();

        if (! $row instanceof SupplierQuotation) {
            return null;
        }

        return new SupplierQuotationDetail(self::hydrate($row), $this->readLines($row->id));
    }

    /** @return list<SupplierQuotationLine> */
    private function readLines(string $quotationId): array
    {
        $rows = $this->connection->table('supplier_quotation_items')
            ->where('supplier_quotation_id', $quotationId)
            ->whereNull('deleted_at')
            ->orderBy('id')
            // `D-81`: available is subtracted here, where NUMERIC is exact.
            ->selectRaw('id, catalog_item_id, unit_price, quantity, consumed_quantity, quantity - consumed_quantity as available_quantity')
            ->get();

        $lines = [];

        foreach ($rows as $row) {
            $lineId = $row->id;
            $catalogItemId = $row->catalog_item_id;
            $unitPrice = $row->unit_price;
            $quantity = $row->quantity;
            $consumed = $row->consumed_quantity;
            $available = $row->available_quantity;

            if (! is_string($lineId) || ! is_string($catalogItemId) || ! is_string($unitPrice) || ! is_string($quantity) || ! is_string($consumed) || ! is_string($available)) {
                // Unreachable while the columns stand as Point 1.2 built them:
                // all four are `NOT NULL`, and PostgreSQL hands `uuid` and
                // `numeric` back as strings. Refusing loudly is
                // `DocumentNumberAllocator`'s choice for the same situation —
                // a `(string)` cast here would
                // turn a driver returning a float into a silently rounded price,
                // which is the one failure `DB-07` exists to prevent.
                throw new RuntimeException('supplier_quotation_items returned a line that is not decimal text.');
            }

            $lines[] = new SupplierQuotationLine(
                id: $lineId,
                catalogItemId: $catalogItemId,
                unitPrice: $unitPrice,
                quantity: $quantity,
                consumedQuantity: $consumed,
                availableQuantity: $available,
            );
        }

        return $lines;
    }

    /**
     * §7.2's lines, into Point 1.2's table.
     *
     * No `id` from the caller and no timestamps either: `insert()` bypasses the
     * model, so everything `standardColumns()` would have filled is filled here
     * instead. `DB-02`'s actor is the use case's on a line exactly as on the
     * header — a line has no separate author.
     *
     * @param  list<array<string, mixed>>  $items
     */
    private function writeLines(string $quotationId, array $items, string $actorId): void
    {
        if ($items === []) {
            return;
        }

        $now = now();
        $rows = [];

        foreach ($items as $item) {
            $rows[] = [
                'id' => Str::uuid()->toString(),
                'supplier_quotation_id' => $quotationId,
                'created_by' => $actorId,
                'updated_by' => $actorId,
                'created_at' => $now,
                'updated_at' => $now,
                ...$item,
            ];
        }

        $this->connection->table('supplier_quotation_items')->insert($rows);
    }

    private static function hydrate(SupplierQuotation $row): SupplierQuotationSummary
    {
        return new SupplierQuotationSummary(
            id: $row->id,
            code: $row->code,
            supplierId: $row->supplier_id,
            dealId: $row->deal_id,
            totalPrice: $row->total_price,
            currencyId: $row->currency_id,
            offerDate: $row->offer_date,
            validUntil: $row->valid_until,
            notes: $row->notes,
        );
    }
}
