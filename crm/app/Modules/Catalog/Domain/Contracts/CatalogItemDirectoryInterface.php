<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Domain\Contracts;

use App\Modules\Catalog\Domain\Importing\ImportSummary;
use App\Modules\Catalog\Domain\Listing\CatalogItemListCriteria;
use App\Modules\Catalog\Domain\Listing\CatalogItemPage;
use App\Modules\Catalog\Domain\Listing\CatalogItemSummary;
use App\Modules\Catalog\Domain\Writing\CatalogItemDraft;

/**
 * The `catalog_items` table as §8's Catalog screen needs to read it.
 *
 * ── No scope parameter, for the reason the supplier directory gives ────────
 *
 * `CustomerDirectoryInterface` takes a `CustomerRowScope` on every method
 * because `SEC-08` is row-level security and §3.3 gives customers five scopes.
 * §3.7 — the one table covering the catalog **and** its suppliers — has no
 * ownership to scope by: its two columns are "All operational roles" and CEO,
 * and the seeded matrix gives every one of them `Scope::All`.
 *
 * So the absence of a scope is a transcription of §3.7, not an omission. If
 * §3.7 ever gains one, this interface changes and every caller is forced to say
 * what it holds — which is the failure mode worth having.
 */
interface CatalogItemDirectoryInterface
{
    public function list(CatalogItemListCriteria $criteria): CatalogItemPage;

    /** Null when the row is absent or soft-deleted. */
    public function find(string $catalogItemId): ?CatalogItemSummary;

    /**
     * The id of a live product with exactly this name, ignoring case — Module 6
     * Point 3.1, the lookup `D-22`'s automatic add is decided by.
     *
     * Returns an id rather than a `CatalogItemSummary` because the only caller
     * needs a foreign key, and `list()`'s `q` is the wrong instrument: that is
     * `D-48`'s fuzzy `SearchService`, which is built to return near matches and
     * would happily call "Split unit 2HP" a match for "Split unit 1.5HP".
     *
     * **Live** means not soft-deleted (`DB-01` — an archived row is out of the
     * set), and **product** means §7.3's `kind`, so a service of the same name
     * is not it. A **deactivated** product still matches: `is_active` is not an
     * archive, and §10.4 (`D-37`) gives a deactivated item a documented life on
     * an open quotation, so pointing at one is a handled situation where a
     * second row of the same name would not be.
     */
    public function findProductIdByName(string $name): ?string;

    /** Point 3.2. */
    public function create(CatalogItemDraft $draft, string $actorId): CatalogItemSummary;

    /** Null on the same case as {@see find()} — absent or soft-deleted. */
    public function update(string $catalogItemId, CatalogItemDraft $draft, string $actorId): ?CatalogItemSummary;

    /** `D-86` (F-10 · 1.5) — one `catalog_item_suppliers` row; its id, for the audit row. */
    public function link(string $catalogItemId, string $supplierId, string $actorId): string;

    /**
     * `D-86` (F-10 · 1.7) — the live links of one item, as supplier ids.
     *
     * @return list<string>
     */
    public function supplierIdsOf(string $catalogItemId): array;

    /**
     * `D-86` (F-10 · 1.7) — retire the live link (`DB-01`: soft delete, never
     * a row delete) and answer its id for the audit row. Null when no live
     * link joins the pair.
     */
    public function unlinkSupplier(string $catalogItemId, string $supplierId, string $actorId): ?string;

    /** `D-86` (F-10 · 1.5) — the one `catalog_import_batches` row of an import. */
    public function recordImportBatch(
        string $originalFilename,
        int $rowCount,
        int $importedCount,
        int $incompleteCount,
        string $actorId,
    ): ImportSummary;
}
