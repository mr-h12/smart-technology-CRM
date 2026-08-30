<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Domain\Contracts;

use App\Modules\Catalog\Domain\Listing\CatalogItemListCriteria;
use App\Modules\Catalog\Domain\Listing\CatalogItemPage;
use App\Modules\Catalog\Domain\Listing\CatalogItemSummary;

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
}
