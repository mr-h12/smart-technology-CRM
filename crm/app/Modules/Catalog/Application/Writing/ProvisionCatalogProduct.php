<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Application\Writing;

use App\Modules\Catalog\Domain\Contracts\CatalogItemDirectoryInterface;
use App\Modules\Catalog\Domain\Contracts\CatalogProductProvisionerInterface;

/**
 * `D-22`'s automatic add, on Catalog's side of the boundary.
 *
 * ── Why it delegates the write instead of doing it ─────────────────────────
 *
 * `SaveCatalogItem::create()` already owns the create: it opens the
 * transaction, files the company on `DB-05`'s managed list, and writes
 * `CATALOG_ITEM_CREATED`. `AUD-01` names create explicitly, and `D-45`'s stated
 * mitigation for opening the catalog to every employee is *"every edit is
 * written to the audit log"* — so a second create path beside that one would be
 * a product added with no audit row, which is the one thing `D-45` bought.
 * This class is therefore a lookup and a delegation, not a writer.
 *
 * ── Why it lives in Application ────────────────────────────────────────────
 *
 * It needs `SaveCatalogItem`, which is Application, and `deptrac.layers.yaml`
 * lets `Infrastructure` reach `Domain` but not `Application`. So the reads go
 * through the Domain contract and the class sits beside the writer it calls.
 *
 * ── What it deliberately does not do ───────────────────────────────────────
 *
 * It does not check a permission — `D-45` opens catalog creation to every
 * employee, and the caller has already passed its own route's grant. It does
 * not open a transaction: `SaveCatalogItem` opens its own, and Module 6 calls
 * this from inside the transaction `DB-11` requires, where a nested one is a
 * savepoint rather than a second commit. It does not trim or normalise the
 * name beyond case — the boundary that accepts a typed name is Point 3.3's,
 * and normalising in two places is how two rules drift apart.
 */
final readonly class ProvisionCatalogProduct implements CatalogProductProvisionerInterface
{
    public function __construct(
        private CatalogItemDirectoryInterface $items,
        private SaveCatalogItem $catalog,
    ) {}

    public function productIdFor(string $name, string $actorId): string
    {
        $existing = $this->items->findProductIdByName($name);

        if ($existing !== null) {
            return $existing;
        }

        // §7.3's other columns are left to their defaults, which is what "added
        // automatically, without review" can honestly mean: an offer line
        // carries a name, a price and a quantity, and the price is `D-21`'s to
        // keep off the catalog. `is_active` defaults true, so the product the
        // next offer searches for is there.
        return $this->catalog->create(['kind' => 'product', 'name' => $name], $actorId)->id;
    }
}
